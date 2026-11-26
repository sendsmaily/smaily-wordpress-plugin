import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
  classifyAutomationsFailure,
  getAutomationsCatalog,
  getAutomationsConfig,
  putAutomationsConfig,
  sendAutomationTest,
  switchAutomationTestMode,
} from './automations';
import { _resetApiClient, ApiError, configureApiClient } from './client';

/**
 * classifyAutomationsFailure — the shared failure mapper for all three
 * automations proxy routes. The generic branch must produce a HUMAN
 * headline with the raw technical error demoted to `detail` (T2.4/4 —
 * a deleted engine-side key surfaced as a bare "GET /… → 401" banner).
 */
describe('classifyAutomationsFailure', () => {
  it('maps a proxy 503 to not_connected', () => {
    const failure = classifyAutomationsFailure(
      new ApiError('GET → 503', 503, { error: 'not_configured', message: 'pole seadistatud' }),
    );
    expect(failure.kind).toBe('not_connected');
    expect(failure.message).toBe('pole seadistatud');
  });

  it('maps a 502 api_key_rejected body to key_rejected', () => {
    const failure = classifyAutomationsFailure(
      new ApiError('GET → 502', 502, { error: 'api_key_rejected', message: 'võti tagasi lükatud' }),
    );
    expect(failure.kind).toBe('key_rejected');
    expect(failure.message).toBe('võti tagasi lükatud');
  });

  it('turns an unrecognised ApiError into a human headline with the raw error demoted to detail', () => {
    const failure = classifyAutomationsFailure(new ApiError('GET /rec-engine/automations/catalog → 401', 401, null));

    expect(failure.kind).toBe('error');
    // Human: what happened, the status, and where to go — never the raw request line.
    expect(failure.message).toContain('HTTP 401');
    expect(failure.message).toContain('Campaign Intelligence');
    expect(failure.message).not.toContain('GET /');
    // The technical fact is preserved for the detail line.
    expect(failure.detail).toBe('GET /rec-engine/automations/catalog → 401');
  });

  it('prefers the proxy body message as the detail when present', () => {
    const failure = classifyAutomationsFailure(
      new ApiError('GET → 502', 502, { error: 'engine_unreachable', message: 'engine timed out' }),
    );
    expect(failure.kind).toBe('error');
    expect(failure.message).toContain('HTTP 502');
    expect(failure.detail).toBe('engine timed out');
  });

  it('shows the engine error code as the detail when the proxy sends no message (CON-301)', () => {
    const failure = classifyAutomationsFailure(
      new ApiError('GET /rec-engine/automations/config → 502', 502, { error: 'network_error', requestId: null }),
    );
    expect(failure.kind).toBe('error');
    expect(failure.message).toContain('HTTP 502');
    expect(failure.detail).toBe('network_error');
  });

  it('maps a non-ApiError to a human network message with the Error text as detail', () => {
    const failure = classifyAutomationsFailure(new Error('fetch failed'));
    expect(failure.kind).toBe('error');
    expect(failure.message).toContain('network connection');
    expect(failure.detail).toBe('fetch failed');
  });

  it('handles a thrown non-Error without a detail line', () => {
    const failure = classifyAutomationsFailure('boom');
    expect(failure.kind).toBe('error');
    expect(failure.detail).toBeUndefined();
  });
});

