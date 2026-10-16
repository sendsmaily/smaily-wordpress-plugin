import {
  type SettingsTabKey,
  type TransactionalSendStatus,
  type TransactionalTrigger,
} from '../state/types';
import { apiRequest } from './client';

export interface SaveSettingsResponse {
  saved: boolean;
  errors: Array<{ field: string; message: string }>;
  /** The confirmation cards' status lines, read after the save (PRO-4091). */
  transactionalStatus?: Partial<Record<TransactionalTrigger, TransactionalSendStatus>>;
}

export interface SaveSettingsRequest {
  tab: SettingsTabKey;
  data: Record<string, unknown>;
}

/**
 * POST /smaily-connect/v1/settings — persist one tab's slice at a time.
 *
 * The endpoint always returns a SaveSettingsResponse — validation errors
 * land in `errors[]` with a 4xx status; the wrapper unwraps the JSON
 * body even on non-2xx so the hook can surface field-level error
 * messages alongside the network-failure path. ApiError still throws on
 * genuine transport failures.
 */
export async function saveSettings(
  body: SaveSettingsRequest,
  signal?: AbortSignal,
): Promise<SaveSettingsResponse> {
  return apiRequest<SaveSettingsResponse>('/settings', {
    method: 'POST',
    body,
    signal,
  });
}
