import { render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { type NetworkSite } from '../../state/hydrate';
import { type WizardState } from '../../state/types';
import { wizardInitialState } from '../../state/wizard-reducer';
import { Step2Subscribers } from './Step2Subscribers';

/**
 * CON-467: on a multisite network the boot payload lists the other sites
 * that keep contacts in one of this site's Smaily accounts, with their
 * contact sync mode. The contacts step — the same component in setup and
 * Settings — warns about those whose mode differs from the one chosen here.
 */
describe('Step2Subscribers — other sites of the network on the same Smaily account (CON-467)', () => {
  const boot = (networkSites?: NetworkSite[]): void => {
    (window as unknown as { smailyConnectBoot?: unknown }).smailyConnectBoot = { networkSites };
  };

  const withMode = (contactSyncMode: WizardState['contactSyncMode']): WizardState => ({
    ...wizardInitialState,
    contactSyncMode,
  });

  afterEach(() => {
    delete (window as unknown as { smailyConnectBoot?: unknown }).smailyConnectBoot;
  });

  it('names a site whose mode differs, with its address and its mode', () => {
    boot([{ name: 'Outlet', url: 'https://outlet.example', mode: 'legitimate_interest' }]);

    render(<Step2Subscribers state={withMode('consent')} dispatch={vi.fn()} />);

    const banner = screen.getByRole('alert');
    expect(banner).toHaveTextContent(/Another site of this network sends contacts to the same Smaily account with a different contact sync mode/);
    expect(banner).toHaveTextContent('Outlet (https://outlet.example): All customers (legitimate interest)');
  });

  it('leaves out a site with the mode chosen here', () => {
    boot([
      { name: 'Outlet', url: 'https://outlet.example', mode: 'checkout_optin' },
      { name: 'Twin', url: 'https://twin.example', mode: 'consent' },
    ]);

    render(<Step2Subscribers state={withMode('consent')} dispatch={vi.fn()} />);

    const banner = screen.getByRole('alert');
    expect(banner).toHaveTextContent('Outlet (https://outlet.example): Checkout opt-in only');
    expect(banner).not.toHaveTextContent('Twin');
  });

  it('shows nothing when every sharing site has the mode chosen here', () => {
    boot([{ name: 'Twin', url: 'https://twin.example', mode: 'consent' }]);

    render(<Step2Subscribers state={withMode('consent')} dispatch={vi.fn()} />);

    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  });

  it('shows nothing on a single site', () => {
    boot([]);
    render(<Step2Subscribers state={withMode('consent')} dispatch={vi.fn()} />);
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  });

  it('shows nothing with a boot payload written before the list existed', () => {
    boot(undefined);
    render(<Step2Subscribers state={withMode('consent')} dispatch={vi.fn()} />);
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  });
});
