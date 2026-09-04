import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useReducer } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import * as backfillApi from '../../api/backfill';
import * as testConnectionApi from '../../api/testConnection';
import { wizardInitialState, wizardReducer } from '../../state/wizard-reducer';
import { Step1Connect } from './Step1Connect';
import { Step2Subscribers } from './Step2Subscribers';

/**
 * One end-to-end integration test wiring the reducer + Step 1 + Step 2
 * together with API mocks. Confirms:
 *   1. Step 1 credential entry + Test connection dispatch lands in the
 *      reducer's smailyConnection slot.
 *   2. Step 1 hand-off to Step 2 carries the synced WizardState.
 *   3. Step 2 backfill Start triggers the API and the reducer.
 *
 * Coverage threshold doesn't extend to step components per Erkki's
 * sub-PR 2.C spec — this test exists for confidence, not as a gate.
 */
describe('Step 1 → Step 2 integration', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('handles Smaily test → handoff → backfill start through the shared reducer', async () => {
    vi.spyOn(testConnectionApi, 'testSmailyConnection').mockResolvedValue({
      connected: true,
      accountName: 'My Pet Shop',
    });
    vi.spyOn(backfillApi, 'startBackfill').mockResolvedValue({
      job_id: 42,
      status: 'running',
      total: 1_234,
    });
    // The hook now fetches initial status on mount (so prior runs surface
    // without waiting for a Start click). Return 'idle' first so the
    // Start-backfill button stays clickable; subsequent polls land in
    // 'running' once start() flips the local state.
    vi.spyOn(backfillApi, 'getBackfillStatus')
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
      .mockResolvedValue({
        status: 'running',
        processed: 0,
        synced: 0,
        sent: 0,
        failed: 0,
        total: 1_234,
        percent: 0,
        eta_seconds: null,
        started_at: null,
        completed_at: null,
        audience_estimate: null,
      });

    const seeded = {
      ...wizardInitialState,
      env: {
        ...wizardInitialState.env,
        storeTotals: { customers: 1_234, orders: 0, products: 0 },
      },
    };

    function Host(): React.JSX.Element {
      const [state, dispatch] = useReducer(wizardReducer, seeded);

      return (
        <div>
          <Step1Connect state={state} dispatch={dispatch} />
          <hr />
          <Step2Subscribers state={state} dispatch={dispatch} />
          {/* Surface the reducer state for assertions. */}
          <output data-testid="state-connection">{state.smailyConnection.kind}</output>
          <output data-testid="state-backfill-status">{state.contactsBackfill.status}</output>
        </div>
      );
    }

    render(<Host />);

    // 1. Step 1 — type credentials.
    fireEvent.change(screen.getByLabelText(/subdomain/i), { target: { value: 'demo' } });
    fireEvent.change(screen.getByLabelText(/api username/i), { target: { value: 'alice' } });
    fireEvent.change(screen.getByLabelText(/api password/i), { target: { value: 's3cret' } });

    // 2. Click Test connection.
    fireEvent.click(screen.getByRole('button', { name: /test connection/i }));

    await waitFor(() => {
      expect(screen.getByTestId('state-connection')).toHaveTextContent('success');
    });
    // "Connected: My Pet Shop" when API returned accountName, or
    // "Connected to Smaily." when it didn't — both indicate success.
    expect(screen.getByText(/connected/i)).toBeInTheDocument();

    // 3. Step 2 backfill start.
    fireEvent.click(screen.getByRole('button', { name: /start import/i }));

    await waitFor(() => {
      expect(screen.getByTestId('state-backfill-status')).toHaveTextContent('running');
    });

    // 4. The reducer carried the customer total from env into Step 2's display.
    expect(screen.getByText(/1,?234 contacts will be synced to Smaily/i)).toBeInTheDocument();
  });
});
