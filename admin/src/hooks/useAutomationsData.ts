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
  /** Re-read only the §12 config (after a save); the loaded catalog is kept. */
  refetchConfig: () => void;
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
  const [request, setRequest] = useState({ attempt: 0, configOnly: false });

  const abortRef = useRef<AbortController | null>(null);
  const catalogRef = useRef<AutomationsCatalogResponse | null>(null);

  useEffect(() => {
    if (!enabled) {
      return undefined;
    }

    const controller = new AbortController();
    abortRef.current?.abort();
    abortRef.current = controller;

    setStatus('pending');
    setFailure(null);

    const knownCatalog = request.configOnly ? catalogRef.current : null;

    void (async () => {
      try {
        const [catalog, config] = await Promise.all([
          knownCatalog ?? getAutomationsCatalog(controller.signal),
          getAutomationsConfig(controller.signal),
        ]);
        if (controller.signal.aborted) {
          return;
        }
        catalogRef.current = catalog;
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
  }, [enabled, request]);

  const refetch = useCallback((): void => {
    setRequest((r) => ({ attempt: r.attempt + 1, configOnly: false }));
  }, []);

  const refetchConfig = useCallback((): void => {
    setRequest((r) => ({ attempt: r.attempt + 1, configOnly: true }));
  }, []);

  return { data, status, failure, refetch, refetchConfig };
}
