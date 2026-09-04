import { act, renderHook, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import * as api from '../api/backfill';
import { useBackfillProgress } from './useBackfillProgress';

/**
 * Polling tests run on REAL timers with short intervals — fake timers
 * + React act() + Promise microtasks interleave in ways that make the
 * assertion sequence brittle. 50 ms is short enough to keep the suite
 * fast, real enough that we don't fight the scheduler.
 */
describe('useBackfillProgress', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('starts in idle state with no poll error', () => {
    const { result } = renderHook(() => useBackfillProgress());
    expect(result.current.progress.status).toBe('idle');
    expect(result.current.pollError).toBeNull();
  });

  it('start() flips the status to running and seeds the total', async () => {
    vi.spyOn(api, 'startBackfill').mockResolvedValue({
      job_id: 42,
      status: 'running',
      total: 5_000,
    });
    // Never resolve the status poll — we only care about start().
    vi.spyOn(api, 'getBackfillStatus').mockReturnValue(new Promise(() => {}));

    const { result } = renderHook(() => useBackfillProgress({ intervalMs: 60_000 }));

    await act(async () => {
      await result.current.start();
    });

    expect(result.current.progress.status).toBe('running');
    expect(result.current.progress.total).toBe(5_000);
  });

  /**
   * PRO-1715: a contact import with an empty audience is already finished when
   * /backfill/start answers. Showing 'running' anyway left the panel spinning
   * on a job that would never tick again, until the merchant cancelled by hand.
   */
  it('start() adopts the terminal state when the server already finished the run', async () => {
    vi.spyOn(api, 'startBackfill').mockResolvedValue({
      job_id: 42,
      status: 'completed',
      total: 3,
    });
    vi.spyOn(api, 'getBackfillStatus').mockResolvedValue({
      status: 'completed',
      processed: 3,
      synced: 0,
      sent: 0,
      failed: 0,
      total: 3,
      percent: 100,
      eta_seconds: null,
      started_at: '2026-08-04 09:00:00',
      completed_at: '2026-08-04 09:00:00',
      audience_estimate: 0,
    });

    const { result } = renderHook(() => useBackfillProgress({ intervalMs: 60_000 }));

    await act(async () => {
      await result.current.start();
    });

    expect(result.current.progress.status).toBe('completed');
    expect(result.current.progress.audienceEstimate).toBe(0);
    expect(result.current.progress.completedAt).toBe('2026-08-04 09:00:00');
  });

  it('cancel() flips the status to cancelled without waiting for a poll', async () => {
    vi.spyOn(api, 'cancelBackfill').mockResolvedValue({ cancelled: true });

    const { result } = renderHook(() => useBackfillProgress({ intervalMs: 60_000 }));

    await act(async () => {
      await result.current.cancel();
    });

    expect(result.current.progress.status).toBe('cancelled');
  });

  it('polls /status while running and stops after a completed response', async () => {
    vi.spyOn(api, 'startBackfill').mockResolvedValue({
      job_id: 42,
      status: 'running',
      total: 100,
    });
    const statusSpy = vi
      .spyOn(api, 'getBackfillStatus')
      // First call is the on-mount snapshot fetch — reports nothing in
      // flight so start() drives the running → completed sequence below.
      .mockResolvedValueOnce({
        status: 'idle',
        processed: 0,
        synced: 0,
        sent: 0,
        failed: 0,
        total: 0,
        percent: 0,
        eta_seconds: null,
        started_at: null,
        completed_at: null,
        audience_estimate: null,
      })
      .mockResolvedValueOnce({
        status: 'running',
        processed: 50,
        synced: 50,
        sent: 50,
        failed: 0,
        total: 100,
        percent: 50,
        eta_seconds: 30,
        started_at: '2026-05-21 09:00:00',
        completed_at: null,
        audience_estimate: null,
      })
      .mockResolvedValueOnce({
        status: 'completed',
        processed: 100,
        synced: 100,
        sent: 100,
        failed: 0,
        total: 100,
        percent: 100,
        eta_seconds: null,
        started_at: '2026-05-21 09:00:00',
        completed_at: '2026-05-21 09:01:30',
        audience_estimate: null,
      });

    const { result } = renderHook(() => useBackfillProgress({ intervalMs: 50 }));

    await act(async () => {
      await result.current.start();
    });

    await waitFor(
      () => {
        expect(result.current.progress.processed).toBe(50);
      },
      { timeout: 1000 },
    );

    await waitFor(
      () => {
        expect(result.current.progress.status).toBe('completed');
      },
      { timeout: 1000 },
    );

    expect(statusSpy).toHaveBeenCalled();
  });

  it('records a poll-loop error without crashing the hook', async () => {
    vi.spyOn(api, 'startBackfill').mockResolvedValue({
      job_id: 42,
      status: 'running',
      total: 100,
    });
    vi.spyOn(api, 'getBackfillStatus').mockRejectedValue(new Error('Network 502'));

    const { result } = renderHook(() => useBackfillProgress({ intervalMs: 50 }));

    await act(async () => {
      await result.current.start();
    });

    await waitFor(
      () => {
        expect(result.current.pollError).toBe('Network 502');
      },
      { timeout: 1000 },
    );
  });
});
