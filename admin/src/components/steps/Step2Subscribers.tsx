import { useEffect, type Dispatch } from 'react';

import { __, sprintf } from '@admin/lib/i18n';
import { useBackfillProgress } from '../../hooks/useBackfillProgress';
import { DEFAULT_SYNC_FIELDS, type ContactSyncMode, type WizardAction, type WizardState } from '../../state/types';
import { cn } from '../../utils/cn';
import { Banner, Button, Card, Checkbox, ProgressBar, Radio, Toggle } from '../primitives';

export interface Step2SubscribersProps {
  state: WizardState;
  dispatch: Dispatch<WizardAction>;
  inSettings?: boolean;
  /**
   * Override the backfill polling cadence — Settings passes 30_000,
   * wizard sticks to the default 5_000.
   */
  backfillIntervalMs?: number;
}

/**
 * Step 2 — Subscribers.
 *
 *   1. Sync-enabled toggle (PLUGIN.md §5.2 — defaults ON)
 *   2. Field selection grid (10 fields × 2 columns)
 *   3. WordPress / WooCommerce checkbox toggles
 *   4. Backfill panel:
 *        - "Start backfill" button
 *        - ProgressBar with live polling via useBackfillProgress
 *        - Status text (running / completed / failed / cancelled)
 */
export function Step2Subscribers({
  state,
  dispatch,
  inSettings = false,
  backfillIntervalMs,
}: Step2SubscribersProps): React.JSX.Element {
  const { progress, pollError, start, cancel } = useBackfillProgress({
    jobType: 'contacts',
    intervalMs: backfillIntervalMs ?? (inSettings ? 30_000 : 5_000),
  });

  // Mirror hook progress into the reducer so other components (the
  // Settings widget, Step 6 summary) see the same data.
  useEffect(() => {
    dispatch({
      type: 'BACKFILL_PROGRESS',
      payload: { jobType: 'contacts', progress },
    });
  }, [progress, dispatch]);

  const handleStart = async (): Promise<void> => {
    dispatch({ type: 'BACKFILL_START', payload: { jobType: 'contacts' } });
    await start();
  };

  const isRunning = progress.status === 'running';
  const isComplete = progress.status === 'completed';
  const hasFailed = progress.status === 'failed';
  const wasCancelled = progress.status === 'cancelled';
  // The run finished without a contact to sync because the audience is empty
  // — not because the walk went wrong (PRO-1715).
  const nothingToSync = progress.synced === 0 && progress.audienceEstimate === 0;

  return (
    <div className="space-y-6">
      {!inSettings && (
        <div>
          <p className="text-sm font-medium uppercase tracking-wide text-text-tertiary">{ __( 'Step 2 of 6', 'smaily-connect' ) }</p>
          <h2 className="mt-1 text-2xl font-semibold text-text-primary">{ __( 'Contact synchronisation to Smaily', 'smaily-connect' ) }</h2>
          <p className="mt-2 text-sm text-text-secondary">
            { __(
              'Contact synchronisation allows you to send contacts daily to your Smaily account.',
              'smaily-connect',
            ) }
          </p>
        </div>
      )}

      <Card title={ __( 'Contact synchronisation', 'smaily-connect' ) }>
        <Toggle
          name="smly-subscriber-sync-enabled"
          checked={state.subscriberSyncEnabled}
          onChange={(e) =>
            dispatch({ type: 'SET_SUBSCRIBER_SYNC_ENABLED', payload: e.target.checked })
          }
          label={ __( 'Sync contacts to Smaily', 'smaily-connect' ) }
          description={ __(
            'When sync has been enabled, all new subscribers and/or clients are added to your Smaily contact list.',
            'smaily-connect',
          ) }
        />

        {state.subscriberSyncEnabled && (
          <div className="mt-5">
            <p className="text-sm font-medium text-text-primary">{ __( 'Fields', 'smaily-connect' ) }</p>
            <p className="mt-1 text-xs text-text-tertiary">
              { __( 'Email address is synced by default. Choose any additional fields to be added as well.', 'smaily-connect' ) }
            </p>
            <div className="mt-3 grid grid-cols-1 gap-2 md:grid-cols-2">
              {DEFAULT_SYNC_FIELDS.map((field) => (
                <Checkbox
                  key={field}
                  name={`smly-sync-${field}`}
                  checked={state.syncFields.includes(field)}
                  onChange={() => dispatch({ type: 'TOGGLE_SYNC_FIELD', payload: { field } })}
                  label={prettyFieldLabel(field)}
                />
              ))}
            </div>
          </div>
        )}
      </Card>

      {state.subscriberSyncEnabled && (
        <Card
          title={ __( 'Contact synchronisation selection', 'smaily-connect' ) }
          description={ __( 'Choose which contacts are synced to Smaily, by your lawful basis for marketing email.', 'smaily-connect' ) }
        >
          <fieldset className="space-y-3" aria-label={ __( 'Contact synchronisation selection', 'smaily-connect' ) }>
            {CONTACT_SYNC_MODES.map((mode) => {
              const isSelected = state.contactSyncMode === mode.value;
              const needsCheckout = mode.value === 'checkout_optin';
              const isDisabled = needsCheckout && ! state.checkoutSubscriptionCheckbox;
              return (
                <label
                  key={mode.value}
                  className={cn(
                    'flex cursor-pointer flex-col gap-2 rounded-lg border p-4 transition-colors duration-120',
                    'focus-within:ring-2 focus-within:ring-brand focus-within:ring-offset-2',
                    isSelected
                      ? 'border-brand bg-brand-soft-bg'
                      : 'border-border bg-surface hover:border-border-cool',
                    isDisabled && 'cursor-not-allowed opacity-60 hover:border-border',
                  )}
                >
                  <Radio
                    name="smly-contact-sync-mode"
                    value={mode.value}
                    checked={isSelected}
                    disabled={isDisabled}
                    onChange={() => dispatch({ type: 'SET_CONTACT_SYNC_MODE', payload: mode.value })}
                    label={<span className="font-semibold text-text-primary">{mode.label}</span>}
                  />
                  <p className="ml-7 text-sm text-text-secondary">{mode.description}</p>
                  {isDisabled && (
                    <p className="ml-7 text-xs text-text-tertiary">
                      { __(
                        'Turn on “Show marketing subscription checkbox during checkout” below to use this mode.',
                        'smaily-connect',
                      ) }
                    </p>
                  )}
                </label>
              );
            })}
          </fieldset>

          {state.contactSyncMode === 'legitimate_interest' && (
            <Banner tone="warning" className="mt-4">
              { __(
                'This sends every customer to Smaily regardless of marketing consent. Make sure you have a lawful basis (legitimate interest).',
                'smaily-connect',
              ) }
            </Banner>
          )}

          {state.contactSyncMode !== 'checkout_optin' && (
            <div className="mt-4">
              <Checkbox
                name="smly-include-guests"
                checked={state.includeGuests}
                onChange={() => dispatch({ type: 'SET_INCLUDE_GUESTS', payload: !state.includeGuests })}
                label={ __( 'Also sync guest-order email addresses', 'smaily-connect' ) }
              />
            </div>
          )}
        </Card>
      )}

      <Card title={ __( 'Subscription checkboxes', 'smaily-connect' ) }>
        <div className="space-y-4">
          <Toggle
            name="smly-wp-subscription"
            checked={state.wordpressSubscriptionCheckbox}
            onChange={(e) =>
              dispatch({ type: 'SET_WORDPRESS_SUBSCRIPTION_CHECKBOX', payload: e.target.checked })
            }
            label={ __( 'Show marketing subscription checkbox during account registration', 'smaily-connect' ) }
          />
          <Toggle
            name="smly-checkout-subscription"
            checked={state.checkoutSubscriptionCheckbox}
            onChange={(e) =>
              dispatch({ type: 'SET_CHECKOUT_SUBSCRIPTION_CHECKBOX', payload: e.target.checked })
            }
            label={ __( 'Show marketing subscription checkbox during checkout', 'smaily-connect' ) }
          />
        </div>
      </Card>

      <Card title={ __( 'Initial contact import', 'smaily-connect' ) } description={ __( 'Import existing contacts to Smaily.', 'smaily-connect' ) }>
        <p className="text-sm text-text-secondary">
          {state.env.storeTotals.customers > 0
            ? sprintf(
                // translators: %d is the number of contacts.
                __( '%d contacts will be synced to Smaily in batches of 100 with ~30 seconds apart. Contact sync can take some time.', 'smaily-connect' ),
                state.env.storeTotals.customers,
              )
            : __( 'No existing WordPress users detected — the backfill will be a no-op.', 'smaily-connect' )}
        </p>
        {/* F3-55: the audience estimate — on a consent-mode store only the
            opted-in users become Smaily contacts, so "30k users processed"
            without this line reads as "30k contacts sent". Shown only when
            the mode actually narrows the audience. */}
        {progress.audienceEstimate !== null
          && state.env.storeTotals.customers > 0
          && progress.audienceEstimate < state.env.storeTotals.customers && (
          <p className="mt-1 text-sm text-text-secondary">
            {sprintf(
              // translators: %d is the number of contacts in the sync audience.
              __( 'Based on your contact sync mode, about %d of them will be synced to Smaily as contacts.', 'smaily-connect' ),
              progress.audienceEstimate,
            )}
          </p>
        )}

        <div className="mt-4 flex items-center gap-3">
          <Button
            variant="primary"
            onClick={handleStart}
            disabled={isRunning || state.env.storeTotals.customers === 0}
            loading={isRunning && progress.processed === 0}
            type="button"
          >
            {isRunning ? __( 'Running…', 'smaily-connect' ) : __( 'Start import', 'smaily-connect' )}
          </Button>

          {isRunning && (
            <Button variant="ghost" onClick={() => void cancel()} type="button">
              { __( 'Cancel', 'smaily-connect' ) }
            </Button>
          )}
        </div>

        {(isRunning || isComplete || hasFailed || wasCancelled) && (
          <div className="mt-5">
            <ProgressBar
              percent={progress.percent}
              tone={hasFailed ? 'danger' : isComplete ? 'success' : 'brand'}
              ariaLabel={ __( 'Contact import progress', 'smaily-connect' ) }
            />
            <p className="mt-2 text-xs text-text-secondary">
              {/* F3-55: `processed` counts users WALKED (drives the bar);
                  `synced` counts audience members actually handled — the only
                  number that may be labelled "contacts synced". */}
              {isRunning && sprintf(
                // translators: %1$d is contacts checked, %2$d is total contacts, %3$d is contacts synced.
                __( 'Checked %1$d of %2$d contacts — %3$d contacts synced.', 'smaily-connect' ),
                progress.processed,
                progress.total,
                progress.synced,
              )}
              {/* PRO-1715: a run that had no-one to sync is its own outcome —
                  "Done — 0 contacts synced" reads like a failure. */}
              {isComplete && (nothingToSync
                ? __( 'Nothing to import — no contacts match your synchronization settings.', 'smaily-connect' )
                : sprintf(
                    // translators: %1$d is contacts synced, %2$d is contacts checked.
                    __( 'Done — %1$d contacts synced (%2$d contacts checked).', 'smaily-connect' ),
                    progress.synced,
                    progress.processed,
                  ))}
              {hasFailed && (progress.error
                ? sprintf(
                    // translators: %s is the error message.
                    __( 'Backfill failed: %s', 'smaily-connect' ),
                    progress.error,
                  )
                : __( 'Backfill failed.', 'smaily-connect' ))}
              {wasCancelled && __( 'Backfill cancelled. Re-run when ready.', 'smaily-connect' )}
            </p>
            {(isComplete || wasCancelled || hasFailed) && progress.completedAt && (
              <p className="mt-1 text-xs text-text-tertiary">
                {sprintf(
                  // translators: %s is a localized date/time.
                  __( 'Last run finished %s.', 'smaily-connect' ),
                  formatLastRun(progress.completedAt),
                )}
              </p>
            )}
            {isRunning && (
              <p className="mt-2 text-xs text-text-tertiary">
                { __(
                  'Backfill runs in the background on your server. You can safely leave this page or close the browser — the job will continue. Return here any time to check progress.',
                  'smaily-connect',
                ) }
              </p>
            )}
          </div>
        )}

        {pollError && (
          <p className="mt-3 text-xs text-danger">{sprintf(
            // translators: %s is the error message.
            __( "Couldn't check status: %s", 'smaily-connect' ),
            pollError,
          )}</p>
        )}
      </Card>
    </div>
  );
}

