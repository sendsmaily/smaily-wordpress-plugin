import { apiRequest } from './client';

export type BackfillJobType = 'contacts' | 'products' | 'customers' | 'orders';

export interface BackfillStartResponse {
  job_id: number;
  status: string;
  total: number;
}

export interface BackfillStatusResponse {
  status: 'idle' | 'running' | 'completed' | 'failed' | 'cancelled';
  processed: number;
  /**
   * Contacts job (F3-55): cumulative audience members handled (POSTed +
   * already-fresh). `processed` counts rows WALKED — on a consent-mode
   * store the two differ by the opted-out majority. Engine jobs leave it 0.
   */
  synced: number;
  /** Engine-confirmed rows (sent + deduplicated) for this run (3.10.0). */
  sent: number;
  /** Terminal failed rows for this run — per-row detail in the Event Log. */
  failed: number;
  total: number;
  percent: number;
  eta_seconds: number | null;
  /** MySQL DATETIME in UTC, or null when the job has never been started. */
  started_at: string | null;
  /** Set once the job reaches a terminal status; null while running/idle. */
  completed_at: string | null;
  /**
   * Contacts job only, and only while NOT running: the sync mode's audience
   * size (consent → opted-in count). Null = not applicable / running.
   */
  audience_estimate: number | null;
}

export interface BackfillCancelResponse {
  cancelled: boolean;
}

/**
 * Wrappers for the three /backfill/* sub-routes (sub-PR 2.0). The
 * snake_case payload + response shapes mirror the PHP REST controller
 * exactly so we don't have to maintain a translation layer on the wire.
 *
 * Phase 3 adds 'orders' / 'customers' / 'products' to BackfillJobType
 * — the wrappers don't change, only the union does.
 */

export function startBackfill(
  jobType: BackfillJobType,
  signal?: AbortSignal,
): Promise<BackfillStartResponse> {
  return apiRequest<BackfillStartResponse>('/backfill/start', {
    method: 'POST',
    body: { job_type: jobType },
    signal,
  });
}

export function getBackfillStatus(
  jobType: BackfillJobType,
  signal?: AbortSignal,
): Promise<BackfillStatusResponse> {
  return apiRequest<BackfillStatusResponse>(
    `/backfill/status?job_type=${encodeURIComponent(jobType)}`,
    { signal },
  );
}

export function cancelBackfill(
  jobType: BackfillJobType,
  signal?: AbortSignal,
): Promise<BackfillCancelResponse> {
  return apiRequest<BackfillCancelResponse>('/backfill/cancel', {
    method: 'POST',
    body: { job_type: jobType },
    signal,
  });
}
