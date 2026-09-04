import { __ } from '@admin/lib/i18n';

import { apiRequest, ApiError } from './client';

/**
 * Plugin-side proxies for the rec-engine connect/health/disconnect
 * cycle. The api_key never lands in the browser — these endpoints
 * read the encrypted secret server-side and forward to the engine.
 *
 * Sub-PR 3.1 wires this surface. Sub-PRs 3.2+ extend the engine
 * client class behind these endpoints for ingest / GDPR endpoints.
 */

export interface SetupExchangeRequest {
  /** Full pasted setup URL, e.g. "https://intelligence.smaily.com/setup/<token>". */
  setupUrl: string;
}

export interface SetupExchangeSuccess {
  connected: true;
  tenantName: string;
  tenantId: string;
  engineVersion: string;
  baseUrl: string;
  issuedAt: string;
}

export interface SetupExchangeFailure {
  connected: false;
  error:
    | 'invalid_setup_url'
    | 'token_expired_or_used'
    | 'token_not_found'
    | 'engine_unreachable';
  message: string;
  /** Populated on token_expired_or_used. */
  regenerateUrl?: string;
}

export type SetupExchangeResponse = SetupExchangeSuccess | SetupExchangeFailure;

/**
 * Run the setup exchange. Returns the typed response shape regardless
 * of HTTP status — the endpoint deliberately uses 400/502 for the
 * known failure modes, and bubbling those up as ApiError would make
 * the caller juggle two error paths. Network-level failures (offline,
 * 5xx without JSON) surface as engine_unreachable.
 */
export async function setupExchange(
  body: SetupExchangeRequest,
  signal?: AbortSignal,
): Promise<SetupExchangeResponse> {
  try {
    return await apiRequest<SetupExchangeResponse>('/rec-engine/setup-exchange', {
      method: 'POST',
      body: { setup_url: body.setupUrl },
      signal,
    });
  } catch (err) {
    if (err instanceof ApiError && err.body !== null && typeof err.body === 'object') {
      // The endpoint always emits a typed JSON body. Trust it.
      return err.body as SetupExchangeResponse;
    }
    return {
      connected: false,
      error: 'engine_unreachable',
      message: err instanceof Error ? err.message : __( 'Network error', 'smaily-connect' ),
    };
  }
}

export interface PingSuccess {
  ok: true;
  engineVersion: string;
  tenantStatus: string;
  serverTime: string;
}

export interface PingFailure {
  ok: false;
  error: string;
  message: string;
  requestId?: string | null;
}

export type PingResponse = PingSuccess | PingFailure;

export async function pingEngine(signal?: AbortSignal): Promise<PingResponse> {
  try {
    return await apiRequest<PingResponse>('/rec-engine/ping', {
      method: 'POST',
      body: {},
      signal,
    });
  } catch (err) {
    if (err instanceof ApiError && err.body !== null && typeof err.body === 'object') {
      return err.body as PingResponse;
    }
    return {
      ok: false,
      error: 'network_error',
      message: err instanceof Error ? err.message : __( 'Network error', 'smaily-connect' ),
    };
  }
}

export interface DisconnectResponse {
  disconnected: boolean;
}

export function disconnectEngine(
  signal?: AbortSignal,
): Promise<DisconnectResponse> {
  return apiRequest<DisconnectResponse>('/rec-engine/disconnect', {
    method: 'POST',
    body: {},
    signal,
  });
}
