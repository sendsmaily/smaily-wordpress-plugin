import { useCallback, useRef, useState } from 'react';

import { __ } from '@admin/lib/i18n';

import {
  testSmailyConnection,
  type TestConnectionResponse,
} from '../api/testConnection';
import { type SmailyCredentials } from '../state/types';

/** Hook status union — same shape TanStack Query's useMutation exposes. */
export type TestConnectionStatus = 'idle' | 'pending' | 'success' | 'error';

export interface UseTestConnectionResult {
  /**
   * Fire the request. Subsequent calls cancel any in-flight one via
   * AbortController so a user mashing the button doesn't see stale
   * results land out of order.
   */
  mutate: (credentials: SmailyCredentials) => Promise<void>;
  status: TestConnectionStatus;
  data: TestConnectionResponse | null;
  /** Localised failure message (null on idle / pending / success). */
  error: string | null;
  /** Returns hook to `idle` with no data / error — useful on credential edit. */
  reset: () => void;
}

/**
 * Test connection mutation hook — TanStack Query-style.
 *
 * Erkki ratified the API shape in sub-PR 2.E.1 spec:
 *   const { mutate, status, error, reset } = useTestConnection();
 *
 * Why hand-rolled instead of pulling TanStack Query in: the bundle
 * budget is tight, the hook has exactly one mutation pattern, and
 * staying off TanStack Query keeps the dep surface minimal for
 * Mailstone 2's @smaily/recengine-client extraction (the client is
 * platform-agnostic; React-Query is not).
 *
 * Status mapping:
 *   - idle    — before the first mutate() call or after reset()
 *   - pending — request in flight
 *   - success — server returned 2xx; data is populated
 *   - error   — network failure OR server returned `connected: false`
 *               (we surface the latter as an error too, since the UX is
 *               the same: "couldn't validate, show why")
 */
export function useTestConnection(): UseTestConnectionResult {
  const [status, setStatus] = useState<TestConnectionStatus>('idle');
  const [data, setData] = useState<TestConnectionResponse | null>(null);
  const [error, setError] = useState<string | null>(null);

  const inflightRef = useRef<AbortController | null>(null);

  const mutate = useCallback(async (credentials: SmailyCredentials): Promise<void> => {
    inflightRef.current?.abort();
    const controller = new AbortController();
    inflightRef.current = controller;

    setStatus('pending');
    setError(null);

    try {
      const response = await testSmailyConnection(credentials, controller.signal);

      // Bail if a newer mutate() superseded us mid-flight.
      if (inflightRef.current !== controller) {
        return;
      }

      setData(response);
      if (response.connected) {
        setStatus('success');
        setError(null);
      } else {
        setStatus('error');
        setError(response.error ?? __( 'Smaily rejected the credentials.', 'smaily-connect' ));
      }
    } catch (err) {
      // AbortError fires when the next mutate() cancels us — silent.
      if ((err as { name?: string } | null)?.name === 'AbortError') {
        return;
      }

      if (inflightRef.current !== controller) {
        return;
      }

      setStatus('error');
      setError(err instanceof Error ? err.message : __( 'Unknown error', 'smaily-connect' ));
    }
  }, []);

  const reset = useCallback((): void => {
    inflightRef.current?.abort();
    inflightRef.current = null;
    setStatus('idle');
    setData(null);
    setError(null);
  }, []);

  return { mutate, status, data, error, reset };
}
