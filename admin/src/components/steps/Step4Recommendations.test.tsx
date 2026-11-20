import { render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { wizardInitialState } from '../../state/wizard-reducer';
import { _resetBannerBoot } from '../banners';
import { Step4Recommendations } from './Step4Recommendations';

const BODY =
  'Connect your store with Smaily, and add personalized product recommendations to your campaigns, tailored to each customer’s interests.';

const smailyConnected = {
  ...wizardInitialState,
  smailyConnection: { kind: 'success' },
} as typeof wizardInitialState;

describe('Step4Recommendations — the Campaign Intelligence banner replaces the introduction (CON-350)', () => {
  afterEach(() => {
    delete (window as unknown as { smailyConnectBoot?: unknown }).smailyConnectBoot;
    _resetBannerBoot();
  });

  it('shows the invitation with Contact Smaily above the setup link field in the wizard', () => {
    render(<Step4Recommendations state={smailyConnected} dispatch={vi.fn()} />);

    const banner = screen.getByRole('region', { name: 'Campaign Intelligence' });
    expect(banner).toHaveTextContent(BODY);
    expect(screen.getByRole('link', { name: 'Contact Smaily' })).toHaveAttribute(
      'href',
      'https://smaily.com/consultation/',
    );
    expect(screen.getByLabelText(/Setup URL/)).toBeInTheDocument();
    expect(
      banner.compareDocumentPosition(screen.getByLabelText(/Setup URL/)) & Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy();
  });

  it('no longer states the price', () => {
    render(<Step4Recommendations state={smailyConnected} dispatch={vi.fn()} />);

    expect(screen.queryByText(/€250/)).not.toBeInTheDocument();
  });

  it('keeps the banner on the Settings tab, where it cannot be closed', () => {
    render(<Step4Recommendations state={smailyConnected} dispatch={vi.fn()} inSettings />);

    expect(screen.queryByText('Step 4 of 6')).not.toBeInTheDocument();
    expect(screen.getByRole('region', { name: 'Campaign Intelligence' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Dismiss' })).not.toBeInTheDocument();
  });

  it('shows the upsell when the Smaily plan has no API access', () => {
    (window as unknown as { smailyConnectBoot: unknown }).smailyConnectBoot = {
      banners: {
        smailyAccountConnected: true,
        smailyPlanBlocked: true,
        smailyLoginUrl: 'https://demo.sendsmaily.net/',
        ciBannerDismissed: false,
      },
    };

    render(<Step4Recommendations state={smailyConnected} dispatch={vi.fn()} />);

    expect(screen.getByText('Requires a paid Smaily plan')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Log in to Smaily' })).toHaveAttribute(
      'href',
      'https://demo.sendsmaily.net/',
    );
    expect(screen.queryByRole('link', { name: 'Contact Smaily' })).not.toBeInTheDocument();
  });

  it('shows no banner once Campaign Intelligence is connected', () => {
    render(
      <Step4Recommendations
        state={{ ...smailyConnected, recEngineConnection: { kind: 'success', message: 'Acme' } }}
        dispatch={vi.fn()}
      />,
    );

    expect(screen.queryByRole('region', { name: 'Campaign Intelligence' })).not.toBeInTheDocument();
  });
});

describe('Step4Recommendations — a deactivated account is stated, not disguised (PRO-1893)', () => {
  const connected = {
    ...wizardInitialState,
    recEngineConnection: { kind: 'success', message: 'Acme Pets' },
  } as typeof wizardInitialState;

  it('shows the tenant as connected while the account is live', () => {
    render(<Step4Recommendations state={connected} dispatch={vi.fn()} />);

    expect(screen.getByText('Acme Pets')).toBeInTheDocument();
    expect(screen.queryByText('Account deactivated')).not.toBeInTheDocument();
  });

  it('replaces the connected tick with the deactivated banner', () => {
    render(
      <Step4Recommendations
        state={{ ...connected, recEngineRefused: true }}
        dispatch={vi.fn()}
      />,
    );

    expect(screen.getByText('Account deactivated')).toBeInTheDocument();
    expect(
      screen.getByText(/Contact Smaily to reactivate it\./),
    ).toBeInTheDocument();
    // No green "Connected as <tenant>" while nothing is being sent.
    expect(screen.queryByText('Connected')).not.toBeInTheDocument();
  });
});

describe('Step4Recommendations — the browse-tracking toggle says where consent comes from (PRO-3673)', () => {
  const connected = {
    ...wizardInitialState,
    recEngineConnection: { kind: 'success', message: 'Acme Pets' },
  } as typeof wizardInitialState;

  it('says nothing is sent and links the WP Consent API plugin when the store has no consent API', () => {
    render(<Step4Recommendations state={connected} dispatch={vi.fn()} />);

    expect(
      screen.getByText('Browse tracking sends nothing until a consent banner is connected to the WP Consent API.', {
        exact: false,
      }),
    ).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Get the WP Consent API plugin' })).toHaveAttribute(
      'href',
      'https://wordpress.org/plugins/wp-consent-api/',
    );
    expect(screen.queryByText(/Consent comes from your consent banner/)).not.toBeInTheDocument();
  });

  it("says consent comes from the store's consent plugin when the consent API is active", () => {
    render(
      <Step4Recommendations
        state={{ ...connected, env: { ...connected.env, consentApiPresent: true } }}
        dispatch={vi.fn()}
      />,
    );

    expect(
      screen.getByText(
        "Consent comes from your consent banner through the WP Consent API: browse events are sent only for visitors who said yes to marketing in that banner.",
      ),
    ).toBeInTheDocument();
    expect(screen.queryByText(/sends nothing until a consent banner is connected/)).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Get the WP Consent API plugin' })).not.toBeInTheDocument();
  });
});
