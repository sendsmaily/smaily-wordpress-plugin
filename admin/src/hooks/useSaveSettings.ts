import { useCallback, useRef, useState } from 'react';

import { __ } from '@admin/lib/i18n';

import { ApiError } from '../api/client';
import {
  saveSettings,
  type SaveSettingsRequest,
  type SaveSettingsResponse,
} from '../api/saveSettings';

export type SaveSettingsStatus = 'idle' | 'pending' | 'success' | 'error';

export interface UseSaveSettingsResult {
  mutate: (request: SaveSettingsRequest) => Promise<void>;
  status: SaveSettingsStatus;
  data: SaveSettingsResponse | null;
  /** Network failure or validation errors[] flattened to a single message. */
  error: string | null;
  reset: () => void;
}

export interface UseSaveSettingsOptions {
  /** Fires after a successful save. Settings UI dispatches CLEAR_TAB_DIRTY here. */
  onSuccess?: (response: SaveSettingsResponse, request: SaveSettingsRequest) => void;
  /** Fires after a failed save (network or validation). */
  onError?: (error: string, request: SaveSettingsRequest) => void;
}

/**
 * Save mutation hook — TanStack Query-style.
 *
 * The endpoint returns a SaveSettingsResponse on both 2xx success AND
 * 4xx validation failure. The wrapper apiRequest throws ApiError on
 * the latter (status >= 400). This hook unwraps the error body so the
 * UI gets a uniform SaveSettingsResponse via `data`, regardless of
 * which side of the HTTP status the response came from.
 *
 * Mid-flight supersede mirrors useTestConnection's pattern: a second
 * mutate() cancels the first via AbortController + inflightRef
 * identity check.
 */
export function useSaveSettings(options: UseSaveSettingsOptions = {}): UseSaveSettingsResult {
  const [status, setStatus] = useState<SaveSettingsStatus>('idle');
  const [data, setData] = useState<SaveSettingsResponse | null>(null);
  const [error, setError] = useState<string | null>(null);

  const inflightRef = useRef<AbortController | null>(null);

  const onSuccessRef = useRef(options.onSuccess);
  onSuccessRef.current = options.onSuccess;
  const onErrorRef = useRef(options.onError);
  onErrorRef.current = options.onError;

  const mutate = useCallback(async (request: SaveSettingsRequest): Promise<void> => {
    inflightRef.current?.abort();
    const controller = new AbortController();
    inflightRef.current = controller;

    setStatus('pending');
    setError(null);

    try {
      const response = await saveSettings(request, controller.signal);

      if (inflightRef.current !== controller) {
        return;
      }

      setData(response);
      if (response.saved) {
        setStatus('success');
        setError(null);
        onSuccessRef.current?.(response, request);
      } else {
        setStatus('error');
        const message = formatErrors(response.errors);
        setError(message);
        onErrorRef.current?.(message, request);
      }
    } catch (err) {
      if ((err as { name?: string } | null)?.name === 'AbortError') {
        return;
      }

      if (inflightRef.current !== controller) {
        return;
      }

      if (err instanceof ApiError) {
        // Validation errors arrive as 4xx responses; the body still
        // matches SaveSettingsResponse shape.
        const body = err.body as SaveSettingsResponse | null;
        if (body !== null && Array.isArray(body.errors)) {
          setData(body);
          setStatus('error');
          const message = formatErrors(body.errors);
          setError(message);
          onErrorRef.current?.(message, request);
          return;
        }
        setStatus('error');
        setError(err.message);
        onErrorRef.current?.(err.message, request);
        return;
      }

      setStatus('error');
      const message = err instanceof Error ? err.message : __( 'Unknown error', 'smaily-connect' );
      setError(message);
      onErrorRef.current?.(message, request);
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

function formatErrors(errors: SaveSettingsResponse['errors']): string {
  if (errors.length === 0) {
    return __( 'Save failed.', 'smaily-connect' );
  }
  return errors.map((e) => `${e.field}: ${e.message}`).join('; ');
}
