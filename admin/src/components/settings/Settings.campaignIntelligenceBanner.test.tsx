import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { dismissNotice } from '../../api/notices';
import { listWorkflows } from '../../api/workflows';
import { _resetUseWorkflowsCache } from '../../hooks/useWorkflows';
import { type BannerBoot } from '../../state/hydrate';
import { buildSettingsInitialState } from '../../state/settings-reducer';
import { type WizardState } from '../../state/types';
import { _resetBannerBoot } from '../banners';
import { Settings } from './Settings';

/**
 * CON-350 — the Campaign Intelligence banner on top of Settings (closable,
 * back in 30 days) and on the Campaign Intelligence tab (always there).
 */
vi.mock('../../api/workflows', () => ({
  listWorkflows: vi.fn(),
}));
vi.mock('../../api/notices', () => ({
  dismissNotice: vi.fn(),
}));

const workflowsMock = vi.mocked(listWorkflows);
const dismissMock = vi.mocked(dismissNotice);

const BODY =
  'Connect your store with Smaily, and add personalized product recommendations to your campaigns, tailored to each customer’s interests.';

/** The Estonian msgstr the shipped catalog holds for a source string. */
function estonianFor(msgid: string): string {
  const catalog = readFileSync(
    resolve(__dirname, '../../../../languages/smaily-connect-et.po'),
    'utf8',
  );
  const escaped = msgid.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const match = catalog.match(new RegExp(`^msgid "${escaped}"\\nmsgstr "(.+)"$`, 'm'));
  return match?.[1] ?? '';
}

function setBoot(banners: Partial<BannerBoot>): void {
  (window as unknown as { smailyConnectBoot: unknown }).smailyConnectBoot = {
    banners: {
      smailyAccountConnected: true,
      smailyPlanBlocked: false,
      smailyLoginUrl: 'https://demo.sendsmaily.net/',
      ciBannerDismissed: false,
      ...banners,
    },
  };
}

function renderSettings(hash: string, overrides: Partial<WizardState> = {}): void {
  window.location.hash = hash;
  render(
    <Settings
      initialState={{ ...buildSettingsInitialState({ smailyConnected: true }), ...overrides }}
    />,
  );
}

const banners = (): HTMLElement[] => screen.queryAllByRole('region', { name: 'Campaign Intelligence' });

describe('Settings — the Campaign Intelligence banner (CON-350)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    _resetUseWorkflowsCache();
    workflowsMock.mockResolvedValue({ workflows: [] });
    dismissMock.mockResolvedValue({ dismissed: true });
    setBoot({});
  });

  afterEach(() => {
    _resetUseWorkflowsCache();
    window.location.hash = '';
    delete (window as unknown as { wp?: unknown }).wp;
    delete (window as unknown as { smailyConnectBoot?: unknown }).smailyConnectBoot;
    _resetBannerBoot();
  });

  it('invites the store on top of Settings, with Contact Smaily', () => {
    renderSettings('connection');

    const banner = screen.getByRole('region', { name: 'Campaign Intelligence' });
    expect(banner).toHaveTextContent(BODY);
    expect(within(banner).getByRole('link', { name: 'Contact Smaily' })).toHaveAttribute(
      'href',
      'https://smaily.com/consultation/',
    );
    expect(within(banner).queryByRole('button', { name: 'Try Intelligence free' })).not.toBeInTheDocument();
  });

  it('closes on Dismiss and records it on the server', () => {
    renderSettings('connection');

    fireEvent.click(screen.getByRole('button', { name: 'Dismiss' }));

    expect(banners()).toHaveLength(0);
    expect(dismissMock).toHaveBeenCalledWith('ci_invitation');
  });

  it('stays closed while the server says it was closed, but the tab keeps its block', () => {
    setBoot({ ciBannerDismissed: true });
    renderSettings('connection');
    expect(banners()).toHaveLength(0);

    fireEvent.click(screen.getByRole('tab', { name: /Campaign Intelligence/ }));

    expect(banners()).toHaveLength(1);
    expect(screen.queryByRole('button', { name: 'Dismiss' })).not.toBeInTheDocument();
  });

  it('shows one banner on the Campaign Intelligence tab, not two', () => {
    renderSettings('recommendations');

    expect(banners()).toHaveLength(1);
    expect(screen.getByLabelText(/Setup URL/)).toBeInTheDocument();
  });

  it('shows the upsell with Log in to Smaily when the plan has no API access', () => {
    setBoot({ smailyPlanBlocked: true });
    renderSettings('connection');

    const banner = screen.getByRole('region', { name: 'Campaign Intelligence' });
    expect(within(banner).getByText('Requires a paid Smaily plan')).toBeInTheDocument();
    expect(
      within(banner).getByText(
        'Campaign Intelligence requires a paid Smaily plan. Log in to Smaily to review your plan options.',
      ),
    ).toBeInTheDocument();
    expect(within(banner).getByRole('link', { name: 'Log in to Smaily' })).toHaveAttribute(
      'href',
      'https://demo.sendsmaily.net/',
    );
  });

  it('shows nothing once Campaign Intelligence is connected', () => {
    renderSettings('connection', { recEngineConnection: { kind: 'success', message: 'Acme' } });

    expect(banners()).toHaveLength(0);
  });

  it('speaks Estonian when the admin runs in Estonian', () => {
    const strings = [
      BODY,
      'Contact Smaily',
      'Dismiss',
      'Engine in action',
      'What the engine recommends you do next',
      '56 customers are due to reorder',
      '12 customers are slipping away',
      'Create segment in Smaily',
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

    renderSettings('connection');

    const banner = screen.getByRole('region', { name: 'Campaign Intelligence' });
    expect(banner).toHaveTextContent(et(BODY));
    expect(within(banner).getByRole('link', { name: et('Contact Smaily') })).toBeInTheDocument();
    expect(within(banner).getByRole('button', { name: et('Dismiss') })).toBeInTheDocument();
    expect(banner).toHaveTextContent(et('56 customers are due to reorder'));
  });
});
