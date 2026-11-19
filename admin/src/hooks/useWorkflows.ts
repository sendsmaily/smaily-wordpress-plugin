import { useCallback, useEffect, useRef, useState } from 'react';

import { __ } from '@admin/lib/i18n';

import { listWorkflows, type Workflow } from '../api/workflows';

export type UseWorkflowsStatus = 'idle' | 'pending' | 'success' | 'error';

export interface UseWorkflowsResult {
  workflows: Workflow[];
  status: UseWorkflowsStatus;
  error: string | null;
  /** Re-fetch the list (bypasses the per-key cache). */
  refresh: () => Promise<void>;
}

/**
 * Fetches the Smaily autoresponder list for a given account key with a
 * module-level memo. Distinct account_keys (Mode A) cache independently
 * — switching from the Estonian credential set's list to the English
 * one doesn't refetch if both have been seen this session.
 *
 * Cache lives at module scope (not in the hook state) so multiple
 * Step 3 rows referring to the same account_key share one in-flight
 * request, not one per row.
 *
 * No automatic invalidation — the user clicking a "Refresh workflows"
 * button drives refresh(). Settings save flow has its own React-Query-
 * style cache invalidation pattern that lands in sub-PR 2.G if needed;
 * Phase 2 ships with manual refresh only.
 */

/**
 * What one fetch ended with. Every instance that waits on it shows this.
 * `workflows: null` leaves the list it shows as it is.
 */
interface FetchOutcome {
  workflows: Workflow[] | null;
  error: string | null;
}

const cache: Map<string, Workflow[]> = new Map();
const inflight: Map<string, Promise<FetchOutcome>> = new Map();

export function useWorkflows(accountKey: string = 'default'): UseWorkflowsResult {
  const [workflows, setWorkflows] = useState<Workflow[]>(() => cache.get(accountKey) ?? []);
  const [status, setStatus] = useState<UseWorkflowsStatus>(
    cache.has(accountKey) ? 'success' : 'idle',
  );
  const [error, setError] = useState<string | null>(null);

  const mountedRef = useRef(true);
  useEffect(() => {
    // Set again on every mount: <StrictMode> unmounts and remounts once.
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
    };
  }, []);

  const apply = useCallback((outcome: FetchOutcome): void => {
    if (!mountedRef.current) {
      return;
    }
    if (outcome.workflows !== null) {
      setWorkflows(outcome.workflows);
    }
    setStatus(outcome.error === null ? 'success' : 'error');
    setError(outcome.error);
  }, []);

  const fetchList = useCallback(
    async (force: boolean): Promise<void> => {
      if (!force && cache.has(accountKey)) {
        apply({ workflows: cache.get(accountKey) ?? [], error: null });
        return;
      }

      // Coalesce concurrent fetches for the same key: a waiting instance
      // shows what the shared fetch ended with, failure included.
      const pending = force ? undefined : inflight.get(accountKey);
      if (pending !== undefined) {
        apply(await pending);
        return;
      }

      if (mountedRef.current) {
        setStatus('pending');
        setError(null);
      }

      const job = (async (): Promise<FetchOutcome> => {
        try {
          const response = await listWorkflows(accountKey);
          if (response.error !== undefined && response.workflows.length === 0) {
            // Server returned an error string with empty list — surface it.
            return { workflows: [], error: response.error };
          }
          cache.set(accountKey, response.workflows);
          return { workflows: response.workflows, error: null };
        } catch (err) {
          return {
            workflows: null,
            error: err instanceof Error ? err.message : __( 'Failed to load workflows', 'smaily-connect' ),
          };
        }
      })();

      inflight.set(accountKey, job);
      try {
        apply(await job);
      } finally {
        inflight.delete(accountKey);
      }
    },
    [accountKey, apply],
  );

  // Initial fetch on mount / account_key change.
  useEffect(() => {
    void fetchList(false);
  }, [fetchList]);

  const refresh = useCallback(async (): Promise<void> => {
    cache.delete(accountKey);
    await fetchList(true);
  }, [accountKey, fetchList]);

  return { workflows, status, error, refresh };
}

/** Test-only helper — clears the module-level cache between cases. */
export function _resetUseWorkflowsCache(): void {
  cache.clear();
  inflight.clear();
}
