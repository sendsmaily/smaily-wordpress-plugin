import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { _resetApiClient, ApiError, configureApiClient } from './client';
import { disconnectEngine, pingEngine, setupExchange } from './recEngine';

/** Build a JSON Response the way the plugin's REST endpoints reply. */
function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}

describe('rec-engine API wrappers', () => {
  beforeEach(() => {
    _resetApiClient();
    configureApiClient({ restUrl: 'https://example.test/api', nonce: 'n' });
    vi.restoreAllMocks();
  });

  afterEach(() => {
    _resetApiClient();
    vi.restoreAllMocks();
  });

  describe('setupExchange', () => {
    it('POSTs the setup URL to /rec-engine/setup-exchange and returns the connected body', async () => {
      const fetchSpy = vi.spyOn(global, 'fetch').mockResolvedValue(
        jsonResponse({
          connected: true,
          tenantName: 'Smaily Connect test',
          tenantId: 'tnt_1',
          engineVersion: '1.6.0',
          baseUrl: 'https://engine.test',
          issuedAt: '2026-09-04T10:00:00Z',
        }),
      );

      const result = await setupExchange({ setupUrl: 'https://engine.test/setup/tok' });

      expect(result).toEqual({
        connected: true,
        tenantName: 'Smaily Connect test',
        tenantId: 'tnt_1',
        engineVersion: '1.6.0',
        baseUrl: 'https://engine.test',
        issuedAt: '2026-09-04T10:00:00Z',
      });

      const [url, init] = fetchSpy.mock.calls[0]!;
      expect(url).toBe('https://example.test/api/rec-engine/setup-exchange');
      expect((init as RequestInit).method).toBe('POST');
      expect(JSON.parse((init as RequestInit).body as string)).toEqual({
        setup_url: 'https://engine.test/setup/tok',
      });
    });

    it('unwraps the typed failure body the endpoint returns with a 4xx status', async () => {
      vi.spyOn(global, 'fetch').mockResolvedValue(
        jsonResponse(
          {
            connected: false,
            error: 'token_expired_or_used',
            message: 'That setup link has already been used.',
            regenerateUrl: 'https://engine.test/integrations',
          },
          400,
        ),
      );

      const result = await setupExchange({ setupUrl: 'https://engine.test/setup/tok' });

      expect(result).toEqual({
        connected: false,
        error: 'token_expired_or_used',
        message: 'That setup link has already been used.',
        regenerateUrl: 'https://engine.test/integrations',
      });
    });

    it('reports engine_unreachable when the request fails at the transport level', async () => {
      vi.spyOn(global, 'fetch').mockRejectedValue(new Error('Failed to fetch'));

      const result = await setupExchange({ setupUrl: 'https://engine.test/setup/tok' });

      expect(result).toEqual({
        connected: false,
        error: 'engine_unreachable',
        message: 'Failed to fetch',
      });
    });

    it('reports engine_unreachable when the error reply carries no JSON body', async () => {
      // A non-JSON error page yields an ApiError with a null body — not a
      // typed response, so the caller still gets engine_unreachable.
      vi.spyOn(global, 'fetch').mockResolvedValue(
        new Response('<html>502</html>', {
          status: 502,
          headers: { 'Content-Type': 'text/html' },
        }),
      );

      const result = await setupExchange({ setupUrl: 'https://engine.test/setup/tok' });

      expect(result.connected).toBe(false);
      expect((result as { error: string }).error).toBe('engine_unreachable');
    });
  });

  describe('pingEngine', () => {
    it('POSTs to /rec-engine/ping and returns the healthy body', async () => {
      const fetchSpy = vi.spyOn(global, 'fetch').mockResolvedValue(
        jsonResponse({
          ok: true,
          engineVersion: '1.6.0',
          tenantStatus: 'active',
          serverTime: '2026-09-04T10:00:00Z',
        }),
      );

      const result = await pingEngine();

      expect(result).toEqual({
        ok: true,
        engineVersion: '1.6.0',
        tenantStatus: 'active',
        serverTime: '2026-09-04T10:00:00Z',
      });

      const [url, init] = fetchSpy.mock.calls[0]!;
      expect(url).toBe('https://example.test/api/rec-engine/ping');
      expect((init as RequestInit).method).toBe('POST');
    });

    it('unwraps the typed failure body from a 502 reply', async () => {
      vi.spyOn(global, 'fetch').mockResolvedValue(
        jsonResponse(
          { ok: false, error: 'engine_unreachable', message: 'Engine timed out', requestId: 'req_9' },
          502,
        ),
      );

      const result = await pingEngine();

      expect(result).toEqual({
        ok: false,
        error: 'engine_unreachable',
        message: 'Engine timed out',
        requestId: 'req_9',
      });
    });

    it('reports network_error when the request fails at the transport level', async () => {
      vi.spyOn(global, 'fetch').mockRejectedValue(new Error('Network down'));

      const result = await pingEngine();

      expect(result).toEqual({
        ok: false,
        error: 'network_error',
        message: 'Network down',
      });
    });
  });

  describe('disconnectEngine', () => {
    it('POSTs to /rec-engine/disconnect and returns the parsed body', async () => {
      const fetchSpy = vi.spyOn(global, 'fetch').mockResolvedValue(
        jsonResponse({ disconnected: true }),
      );

      const result = await disconnectEngine();

      expect(result).toEqual({ disconnected: true });

      const [url, init] = fetchSpy.mock.calls[0]!;
      expect(url).toBe('https://example.test/api/rec-engine/disconnect');
      expect((init as RequestInit).method).toBe('POST');
    });

    it('throws ApiError on an error reply — it has no typed failure shape', async () => {
      vi.spyOn(global, 'fetch').mockResolvedValue(
        jsonResponse({ code: 'rest_forbidden' }, 403),
      );

      await expect(disconnectEngine()).rejects.toBeInstanceOf(ApiError);
    });

    it('propagates a transport failure to the caller', async () => {
      vi.spyOn(global, 'fetch').mockRejectedValue(new Error('Network down'));

      await expect(disconnectEngine()).rejects.toThrow('Network down');
    });
  });
});