const CONTACT_SYNC_MODES: ReadonlyArray<{ value: ContactSyncMode; label: string; description: string }> = [
  {
    value: 'consent',
    label: __( 'Subscribers only (consent)', 'smaily-connect' ),
    description: __(
      'Only contacts who have subscribed to marketing emails are sent to Smaily. Unsubscribed contacts are synced back daily.',
      'smaily-connect',
    ),
  },
  {
    value: 'legitimate_interest',
    label: __( 'All customers (legitimate interest)', 'smaily-connect' ),
    description: __(
      'Every customer is sent to Smaily regardless of marketing consent. Requires a lawful basis.',
      'smaily-connect',
    ),
  },
  {
    value: 'checkout_optin',
    label: __( 'Checkout opt-in only', 'smaily-connect' ),
    description: __(
      'Only customers (guests included) who subscribe to marketing emails during the checkout are synced to Smaily.',
      'smaily-connect',
    ),
  },
];

const FIELD_LABELS: Record<string, string> = {
  first_name: __( 'First name', 'smaily-connect' ),
  last_name: __( 'Last name', 'smaily-connect' ),
  user_phone: __( 'Phone', 'smaily-connect' ),
  birthday: __( 'Birthday', 'smaily-connect' ),
  user_gender: __( 'Gender', 'smaily-connect' ),
  customer_group: __( 'Customer group', 'smaily-connect' ),
  customer_id: __( 'Customer ID', 'smaily-connect' ),
  first_registered: __( 'First registered date', 'smaily-connect' ),
  nickname: __( 'Nickname', 'smaily-connect' ),
  site_title: __( 'Site title', 'smaily-connect' ),
};

function prettyFieldLabel(field: string): string {
  return FIELD_LABELS[field] ?? field.replace(/_/g, ' ');
}

/**
 * Render a server-emitted UTC datetime ("2026-05-21 10:42:17") in the
 * merchant's local timezone. Used by the "Last run finished …" hint
 * under the backfill progress bar. We deliberately avoid Intl options
 * the older Node ICU builds in CI choke on — toLocaleString with no
 * arguments is the broadest path.
 */
function formatLastRun(utcDatetime: string): string {
  const iso = utcDatetime.replace(' ', 'T') + 'Z';
  const date = new Date(iso);
  if (isNaN(date.getTime())) {
    return utcDatetime;
  }
  return date.toLocaleString();
}
