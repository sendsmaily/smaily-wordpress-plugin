import { useCallback, useEffect, useState } from 'react';

import { errorMessage } from '../../api/client';
import { getDashboardState, type DashboardState } from '../../api/dashboard';
import { listEvents, type EventRow } from '../../api/events';
import { dismissNotice } from '../../api/notices';
import { useAutomationsData } from '../../hooks/useAutomationsData';
import { formatUtc } from '@admin/lib/format-utc';
import { __, _n, sprintf } from '@admin/lib/i18n';
import { LOG_URL, SETTINGS_URL, SETUP_URL } from '@admin/lib/links';
import { engineTriggerStatus, type EngineTriggerStatus } from '../../state/engine-automations';
import { mainAccount } from '../../state/main-account';
import { type WizardState } from '../../state/types';
import { cn } from '../../utils/cn';
import {
  bannerBoot,
  CampaignIntelligenceBanner,
  campaignIntelligenceBannerVariant,
  SmailyInvitationBanner,
} from '../banners';
import { Banner, Card, Pill, type PillTone } from '../primitives';
import { statusLabel, statusTone } from '../settings/EventLog';
import { isEstonianAdminLocale } from '../steps/EngineAutomationsSection';
import { VersionLine } from '../VersionLine';
import { dashboardVerdict, LOG_FAILED_URL, type Verdict } from './verdict';

export interface DashboardProps {
  /** The saved settings, hydrated from the boot payload as Settings reads them. */
  state: WizardState;
}

/** How many of the latest events the Dashboard lists (Magento's ten). */
const RECENT_EVENTS = 10;

/**
 * The Dashboard (CON-464): the plugin's state at a glance, in the order and
 * words of Magento's and PrestaShop's Dashboard — the invitation banners,
 * the warning banners, one verdict, the connection cards (with PrestaShop's
 * Background tasks), the four tiles, the Automations card, the latest
 * events beside the quick links, and the version line.
 *
 * Every number comes from the plugin's own queues (GET /dashboard, GET
 * /events); the Campaign Intelligence emails' states are read from the
 * engine after the page shows, so a slow engine never holds it up.
 */
