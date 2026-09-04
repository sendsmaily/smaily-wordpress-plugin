import { render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import * as backfillApi from '../../api/backfill';
import { wizardInitialState } from '../../state/wizard-reducer';
import { type WizardState } from '../../state/types';
import { Step2Subscribers } from './Step2Subscribers';

/**
 * F3-55 (Prike "30k contacts go to Smaily, we have 16k opt-ins"): the
 * backfill WALKS every WP user but syncs only the contact-sync mode's
 * audience. The UI used to label the walk count "contacts synced", which
 * on a consent-mode store read as a consent violation. These tests pin:
 *   - the pre-start audience-estimate hint (shown only when the mode
 *     actually narrows the audience);
 *   - running copy: users CHECKED vs contacts SYNCED as separate numbers;
 *   - completed copy: `synced` is the "contacts synced" number, never
 *     `processed`.
 */
describe('Step2Subscribers — audience-aware backfill copy (F3-55)', () => {
  const state: WizardState = {
    ...wizardInitialState,
    env: {
      ...wizardInitialState.env,
      storeTotals: { ...wizardInitialState.env.storeTotals, customers: 30000 },
    },
  };

  const status = (overrides: Partial<backfillApi.BackfillStatusResponse>): backfillApi.BackfillStatusResponse => ({
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
    ...overrides,
  });

  beforeEach(() => {
    vi.restoreAllMocks();
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('shows the audience estimate before a run when the mode narrows the audience', async () => {
    vi.spyOn(backfillApi, 'getBackfillStatus').mockResolvedValue(
      status({ audience_estimate: 16000 }),
    );

    render(<Step2Subscribers state={state} dispatch={vi.fn()} />);

    expect(
      await screen.findByText(/about 16000 of them will be synced to Smaily as contacts/i),
    ).toBeInTheDocument();
  });

  it('hides the estimate when it equals the user count (mode does not narrow)', async () => {
    vi.spyOn(backfillApi, 'getBackfillStatus').mockResolvedValue(
      status({ audience_estimate: 30000 }),
    );

    render(<Step2Subscribers state={state} dispatch={vi.fn()} />);

    expect(await screen.findByText(/30000 contacts will be synced to Smaily/i)).toBeInTheDocument();
    expect(screen.queryByText(/will be synced to Smaily as contacts/i)).not.toBeInTheDocument();
  });

  it('separates users checked from contacts synced while running', async () => {
    vi.spyOn(backfillApi, 'getBackfillStatus').mockResolvedValue(
      status({
        status: 'running',
        processed: 12400,
        synced: 6100,
        total: 30000,
        percent: 41,
        started_at: '2026-07-08 10:00:00',
      }),
    );

    render(<Step2Subscribers state={state} dispatch={vi.fn()} />);

    expect(
      await screen.findByText(/Checked 12400 of 30000 contacts — 6100 contacts synced\./i),
    ).toBeInTheDocument();
  });

  it('labels only the synced count as contacts when completed', async () => {
    vi.spyOn(backfillApi, 'getBackfillStatus').mockResolvedValue(
      status({
        status: 'completed',
        processed: 30000,
        synced: 16012,
        total: 30000,
        percent: 100,
        started_at: '2026-07-08 10:00:00',
        completed_at: '2026-07-08 11:00:00',
      }),
    );

    render(<Step2Subscribers state={state} dispatch={vi.fn()} />);

    expect(
      await screen.findByText(/Done — 16012 contacts synced \(30000 contacts checked\)\./i),
    ).toBeInTheDocument();
    // The walk count must never be presented as the contacts number.
    expect(screen.queryByText(/30000 contacts synced/i)).not.toBeInTheDocument();
  });

  /**
   * PRO-1715: a store with nobody in the audience finishes the run instantly.
   * "Done — 0 contacts synced" reads like a failure; say what actually happened.
   */
  it('names the nothing-to-sync outcome instead of reporting zero synced', async () => {
    vi.spyOn(backfillApi, 'getBackfillStatus').mockResolvedValue(
      status({
        status: 'completed',
        processed: 30000,
        synced: 0,
        total: 30000,
        percent: 100,
        started_at: '2026-08-04 10:00:00',
        completed_at: '2026-08-04 10:00:00',
        audience_estimate: 0,
      }),
    );

    render(<Step2Subscribers state={state} dispatch={vi.fn()} />);

    expect(
      await screen.findByText(/Nothing to import — no contacts match your synchronization settings\./i),
    ).toBeInTheDocument();
    expect(screen.queryByText(/Done — 0 contacts synced/i)).not.toBeInTheDocument();
  });
});
