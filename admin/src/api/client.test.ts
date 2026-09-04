import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { _resetApiClient, ApiError, apiRequest, configureApiClient } from './client';

describe('apiRequest', () => {
  beforeEach(() => {
    _resetApiClient();
    vi.restoreAllMocks();
  });

  afterEach(() => {
    _resetApiClient();
    vi.restoreAllMocks();
  });

  it('throws when called before configureApiClient', async () => {
    await expect(apiRequest('/anything')).rejects.toThrow(/before configureApiClient/);
  });

  it('strips a trailing slash from the configured base URL', async () => {
    configureApiClient({ restUrl: 'https://example.test/wp-json/smaily-connect/v1/', nonce: 'abc' });

    const fetchSpy = vi.spyOn(global, 'fetch').mockResolvedValue(
      new Response(JSON.stringify({ ok: true }), { status: 200, headers: { 'Content-Type': 'application/json' } }),
    );

    await apiRequest('/test-smaily');

    const [url] = fetchSpy.mock.calls[0]!;
    expect(url).toBe('https://example.test/wp-json/smaily-connect/v1/test-smaily');
  });

  it('sends the X-WP-Nonce header on every request', async () => {
    configureApiClient({ restUrl: 'https://example.test/api', nonce: 'NONCE-XYZ' });

    const fetchSpy = vi.spyOn(global, 'fetch').mockResolvedValue(
      new Response(JSON.stringify({}), { status: 200, headers: { 'Content-Type': 'application/json' } }),
    );

    await apiRequest('/test');

    const init = fetchSpy.mock.calls[0]![1] as RequestInit;
    expect((init.headers as Record<string, string>)['X-WP-Nonce']).toBe('NONCE-XYZ');
  });

  it('JSON-encodes a body and sets Content-Type for POSTs', async () => {
    configureApiClient({ restUrl: 'https://example.test/api', nonce: 'n' });

    const fetchSpy = vi.spyOn(global, 'fetch').mockResolvedValue(
      new Response(JSON.stringify({ ok: true }), { status: 200, headers: { 'Content-Type': 'application/json' } }),
    );

    await apiRequest('/test', { method: 'POST', body: { hello: 'world' } });

    const init = fetchSpy.mock.calls[0]![1] as RequestInit;
    expect(init.method).toBe('POST');
    expect((init.headers as Record<string, string>)['Content-Type']).toBe('application/json');
    expect(init.body).toBe(JSON.stringify({ hello: 'world' }));
  });

  it('throws ApiError with status + body on non-2xx responses', async () => {
    configureApiClient({ restUrl: 'https://example.test/api', nonce: 'n' });

    vi.spyOn(global, 'fetch').mockResolvedValue(
      new Response(JSON.stringify({ code: 'forbidden' }), {
        status: 403,
        headers: { 'Content-Type': 'application/json' },
      }),
    );

    const promise = apiRequest('/locked');
    await expect(promise).rejects.toBeInstanceOf(ApiError);
    try {
      await promise;
    } catch (err) {
      const apiErr = err as ApiError;
      expect(apiErr.status).toBe(403);
      expect(apiErr.body).toEqual({ code: 'forbidden' });
    }
  });

  it('returns undefined on 204 No Content without trying to parse JSON', async () => {
    configureApiClient({ restUrl: 'https://example.test/api', nonce: 'n' });

    vi.spyOn(global, 'fetch').mockResolvedValue(new Response(null, { status: 204 }));

    const result = await apiRequest('/test');
    expect(result).toBeUndefined();
  });
});
