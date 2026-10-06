import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useReducer } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import * as backfillApi from '../../api/backfill';
import * as recEngineApi from '../../api/recEngine';
import { wizardInitialState, wizardReducer } from '../../state/wizard-reducer';
import { Step4Recommendations } from './Step4Recommendations';

const STATUS = {
  status: 'running' as const,
  processed: 0,
  synced: 0,
  sent: 0,
  failed: 0,
  total: 3,
  percent: 0,
  eta_seconds: null,
  started_at: '2026-10-05 10:00:00',
  completed_at: null,
  audience_estimate: null,
};

const CONNECTED = {
  connected: true as const,
  tenantName: 'Acme Pets',
  tenantId: 'tnt_1',
  engineVersion: '1.9.1',
  baseUrl: 'https://engine.test',
  issuedAt: '2026-10-05T10:00:00Z',
  catalogImportDelaySeconds: 180,
};

/** Step 4 driven by the real reducer, so a successful Connect flips the view. */
function Harness({ inSettings = false }: { inSettings?: boolean }): React.JSX.Element {
  const [state, dispatch] = useReducer(wizardReducer, wizardInitialState);
  return <Step4Recommendations state={state} dispatch={dispatch} inSettings={inSettings} />;
}

async function connect(): Promise<void> {
  fireEvent.change(screen.getByLabelText(/Setup URL/), {
    target: { value: 'https://engine.test/setup/tok' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Connect' }));
  await screen.findByText('Acme Pets');
}

describe('Step4Recommendations — connecting starts the catalog import (PRO-3743)', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
    vi.spyOn(backfillApi, 'getBackfillStatus').mockResolvedValue(STATUS);
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it.each([false, true])(
    'tells the merchant the catalog import started, with Hold back (inSettings=%s)',
    async (inSettings) => {
      vi.spyOn(recEngineApi, 'setupExchange').mockResolvedValue({
        ...CONNECTED,
        catalogImport: 'started',
      });

      render(<Harness inSettings={inSettings} />);
      await connect();

      expect(screen.getByText('Catalog import started')).toBeInTheDocument();
      expect(screen.getByText(/starting in about 3 minutes/)).toBeInTheDocument();
      expect(screen.getByRole('button', { name: 'Hold back' })).toBeInTheDocument();
    },
  );

  it('shows no notice when a catalog import was already running', async () => {
    vi.spyOn(recEngineApi, 'setupExchange').mockResolvedValue({
      ...CONNECTED,
      catalogImport: 'unchanged',
    });

    render(<Harness />);
    await connect();

    expect(screen.queryByText('Catalog import started')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Hold back' })).not.toBeInTheDocument();
  });

  it('Hold back cancels the products import and says so', async () => {
    vi.spyOn(recEngineApi, 'setupExchange').mockResolvedValue({
      ...CONNECTED,
      catalogImport: 'started',
    });
    const cancel = vi.spyOn(backfillApi, 'cancelBackfill').mockResolvedValue({ cancelled: true });

    render(<Harness />);
    await connect();
    fireEvent.click(screen.getByRole('button', { name: 'Hold back' }));

    await screen.findByText('Catalog import held back');
    expect(cancel).toHaveBeenCalledWith('products');
    expect(screen.queryByText('Catalog import started')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Hold back' })).not.toBeInTheDocument();
  });

  it('keeps Hold back available when the cancel fails', async () => {
    vi.spyOn(recEngineApi, 'setupExchange').mockResolvedValue({
      ...CONNECTED,
      catalogImport: 'started',
    });
    vi.spyOn(backfillApi, 'cancelBackfill').mockRejectedValue(new Error('Server error'));

    render(<Harness />);
    await connect();
    fireEvent.click(screen.getByRole('button', { name: 'Hold back' }));

    await waitFor(() =>
      expect(screen.getByText(/Couldn't hold back the import: Server error/)).toBeInTheDocument(),
    );
    expect(screen.getByRole('button', { name: 'Hold back' })).toBeInTheDocument();
  });
});