export function Dashboard({ state }: DashboardProps): React.JSX.Element {
  const [data, setData] = useState<{ dashboard: DashboardState; events: EventRow[] } | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const controller = new AbortController();
    void (async () => {
      try {
        const [dashboard, events] = await Promise.all([
          getDashboardState(controller.signal),
          listEvents({ page: 1, perPage: RECENT_EVENTS }, controller.signal),
        ]);
        if (!controller.signal.aborted) {
          setData({ dashboard, events: events.events });
        }
      } catch (err) {
        if (!controller.signal.aborted) {
          setError(errorMessage(err, __('The Dashboard could not be loaded. Reload the page to try again.', 'smaily-connect')));
        }
      }
    })();
    return () => controller.abort();
  }, []);

  const banners = bannerBoot();
  const [ciBannerClosed, setCiBannerClosed] = useState(banners.ciBannerDismissed);
  const ciBannerVariant = campaignIntelligenceBannerVariant(state, banners);
  const handleCloseCiBanner = useCallback((): void => {
    setCiBannerClosed(true);
    // A failed request only means the banner returns on the next load.
    dismissNotice('ci_invitation').catch(() => undefined);
  }, []);

  const main = mainAccount(state);
  const engineConnected = state.recEngineConnection.kind === 'success';
  const dashboard = data?.dashboard ?? null;
  const smailyPlanBlocked = banners.smailyPlanBlocked || dashboard?.smaily_refusal === 'plan_blocked';
  const smailyConnected = main.connection.kind === 'success' && (dashboard?.smaily_refusal ?? '') === '';
  const verdict =
    dashboard === null
      ? null
      : dashboardVerdict({
          smailyConnected,
          smailyPlanBlocked,
          engineKeyRejected: engineConnected && !state.recEngineRefused && dashboard.engine_key_rejected,
          backgroundRunning: dashboard.background.running,
          engineRefused: engineConnected && state.recEngineRefused,
          failed24h: dashboard.failed_24h,
          engineDown: dashboard.engine_down,
          unsentNights: dashboard.unsent_product_list_nights,
        });
  // Setup or the Smaily connection outranks the warnings, as on Magento.
  const showWarnings = verdict !== null && verdict.level !== 'disconnected';

  return (
    <div className="min-h-screen bg-page-bg font-sans text-text-primary">
      <div className="mx-auto max-w-5xl space-y-6 p-6">
        <header>
          <h1 className="text-2xl font-semibold">{__('Smaily Connect — Dashboard', 'smaily-connect')}</h1>
        </header>

        {!banners.smailyAccountConnected && <SmailyInvitationBanner connectHref={`${SETTINGS_URL}#connection`} />}
        {ciBannerVariant !== null && !ciBannerClosed && (
          <CampaignIntelligenceBanner
            variant={ciBannerVariant}
            smailyLoginUrl={banners.smailyLoginUrl}
            onDismiss={handleCloseCiBanner}
            // "Try Intelligence free" connected the store (CON-175): the cards show it after a reload.
            onConnected={() => window.location.reload()}
            onShowSmailyConnection={() => {
              window.location.href = `${SETTINGS_URL}#connection`;
            }}
          />
        )}

        {showWarnings && dashboard !== null && dashboard.failed_24h > 0 && (
          <Banner
            tone="warning"
            title={sprintf(
              // translators: %d is the number of events that failed in the last 24 hours.
              _n(
                '%d event failed in the last 24 hours',
                '%d events failed in the last 24 hours',
                dashboard.failed_24h,
                'smaily-connect',
              ),
              dashboard.failed_24h,
            )}
            actions={<ActionLink href={LOG_FAILED_URL} label={__('Review failures', 'smaily-connect')} />}
          />
        )}
        {showWarnings && dashboard !== null && dashboard.unsent_product_list_nights > 0 && (
          <Banner
            tone="warning"
            title={sprintf(
              // translators: %d is the number of nights (3 or more) with no product list sent.
              __('No nightly product list has gone to Campaign Intelligence for %d nights', 'smaily-connect'),
              dashboard.unsent_product_list_nights,
            )}
          />
        )}

        {error !== null && <Banner tone="danger">{error}</Banner>}
        {verdict !== null && <VerdictCard verdict={verdict} />}

        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <ConnectionCard
            label={__('Smaily', 'smaily-connect')}
            on={smailyConnected}
            sub={
              smailyConnected
                ? sprintf(
                    // translators: %s is the Smaily account's subdomain.
                    __('Connected (%s)', 'smaily-connect'),
                    main.credentials.subdomain,
                  )
                : smailyPlanBlocked
                  ? __('Package does not include API access', 'smaily-connect')
                  : __('Credentials missing or invalid', 'smaily-connect')
            }
            pill={
              smailyConnected
                ? { tone: 'success', label: __('Connected', 'smaily-connect') }
                : { tone: 'danger', label: __('Not connected', 'smaily-connect') }
            }
          />
          <ConnectionCard label={__('Campaign Intelligence', 'smaily-connect')} {...engineCard(state, dashboard)} />
          <ConnectionCard
            label={__('Browse tracking', 'smaily-connect')}
            on={engineConnected && state.recEngineFeatures.trackBrowsing}
            sub={
              engineConnected && state.recEngineFeatures.trackBrowsing
                ? __('Script live on storefront', 'smaily-connect')
                : __('Disabled', 'smaily-connect')
            }
            pill={
              engineConnected && state.recEngineFeatures.trackBrowsing
                ? { tone: 'success', label: __('On', 'smaily-connect') }
                : { tone: 'neutral', label: __('Off', 'smaily-connect') }
            }
          />
          {dashboard !== null && <BackgroundCard background={dashboard.background} />}
        </div>

        {dashboard !== null && <Tiles dashboard={dashboard} engineConnected={engineConnected} />}

        <AutomationsCard state={state} />

        <div className="grid gap-6 lg:grid-cols-[1.7fr_1fr]">
          <RecentActivity events={data?.events ?? null} />
          <QuickLinks docsUrl={state.env.docsUrl ?? ''} />
        </div>

        <VersionLine />
      </div>
    </div>
  );
}

