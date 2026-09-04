import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import * as api from '../api/testConnection';
import { useTestConnection } from './useTestConnection';

describe('useTestConnection', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('starts in idle status with no data + no error', () => {
    const { result } = renderHook(() => useTestConnection());

    expect(result.current.status).toBe('idle');
    expect(result.current.data).toBeNull();
    expect(result.current.error).toBeNull();
  });

  it('transitions idle → pending → success on a connected response', async () => {
    vi.spyOn(api, 'testSmailyConnection').mockResolvedValue({
      connected: true,
      accountName: 'My Pet Shop',
    });

    const { result } = renderHook(() => useTestConnection());

    await act(async () => {
      await result.current.mutate({ subdomain: 'demo', username: 'alice', password: 's3cret' });
    });

    expect(result.current.status).toBe('success');
    expect(result.current.data?.connected).toBe(true);
    expect(result.current.error).toBeNull();
  });

  it('surfaces a connected:false response as status="error"', async () => {
    vi.spyOn(api, 'testSmailyConnection').mockResolvedValue({
      connected: false,
      error: 'Invalid password.',
    });

    const { result } = renderHook(() => useTestConnection());

    await act(async () => {
      await result.current.mutate({ subdomain: 'demo', username: 'alice', password: 'wrong' });
    });

    expect(result.current.status).toBe('error');
    expect(result.current.error).toBe('Invalid password.');
  });

  it('treats a thrown error as status="error"', async () => {
    vi.spyOn(api, 'testSmailyConnection').mockRejectedValue(new Error('Network down'));

    const { result } = renderHook(() => useTestConnection());

    await act(async () => {
      await result.current.mutate({ subdomain: 'demo', username: 'alice', password: 's3cret' });
    });

    expect(result.current.status).toBe('error');
    expect(result.current.error).toBe('Network down');
  });

  it('reset() returns the hook to idle and clears data + error', async () => {
    vi.spyOn(api, 'testSmailyConnection').mockResolvedValue({ connected: true });

    const { result } = renderHook(() => useTestConnection());

    await act(async () => {
      await result.current.mutate({ subdomain: 'demo', username: 'alice', password: 's3cret' });
    });
    expect(result.current.status).toBe('success');

    act(() => {
      result.current.reset();
    });

    expect(result.current.status).toBe('idle');
    expect(result.current.data).toBeNull();
    expect(result.current.error).toBeNull();
  });

  it('discards the first response when a second mutate() supersedes it mid-flight', async () => {
    let resolveFirst: ((value: { connected: boolean }) => void) | undefined;
    const firstPromise = new Promise<{ connected: boolean }>((resolve) => {
      resolveFirst = resolve;
    });

    vi.spyOn(api, 'testSmailyConnection')
      .mockReturnValueOnce(firstPromise)
      .mockResolvedValueOnce({ connected: false, error: 'second mutate wins' });

    const { result } = renderHook(() => useTestConnection());

    // Fire the first mutate — it parks on firstPromise inside an act() so
    // React state updates are flushed; we don't await the mutate itself.
    let firstMutatePromise: Promise<void> | undefined;
    await act(async () => {
      firstMutatePromise = result.current.mutate({ subdomain: 'a', username: 'b', password: 'c' });
      // Let microtasks (the setStatus('pending') inside mutate) flush.
      await Promise.resolve();
    });

    expect(result.current.status).toBe('pending');

    // Fire the second mutate — aborts the first, completes synchronously.
    await act(async () => {
      await result.current.mutate({ subdomain: 'a', username: 'b', password: 'c' });
    });

    expect(result.current.error).toBe('second mutate wins');

    // Resolve the first promise after the supersede — its result must NOT
    // overwrite the second's state because mutate compares inflightRef.
    await act(async () => {
      resolveFirst?.({ connected: true });
      // Wait for the first mutate's promise chain to finish before asserting.
      await firstMutatePromise;
    });

    expect(result.current.error).toBe('second mutate wins');
  });
});
