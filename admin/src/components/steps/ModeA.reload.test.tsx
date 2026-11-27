import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import * as backfillApi from '../../api/backfill';
import * as saveSettingsApi from '../../api/saveSettings';
import { _resetUseWorkflowsCache } from '../../hooks/useWorkflows';
import { type BootPayload, hydrateState } from '../../state/hydrate';
import { _resetBannerBoot, campaignIntelligenceBannerVariant } from '../banners';
import { Settings } from '../settings/Settings';
import { Wizard } from '../wizard/Wizard';
import { Step1Connect } from './Step1Connect';
import { Step6Done } from './Step6Done';

/**
 * CON-446: the account-per-language mode (Mode A) after a reload. Each
 * language's saved account comes back from the boot payload; once the
 * fallback language account's connection works, the setup and Settings
 * treat it as the main Smaily account — Continue, "Smaily connected", the
 * invitations and the Connection save. The shared account below was never
 * verified, so only the fallback account can make any of that true.
 */
function boot(): BootPayload {
  return {
    buildHash: 'test',
    nonce: 'nonce',
    restUrl: 'http://example.test/wp-json/smaily-connect/v1',
    view: 'wizard',
    envSnapshot: {
      detectedLanguages: ['et', 'en_GB'],
      multilingualPlugin: 'polylang',
      elementorPresent: false,
      cf7Present: false,
      wcActive: true,
      hposActive: true,
      storeTotals: { customers: 0, orders: 0, products: 0 },
    },
    savedSettings: {
      smailyCredentials: { subdomain: 'eestisub', username: 'mari', password: '' },
      smailyConnected: false,
      setupCompleted: true,
      multilingualMode: 'A',
      defaultFallbackAccountKey: 'account_et',
      perLanguageAccounts: [
        {
          accountKey: 'account_et',
          language: 'et',
          credentials: { subdomain: 'eestisub', username: 'mari', password: '' },
          connected: true,
        },
        {
          accountKey: 'account_en_GB',
          language: 'en_GB',
          credentials: { subdomain: 'englishsub', username: 'john', password: '' },
          connected: true,
        },
      ],
      subscriberSyncEnabled: true,
      syncFields: ['first_name'],
      wordpressSubscriptionCheckbox: false,
      checkoutSubscriptionCheckbox: false,
      contactSyncMode: 'consent',
      includeGuests: false,
      abandonedCartCutoffMinutes: 30,
      welcomeEnabled: false,
      firstOrderEnabled: false,
      abandonedCartEnabled: false,
    },
  };
}

function setNoSmailyAccountOnServer(): void {
  (window as unknown as { smailyConnectBoot: unknown }).smailyConnectBoot = {
    banners: {
      smailyAccountConnected: false,
      smailyPlanBlocked: false,
      smailyLoginUrl: '',
      ciBannerDismissed: false,
    },
  };
  _resetBannerBoot();
}

describe('Mode A after a reload (CON-446)', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
    _resetUseWorkflowsCache();
    window.location.hash = '';
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
    delete (window as unknown as { smailyConnectBoot?: unknown }).smailyConnectBoot;
    _resetBannerBoot();
    _resetUseWorkflowsCache();
    vi.restoreAllMocks();
    window.location.hash = '';
  });

  it('loads each language account back, without its password', () => {
    const state = hydrateState(boot(), false);

    expect(state.perLanguageAccounts).toEqual([
      {
        accountKey: 'account_et',
        language: 'et',
        credentials: { subdomain: 'eestisub', username: 'mari', password: '' },
        connection: { kind: 'success', message: 'mari' },
      },
      {
        accountKey: 'account_en_GB',
        language: 'en_GB',
        credentials: { subdomain: 'englishsub', username: 'john', password: '' },
        connection: { kind: 'success', message: 'john' },
      },
    ]);
  });

  it('reads an account with no usable password as not connected', () => {
    const payload = boot();
    payload.savedSettings.perLanguageAccounts![1]!.connected = false;

    expect(hydrateState(payload, false).perLanguageAccounts[1]!.connection).toEqual({ kind: 'idle' });
  });

  it('shows each language account as connected on the Connect step, and Continue is open', () => {
    render(<Wizard initialState={hydrateState(boot(), false)} />);

    expect(screen.getByText('eestisub.sendsmaily.net')).toBeInTheDocument();
    expect(screen.getByText('englishsub.sendsmaily.net')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /continue/i })).toBeEnabled();
  });

  it('shows no invitation once the fallback account works', () => {
    setNoSmailyAccountOnServer();
    render(<Step1Connect state={hydrateState(boot(), false)} dispatch={vi.fn()} />);

    expect(screen.queryByRole('region', { name: 'Smaily' })).not.toBeInTheDocument();
  });

  it('shows Smaily as connected on the summary step', () => {
    render(<Step6Done state={hydrateState(boot(), false)} />);

    const row = screen.getByText('Smaily connected').closest('li');
    expect(row?.textContent).toContain('✓');
  });

  it('invites to Campaign Intelligence once the fallback account works', () => {
    expect(
      campaignIntelligenceBannerVariant(hydrateState(boot(), true), {
        smailyAccountConnected: true,
        smailyPlanBlocked: false,
        smailyLoginUrl: '',
        ciBannerDismissed: false,
      }),
    ).toBe('invite');
  });

  it('Settings keeps every tab open and saves the fallback account as the main account', async () => {
    const save = vi.spyOn(saveSettingsApi, 'saveSettings').mockResolvedValue({ saved: true, errors: [] });
    render(<Settings initialState={hydrateState(boot(), true)} />);

    for (const tab of screen.getAllByRole('tab')) {
      expect(tab).toBeEnabled();
    }

    // The merchant makes English the fallback account.
    fireEvent.click(within(screen.getByText('Default fallback account').closest('section') ?? document.body).getByLabelText('en-GB'));
    fireEvent.click(screen.getByRole('button', { name: /save changes/i }));

    await waitFor(() => expect(save).toHaveBeenCalledTimes(1));
    const request = save.mock.calls[0]![0];
    expect(request.tab).toBe('connection');
    expect(request.data['smailyCredentials']).toEqual({ subdomain: 'englishsub', username: 'john', password: '' });
    expect(request.data['defaultFallbackAccountKey']).toBe('account_en_GB');
  });
});
