import { apiRequest } from './client';

/**
 * Plugin-side proxy for the engine's console entry (contract §24,
 * REST\ConsoleEntryEndpoint — CON-228). The server sends the signed-in
 * admin's own email; the browser sends nothing.
 */

export interface ConsoleEntryResponse {
  /** One-time link, valid 5 minutes — open it at once, never keep or log it. */
  url: string;
  expires_at: string;
}

/**
 * Error bodies the route answers (`error`): `not_available` (+ `console_url`),
 * `api_key_rejected`, `tenant_inactive`, `rate_limited` (+ `retry_after`),
 * `no_admin_email`, `engine_unavailable`, `invalid_engine_response`.
 */
export interface ConsoleEntryErrorBody {
  error?: string;
  console_url?: string;
  retry_after?: number | null;
  message?: string;
}

/** Ask for a one-time console link. Throws ApiError when the route refuses. */
export function getConsoleEntry(): Promise<ConsoleEntryResponse> {
  return apiRequest<ConsoleEntryResponse>('/rec-engine/console-entry', { method: 'POST', body: {} });
}
