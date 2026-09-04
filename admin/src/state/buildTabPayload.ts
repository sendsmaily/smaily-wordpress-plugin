import { type SettingsTabKey, type WizardState } from './types';

/**
 * Strip the wizardState shape into the tab-specific payload the
 * Settings endpoint accepts. Each tab handler on the PHP side reads
 * a different slice; this keeps the wire format small + targeted.
 *
 * Shared by:
 *   - admin/src/components/settings/Settings.tsx — per-tab Save click
 *   - admin/src/components/wizard/Wizard.tsx     — Finish click runs
 *     all four tabs sequentially (sub-PR 2.H.17)
 */
export function buildTabPayload(
  state: WizardState,
  tab: SettingsTabKey,
): Record<string, unknown> {
  switch (tab) {
    case 'connection':
      return {
        smailyCredentials: state.smailyCredentials,
        multilingualMode: state.multilingualMode,
        perLanguageAccounts: state.perLanguageAccounts,
        defaultFallbackAccountKey: state.defaultFallbackAccountKey,
        transactionalEmailsEnabled: state.transactionalEmailsEnabled,
        transactionalCredentials: state.transactionalCredentials,
      };
    case 'subscribers':
      return {
        subscriberSyncEnabled: state.subscriberSyncEnabled,
        syncFields: state.syncFields,
        wordpressSubscriptionCheckbox: state.wordpressSubscriptionCheckbox,
        checkoutSubscriptionCheckbox: state.checkoutSubscriptionCheckbox,
        contactSyncMode: state.contactSyncMode,
        includeGuests: state.includeGuests,
      };
    case 'woocommerce':
      return {
        welcomeEnabled: state.welcomeEnabled,
        firstOrderEnabled: state.firstOrderEnabled,
        abandonedCartEnabled: state.abandonedCartEnabled,
        abandonedCartCutoffMinutes: state.abandonedCartCutoffMinutes,
        automationMappings: state.automationMappings,
        orderConfirmationEnabled: state.orderConfirmationEnabled,
        shippingConfirmationEnabled: state.shippingConfirmationEnabled,
        shippedOrderStatuses: state.shippedOrderStatuses,
      };
    case 'recommendations':
      return {
        recEngineFeatures: state.recEngineFeatures,
      };
    case 'finish':
      // Wizard-only pseudo-tab — no payload, the server just flips
      // the smly_plus_setup_completed option.
      return {};
  }
}
