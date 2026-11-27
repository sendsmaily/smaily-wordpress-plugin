import { useEffect, useState } from 'react';

import { __ } from '@admin/lib/i18n';
import { SMAILY_CONSULTATION_URL } from '@admin/lib/links';
import { getOnboardingStatus, type OnboardingStatus } from '../../api/onboarding';
import { type SetupExchangeSuccess } from '../../api/recEngine';
import { type BannerBoot } from '../../state/hydrate';
import { mainAccount } from '../../state/main-account';
import { type WizardState } from '../../state/types';
import { StartWindow, useIntelligenceStart, type IntelligenceStart } from './IntelligenceStart';
import { PromoBanner, promoCtaClass, promoLinkClass } from './PromoBanner';

let startStatus: Promise<OnboardingStatus> | null = null;

/**
 * Whether the engine lets this store start Campaign Intelligence on its own
 * (contract §21 "Is the start open?", CON-175), and whether the start window
 * may open in a frame. The server reads the engine and keeps its answer five
 * minutes; one request per page load serves every banner on it.
 */
export function ciStartStatus(): Promise<OnboardingStatus> {
  startStatus ??= getOnboardingStatus();
  return startStatus;
}

/** Tests only: read the status again. */
export function _resetCiStartStatus(): void {
  startStatus = null;
}

export type CampaignIntelligenceBannerVariant = 'invite' | 'upsell';

/**
 * Which Campaign Intelligence banner a screen shows (CON-350), or none:
 *
 *   - connected to Campaign Intelligence → none (the connected view stays);
 *   - the Smaily account's package has no API access (Smaily code 227) →
 *     the upsell: Campaign Intelligence needs a paid Smaily plan;
 *   - a working Smaily account → the invitation;
 *   - otherwise none — the Smaily connection is the thing to fix first.
 */
export function campaignIntelligenceBannerVariant(
  state: WizardState,
  boot: BannerBoot,
): CampaignIntelligenceBannerVariant | null {
  if (state.recEngineConnection.kind === 'success') {
    return null;
  }
  if (boot.smailyPlanBlocked) {
    return 'upsell';
  }
  return mainAccount(state).connection.kind === 'success' ? 'invite' : null;
}

export interface CampaignIntelligenceBannerProps {
  variant: CampaignIntelligenceBannerVariant;
  /** The Smaily account's own address — the upsell's "Log in to Smaily". */
  smailyLoginUrl: string;
  /** Given only on top of Settings: the banner there can be closed. */
  onDismiss?: () => void;
  /** "Try Intelligence free" connected the store (CON-175): show the connected view. */
  onConnected?: (response: SetupExchangeSuccess) => void;
  /** Shows the Smaily connection settings — offered when Smaily refused the login. */
  onShowSmailyConnection?: () => void;
  className?: string;
}

export function CampaignIntelligenceBanner({
  variant,
  smailyLoginUrl,
  onDismiss,
  onConnected,
  onShowSmailyConnection,
  className,
}: CampaignIntelligenceBannerProps): React.JSX.Element {
  const [status, setStatus] = useState<OnboardingStatus>({ start_open: false, frame: false });
  const asks = variant === 'invite';
  useEffect(() => {
    if (!asks) {
      return undefined;
    }
    let live = true;
    void ciStartStatus().then((answer) => {
      if (live) {
        setStatus(answer);
      }
    });
    return () => {
      live = false;
    };
  }, [asks]);

  const start = useIntelligenceStart(status.frame, (response) => onConnected?.(response));
  const { phase } = start;
  const refusal = phase.kind === 'refused' ? phase.refusal.error : null;

  // §21: a 403 is Smaily's code 227 — the upsell; a 404 closes the start.
  const upsell = variant === 'upsell' || refusal === 'smaily_paid_package_required';
  const startOpen = status.start_open && refusal !== 'not_found';
  const busy = phase.kind === 'connecting' || start.exchange.status === 'pending';

  let action: React.ReactNode;
  if (upsell) {
    action =
      smailyLoginUrl !== '' ? (
        <a href={smailyLoginUrl} target="_blank" rel="noopener noreferrer" className={promoCtaClass}>
          {__('Log in to Smaily', 'smaily-connect')}
        </a>
      ) : null;
  } else if (startOpen) {
    const fallback = phase.kind === 'fallback';
    action = (
      <button
        type="button"
        onClick={fallback ? start.openWindow : start.start}
        disabled={busy}
        aria-busy={busy}
        className={promoCtaClass}
      >
        {fallback
          ? __('Open in a new window', 'smaily-connect')
          : __('Try Intelligence free', 'smaily-connect')}
      </button>
    );
  } else {
    action = (
      <a href={SMAILY_CONSULTATION_URL} target="_blank" rel="noopener noreferrer" className={promoCtaClass}>
        {__('Contact Smaily', 'smaily-connect')}
      </a>
    );
  }

  let note: React.ReactNode;
  if (refusal === 'smaily_paid_package_required') {
    note = __(
      'Intelligence needs a paid Smaily package. Your Smaily account is on the free package. Trying Intelligence is free, but it works only with a paid Smaily package. No store was created and nothing was sent.',
      'smaily-connect',
    );
  } else if (upsell) {
    note = __(
      'Campaign Intelligence requires a paid Smaily plan. Log in to Smaily to review your plan options.',
      'smaily-connect',
    );
  } else if (startOpen) {
    note = startNote(start, onShowSmailyConnection);
  }

  return (
    <>
      <PromoBanner
        logo="intelligence"
        label="Campaign Intelligence"
        title={
          <>
            Campaign <span className="text-[#8a5cb8]">Intelligence</span>
          </>
        }
        body={__(
          'Connect your store with Smaily, and add personalized product recommendations to your campaigns, tailored to each customer’s interests.',
          'smaily-connect',
        )}
        actions={action}
        note={note}
        pill={upsell ? __('Requires a paid Smaily plan', 'smaily-connect') : undefined}
        onDismiss={onDismiss}
        panel={{
          label: __('Engine in action', 'smaily-connect'),
          heading: __('What the engine recommends you do next', 'smaily-connect'),
          rows: [
            { icon: 'clock', text: __('56 customers are due to reorder', 'smaily-connect') },
            { icon: 'undo', text: __('12 customers are slipping away', 'smaily-connect') },
          ],
          chip: __('Create segment in Smaily', 'smaily-connect'),
        }}
        className={className}
      />
      {phase.kind === 'frame' && (
        <StartWindow url={phase.url} origin={phase.origin} onClose={start.closeFrame} />
      )}
    </>
  );
}

