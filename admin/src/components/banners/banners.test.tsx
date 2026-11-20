import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { wizardInitialState } from '../../state/wizard-reducer';
import { Step1Connect } from '../steps/Step1Connect';
import { bannerBoot, CampaignIntelligenceBanner } from '.';

/** The Estonian msgstr the shipped catalog holds for a source string. */
function estonianFor(msgid: string): string {
  const catalog = readFileSync(resolve(__dirname, '../../../../languages/smaily-connect-et.po'), 'utf8');
  const escaped = msgid.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const match = catalog.match(new RegExp(`^msgid "${escaped}"\\nmsgstr "(.+)"$`, 'm'));
  return match?.[1] ?? '';
}

function setBanners(smailyAccountConnected: boolean): void {
  (window as unknown as { smailyConnectBoot: unknown }).smailyConnectBoot = {
    banners: {
      smailyAccountConnected,
      smailyPlanBlocked: false,
      smailyLoginUrl: '',
      ciBannerDismissed: false,
    },
  };
}

afterEach(() => {
  delete (window as unknown as { smailyConnectBoot?: unknown }).smailyConnectBoot;
  delete (window as unknown as { wp?: unknown }).wp;
});

describe('Campaign Intelligence banner — the start button (CON-350)', () => {
  it('offers Try Intelligence free once the start is open', () => {
    render(<CampaignIntelligenceBanner variant="invite" smailyLoginUrl="" startOpen />);

    expect(screen.getByRole('button', { name: 'Try Intelligence free' })).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Contact Smaily' })).not.toBeInTheDocument();
  });

  it('has Estonian for the start button and the upsell', () => {
    for (const source of [
      'Try Intelligence free',
      'Log in to Smaily',
      'Requires a paid Smaily plan',
      'Campaign Intelligence requires a paid Smaily plan. Log in to Smaily to review your plan options.',
    ]) {
      expect(estonianFor(source), source).not.toBe('');
    }
  });
});

describe('Smaily invitation on the Connect step (CON-351)', () => {
  const TANEL = 'Connect your store to Smaily to sync contacts and set up automated emails.';

  it('invites a store with no Smaily account', () => {
    setBanners(false);
    render(<Step1Connect state={wizardInitialState} dispatch={vi.fn()} />);

    expect(screen.getByRole('region', { name: 'Smaily' })).toHaveTextContent(TANEL);
    expect(screen.getByRole('link', { name: 'Create a Smaily account' })).toHaveAttribute(
      'href',
      'https://www.sendsmaily.net',
    );
    expect(
      screen.getByText('Log in to Smaily: gear icon → Integrations → Create a new user (API username and password).'),
    ).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Dismiss' })).not.toBeInTheDocument();
  });

  it('takes Connect your Smaily account to the subdomain field', () => {
    setBanners(false);
    render(<Step1Connect state={wizardInitialState} dispatch={vi.fn()} />);

    fireEvent.click(screen.getByRole('button', { name: 'Connect your Smaily account' }));

    expect(document.activeElement).toBe(document.getElementById('smaily-subdomain-default'));
  });

  it('is gone once an account is connected, or its test passed on the page', () => {
    setBanners(true);
    const connected = render(<Step1Connect state={wizardInitialState} dispatch={vi.fn()} />);
    expect(screen.queryByRole('region', { name: 'Smaily' })).not.toBeInTheDocument();
    connected.unmount();

    setBanners(false);
    render(
      <Step1Connect
        state={{ ...wizardInitialState, smailyConnection: { kind: 'success' } }}
        dispatch={vi.fn()}
      />,
    );
    expect(screen.queryByRole('region', { name: 'Smaily' })).not.toBeInTheDocument();
  });

  it('speaks Estonian when the admin runs in Estonian', () => {
    const strings = [
      TANEL,
      'Connect your Smaily account',
      'Create a Smaily account',
      'Log in to Smaily: gear icon → Integrations → Create a new user (API username and password).',
      'Smaily in action',
      'Welcome email',
      'Abandoned cart reminder',
      'Payment reminder',
      'Automated',
    ];
    const translations: Record<string, string> = Object.fromEntries(
      strings.map((s) => [s, estonianFor(s)]),
    );
    const et = (source: string): string => translations[source] ?? '';
    for (const [source, translated] of Object.entries(translations)) {
      expect(translated, source).not.toBe('');
    }
    (window as unknown as { wp: unknown }).wp = {
      i18n: { __: (text: string): string => translations[text] ?? text },
    };
    setBanners(false);

    render(<Step1Connect state={wizardInitialState} dispatch={vi.fn()} />);

    const banner = screen.getByRole('region', { name: 'Smaily' });
    expect(banner).toHaveTextContent(et(TANEL));
    expect(banner).toHaveTextContent(et('Welcome email'));
  });

  it('reads as connected without a boot payload, so nothing shows by accident', () => {
    expect(bannerBoot().smailyAccountConnected).toBe(true);
  });
});