function ActionLink({ href, label, danger = false }: { href: string; label: string; danger?: boolean }): React.JSX.Element {
  return (
    <a
      href={href}
      className={cn(
        'inline-flex h-9 items-center rounded-md px-4 text-sm font-medium no-underline',
        danger ? 'bg-danger text-text-white hover:bg-danger-hover' : 'border border-border-strong bg-surface text-text-primary',
      )}
    >
      {label}
    </a>
  );
}

const VERDICT_BAR: Record<Verdict['level'], string> = {
  ok: 'border-l-success',
  degraded: 'border-l-warning',
  disconnected: 'border-l-danger',
};

function VerdictCard({ verdict }: { verdict: Verdict }): React.JSX.Element {
  return (
    <section
      aria-label={verdict.kicker}
      className={cn(
        'flex flex-wrap items-center gap-4 rounded-lg border border-l-[5px] border-border bg-surface px-6 py-4 shadow-card',
        VERDICT_BAR[verdict.level],
      )}
    >
      <div className="min-w-0 flex-1">
        <p className="text-xs font-semibold uppercase tracking-wide text-text-secondary">{verdict.kicker}</p>
        <p className="mt-1 text-sm text-text-primary">{verdict.text}</p>
      </div>
      {verdict.action !== null && (
        <ActionLink href={verdict.action.href} label={verdict.action.label} danger={verdict.action.danger} />
      )}
    </section>
  );
}

interface ConnectionCardProps {
  label: string;
  on: boolean;
  sub: string;
  pill: { tone: PillTone; label: string };
  hint?: string;
}

function ConnectionCard({ label, on, sub, pill, hint }: ConnectionCardProps): React.JSX.Element {
  return (
    <Card>
      <div className="flex gap-3">
        <span
          aria-hidden
          className={cn('mt-1.5 inline-block h-2 w-2 shrink-0 rounded-full', on ? 'bg-success' : 'bg-border-cool')}
        />
        <div className="min-w-0 space-y-1">
          <p className="font-semibold">{label}</p>
          <p className="text-sm text-text-secondary">{sub}</p>
          <Pill tone={pill.tone} dot>
            {pill.label}
          </Pill>
          {hint !== undefined && <p className="text-xs text-text-secondary">{hint}</p>}
        </div>
      </div>
    </Card>
  );
}

/** The Campaign Intelligence card: sub-line and pill read off one engine state, as Magento's. */
function engineCard(state: WizardState, dashboard: DashboardState | null): Omit<ConnectionCardProps, 'label'> {
  if (state.recEngineConnection.kind !== 'success') {
    return { on: false, sub: __('Not connected', 'smaily-connect'), pill: { tone: 'neutral', label: __('Off', 'smaily-connect') } };
  }
  if (state.recEngineRefused) {
    return {
      on: false,
      sub: __('Account not active on the Smaily side', 'smaily-connect'),
      pill: { tone: 'danger', label: __('Not active', 'smaily-connect') },
    };
  }
  // The engine's latest answer refused the stored key (PrestaShop's "Key rejected").
  if (dashboard?.engine_key_rejected === true) {
    return {
      on: false,
      sub: __('Campaign Intelligence rejected the stored API key.', 'smaily-connect'),
      pill: { tone: 'danger', label: __('Key rejected', 'smaily-connect') },
    };
  }
  if (dashboard?.engine_down === true) {
    return {
      on: false,
      sub: __('Currently unreachable', 'smaily-connect'),
      pill: { tone: 'warning', label: __('Unreachable', 'smaily-connect') },
    };
  }
  const tenant = state.recEngineConnection.message ?? '';
  return {
    on: true,
    sub:
      tenant === ''
        ? __('Connected', 'smaily-connect')
        : sprintf(
            // translators: %s is the Campaign Intelligence account's name.
            __('Connected (%s)', 'smaily-connect'),
            tenant,
          ),
    pill: { tone: 'success', label: __('On', 'smaily-connect') },
  };
}

