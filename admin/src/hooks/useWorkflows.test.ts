import { StrictMode } from 'react';
import { act, renderHook, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import * as api from '../api/workflows';
import { _resetUseWorkflowsCache, useWorkflows } from './useWorkflows';

describe('useWorkflows', () => {
  beforeEach(() => {
    _resetUseWorkflowsCache();
    vi.restoreAllMocks();
  });

  afterEach(() => {
    _resetUseWorkflowsCache();
    vi.restoreAllMocks();
  });

  it('fetches the list on mount and ends in status="success"', async () => {
    vi.spyOn(api, 'listWorkflows').mockResolvedValue({
      workflows: [
        { id: '42', name: 'Welcome', status: 'ACTIVE' },
      ],
    });

    const { result } = renderHook(() => useWorkflows('default'));

    // The hook fires the fetch synchronously on mount, so by the time
    // we observe `status` it may already be 'pending' rather than 'idle'.
    // Asserting the terminal state is what matters.
    await waitFor(() => {
      expect(result.current.status).toBe('success');
    });
    expect(result.current.workflows).toHaveLength(1);
  });

  it('reuses the cache when the same account_key is mounted twice', async () => {
    const spy = vi.spyOn(api, 'listWorkflows').mockResolvedValue({
      workflows: [{ id: '7', name: 'Cached', status: 'ACTIVE' }],
    });

    const first = renderHook(() => useWorkflows('et'));
    await waitFor(() => {
      expect(first.result.current.status).toBe('success');
    });

    expect(spy).toHaveBeenCalledTimes(1);

    // Second mount with the same key — must hit the cache, no extra fetch.
    const second = renderHook(() => useWorkflows('et'));
    expect(second.result.current.workflows).toHaveLength(1);
    expect(spy).toHaveBeenCalledTimes(1);
  });

  it('refetches when refresh() is called even if the cache is populated', async () => {
    const spy = vi
      .spyOn(api, 'listWorkflows')
      .mockResolvedValueOnce({ workflows: [{ id: '1', name: 'A', status: 'ACTIVE' }] })
      .mockResolvedValueOnce({
        workflows: [
          { id: '1', name: 'A', status: 'ACTIVE' },
          { id: '2', name: 'B (added)', status: 'ACTIVE' },
        ],
      });

    const { result } = renderHook(() => useWorkflows('default'));
    await waitFor(() => {
      expect(result.current.status).toBe('success');
    });

    await act(async () => {
      await result.current.refresh();
    });

    expect(spy).toHaveBeenCalledTimes(2);
    expect(result.current.workflows).toHaveLength(2);
  });

  it('surfaces a server-side error string from the response', async () => {
    vi.spyOn(api, 'listWorkflows').mockResolvedValue({
      workflows: [],
      error: 'Credentials missing',
    });

    const { result } = renderHook(() => useWorkflows('default'));

    await waitFor(() => {
      expect(result.current.status).toBe('error');
    });
    expect(result.current.error).toBe('Credentials missing');
  });

  it('records a thrown network error as status="error"', async () => {
    vi.spyOn(api, 'listWorkflows').mockRejectedValue(new Error('500 Gateway'));

    const { result } = renderHook(() => useWorkflows('default'));

    await waitFor(() => {
      expect(result.current.status).toBe('error');
    });
    expect(result.current.error).toBe('500 Gateway');
  });

  /**
   * CON-314: WordPress serves its development React build where SCRIPT_DEBUG
   * is on, and the admin renders in <StrictMode>, which mounts, unmounts and
   * mounts every component once. The list must still arrive.
   */
  it('ends in status="success" under StrictMode', async () => {
    vi.spyOn(api, 'listWorkflows').mockResolvedValue({
      workflows: [{ id: '42', name: 'Welcome', status: 'ACTIVE' }],
    });

    const { result } = renderHook(() => useWorkflows('default'), { wrapper: StrictMode });

    await waitFor(() => {
      expect(result.current.status).toBe('success');
    });
    expect(result.current.workflows).toHaveLength(1);
  });

  it('surfaces a server-side error string under StrictMode', async () => {
    vi.spyOn(api, 'listWorkflows').mockResolvedValue({
      workflows: [],
      error: 'Credentials missing',
    });

    const { result } = renderHook(() => useWorkflows('default'), { wrapper: StrictMode });

    await waitFor(() => {
      expect(result.current.status).toBe('error');
    });
    // Nothing that finishes later may put the hook back to idle.
    await new Promise((resolve) => setTimeout(resolve, 20));
    expect(result.current.status).toBe('error');
    expect(result.current.error).toBe('Credentials missing');
  });

  /**
   * A second hook instance mounted while the first one's fetch is in flight
   * shares that fetch; when it fails, both must show the failure.
   */
  it.each([
    ['a thrown network error', () => Promise.reject(new Error('500 Gateway')), '500 Gateway'],
    [
      'a server-side error string',
      () => Promise.resolve({ workflows: [], error: 'Credentials missing' }),
      'Credentials missing',
    ],
  ])('shows %s on every instance that shares the fetch', async (_label, outcome, message) => {
    let settle: () => void = () => undefined;
    const spy = vi.spyOn(api, 'listWorkflows').mockImplementation(
      () =>
        new Promise((resolve, reject) => {
          settle = () => {
            outcome().then(resolve, reject);
          };
        }),
    );

    const first = renderHook(() => useWorkflows('default'));
    const second = renderHook(() => useWorkflows('default'));
    expect(spy).toHaveBeenCalledTimes(1);

    await act(async () => {
      settle();
      await new Promise((resolve) => setTimeout(resolve, 0));
    });

    for (const instance of [first, second]) {
      await waitFor(() => {
        expect(instance.result.current.status).toBe('error');
      });
      expect(instance.result.current.error).toBe(message);
      expect(instance.result.current.workflows).toEqual([]);
    }
  });

  it('gives every instance that shares the fetch the same list', async () => {
    let settle: () => void = () => undefined;
    vi.spyOn(api, 'listWorkflows').mockImplementation(
      () =>
        new Promise((resolve) => {
          settle = () => resolve({ workflows: [{ id: '42', name: 'Welcome', status: 'ACTIVE' }] });
        }),
    );

    const first = renderHook(() => useWorkflows('default'));
    const second = renderHook(() => useWorkflows('default'));

    await act(async () => {
      settle();
      await new Promise((resolve) => setTimeout(resolve, 0));
    });

    for (const instance of [first, second]) {
      await waitFor(() => {
        expect(instance.result.current.status).toBe('success');
      });
      expect(instance.result.current.workflows).toHaveLength(1);
    }
  });
});
