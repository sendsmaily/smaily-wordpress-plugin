import { useCallback, useEffect, useRef, useState } from 'react';

import {
  classifyAutomationsFailure,
  getAutomationsCatalog,
  getAutomationsConfig,
  type AutomationConfigServerRow,
  type AutomationsCatalogResponse,
  type AutomationsFailure,
} from '../api/automations';

export interface AutomationsData {
  catalog: AutomationsCatalogResponse;
  configs: AutomationConfigServerRow[];
}

export type AutomationsDataStatus = 'idle' | 'pending' | 'success' | 'error';

export interface UseAutomationsDataResult {
  data: AutomationsData | null;
  status: AutomationsDataStatus;
  failure: AutomationsFailure | null;
  /** Re-run both GETs (the Retry button). */
  refetch: () => void;
}

/**
 * Fetch the §11 catalog + §12 config in parallel on every mount —
 * deliberately NO cache: the engine's GET is the source of truth
 * (F3-51; an engine-side operator edit or a new catalog trigger must
 * show up on the next section open). The section unmounts on tab
 * switch, so re-opening re-fetches.
 *
 * `enabled=false` (rec-engine not connected) keeps the hook idle — the
 * section renders the upsell banner instead.
 */
export function useAutomationsData(enabled: boolean): UseAutomationsDataResult {
  const [data, setData] = useState<AutomationsData | null>(null);
  const [status, setStatus] = useState<AutomationsDataStatus>(enabled ? 'pending' : 'idle');
  const [failure, setFailure] = useState<AutomationsFailure | null>(null);
  const [attempt, setAttempt] = useState(0);

  const abortRef = useRef<AbortController | null>(null);

  useEffect(() => {
    if (!enabled) {
      return undefined;
    }

    const controller = new AbortController();
    abortRef.current?.abort();
    abortRef.current = controller;

    setStatus('pending');
    setFailure(null);

    void (async () => {
      try {
        const [catalog, config] = await Promise.all([
          getAutomationsCatalog(controller.signal),
          getAutomationsConfig(controller.signal),
        ]);
        if (controller.signal.aborted) {
          return;
        }
        setData({ catalog, configs: config.configs });
        setStatus('success');
      } catch (err) {
        if (controller.signal.aborted) {
          return;
        }
        setFailure(classifyAutomationsFailure(err));
        setStatus('error');
      }
    })();

    return () => controller.abort();
  }, [enabled, attempt]);

  const refetch = useCallback((): void => {
    setAttempt((n) => n + 1);
  }, []);

  return { data, status, failure, refetch };
}