/**
 * PrestaShop's Background tasks card, for Action Scheduler: running while
 * one of the plugin's actions ran in the last 15 minutes. When they do not
 * run it says why, as far as the store can tell: WP-Cron switched off, or
 * WP-Cron waiting for visits.
 */
function BackgroundCard({ background }: { background: DashboardState['background'] }): React.JSX.Element {
  const sub =
    background.last_run === ''
      ? __('Never run', 'smaily-connect')
      : sprintf(
          // translators: %s is the date and time Smaily Connect's background tasks last ran.
          __('Last run %s', 'smaily-connect'),
          formatUtc(background.last_run),
        );
  if (background.running) {
    return (
      <ConnectionCard
        label={__('Background tasks', 'smaily-connect')}
        on
        sub={sub}
        pill={{ tone: 'success', label: __('Running', 'smaily-connect') }}
      />
    );
  }
  return (
    <ConnectionCard
      label={__('Background tasks', 'smaily-connect')}
      on={false}
      sub={sub}
      pill={{ tone: 'danger', label: __('Not running', 'smaily-connect') }}
      hint={
        background.wp_cron_disabled
          ? __(
              "WP-Cron is switched off on this site (DISABLE_WP_CRON), so the server's cron has to call wp-cron.php. Ask your host to set it up.",
              'smaily-connect',
            )
          : __(
              'Action Scheduler runs them through WP-Cron, which needs visits to the site. Ask your host for a server cron that calls wp-cron.php every minute.',
              'smaily-connect',
            )
      }
    />
  );
}

function Tiles({ dashboard, engineConnected }: { dashboard: DashboardState; engineConnected: boolean }): React.JSX.Element {
  const failed = dashboard.failed_24h;
  return (
    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <Tile label={__('Contact syncs', 'smaily-connect')} value={dashboard.contact_syncs_30d}>
        {__('delivered, last 30 days', 'smaily-connect')}
      </Tile>
      {engineConnected && (
        <Tile label={__('Catalog items', 'smaily-connect')} value={dashboard.catalog_items_30d}>
          {__('delivered, last 30 days', 'smaily-connect')}
        </Tile>
      )}
      <Tile label={__('Queued today', 'smaily-connect')} value={dashboard.queued_today}>
        {__('events waiting to send', 'smaily-connect')}
      </Tile>
      <Tile label={__('Failed', 'smaily-connect')} value={failed} attention={failed > 0}>
        {failed > 0 ? (
          <a href={LOG_FAILED_URL} className="underline">
            {__('View failed deliveries', 'smaily-connect')}
          </a>
        ) : (
          __('last 24 hours', 'smaily-connect')
        )}
      </Tile>
    </div>
  );
}

function Tile({
  label,
  value,
  attention = false,
  children,
}: {
  label: string;
  value: number;
  attention?: boolean;
  children: React.ReactNode;
}): React.JSX.Element {
  return (
    <Card className={cn(attention && 'border-danger-border')}>
      <div className="flex items-center justify-between gap-2">
        <span className="text-sm font-medium text-text-secondary">{label}</span>
        {attention && <Pill tone="danger">{__('Attention', 'smaily-connect')}</Pill>}
      </div>
      <p className="mt-2 text-3xl font-semibold">{value}</p>
      <p className="mt-1 text-xs text-text-secondary">{children}</p>
    </Card>
  );
}

