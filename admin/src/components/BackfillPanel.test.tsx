import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import * as api from '../api/backfill';
import { BackfillPanel } from './BackfillPanel';

const IDLE = {
  status: 'idle' as const,
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
};

describe('BackfillPanel', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
    // The hook fetches status on mount — keep it idle so polling is quiet.
    vi.spyOn(api, 'getBackfillStatus').mockResolvedValue(IDLE);
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('renders the label and the record count', () => {
    render(<BackfillPanel jobType="products" label="Products" recordCount={42} />);
    expect(screen.getByText('Products')).toBeInTheDocument();
    expect(screen.getByText(/42 products to import/i)).toBeInTheDocument();
  });

  it('disables the button when there are no records', () => {
    render(<BackfillPanel jobType="orders" label="Orders" recordCount={0} />);
    expect(screen.getByRole('button', { name: /import now/i })).toBeDisabled();
    expect(screen.getByText(/no orders to import/i)).toBeInTheDocument();
  });

  it('renders the multilingual count note under the record count when provided', () => {
    render(
      <BackfillPanel
        jobType="products"
        label="Products"
        recordCount={7794}
        countNote="Counts one entry per language (6 detected). Translations are merged into a single product during import, so the synced total will be lower."
      />,
    );
    expect(screen.getByText(/7794 products to import/i)).toBeInTheDocument();
    expect(screen.getByText(/one entry per language \(6 detected\)/i)).toBeInTheDocument();
  });

  it('shows the synced count as an absolute number, not a fraction of raw posts', async () => {
    // Multilingual catalog: 7794 raw posts (each language a separate post)
    // collapse to ~1354 canonical products. `sent` (canonical) must NOT be
    // shown over `total` (raw posts) — that read as a misleading "1354 of 7794".
    vi.spyOn(api, 'getBackfillStatus').mockResolvedValue({
      status: 'completed',
      processed: 7794,
      synced: 7794,
      sent: 1354,
      failed: 0,
      total: 7794,
      percent: 100,
      eta_seconds: null,
      started_at: '2026-06-14 10:00:00',
      completed_at: '2026-06-14 10:05:00',
      audience_estimate: null,
    });

    render(<BackfillPanel jobType="products" label="Products" recordCount={1354} />);

    expect(await screen.findByText(/1354 products synced/i)).toBeInTheDocument();
    expect(screen.queryByText(/of 7794/i)).not.toBeInTheDocument();
  });

  it('starts the backfill for its own job type on click', async () => {
    const startSpy = vi
      .spyOn(api, 'startBackfill')
      .mockResolvedValue({ job_id: 7, status: 'running', total: 42 });
    // Hold the poll so the status stays at the seeded "running".
    vi.spyOn(api, 'getBackfillStatus').mockReturnValue(new Promise(() => {}));

    render(<BackfillPanel jobType="products" label="Products" recordCount={42} intervalMs={60_000} />);

    fireEvent.click(screen.getByRole('button', { name: /import now/i }));

    await waitFor(() => expect(startSpy).toHaveBeenCalledWith('products'));
    await screen.findByRole('button', { name: /importing/i });
    // Cancel affordance appears while running.
    expect(screen.getByRole('button', { name: /cancel/i })).toBeInTheDocument();
  });
});
