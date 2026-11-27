import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import * as automationsApi from '../../api/automations';
import { ApiError } from '../../api/client';
import * as dashboardApi from '../../api/dashboard';
import { type DashboardState } from '../../api/dashboard';
import * as eventsApi from '../../api/events';
import { type EventRow } from '../../api/events';
import { type BannerBoot } from '../../state/hydrate';
import { buildSettingsInitialState } from '../../state/settings-reducer';
import { type WizardState } from '../../state/types';
import { _resetBannerBoot } from '../banners';
import { Dashboard } from './Dashboard';

/** CON-464: the Dashboard's banners, verdict, cards, tiles, automations, activity and links. */

const DOCS_URL = 'https://smaily.com/connect-woo/';

const HEALTHY: DashboardState = {
  failed_24h: 0,
  contact_syncs_30d: 120,
  catalog_items_30d: 45,
  queued_today: 7,
  smaily_refusal: '',
  engine_down: false,
  unsent_product_list_nights: 0,
  background: { running: true, last_run: '2026-11-27 10:00:00', wp_cron_disabled: false },
};

const EVENT: EventRow = {
  id: 1,
  source: 'smaily',
  event_type: 'contact.sync',
  entity_id: '42',
  status: 'sent',
  attempts: 1,
  max_attempts: null,
  last_error: '',
  created_at: '2026-11-27 09:59:00',
  retry_refusal: '',
  retry_refusal_message: '',
  can_send_again: false,
  cancelled: false,
  skipped: false,
  skip_reason: '',
  scheduled_for: '',
};

function setBoot(banners: Partial<BannerBoot> = {}): void {
  (window as unknown as { smailyConnectBoot: unknown }).smailyConnectBoot = {
    version: '3.27.0',
    banners: {
      smailyAccountConnected: true,
      smailyPlanBlocked: false,
      smailyLoginUrl: 'https://demo.sendsmaily.net/',
      ciBannerDismissed: false,
      ...banners,
    },
  };
}

/** A finished setup: Smaily connected, Campaign Intelligence connected with browse tracking on. */
function connectedState(overrides: Partial<WizardState> = {}): WizardState {
  return {
    ...buildSettingsInitialState({
      smailyConnected: true,
      smailyCredentials: { subdomain: 'demo', username: 'api' },
      docsUrl: DOCS_URL,
    }),
    recEngineConnection: { kind: 'success', message: 'Demo shop' },
    recEngineFeatures: { trackBrowsing: true },
    ...overrides,
  };
}

function serve(dashboard: Partial<DashboardState> = {}, events: EventRow[] = [EVENT]): void {
  vi.spyOn(dashboardApi, 'getDashboardState').mockResolvedValue({ ...HEALTHY, ...dashboard });
  vi.spyOn(eventsApi, 'listEvents').mockResolvedValue({
    events,
    total: events.length,
    page: 1,
    per_page: 10,
    failed_24h: dashboard.failed_24h ?? 0,
  });
}

function serveEngineEmails(): void {
  vi.spyOn(automationsApi, 'getAutomationsCatalog').mockResolvedValue({
    triggers: [
      { key: 'replenish_due', name_et: 'Täiendamise aeg', name_en: 'Replenishment due', description_et: '', description_en: '', recipe_et: '' },
      { key: 'winback', name_et: 'Tagasivõit', name_en: 'Win-back', description_et: '', description_en: '', recipe_et: '' },
      { key: 'browse_abandon', name_et: 'Sirvimine', name_en: 'Browse abandonment', description_et: '', description_en: '', recipe_et: '' },
    ],
    language_modes: ['single', 'per_language'],
    docs: 'https://example.test/docs',
  });
  vi.spyOn(automationsApi, 'getAutomationsConfig').mockResolvedValue({
    configs: [],
    setup: [
      { trigger_key: 'replenish_due', setup_state: 'live', stop_reason: null, go_live_blockers: [], live_since: null, daily_limit: null },
      { trigger_key: 'winback', setup_state: 'test', stop_reason: null, go_live_blockers: [], live_since: null, daily_limit: null },
      { trigger_key: 'browse_abandon', setup_state: 'off', stop_reason: null, go_live_blockers: [], live_since: null, daily_limit: null },
    ],
  });
}

