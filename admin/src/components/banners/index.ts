import { readBoot, type BannerBoot } from '../../state/hydrate';

export { PromoBanner } from './PromoBanner';
export {
  CampaignIntelligenceBanner,
  campaignIntelligenceBannerVariant,
  ciStartOpen,
  type CampaignIntelligenceBannerVariant,
} from './CampaignIntelligenceBanner';
export { SmailyInvitationBanner } from './SmailyInvitationBanner';

/**
 * The boot payload's `banners` (CON-350, CON-351). Without one — a boot blob
 * written before it, or a test — no Smaily invitation shows (the account
 * reads as connected) and the Campaign Intelligence banner follows the
 * screen's own state alone.
 */
export function bannerBoot(): BannerBoot {
  const banners = readBoot()?.banners;
  return {
    smailyAccountConnected: banners?.smailyAccountConnected ?? true,
    smailyPlanBlocked: banners?.smailyPlanBlocked ?? false,
    smailyLoginUrl: banners?.smailyLoginUrl ?? '',
    ciBannerDismissed: banners?.ciBannerDismissed ?? false,
  };
}
