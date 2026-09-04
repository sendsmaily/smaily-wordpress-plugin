import { type WizardState } from '../../state/types';
import { Button, Card } from '../primitives';
import { __, _n, sprintf } from '@admin/lib/i18n';

export interface Step6DoneProps {
  state: WizardState;
  inSettings?: boolean;
  onOpenRecEngineDashboard?: () => void;
}

interface SummaryItem {
  label: string;
  active: boolean;
  detail?: string;
}

/**
 * Step 6 — Done. Renders a live state-reflection summary of everything
 * the user configured + the outbound link to the rec-engine dashboard.
 * The Smaily-account link lives on step 1 (PRO-1718), where a merchant
 * actually needs it — to fetch the API credentials.
 *
 * Each summary row is derived from state, not stored. That keeps Step 6
 * always honest — if the user navigates Back and toggles something off,
 * the indicator updates automatically when they return.
 *
 * inSettings hides this entire view from the Settings tab tree.
 */
export function Step6Done({
  state,
  inSettings = false,
  onOpenRecEngineDashboard,
}: Step6DoneProps): React.JSX.Element {
  const items: SummaryItem[] = computeSummary(state);

  return (
    <div className="space-y-6">
      {!inSettings && (
        <div>
          <p className="text-sm font-medium uppercase tracking-wide text-text-tertiary">
            {__('Step 6 of 6', 'smaily-connect')}
          </p>
          <h2 className="mt-1 text-2xl font-semibold text-text-primary">
            {__('Overview', 'smaily-connect')}
          </h2>
          <p className="mt-2 text-sm text-text-secondary">
            {__(
              'Smaily Connect is configured and ready. Make sure that all settings are correct and click Finish to save.',
              'smaily-connect',
            )}
          </p>
        </div>
      )}

      <Card title={__("What's active", 'smaily-connect')}>
        <ul className="divide-y divide-border-subtle">
          {items.map((item) => (
            <li key={item.label} className="flex items-start gap-3 py-3">
              <span
                className={`mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-xs font-semibold ${
                  item.active
                    ? 'bg-success text-text-white'
                    : 'border border-border-strong bg-surface text-text-tertiary'
                }`}
                aria-hidden
              >
                {item.active ? '✓' : '○'}
              </span>
              <span className="min-w-0 flex-1">
                <span
                  className={`block text-sm font-medium ${
                    item.active ? 'text-text-primary' : 'text-text-tertiary'
                  }`}
                >
                  {item.label}
                </span>
                {item.detail && <span className="block text-xs text-text-tertiary">{item.detail}</span>}
              </span>
            </li>
          ))}
        </ul>
      </Card>

      {state.recEngineConnection.kind === 'success' && (
        <Card title={__('Open dashboards', 'smaily-connect')}>
          <div className="flex flex-wrap items-center gap-3">
            <Button variant="secondary" type="button" onClick={onOpenRecEngineDashboard}>
              {__('Open Campaign Intelligence dashboard →', 'smaily-connect')}
            </Button>
          </div>
        </Card>
      )}
    </div>
  );
}

function computeSummary(state: WizardState): SummaryItem[] {
  const items: SummaryItem[] = [];

  // Connection -----------------------------------------------------------
  const accountCount = 1 + state.perLanguageAccounts.length;
  items.push({
    label: __('Smaily connected', 'smaily-connect'),
    active: state.smailyConnection.kind === 'success',
    detail:
      state.multilingualMode === 'A'
        ? sprintf(
            /* translators: %d: number of configured accounts. */
            __('%d accounts configured (Mode A)', 'smaily-connect'),
            accountCount,
          )
        : sprintf(
            /* translators: %s: multilingual mode identifier. */
            __('Multilingual mode: %s', 'smaily-connect'),
            state.multilingualMode,
          ),
  });

  // Subscribers ----------------------------------------------------------
  items.push({
    label: __('Contact sync enabled', 'smaily-connect'),
    active: state.subscriberSyncEnabled,
    detail: state.subscriberSyncEnabled
      ? sprintf(
          /* translators: %d: number of synced fields. */
          _n('%d field synced', '%d fields synced', state.syncFields.length, 'smaily-connect'),
          state.syncFields.length,
        )
      : undefined,
  });

  if (state.contactsBackfill.status === 'completed') {
    items.push({
      label: __('Initial import complete', 'smaily-connect'),
      active: true,
      detail: sprintf(
        /* translators: %d: number of contacts synced. */
        _n(
          '%d contact synced.',
          '%d contacts synced.',
          state.contactsBackfill.synced,
          'smaily-connect',
        ),
        state.contactsBackfill.synced,
      ),
    });
  }

  // Step 3 — automations -------------------------------------------------
  items.push({
    label: __('Welcome email', 'smaily-connect'),
    active: state.welcomeEnabled,
    detail: state.welcomeEnabled
      ? sprintf(
          /* translators: %d: number of mapped workflows. */
          _n('%d workflow mapped', '%d workflows mapped', countMappings(state, 'welcome'), 'smaily-connect'),
          countMappings(state, 'welcome'),
        )
      : undefined,
  });
  items.push({
    label: __('First-order email', 'smaily-connect'),
    active: state.firstOrderEnabled,
    detail: state.firstOrderEnabled
      ? sprintf(
          /* translators: %d: number of mapped workflows. */
          _n('%d workflow mapped', '%d workflows mapped', countMappings(state, 'first_order'), 'smaily-connect'),
          countMappings(state, 'first_order'),
        )
      : undefined,
  });
  items.push({
    label: __('Abandoned-cart reminder', 'smaily-connect'),
    active: state.abandonedCartEnabled,
    detail: state.abandonedCartEnabled
      ? sprintf(
          /* translators: %d: cutoff in minutes. */
          __('%d-minute cutoff', 'smaily-connect'),
          state.abandonedCartCutoffMinutes,
        )
      : undefined,
  });

  // Step 4 — recommendations ---------------------------------------------
  const recActiveCount = Object.values(state.recEngineFeatures).filter(Boolean).length;
  items.push({
    label: __('Campaign Intelligence', 'smaily-connect'),
    active: state.recEngineConnection.kind === 'success',
    detail:
      state.recEngineConnection.kind === 'success'
        ? sprintf(
            /* translators: %d: number of active features (out of 5). */
            __('%d of 5 features active', 'smaily-connect'),
            recActiveCount,
          )
        : __('Not connected (optional)', 'smaily-connect'),
  });

  return items;
}

function countMappings(state: WizardState, trigger: 'welcome' | 'first_order' | 'abandoned_cart'): number {
  return state.automationMappings.filter((m) => m.triggerType === trigger).length;
}