/** Off / Test mode / On / Stopped — the Campaign Intelligence emails' states as their cards in Settings name them. */
function enginePill(status: EngineTriggerStatus): React.JSX.Element {
  switch (status) {
    case 'live':
      return <Pill tone="success">{__('On', 'smaily-connect')}</Pill>;
    case 'test':
      return <Pill tone="warning">{__('Test mode', 'smaily-connect')}</Pill>;
    case 'stopped':
      return <Pill tone="danger">{__('Stopped', 'smaily-connect')}</Pill>;
    default:
      return <Pill tone="neutral">{__('Off', 'smaily-connect')}</Pill>;
  }
}

function onOffPill(on: boolean): React.JSX.Element {
  return on ? (
    <Pill tone="success">{__('On', 'smaily-connect')}</Pill>
  ) : (
    <Pill tone="neutral">{__('Off', 'smaily-connect')}</Pill>
  );
}

/**
 * The Automations card (CON-464, new in this connector first): the Smaily
 * automations from the saved settings, and the Campaign Intelligence emails
 * read from the engine after the page shows.
 */
function AutomationsCard({ state }: { state: WizardState }): React.JSX.Element {
  const engineUsable = state.recEngineConnection.kind === 'success' && !state.recEngineRefused;
  const engine = useAutomationsData(engineUsable);
  const transactional = state.transactionalEmailsEnabled;
  const smaily: Array<[string, boolean]> = [
    [__('Welcome email', 'smaily-connect'), state.welcomeEnabled],
    [__('First-order email', 'smaily-connect'), state.firstOrderEnabled],
    [__('Abandoned-cart reminder', 'smaily-connect'), state.abandonedCartEnabled],
    [__('Order confirmation', 'smaily-connect'), transactional && state.orderConfirmationEnabled],
    [__('Shipping confirmation', 'smaily-connect'), transactional && state.shippingConfirmationEnabled],
    [__('Payment reminder', 'smaily-connect'), transactional && state.paymentReminderEnabled],
    [__('Follow-up email', 'smaily-connect'), transactional && state.followUpEnabled],
  ];

  let engineBody: React.ReactNode;
  if (state.recEngineConnection.kind !== 'success') {
    engineBody = <p className="text-sm text-text-secondary">{__('Needs a Campaign Intelligence connection.', 'smaily-connect')}</p>;
  } else if (engine.status === 'success' && engine.data !== null) {
    const { catalog, configs, setup } = engine.data;
    const et = isEstonianAdminLocale();
    engineBody = (
      <ul className="divide-y divide-border-subtle">
        {catalog.triggers.map((trigger) => (
          <li key={trigger.key} className="flex items-center justify-between gap-3 py-2 text-sm">
            <span>{et ? trigger.name_et : trigger.name_en}</span>
            {enginePill(
              engineTriggerStatus(
                configs.find((c) => c.trigger_key === trigger.key),
                setup.find((s) => s.trigger_key === trigger.key),
              ),
            )}
          </li>
        ))}
      </ul>
    );
  } else if (engine.status === 'pending') {
    engineBody = <p className="text-sm text-text-secondary">{__('Loading engine automations…', 'smaily-connect')}</p>;
  } else {
    engineBody = (
      <p className="text-sm text-text-secondary">
        {__('The state of the Campaign Intelligence emails is not available right now.', 'smaily-connect')}
      </p>
    );
  }

  return (
    <Card
      title={__('Automations', 'smaily-connect')}
      headerAccessory={
        <a href={`${SETTINGS_URL}#woocommerce`} className="text-sm font-medium text-brand underline">
          {__('Manage in Settings', 'smaily-connect')}
        </a>
      }
    >
      <div className="grid gap-6 md:grid-cols-2">
        <div>
          <h4 className="mb-1 text-sm font-semibold">{__('Smaily', 'smaily-connect')}</h4>
          <ul className="divide-y divide-border-subtle">
            {smaily.map(([name, on]) => (
              <li key={name} className="flex items-center justify-between gap-3 py-2 text-sm">
                <span>{name}</span>
                {onOffPill(on)}
              </li>
            ))}
          </ul>
        </div>
        <div>
          <h4 className="mb-1 text-sm font-semibold">{__('Campaign Intelligence', 'smaily-connect')}</h4>
          {engineBody}
        </div>
      </div>
    </Card>
  );
}

