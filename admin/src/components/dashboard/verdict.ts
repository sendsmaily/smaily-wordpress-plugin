import { __, _n, sprintf } from '@admin/lib/i18n';
import { LOG_URL, SETTINGS_URL } from '@admin/lib/links';

/** The facts the Dashboard's one verdict sentence is read from. */
export interface VerdictFacts {
  smailyConnected: boolean;
  smailyPlanBlocked: boolean;
  engineKeyRejected: boolean;
  backgroundRunning: boolean;
  engineRefused: boolean;
  failed24h: number;
  engineDown: boolean;
  unsentNights: number;
}

export interface Verdict {
  level: 'disconnected' | 'degraded' | 'ok';
  /** The short role label above the sentence. */
  kicker: string;
  text: string;
  action: { label: string; href: string; danger?: boolean } | null;
}

/** The Log opened on the failed rows. */
export const LOG_FAILED_URL = `${LOG_URL}&status=failed`;

/**
 * One sentence for the plugin's state, by priority, in Magento's words
 * (CON-464): Smaily not connected > Campaign Intelligence key rejected
 * (PrestaShop's words) > background tasks not running (PrestaShop's) >
 * Campaign Intelligence account not active > failures >
 * Campaign Intelligence unreachable > no nightly product list > all good.
 * The Dashboard opens only once setup is finished, so Magento's "Setup
 * incomplete" never shows here.
 */
export function dashboardVerdict(facts: VerdictFacts): Verdict {
  if (!facts.smailyConnected) {
    return {
      level: 'disconnected',
      kicker: __('Not connected', 'smaily-connect'),
      text: facts.smailyPlanBlocked
        ? __(
            "Smaily is refusing every request because this account's package does not include API access. Contact sync and email automations stay stopped until the account is on a package that includes it — waiting will not restore them, and the credentials cannot be checked until then.",
            'smaily-connect',
          )
        : __(
            'The Smaily credentials are missing or Smaily has not accepted them — check them in Settings > Connection and test the connection.',
            'smaily-connect',
          ),
      action: { label: __('Open Connection settings', 'smaily-connect'), href: `${SETTINGS_URL}#connection` },
    };
  }
  const attention = __('Needs attention', 'smaily-connect');
  if (facts.engineKeyRejected) {
    return {
      level: 'degraded',
      kicker: attention,
      text: `${__('Campaign Intelligence rejected the stored API key.', 'smaily-connect')} ${__(
        'The key was revoked or rotated. Disconnect and reconnect with a fresh setup link.',
        'smaily-connect',
      )}`,
      action: { label: __('Open Settings', 'smaily-connect'), href: `${SETTINGS_URL}#recommendations` },
    };
  }
  if (!facts.backgroundRunning) {
    return {
      level: 'degraded',
      kicker: attention,
      text: __('Background tasks are not running — nothing goes to Smaily until they run again.', 'smaily-connect'),
      action: null,
    };
  }
  if (facts.engineRefused) {
    return {
      level: 'degraded',
      kicker: attention,
      text: __(
        'Campaign Intelligence has stopped accepting data from this store, so product, customer and order data is no longer being sent and recommendations will go stale. Waiting will not fix it — the account has to be made active again on the Smaily side. Your Smaily email sending is unaffected; everything queued here waits and is sent once the account is active.',
        'smaily-connect',
      ),
      action: null,
    };
  }
  if (facts.failed24h > 0) {
    return {
      level: 'degraded',
      kicker: attention,
      text: sprintf(
        // translators: %d is the number of deliveries that failed in the last 24 hours.
        _n(
          'Smaily Connect is running, but %d delivery failed in the last 24 hours.',
          'Smaily Connect is running, but %d deliveries failed in the last 24 hours.',
          facts.failed24h,
          'smaily-connect',
        ),
        facts.failed24h,
      ),
      action: { label: __('Review failures', 'smaily-connect'), href: LOG_FAILED_URL, danger: true },
    };
  }
  if (facts.engineDown) {
    return {
      level: 'degraded',
      kicker: attention,
      text: __(
        'Smaily Connect is running, but Campaign Intelligence is currently unreachable — data is queued locally and will sync when it recovers.',
        'smaily-connect',
      ),
      action: null,
    };
  }
  if (facts.unsentNights > 0) {
    return {
      level: 'degraded',
      kicker: attention,
      text: sprintf(
        // translators: %d is the number of nights (3 or more) with no product list sent.
        __(
          'Smaily Connect is running, but no nightly product list has gone to Campaign Intelligence for %d nights.',
          'smaily-connect',
        ),
        facts.unsentNights,
      ),
      action: null,
    };
  }
  return {
    level: 'ok',
    kicker: __('All systems normal', 'smaily-connect'),
    text: __('Everything is running — deliveries to Smaily are flowing normally.', 'smaily-connect'),
    action: { label: __('View full log', 'smaily-connect'), href: LOG_URL },
  };
}
