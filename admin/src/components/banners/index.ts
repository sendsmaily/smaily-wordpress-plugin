import { readBoot, type BannerBoot } from '../../state/hydrate';

export { PromoBanner } from './PromoBanner';
export {
  CampaignIntelligenceBanner,
  campaignIntelligenceBannerVariant,
  _resetCiStartStatus,
  type CampaignIntelligenceBannerVariant,
} from './CampaignIntelligenceBanner';
export { SmailyInvitationBanner } from './SmailyInvitationBanner';

let boot: BannerBoot | null = null;

/**
 * The boot payload's `banners` (CON-350, CON-351), read once per page load:
 * the payload does not change while the page is open. Without one — a boot
 * blob written before it, or a test — no Smaily invitation shows (the
 * account reads as connected) and the Campaign Intelligence banner follows
 * the screen's own state alone.
 */
export function bannerBoot(): BannerBoot {
  if (boot === null) {
    const banners = readBoot()?.banners;
    boot = {
      smailyAccountConnected: banners?.smailyAccountConnected ?? true,
      smailyPlanBlocked: banners?.smailyPlanBlocked ?? false,
      smailyLoginUrl: banners?.smailyLoginUrl ?? '',
      ciBannerDismissed: banners?.ciBannerDismissed ?? false,
    };
  }
  return boot;
}

/** Tests only: read the boot payload again. */
export function _resetBannerBoot(): void {
  boot = null;
}
