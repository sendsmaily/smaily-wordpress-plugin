import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import * as eventsApi from '../../api/events';
import { EventLog } from './EventLog';

const ROW = {
  id: 1,
  source: 'rec_engine' as const,
  event_type: 'order.upsert',
  entity_id: '42',
  status: 'failed',
  attempts: 5,
  max_attempts: 5,
  last_error: 'http_503 service unavailable',
  created_at: '2026-06-09 10:00:00',
};

describe('EventLog', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('renders rows from the events API', async () => {
    vi.spyOn(eventsApi, 'listEvents').mockResolvedValue({
      events: [ROW],
      total: 1,
      page: 1,
      per_page: 50,
      failed_24h: 0,
    });

    render(<EventLog />);

    expect(await screen.findByText('order.upsert')).toBeInTheDocument();
    expect(screen.getByText('http_503 service unavailable')).toBeInTheDocument();
  });

  it('shows the sticky failed-24h banner when failures exist', async () => {
    vi.spyOn(eventsApi, 'listEvents').mockResolvedValue({
      events: [ROW],
      total: 1,
      page: 1,
      per_page: 50,
      failed_24h: 3,
    });

    render(<EventLog />);

    expect(await screen.findByText(/3 failed events in the last 24 hours/i)).toBeInTheDocument();
  });

  it('renders the empty state when there are no events', async () => {
    vi.spyOn(eventsApi, 'listEvents').mockResolvedValue({
      events: [],
      total: 0,
      page: 1,
      per_page: 50,
      failed_24h: 0,
    });

    render(<EventLog />);

    await waitFor(() => {
      expect(screen.getByText('No events yet.')).toBeInTheDocument();
    });
  });

  it('retries a failed row via the API and reloads', async () => {
    vi.spyOn(eventsApi, 'listEvents').mockResolvedValue({
      events: [ROW],
      total: 1,
      page: 1,
      per_page: 50,
      failed_24h: 1,
    });
    const retrySpy = vi.spyOn(eventsApi, 'retryEvents').mockResolvedValue({ reset: 1 });

    render(<EventLog />);

    // The per-row Retry button only renders for failed rows.
    const retryButton = await screen.findByRole('button', { name: 'Retry' });
    fireEvent.click(retryButton);

    await waitFor(() => {
      expect(retrySpy).toHaveBeenCalledWith({ source: 'rec_engine', id: 1 });
    });
  });

  it('retry-all-failed posts an empty retry', async () => {
    vi.spyOn(eventsApi, 'listEvents').mockResolvedValue({
      events: [ROW],
      total: 1,
      page: 1,
      per_page: 50,
      failed_24h: 2,
    });
    const retrySpy = vi.spyOn(eventsApi, 'retryEvents').mockResolvedValue({ reset: 2 });

    render(<EventLog />);

    const retryAll = await screen.findByRole('button', { name: 'Retry all failed' });
    fireEvent.click(retryAll);

    await waitFor(() => {
      expect(retrySpy).toHaveBeenCalledWith({});
    });

    // The 24h banner (PRO-1539) stays the only surfaced bulk-retry control
    // when it's showing — no duplicate button alongside it.
    expect(screen.getAllByRole('button', { name: 'Retry all failed' })).toHaveLength(1);
  });

  it('shows a reachable Retry all failed control for aged failures with no 24h banner (PRO-1539)', async () => {
    const AGED_ROW = { ...ROW, created_at: '2026-01-01 00:00:00' };
    vi.spyOn(eventsApi, 'listEvents').mockResolvedValue({
      events: [AGED_ROW],
      total: 1,
      page: 1,
      per_page: 50,
      failed_24h: 0,
    });
    const retrySpy = vi.spyOn(eventsApi, 'retryEvents').mockResolvedValue({ reset: 1 });

    render(<EventLog />);

    await screen.findByText('order.upsert');

    // No fresh failures in the last 24h, so the banner is absent...
    expect(screen.queryByText(/failed events in the last 24 hours/i)).not.toBeInTheDocument();

    // ...but the aged failed row still has a reachable bulk-retry control.
    const retryAll = await screen.findByRole('button', { name: 'Retry all failed' });
    fireEvent.click(retryAll);

    await waitFor(() => {
      expect(retrySpy).toHaveBeenCalledWith({});
    });
  });
});
