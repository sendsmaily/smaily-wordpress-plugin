import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { cancelBackfill, getBackfillStatus, startBackfill } from './backfill';
import { _resetApiClient, configureApiClient } from './client';

describe('backfill API wrappers', () => {
  beforeEach(() => {
    _resetApiClient();
    configureApiClient({ restUrl: 'https://example.test/api', nonce: 'n' });
    vi.restoreAllMocks();
  });

  afterEach(() => {
    _resetApiClient();
    vi.restoreAllMocks();
  });

  it('startBackfill POSTs job_type and returns the row info', async () => {
    const fetchSpy = vi.spyOn(global, 'fetch').mockResolvedValue(
      new Response(JSON.stringify({ job_id: 42, status: 'running', total: 1234 }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    );

    const result = await startBackfill('contacts');

    expect(result.job_id).toBe(42);
    expect(result.total).toBe(1234);

    const [url, init] = fetchSpy.mock.calls[0]!;
    expect(url).toBe('https://example.test/api/backfill/start');
    expect((init as RequestInit).method).toBe('POST');
    expect(JSON.parse((init as RequestInit).body as string)).toEqual({ job_type: 'contacts' });
  });

  it('getBackfillStatus uses GET with job_type as query string', async () => {
    const fetchSpy = vi.spyOn(global, 'fetch').mockResolvedValue(
      new Response(
        JSON.stringify({ status: 'running', processed: 10, total: 100, percent: 10, eta_seconds: 90 }),
        { status: 200, headers: { 'Content-Type': 'application/json' } },
      ),
    );

    const result = await getBackfillStatus('contacts');

    expect(result.processed).toBe(10);

    const [url, init] = fetchSpy.mock.calls[0]!;
    expect(url).toBe('https://example.test/api/backfill/status?job_type=contacts');
    expect((init as RequestInit).method).toBe('GET');
  });

  it('cancelBackfill POSTs job_type and returns the cancelled flag', async () => {
    vi.spyOn(global, 'fetch').mockResolvedValue(
      new Response(JSON.stringify({ cancelled: true }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    );

    const result = await cancelBackfill('contacts');

    expect(result.cancelled).toBe(true);
  });
});