/** The line under "Try Intelligence free": where the start stands (§21 "Texts the plugin shows"). */
function startNote(start: IntelligenceStart, onShowSmailyConnection?: () => void): React.ReactNode {
  if (start.exchange.status === 'pending') {
    return __('Connecting the plugin…', 'smaily-connect');
  }
  if (start.exchange.status === 'failed') {
    return start.exchange.message;
  }

  const { phase } = start;
  switch (phase.kind) {
    case 'connecting':
      return __('Connecting your shop · Sending your shop address · Checking your Smaily account', 'smaily-connect');
    case 'window':
      return __(
        'Finish the setup in the window that opened. If the plugin does not connect by itself, copy the setup link that window shows into Setup URL under Connect Smaily Campaign Intelligence.',
        'smaily-connect',
      );
    case 'fallback':
      return __('The setup window did not open here.', 'smaily-connect');
    case 'blocked':
      return __('Your browser blocked the new tab. Allow pop-ups for this page and try again.', 'smaily-connect');
    case 'refused':
      return refusalNote(phase.refusal.error, phase.refusal.console_url, onShowSmailyConnection);
    default:
      return undefined;
  }
}

function refusalNote(error: string, consoleUrl: string | undefined, onShowSmailyConnection?: () => void): React.ReactNode {
  switch (error) {
    case 'not_found':
      return undefined;
    case 'smaily_login_refused':
      return (
        <>
          {__(
            'Smaily refused the API login stored in this plugin. Check it in the plugin settings and try again. No store was created.',
            'smaily-connect',
          )}
          {onShowSmailyConnection !== undefined && (
            <>
              {' '}
              <button type="button" onClick={onShowSmailyConnection} className={promoLinkClass}>
                {__('Open the Smaily connection settings', 'smaily-connect')}
              </button>
            </>
          )}
        </>
      );
    case 'store_exists':
      return (
        <>
          {__('This Smaily account already has a store in Intelligence.', 'smaily-connect')}{' '}
          {consoleLink(consoleUrl)}
          {__(
            'To connect this plugin, create a setup link in the console (Settings → Connections) and paste it here.',
            'smaily-connect',
          )}
        </>
      );
    case 'unavailable':
    case 'rate_limited':
    case 'network':
      return __('Smaily did not answer. Try again shortly.', 'smaily-connect');
    default:
      return __('Campaign Intelligence could not be started. Try again later, or contact Smaily.', 'smaily-connect');
  }
}

/** "Open console" — the server passes on only a console on the engine host. */
function consoleLink(consoleUrl: string | undefined): React.ReactNode {
  if (consoleUrl === undefined || consoleUrl === '') {
    return null;
  }
  return (
    <>
      <a href={consoleUrl} target="_blank" rel="noopener noreferrer" className={promoLinkClass}>
        {__('Open console', 'smaily-connect')}
      </a>{' '}
    </>
  );
}
