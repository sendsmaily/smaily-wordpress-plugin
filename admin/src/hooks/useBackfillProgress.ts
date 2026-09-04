import { useCallback, useEffect, useRef, useState } from 'react';

import { __ } from '@admin/lib/i18n';

import {
  cancelBackfill,
  getBackfillStatus,
  startBackfill,
  type BackfillJobType,
} from '../api/backfill';
import { type BackfillProgress } from '../state/types';

const idleProgress: BackfillProgress = {
  status: 'idle',
  processed: 0,
  synced: 0,
  sent: 0,
  failed: 0,
  total: 0,
  percent: 0,
  etaSeconds: null,
  error: null,
  startedAt: null,
  completedAt: null,
  audienceEstimate: null,
};

export interface UseBackfillProgressOptions {
  /** Poll interval in ms while status === 'running'. Default 5_000. */
  intervalMs?: number;
  /** Job type to track. Phase 1 only has 'contacts'. */
  jobType?: BackfillJobType;
  /**
   * When false, polling is suspended even if the job is still
   * running server-side.
   */
  enabled?: boolean;
}

export interface UseBackfillProgressResult {
  progress: BackfillProgress;
  /** Network failure that broke the polling loop. */
  pollError: string | null;
  start: () => Promise<void>;
  cancel: () => Promise<void>;
}

/**
 * Polls /backfill/status until the job reaches a terminal state.
 *
 * Architecture note: we drive the poll loop through a recursive
 * setTimeout chain inside pollOnce() rather than re-running a
 * useEffect on every response. Reason: when two consecutive polls
 * both return status='running', the React state doesn't change shape
 * — only `processed` increments — and React batches the renders, so
 * a useEffect keyed on `progress.status` would only fire on the
 * initial transition into 'running' and never re-schedule.
 *
 * Cadence per PLUGIN.md §12:
 *   - wizard context (Step 2 first run): 5s — feels live
 *   - settings context (background tracking): 30s — light on the server
 */
export function useBackfillProgress(options: UseBackfillProgressOptions = {}): UseBackfillProgressResult {
  const jobType = options.jobType ?? 'contacts';
  const intervalMs = options.intervalMs ?? 5_000;
  const enabled = options.enabled ?? true;

  const [progress, setProgress] = useState<BackfillProgress>(idleProgress);
  const [pollError, setPollError] = useState<string | null>(null);

  const mountedRef = useRef(true);
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const loopActiveRef = useRef(false);

  useEffect(() => {
    return () => {
      mountedRef.current = false;
      if (timerRef.current !== null) {
        clearTimeout(timerRef.current);
        timerRef.current = null;
      }
      loopActiveRef.current = false;
    };
  }, []);

  // Pull the persisted backfill row on mount so the panel reflects
  // prior runs ("Last run: 2026-05-21 …, 142 / 142 synced") even when
  // the job isn't currently running. Without this the UI started at
  // idleProgress every page load and merchants couldn't tell whether a
  // previous backfill had finished or never ran.
  useEffect(() => {
    let cancelled = false;
    if (!enabled) {
      return undefined;
    }
    (async () => {
      try {
        const response = await getBackfillStatus(jobType);
        if (cancelled || !mountedRef.current) {
          return;
        }
        setProgress({
          status: response.status,
          processed: response.processed,
          synced: response.synced,
          sent: response.sent,
          failed: response.failed,
          total: response.total,
          percent: response.percent,
          etaSeconds: response.eta_seconds,
          error: null,
          startedAt: response.started_at,
          completedAt: response.completed_at,
          audienceEstimate: response.audience_estimate,
        });
      } catch {
        // Soft-fail: stay on idleProgress. The Start-backfill button still
        // works; the user just doesn't see prior-run context until they
        // press it.
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [jobType, enabled]);

  const pollOnce = useCallback(async (): Promise<void> => {
    try {
      const response = await getBackfillStatus(jobType);
      if (!mountedRef.current) {
        return;
      }
      setProgress({
        status: response.status,
        processed: response.processed,
        synced: response.synced,
        sent: response.sent,
        failed: response.failed,
        total: response.total,
        percent: response.percent,
        etaSeconds: response.eta_seconds,
        error: null,
        startedAt: response.started_at,
        completedAt: response.completed_at,
        audienceEstimate: response.audience_estimate,
      });
      setPollError(null);

      // Self-chain — schedule the next poll only while the server
      // still reports 'running' AND the loop hasn't been cancelled.
      if (response.status === 'running' && loopActiveRef.current && mountedRef.current) {
        timerRef.current = setTimeout(() => {
          if (mountedRef.current && loopActiveRef.current) {
            void pollOnce();
          }
        }, intervalMs);
      } else {
        loopActiveRef.current = false;
      }
    } catch (err) {
      if (mountedRef.current) {
        setPollError(err instanceof Error ? err.message : __( 'Failed to fetch backfill status', 'smaily-connect' ));
      }
      loopActiveRef.current = false;
    }
  }, [jobType, intervalMs]);

  // Kick the polling chain off whenever progress.status enters 'running'
  // from a terminal state (idle/completed/failed/cancelled). The chain
  // self-terminates inside pollOnce; this effect only handles the
  // initial trigger + the enabled/disabled toggle.
  useEffect(() => {
    if (!enabled) {
      loopActiveRef.current = false;
      if (timerRef.current !== null) {
        clearTimeout(timerRef.current);
        timerRef.current = null;
      }
      return undefined;
    }

    if (progress.status === 'running' && !loopActiveRef.current) {
      loopActiveRef.current = true;
      timerRef.current = setTimeout(() => {
        if (mountedRef.current && loopActiveRef.current) {
          void pollOnce();
        }
      }, intervalMs);
    } else if (progress.status !== 'running') {
      loopActiveRef.current = false;
    }

    return undefined;
  }, [enabled, progress.status, intervalMs, pollOnce]);

  const start = useCallback(async (): Promise<void> => {
    setPollError(null);
    try {
      const response = await startBackfill(jobType);
      if (!mountedRef.current) {
        return;
      }
      // The server can finish a run before it answers — a contact backfill with
      // an empty audience has nothing to walk (PRO-1715). Read the real terminal
      // state instead of showing a progress spinner that would never advance.
      if (response.status !== 'running') {
        await pollOnce();
        return;
      }
      setProgress({
        status: 'running',
        processed: 0,
        synced: 0,
        sent: 0,
        failed: 0,
        total: response.total,
        percent: 0,
        etaSeconds: null,
        error: null,
        startedAt: new Date().toISOString().slice(0, 19).replace('T', ' '),
        completedAt: null,
        audienceEstimate: null,
      });
    } catch (err) {
      if (mountedRef.current) {
        setPollError(err instanceof Error ? err.message : __( 'Failed to start backfill', 'smaily-connect' ));
      }
    }
  }, [jobType, pollOnce]);

  const cancel = useCallback(async (): Promise<void> => {
    try {
      await cancelBackfill(jobType);
      if (mountedRef.current) {
        setProgress((prev) => ({ ...prev, status: 'cancelled' }));
      }
      loopActiveRef.current = false;
    } catch (err) {
      if (mountedRef.current) {
        setPollError(err instanceof Error ? err.message : __( 'Failed to cancel backfill', 'smaily-connect' ));
      }
    }
  }, [jobType]);

  return { progress, pollError, start, cancel };
}
