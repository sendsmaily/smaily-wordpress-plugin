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
});
