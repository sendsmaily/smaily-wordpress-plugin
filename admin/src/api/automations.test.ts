import { describe, expect, it } from 'vitest';

import { classifyAutomationsFailure } from './automations';
import { ApiError } from './client';

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
