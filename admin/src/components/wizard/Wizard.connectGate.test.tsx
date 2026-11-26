import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import * as backfillApi from '../../api/backfill';
import * as saveSettingsApi from '../../api/saveSettings';
import * as testConnectionApi from '../../api/testConnection';
import { _resetUseWorkflowsCache } from '../../hooks/useWorkflows';
import { type WizardState } from '../../state/types';
import { wizardInitialState } from '../../state/wizard-reducer';
import { Wizard } from './Wizard';

/**
 * The Connect step's Continue gate (CON-441): a passed test of the
 * account the setup saves as the main Smaily account unlocks Continue,
 * in every language mode. In the account-per-language mode (Mode A) the
 * step shows no shared account — the fallback language account is the
 * one it saves.
 */
const MODE_A: WizardState = {
  ...wizardInitialState,
  multilingualMode: 'A',
  defaultFallbackAccountKey: 'account_et_EE',
  env: { ...wizardInitialState.env, detectedLanguages: ['et_EE', 'en_US'] },
};

function continueButton(): HTMLElement {
  return screen.getByRole('button', { name: /continue/i });
}

function fill(idSuffix: string, subdomain: string): void {
  const field = (name: string): HTMLInputElement =>
    document.querySelector<HTMLInputElement>(`#smaily-${name}-${idSuffix}`)!;
  fireEvent.change(field('subdomain'), { target: { value: subdomain } });
  fireEvent.change(field('username'), { target: { value: 'api-user' } });
  fireEvent.change(field('password'), { target: { value: 's3cret' } });
}

function testButton(index: number): HTMLElement {
  return screen.getAllByRole('button', { name: /test connection/i })[index]!;
}

describe('Wizard — Connect step Continue gate', () => {
  let save: ReturnType<typeof vi.spyOn>;

  beforeEach(() => {
    vi.restoreAllMocks();
    _resetUseWorkflowsCache();
    vi.spyOn(testConnectionApi, 'testSmailyConnection').mockResolvedValue({
      connected: true,
      accountName: 'Shop',
    });
    save = vi.spyOn(saveSettingsApi, 'saveSettings').mockResolvedValue({ saved: true, errors: [] });
    vi.spyOn(backfillApi, 'getBackfillStatus').mockResolvedValue({
      status: 'idle',
      processed: 0,
      synced: 0,
      sent: 0,
      failed: 0,
      total: 0,
      percent: 0,
      eta_seconds: null,
      error: null,
      started_at: null,
      completed_at: null,
      audience_estimate: null,
    });
  });

  afterEach(() => {
    vi.restoreAllMocks();
    _resetUseWorkflowsCache();
  });

  it('single account: a passed test of the account unlocks Continue and the save carries it', async () => {
    render(<Wizard initialState={wizardInitialState} />);
    expect(continueButton()).toBeDisabled();

    fill('default', 'shop');
    fireEvent.click(testButton(0));
    await waitFor(() => expect(continueButton()).toBeEnabled());

    fireEvent.click(continueButton());
    await waitFor(() => expect(save).toHaveBeenCalledTimes(1));
    const body = save.mock.calls[0]![0] as saveSettingsApi.SaveSettingsRequest;
    expect(body.tab).toBe('connection');
    expect(body.data.smailyCredentials).toEqual({
      subdomain: 'shop',
      username: 'api-user',
      password: 's3cret',
    });
  });

  it('account per language with a verified saved account: Continue is open as before', () => {
    render(
      <Wizard
        initialState={{
          ...MODE_A,
          smailyCredentials: { subdomain: 'saved', username: 'api-user', password: '' },
          smailyConnection: { kind: 'success', message: 'api-user' },
        }}
      />,
    );

    expect(continueButton()).toBeEnabled();
  });

  it('account per language: a passed test of the fallback account unlocks Continue and is saved as the main account', async () => {
    render(<Wizard initialState={MODE_A} />);
    expect(continueButton()).toBeDisabled();

    fill('et_EE', 'shop-et');
    fireEvent.click(testButton(0));
    await waitFor(() => expect(continueButton()).toBeEnabled());

    fireEvent.click(continueButton());
    await waitFor(() => expect(save).toHaveBeenCalledTimes(1));
    const body = save.mock.calls[0]![0] as saveSettingsApi.SaveSettingsRequest;
    expect(body.data.smailyCredentials).toEqual({
      subdomain: 'shop-et',
      username: 'api-user',
      password: 's3cret',
    });
    expect(body.data.defaultFallbackAccountKey).toBe('account_et_EE');
    await waitFor(() => expect(screen.getByText(/step 2 of 6/i)).toBeInTheDocument());
  });

  it('account per language: a passed test of another language account alone keeps Continue locked', async () => {
    render(<Wizard initialState={MODE_A} />);

    fill('en_US', 'shop-en');
    fireEvent.click(testButton(1));
    await waitFor(() => expect(screen.getByText(/connected: shop/i)).toBeInTheDocument());

    expect(continueButton()).toBeDisabled();
  });
});
