import { __ } from '@admin/lib/i18n';
import { SMAILY_SUPPORT_URL } from '@admin/lib/links';
import { type BannerBoot } from '../../state/hydrate';
import { type WizardState } from '../../state/types';
import { PromoBanner, promoCtaClass } from './PromoBanner';

/**
 * Whether the engine lets a store start Campaign Intelligence on its own.
 *
 * Always false for now: the engine has no such signal yet (INT-298). It
 * flips when INT-298's signal lands — CON-175 then wires the signal and the
 * start window here, and nothing else in the banner changes.
 */
export function ciStartOpen(): boolean {
  return false;
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
  return state.smailyConnection.kind === 'success' ? 'invite' : null;
}

export interface CampaignIntelligenceBannerProps {
  variant: CampaignIntelligenceBannerVariant;
  /** The Smaily account's own address — the upsell's "Log in to Smaily". */
  smailyLoginUrl: string;
  /** Given only on top of Settings: the banner there can be closed. */
  onDismiss?: () => void;
  /** Overrides ciStartOpen() — tests only. */
  startOpen?: boolean;
  /** "Try Intelligence free" — wired by CON-175 together with the start window. */
  onStart?: () => void;
  className?: string;
}

export function CampaignIntelligenceBanner({
  variant,
  smailyLoginUrl,
  onDismiss,
  startOpen = ciStartOpen(),
  onStart,
  className,
}: CampaignIntelligenceBannerProps): React.JSX.Element {
  const upsell = variant === 'upsell';

  let action: React.ReactNode;
  if (upsell) {
    action =
      smailyLoginUrl !== '' ? (
        <a href={smailyLoginUrl} target="_blank" rel="noopener noreferrer" className={promoCtaClass}>
          {__('Log in to Smaily', 'smaily-connect')}
        </a>
      ) : null;
  } else if (startOpen) {
    action = (
      <button type="button" onClick={onStart} className={promoCtaClass}>
        {__('Try Intelligence free', 'smaily-connect')}
      </button>
    );
  } else {
    action = (
      <a href={SMAILY_SUPPORT_URL} target="_blank" rel="noopener noreferrer" className={promoCtaClass}>
        {__('Contact Smaily', 'smaily-connect')}
      </a>
    );
  }

  return (
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
      note={
        upsell
          ? __(
              'Campaign Intelligence requires a paid Smaily plan. Log in to Smaily to review your plan options.',
              'smaily-connect',
            )
          : undefined
      }
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
  );
}
