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
  /**
   * Why this row's Retry is refused, '' when it may be retried (PRO-1733).
   * Only failed transactional-email rows are ever refused: fail-open already
   * re-fired the native WooCommerce email (`wc_email_sent`), the order is
   * gone (`order_missing`), or it was a deliberate second confirmation that
   * failed and sent nothing (`resend_failed`, PRO-2368).
   */
  retry_refusal: string;
  /**
   * The sentence Details shows for that refusal, '' when there is none. The
   * server owns the wording (TransactionalRetryGuard::message) so the 409 body
   * and the Event Log can't drift apart.
   */
  retry_refusal_message: string;
  /**
   * Whether this row may be sent to the customer a SECOND time on purpose
   * (PRO-2324) — true only for an order/shipping confirmation Smaily itself
   * sent, on an order that still exists. The server owns the rule
   * (TransactionalRetryGuard::resendable), so the button and the route can't
   * drift apart.
   */
  can_send_again: boolean;
  /**
   * Whether this row was withdrawn rather than sent (PRO-2372) — an
   * abandoned-cart reminder cancelled because the shopper bought before it
   * went out. The row's stored status is `sent` (it is terminal and must
   * never be retried), so the list says "cancelled" instead.
   */
  cancelled: boolean;
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
 * both. Flips FAILED→PENDING server-side; the rows then go out on their
 * flusher's next scheduled pass, within about a minute — not immediately
 * (PRO-2323). Manual-only — auto-retry would loop on a deterministic 4xx.
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

export interface ResendResponse {
  /** Always 1 — the new queue row this action created. */
  queued: number;
  /** That row's id in the Smaily queue. */
  id: number | null;
}

/**
 * Send an already-sent confirmation to the customer a second time, on explicit
 * merchant request (PRO-2324). It does not touch the row named here: a NEW row
 * is queued for the same order, rebuilt from the order as it is now, and goes
 * out at the next scheduled pass — within about a minute.
 */
export function resendEvent(id: number, signal?: AbortSignal): Promise<ResendResponse> {
  return apiRequest<ResendResponse>('/events/resend', {
    method: 'POST',
    // Only Smaily's own transactional rows can be sent again, so the route
    // takes no other source and the caller has no choice to make.
    body: { source: 'smaily', id },
    signal,
  });
}
