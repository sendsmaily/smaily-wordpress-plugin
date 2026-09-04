import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { getEventDetail, listEvents, retryEvents } from './events';
import { _resetApiClient, configureApiClient } from './client';

describe('events API wrappers', () => {
  beforeEach(() => {
    _resetApiClient();
    configureApiClient({ restUrl: 'https://example.test/api', nonce: 'n' });
    vi.restoreAllMocks();
  });

  afterEach(() => {
    _resetApiClient();
    vi.restoreAllMocks();
  });

  it('listEvents GETs /events with no query when unfiltered', async () => {
    const fetchSpy = vi.spyOn(global, 'fetch').mockResolvedValue(
      new Response(
        JSON.stringify({ events: [], total: 0, page: 1, per_page: 50, failed_24h: 0 }),
        { status: 200, headers: { 'Content-Type': 'application/json' } },
      ),
    );

    const res = await listEvents();

    expect(res.total).toBe(0);
    const [url, init] = fetchSpy.mock.calls[0]!;
    expect(url).toBe('https://example.test/api/events');
    expect((init as RequestInit).method ?? 'GET').toBe('GET');
  });

  it('listEvents serialises filters into the query string', async () => {
    const fetchSpy = vi.spyOn(global, 'fetch').mockResolvedValue(
      new Response(
        JSON.stringify({ events: [], total: 0, page: 2, per_page: 25, failed_24h: 3 }),
        { status: 200, headers: { 'Content-Type': 'application/json' } },
      ),
    );

    await listEvents({ page: 2, perPage: 25, source: 'rec_engine', status: 'failed', type: 'order.upsert' });

    const [url] = fetchSpy.mock.calls[0]!;
    expect(url).toContain('https://example.test/api/events?');
    expect(url).toContain('page=2');
    expect(url).toContain('per_page=25');
    expect(url).toContain('source=rec_engine');
    expect(url).toContain('status=failed');
    expect(url).toContain('type=order.upsert');
  });

  it('getEventDetail GETs /events/detail with source + id', async () => {
    const fetchSpy = vi.spyOn(global, 'fetch').mockResolvedValue(
      new Response(
        JSON.stringify({
          event: {
            id: 7,
            source: 'rec_engine',
            event_type: 'order.upsert',
            entity_id: '42',
            status: 'failed',
            attempts: 5,
            max_attempts: 5,
            last_error: 'http_503 unavailable',
            created_at: '2026-06-09 10:00:00',
          },
          payload: '{"order_id":42}',
        }),
        { status: 200, headers: { 'Content-Type': 'application/json' } },
      ),
    );

    const res = await getEventDetail('rec_engine', 7);

    expect(res.event.last_error).toBe('http_503 unavailable');
    expect(res.payload).toContain('order_id');
    const [url] = fetchSpy.mock.calls[0]!;
    expect(url).toBe('https://example.test/api/events/detail?source=rec_engine&id=7');
  });

  it('retryEvents POSTs /events/retry with the source + id body', async () => {
    const fetchSpy = vi.spyOn(global, 'fetch').mockResolvedValue(
      new Response(JSON.stringify({ reset: 1 }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    );

    const res = await retryEvents({ source: 'rec_engine', id: 42 });

    expect(res.reset).toBe(1);
    const [url, init] = fetchSpy.mock.calls[0]!;
    expect(url).toBe('https://example.test/api/events/retry');
    expect((init as RequestInit).method).toBe('POST');
    expect(JSON.parse((init as RequestInit).body as string)).toEqual({ source: 'rec_engine', id: 42 });
  });

  it('retryEvents POSTs an empty body for retry-all', async () => {
    const fetchSpy = vi.spyOn(global, 'fetch').mockResolvedValue(
      new Response(JSON.stringify({ reset: 5 }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    );

    const res = await retryEvents();

    expect(res.reset).toBe(5);
    const [, init] = fetchSpy.mock.calls[0]!;
    expect(JSON.parse((init as RequestInit).body as string)).toEqual({});
  });
});
