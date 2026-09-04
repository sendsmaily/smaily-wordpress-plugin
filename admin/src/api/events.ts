import { apiRequest } from './client';

/** Which durable queue a row came from. Mirrors the PHP `source` literal. */
export type EventSource = 'rec_engine' | 'smaily';

export interface EventRow {
  id: number;
  source: EventSource;
  event_type: string;
  entity_id: string;
  status: string;
  attempts: number;
  /** Only the rec queue tracks a cap; the Smaily queue returns null. */
  max_attempts: number | null;
  last_error: string;
  created_at: string;
}

export interface EventsListResponse {
  events: EventRow[];
  total: number;
  page: number;
  per_page: number;
  /** Count of `failed` rows across both queues in the last 24h (banner). */
  failed_24h: number;
}

export interface EventDetailResponse {
  event: EventRow;
  /** The stored ENQUEUE payload JSON (often empty `[]` for order/catalog rows). */
  payload: string;
  /**
   * The exact request body POSTed to the engine (F3-44). Empty when nothing was
   * sent (a terminal skip — see last_response.outcome) or for rows enqueued
   * before this shipped / not yet flushed.
   */
  sent_payload: string;
  /** A small JSON summary of the engine reply: `{ http, outcome, error? }` (F3-44). */
  last_response: string;
}

export interface EventsListFilters {
  page?: number;
  perPage?: number;
  source?: EventSource | '';
  status?: string;
  type?: string;
}

/**
 * Read-only Event Log (PLUGIN.md §13). GET /events returns a paginated UNION
 * over both durable queues; GET /events/detail returns one row's full payload.
 * The snake_case wire shapes mirror the PHP controller exactly (no translation
 * layer). Recovery/retry is 3.10.1 — this module is list + detail only.
 */
export function listEvents(
  filters: EventsListFilters = {},
  signal?: AbortSignal,
): Promise<EventsListResponse> {
  const query = new URLSearchParams();
  if (filters.page) query.set('page', String(filters.page));
  if (filters.perPage) query.set('per_page', String(filters.perPage));
  if (filters.source) query.set('source', filters.source);
  if (filters.status) query.set('status', filters.status);
  if (filters.type) query.set('type', filters.type);
  const qs = query.toString();
  return apiRequest<EventsListResponse>(`/events${qs ? `?${qs}` : ''}`, { signal });
}

export function getEventDetail(
  source: EventSource,
  id: number,
  signal?: AbortSignal,
): Promise<EventDetailResponse> {
  return apiRequest<EventDetailResponse>(
    `/events/detail?source=${encodeURIComponent(source)}&id=${id}`,
    { signal },
  );
}

export interface RetryResponse {
  /** How many failed rows were revived to pending. */
  reset: number;
}

/**
 * Re-drive failed rows (3.10.1 recovery). `{ source, id }` retries one row;
 * `{ source }` retries all failed in that queue; `{}` retries all failed in
 * both. Flips FAILED→PENDING server-side and kicks the flushers so the rows
 * re-send promptly (manual-only — auto-retry would loop on a deterministic 4xx).
 */
export function retryEvents(
  args: { source?: EventSource; id?: number } = {},
  signal?: AbortSignal,
): Promise<RetryResponse> {
  return apiRequest<RetryResponse>('/events/retry', {
    method: 'POST',
    body: args,
    signal,
  });
}
