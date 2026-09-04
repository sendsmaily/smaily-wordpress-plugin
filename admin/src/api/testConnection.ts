import { type SmailyCredentials } from '../state/types';
import { apiRequest } from './client';

export interface TestConnectionResponse {
  connected: boolean;
  /** Friendly account name when the API surfaces one (Smaily 'My Pet Shop'). */
  accountName?: string;
  /** Localised failure reason when connected is false. */
  error?: string | null;
}

/**
 * POST /smaily-connect/v1/test-smaily — validates a credential triple
 * without persisting it. Used by the Step 1 Test Connection button and
 * the Settings → Connection tab.
 *
 * The endpoint always returns HTTP 200; a failed validation is
 * `{ connected: false, error: "…" }`, not a 4xx. We propagate the
 * error string into the hook's `error` field for display.
 */
export function testSmailyConnection(
  credentials: SmailyCredentials,
  signal?: AbortSignal,
): Promise<TestConnectionResponse> {
  return apiRequest<TestConnectionResponse>('/test-smaily', {
    method: 'POST',
    body: credentials,
    signal,
  });
}