function card(label: string): HTMLElement {
  const title = screen.getAllByText(label).find((node) => node.tagName === 'P' && node.className.includes('font-semibold'));
  if (title === undefined) {
    throw new Error(`No connection card "${label}"`);
  }
  return title.parentElement as HTMLElement;
}

describe('Dashboard', () => {
  beforeEach(() => {
    setBoot();
    _resetBannerBoot();
  });

  afterEach(() => {
    vi.restoreAllMocks();
    delete (window as unknown as { smailyConnectBoot?: unknown }).smailyConnectBoot;
    _resetBannerBoot();
  });

  it('reads all systems normal on a healthy store, with every card, tile and link', async () => {
    serve();
    serveEngineEmails();

    render(<Dashboard state={connectedState()} />);

    expect(await screen.findByText('All systems normal')).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Smaily Connect — Dashboard' })).toBeInTheDocument();
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();

    expect(within(card('Smaily')).getByText('Connected (demo)')).toBeInTheDocument();
    expect(within(card('Smaily')).getByText('Connected')).toBeInTheDocument();
    expect(within(card('Campaign Intelligence')).getByText('Connected (Demo shop)')).toBeInTheDocument();
    expect(within(card('Campaign Intelligence')).getByText('On')).toBeInTheDocument();
    expect(within(card('Browse tracking')).getByText('Script live on storefront')).toBeInTheDocument();
    expect(within(card('Background tasks')).getByText('Running')).toBeInTheDocument();
    expect(within(card('Background tasks')).getByText(/^Last run /)).toBeInTheDocument();

    expect(screen.getByText('120')).toBeInTheDocument();
    expect(screen.getByText('45')).toBeInTheDocument();
    expect(screen.getByText('7')).toBeInTheDocument();
    expect(screen.getByText('last 24 hours')).toBeInTheDocument();

    expect(screen.getByText('contact.sync')).toBeInTheDocument();
    for (const link of screen.getAllByRole('link', { name: 'View full log' })) {
      expect(link).toHaveAttribute('href', 'admin.php?page=smaily-connect-log');
    }

    expect(screen.getByRole('link', { name: 'Settings' })).toHaveAttribute('href', 'admin.php?page=smaily-connect-settings');
    expect(screen.getByRole('link', { name: 'Log' })).toHaveAttribute('href', 'admin.php?page=smaily-connect-log');
    expect(screen.getByRole('link', { name: 'Initial setup' })).toHaveAttribute('href', 'admin.php?page=smaily-connect-wizard');
    const guide = screen.getByRole('link', { name: 'User Guide' });
    expect(guide).toHaveAttribute('href', DOCS_URL);
    expect(guide).toHaveAttribute('target', '_blank');
    expect(guide).toHaveAttribute('rel', expect.stringContaining('noopener'));

    expect(screen.getByText('Smaily Connect 3.27.0')).toBeInTheDocument();
  });

  it('warns of failed deliveries and leads to the failed rows of the Log', async () => {
    serve({ failed_24h: 3 });

    render(<Dashboard state={connectedState({ recEngineConnection: { kind: 'idle' } })} />);

    expect(await screen.findByText('3 events failed in the last 24 hours')).toBeInTheDocument();
    expect(screen.getByText('Smaily Connect is running, but 3 deliveries failed in the last 24 hours.')).toBeInTheDocument();
    for (const link of screen.getAllByRole('link', { name: 'Review failures' })) {
      expect(link).toHaveAttribute('href', 'admin.php?page=smaily-connect-log&status=failed');
    }
    expect(screen.getByRole('link', { name: 'View failed deliveries' })).toHaveAttribute(
      'href',
      'admin.php?page=smaily-connect-log&status=failed',
    );
    expect(screen.getByText('Attention')).toBeInTheDocument();
    // No Campaign Intelligence connection: no catalog tile.
    expect(screen.queryByText('Catalog items')).not.toBeInTheDocument();
  });

  it('warns of a missing nightly product list', async () => {
    serve({ unsent_product_list_nights: 4 });
    serveEngineEmails();

    render(<Dashboard state={connectedState()} />);

    expect(await screen.findByText('No nightly product list has gone to Campaign Intelligence for 4 nights')).toBeInTheDocument();
  });

  it('puts the Smaily connection first and holds the warnings back while Smaily refuses the account', async () => {
    serve({ smaily_refusal: 'credentials_rejected', failed_24h: 5, unsent_product_list_nights: 4 });
    serveEngineEmails();

    render(<Dashboard state={connectedState()} />);

    expect(await screen.findByText('Not connected', { selector: 'p' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Open Connection settings' })).toHaveAttribute(
      'href',
      'admin.php?page=smaily-connect-settings#connection',
    );
    expect(within(card('Smaily')).getByText('Credentials missing or invalid')).toBeInTheDocument();
    expect(screen.queryByText('5 events failed in the last 24 hours')).not.toBeInTheDocument();
    expect(screen.queryByText(/No nightly product list/)).not.toBeInTheDocument();
  });

  it('says the background tasks do not run, and why when WP-Cron is switched off', async () => {
    serve({ background: { running: false, last_run: '', wp_cron_disabled: true } });
    serveEngineEmails();

    render(<Dashboard state={connectedState()} />);

    expect(
      await screen.findByText('Background tasks are not running — nothing goes to Smaily until they run again.'),
    ).toBeInTheDocument();
    const background = card('Background tasks');
    expect(within(background).getByText('Not running')).toBeInTheDocument();
    expect(within(background).getByText('Never run')).toBeInTheDocument();
    expect(within(background).getByText(/DISABLE_WP_CRON/)).toBeInTheDocument();
  });

  it('shows an unreachable and a deactivated Campaign Intelligence account on its card', async () => {
    serve({ engine_down: true });
    serveEngineEmails();

    const { unmount } = render(<Dashboard state={connectedState()} />);
    expect(await within(card('Campaign Intelligence')).findByText('Unreachable')).toBeInTheDocument();
    expect(screen.getByText(/Campaign Intelligence is currently unreachable/)).toBeInTheDocument();
    unmount();

    render(<Dashboard state={connectedState({ recEngineRefused: true })} />);
    expect(await within(card('Campaign Intelligence')).findByText('Not active')).toBeInTheDocument();
    expect(screen.getByText(/^Campaign Intelligence has stopped accepting data/)).toBeInTheDocument();
  });

  it('lists the Smaily automations and the Campaign Intelligence emails as On, Off or Test mode', async () => {
    serve();
    serveEngineEmails();

    render(
      <Dashboard
        state={connectedState({
          welcomeEnabled: true,
          abandonedCartEnabled: true,
          transactionalEmailsEnabled: true,
          orderConfirmationEnabled: true,
          // Off while transactional emails are off as a whole.
          followUpEnabled: false,
        })}
      />,
    );

    const automations = screen.getByRole('heading', { name: 'Automations' }).closest('.overflow-hidden') as HTMLElement;
    const row = (name: string): HTMLElement => within(automations).getByText(name).closest('li') as HTMLElement;
    expect(within(row('Welcome email')).getByText('On')).toBeInTheDocument();
    expect(within(row('First-order email')).getByText('Off')).toBeInTheDocument();
    expect(within(row('Abandoned-cart reminder')).getByText('On')).toBeInTheDocument();
    expect(within(row('Order confirmation')).getByText('On')).toBeInTheDocument();
    expect(within(row('Shipping confirmation')).getByText('Off')).toBeInTheDocument();
    expect(within(row('Payment reminder')).getByText('Off')).toBeInTheDocument();
    expect(within(row('Follow-up email')).getByText('Off')).toBeInTheDocument();

    expect(await within(automations).findByText('Replenishment due')).toBeInTheDocument();
    expect(within(row('Replenishment due')).getByText('On')).toBeInTheDocument();
    expect(within(row('Win-back')).getByText('Test mode')).toBeInTheDocument();
    expect(within(row('Browse abandonment')).getByText('Off')).toBeInTheDocument();
    expect(within(automations).getByRole('link', { name: 'Manage in Settings' })).toHaveAttribute(
      'href',
      'admin.php?page=smaily-connect-settings#woocommerce',
    );
  });

  it('says the Campaign Intelligence emails cannot be read now when the engine does not answer', async () => {
    serve();
    vi.spyOn(automationsApi, 'getAutomationsCatalog').mockRejectedValue(new ApiError('GET /rec-engine/automations/catalog → 502', 502, null));
    vi.spyOn(automationsApi, 'getAutomationsConfig').mockRejectedValue(new ApiError('GET /rec-engine/automations/config → 502', 502, null));

    render(<Dashboard state={connectedState()} />);

    expect(
      await screen.findByText('The state of the Campaign Intelligence emails is not available right now.'),
    ).toBeInTheDocument();
    // The rest of the page does not wait for the engine.
    expect(await screen.findByText('All systems normal')).toBeInTheDocument();
  });

  it('asks for a Campaign Intelligence connection instead of reading the engine without one', async () => {
    serve();
    const catalog = vi.spyOn(automationsApi, 'getAutomationsCatalog');

    render(<Dashboard state={connectedState({ recEngineConnection: { kind: 'idle' } })} />);

    expect(await screen.findByText('Needs a Campaign Intelligence connection.')).toBeInTheDocument();
    expect(catalog).not.toHaveBeenCalled();
  });

  it('says there are no deliveries yet when the queues are empty', async () => {
    serve({}, []);
    serveEngineEmails();

    render(<Dashboard state={connectedState()} />);

    expect(await screen.findByText(/^No deliveries yet\./)).toBeInTheDocument();
  });

  it('invites a store with no Smaily account to connect one in Settings', async () => {
    setBoot({ smailyAccountConnected: false });
    _resetBannerBoot();
    serve();
    serveEngineEmails();

    render(<Dashboard state={connectedState()} />);

    expect(screen.getByRole('link', { name: 'Connect your Smaily account' })).toHaveAttribute(
      'href',
      'admin.php?page=smaily-connect-settings#connection',
    );
    expect(await screen.findByText('All systems normal')).toBeInTheDocument();
  });

  it('has every Dashboard text in Estonian', () => {
    const catalog = readFileSync(resolve(__dirname, '../../../../languages/smaily-connect-et.po'), 'utf8');
    const source = [
      readFileSync(resolve(__dirname, 'Dashboard.tsx'), 'utf8'),
      readFileSync(resolve(__dirname, 'verdict.ts'), 'utf8'),
      readFileSync(resolve(__dirname, '../log/LogPage.tsx'), 'utf8'),
    ].join('\n');
    const msgids = [...source.matchAll(/__\(\s*(['"])((?:\\.|(?!\1).)*)\1,\s*'smaily-connect'/gs)].map((m) =>
      (m[2] ?? '').replace(/\\'/g, "'"),
    );
    expect(msgids.length).toBeGreaterThan(40);
    for (const msgid of msgids) {
      const escaped = msgid.replace(/\\/g, '\\\\').replace(/"/g, '\\"');
      const entry = catalog.indexOf(`\nmsgid "${escaped}"\nmsgstr "`);
      expect(entry, msgid).toBeGreaterThan(-1);
      const msgstr = catalog.slice(entry).split('\n')[2];
      expect(msgstr, msgid).not.toBe('msgstr ""');
    }
  });
});