function RecentActivity({ events }: { events: EventRow[] | null }): React.JSX.Element {
  return (
    <Card
      title={__('Recent activity', 'smaily-connect')}
      headerAccessory={
        <a href={LOG_URL} className="text-sm font-medium text-brand underline">
          {__('View full log', 'smaily-connect')}
        </a>
      }
    >
      {events !== null && events.length === 0 && (
        <p className="text-sm text-text-secondary">
          {__(
            'No deliveries yet. Once contacts, orders or catalog changes start flowing, the latest queue rows show up here.',
            'smaily-connect',
          )}
        </p>
      )}
      {events !== null && events.length > 0 && (
        <div className="overflow-x-auto">
          <table className="w-full text-left text-xs">
            <thead className="text-text-tertiary">
              <tr className="border-b border-border">
                <th className="py-2 pr-3 font-medium">{__('Time (UTC)', 'smaily-connect')}</th>
                <th className="py-2 pr-3 font-medium">{__('Source', 'smaily-connect')}</th>
                <th className="py-2 pr-3 font-medium">{__('Event', 'smaily-connect')}</th>
                <th className="py-2 pr-3 font-medium">{__('Entity', 'smaily-connect')}</th>
                <th className="py-2 pr-3 font-medium">{__('Status', 'smaily-connect')}</th>
              </tr>
            </thead>
            <tbody>
              {events.map((row) => (
                <tr key={`${row.source}-${row.id}`} className="border-b border-border">
                  <td className="whitespace-nowrap py-2 pr-3 font-mono text-text-secondary">{row.created_at}</td>
                  <td className="py-2 pr-3">
                    {row.source === 'rec_engine'
                      ? __('Campaign Intelligence', 'smaily-connect')
                      : __('Smaily', 'smaily-connect')}
                  </td>
                  <td className="py-2 pr-3 font-mono">{row.event_type}</td>
                  <td className="py-2 pr-3 font-mono text-text-secondary">{row.entity_id || '—'}</td>
                  <td className="py-2 pr-3">
                    <Pill tone={row.cancelled || row.skipped ? 'neutral' : statusTone(row.status)} dot>
                      {statusLabel(row)}
                    </Pill>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Card>
  );
}

/** Settings, Log, Initial setup and the merchant guide (CON-463's link moved here). */
function QuickLinks({ docsUrl }: { docsUrl: string }): React.JSX.Element {
  const links = [
    {
      href: SETTINGS_URL,
      label: __('Settings', 'smaily-connect'),
      desc: __('connection, contacts, automations, Intelligence and RSS in one tabbed page', 'smaily-connect'),
      external: false,
    },
    {
      href: LOG_URL,
      label: __('Log', 'smaily-connect'),
      desc: __('every delivery with retry for failed rows', 'smaily-connect'),
      external: false,
    },
    {
      href: SETUP_URL,
      label: __('Initial setup', 'smaily-connect'),
      desc: __('re-run the guided setup at any time; your settings are kept', 'smaily-connect'),
      external: false,
    },
  ];
  if (docsUrl !== '') {
    links.push({
      href: docsUrl,
      label: __('User Guide', 'smaily-connect'),
      desc: __('the full merchant documentation: every feature, setting and troubleshooting steps', 'smaily-connect'),
      external: true,
    });
  }

  return (
    <Card title={__('Quick links', 'smaily-connect')}>
      <ul className="space-y-3 text-sm">
        {links.map((link) => (
          <li key={link.href}>
            <a
              href={link.href}
              className="font-medium text-brand underline"
              {...(link.external ? { target: '_blank', rel: 'noopener noreferrer' } : {})}
            >
              {link.label}
            </a>
            <span className="block text-xs text-text-secondary">{link.desc}</span>
          </li>
        ))}
      </ul>
    </Card>
  );
}
