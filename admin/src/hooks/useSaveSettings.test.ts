import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import * as api from '../api/saveSettings';
import { ApiError } from '../api/client';
import { useSaveSettings } from './useSaveSettings';

describe('useSaveSettings', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('starts idle and transitions to success on a saved=true response', async () => {
    vi.spyOn(api, 'saveSettings').mockResolvedValue({ saved: true, errors: [] });

    const onSuccess = vi.fn();
    const { result } = renderHook(() => useSaveSettings({ onSuccess }));

    await act(async () => {
      await result.current.mutate({ tab: 'connection', data: {} });
    });

    expect(result.current.status).toBe('success');
    expect(result.current.error).toBeNull();
    expect(onSuccess).toHaveBeenCalledTimes(1);
  });

  it('treats saved=false response as error and joins error[] into a message', async () => {
    vi.spyOn(api, 'saveSettings').mockResolvedValue({
      saved: false,
      errors: [
        { field: 'subdomain', message: 'Required' },
        { field: 'username', message: 'Required' },
      ],
    });

    const onError = vi.fn();
    const { result } = renderHook(() => useSaveSettings({ onError }));

    await act(async () => {
      await result.current.mutate({ tab: 'connection', data: {} });
    });

    expect(result.current.status).toBe('error');
    expect(result.current.error).toContain('subdomain: Required');
    expect(result.current.error).toContain('username: Required');
    expect(onError).toHaveBeenCalledTimes(1);
  });

  it('unwraps a 4xx ApiError body as validation errors', async () => {
    const errBody = {
      saved: false,
      errors: [{ field: 'tab', message: 'Unknown tab.' }],
    };
    vi.spyOn(api, 'saveSettings').mockRejectedValue(
      new ApiError('POST /settings → 400', 400, errBody),
    );

    const { result } = renderHook(() => useSaveSettings());

    await act(async () => {
      await result.current.mutate({ tab: 'connection', data: {} });
    });

    expect(result.current.status).toBe('error');
    expect(result.current.error).toBe('tab: Unknown tab.');
    expect(result.current.data).toEqual(errBody);
  });

  it('surfaces non-ApiError throws via their message', async () => {
    vi.spyOn(api, 'saveSettings').mockRejectedValue(new Error('fetch died'));

    const { result } = renderHook(() => useSaveSettings());

    await act(async () => {
      await result.current.mutate({ tab: 'subscribers', data: {} });
    });

    expect(result.current.status).toBe('error');
    expect(result.current.error).toBe('fetch died');
  });

  it('reset() returns to idle and clears state', async () => {
    vi.spyOn(api, 'saveSettings').mockResolvedValue({ saved: true, errors: [] });

    const { result } = renderHook(() => useSaveSettings());

    await act(async () => {
      await result.current.mutate({ tab: 'recommendations', data: {} });
    });
    expect(result.current.status).toBe('success');

    act(() => {
      result.current.reset();
    });

    expect(result.current.status).toBe('idle');
    expect(result.current.data).toBeNull();
    expect(result.current.error).toBeNull();
  });
});