function answer(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

describe('automations proxy requests', () => {
  beforeEach(() => {
    _resetApiClient();
    configureApiClient({ restUrl: 'https://example.test/api', nonce: 'nonce-1' });
    vi.restoreAllMocks();
  });

  afterEach(() => {
    _resetApiClient();
    vi.restoreAllMocks();
  });

  function call(fetchSpy: ReturnType<typeof vi.spyOn>, index = 0): { url: string; init: RequestInit } {
    const [url, init] = fetchSpy.mock.calls[index] as [string, RequestInit];
    return { url, init };
  }

  it('reads the catalog and the config with GET and the nonce', async () => {
    const fetchSpy = vi.spyOn(global, 'fetch')
      .mockResolvedValueOnce(answer({ triggers: [], language_modes: ['single'], docs: '' }))
      .mockResolvedValueOnce(answer({ configs: [], setup: [] }));

    await expect(getAutomationsCatalog()).resolves.toEqual({ triggers: [], language_modes: ['single'], docs: '' });
    await expect(getAutomationsConfig()).resolves.toEqual({ configs: [], setup: [] });

    expect(call(fetchSpy, 0).url).toBe('https://example.test/api/rec-engine/automations/catalog');
    expect(call(fetchSpy, 1).url).toBe('https://example.test/api/rec-engine/automations/config');
    expect(call(fetchSpy, 1).init.method).toBe('GET');
    expect((call(fetchSpy, 1).init.headers as Record<string, string>)['X-WP-Nonce']).toBe('nonce-1');
  });

  it('PUTs the selection and maps a 422 to validation_failed', async () => {
    const fetchSpy = vi.spyOn(global, 'fetch')
      .mockResolvedValueOnce(answer({ ok: true, upserted: 1 }))
      .mockResolvedValueOnce(answer({ error: 'validation_failed', errors: [{ index: 0, field: 'cooldown_days', message: 'x' }] }, 422))
      .mockResolvedValueOnce(answer({ error: 'api_key_rejected' }, 502));
    const row = { trigger_key: 't', enabled: false, language_mode: 'single' as const, cooldown_days: 7, daily_cap: null };

    await expect(putAutomationsConfig([row])).resolves.toEqual({ ok: true, upserted: 1 });
    expect(call(fetchSpy).init.method).toBe('PUT');
    expect(JSON.parse(call(fetchSpy).init.body as string)).toEqual({ configs: [row] });

    await expect(putAutomationsConfig([row])).resolves.toEqual({
      ok: false,
      kind: 'validation_failed',
      errors: [{ index: 0, field: 'cooldown_days', message: 'x' }],
    });
    await expect(putAutomationsConfig([row])).resolves.toMatchObject({ ok: false, kind: 'key_rejected' });
  });

  it('posts a test send with the key and the typed address (CON-455)', async () => {
    const fetchSpy = vi.spyOn(global, 'fetch').mockResolvedValue(answer({ ok: true, accepted_at: '2026-11-26T08:00:00.000Z' }));

    await expect(sendAutomationTest('replenish_due', 'owner@shop.example')).resolves.toEqual({ ok: true });

    const { url, init } = call(fetchSpy);
    expect(url).toBe('https://example.test/api/rec-engine/automations/test-send');
    expect(init.method).toBe('POST');
    expect((init.headers as Record<string, string>)['X-WP-Nonce']).toBe('nonce-1');
    expect(JSON.parse(init.body as string)).toEqual({ trigger_key: 'replenish_due', email: 'owner@shop.example' });
    expect(fetchSpy).toHaveBeenCalledTimes(1);
  });

  it('switches test mode off with the confirmation and back on without it (CON-455)', async () => {
    const fetchSpy = vi.spyOn(global, 'fetch').mockImplementation(async () => answer({ ok: true }));

    await expect(switchAutomationTestMode('replenish_due', false)).resolves.toEqual({ ok: true });
    await expect(switchAutomationTestMode('replenish_due', true)).resolves.toEqual({ ok: true });

    expect(call(fetchSpy, 0).url).toBe('https://example.test/api/rec-engine/automations/go-live');
    expect(JSON.parse(call(fetchSpy, 0).init.body as string)).toEqual({ trigger_key: 'replenish_due', live: true, confirmed: true });
    expect(JSON.parse(call(fetchSpy, 1).init.body as string)).toEqual({ trigger_key: 'replenish_due', live: false });
  });

  it.each([
    [{ error: 'not_ready', reasons: ['no_workflow', 7, 'no_products'] }, 409, { error: 'not_ready', codes: ['no_workflow', 'no_products'] }],
    [{ error: 'go_live_blocked', blockers: ['no_design'] }, 409, { error: 'go_live_blocked', codes: ['no_design'] }],
    [{ error: 'sender_refused', sender_state: 'unverified' }, 409, { error: 'sender_refused', codes: ['unverified'] }],
    [{ error: 'rate_limited', retry_after: 1800 }, 429, { error: 'rate_limited', codes: [] }],
    [{ error: 'smaily_unavailable', message: 'remote text' }, 502, { error: 'smaily_unavailable', codes: [] }],
    [{ reasons: 'not-a-list' }, 500, { error: 'engine_unavailable', codes: [] }],
    [null, 500, { error: 'engine_unavailable', codes: [] }],
  ])('maps a refused call to its codes, never a message (%o)', async (body, status, expected) => {
    vi.spyOn(global, 'fetch').mockResolvedValue(
      body === null ? new Response('<html>', { status }) : answer(body, status),
    );

    await expect(sendAutomationTest('replenish_due', 'owner@shop.example')).resolves.toEqual({ ok: false, ...expected });
  });

  it('maps a network failure of the switch to engine_unavailable', async () => {
    vi.spyOn(global, 'fetch').mockRejectedValue(new TypeError('fetch failed'));

    await expect(switchAutomationTestMode('replenish_due', true)).resolves.toEqual({
      ok: false,
      error: 'engine_unavailable',
      codes: [],
    });
  });
});
