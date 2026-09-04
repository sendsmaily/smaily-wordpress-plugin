import { type SettingsTabKey, type WizardAction } from './types';

/**
 * Maps a WizardAction to the Settings tab whose dirty-flag should flip.
 *
 * Settings UI uses this through a `taggedDispatch` wrapper: every state-
 * mutating action gets a MARK_TAB_DIRTY companion dispatch immediately
 * after. The wizard context skips this — its single Finish click saves
 * everything at once, so per-tab dirty state is irrelevant.
 *
 * Returns null for actions that don't modify a tab's persistable slice:
 *   - Wizard navigation (WIZARD_*)
 *   - Connection-test lifecycle (transient, not saved)
 *   - Backfill progress events (server-driven, not user input)
 *   - The tab-dirty actions themselves (no feedback loops)
 */
export function actionToTab(action: WizardAction): SettingsTabKey | null {
  switch (action.type) {
    // Connection tab
    case 'SET_SMAILY_CREDENTIALS':
    case 'SET_MULTILINGUAL_MODE':
    case 'ADD_MODE_ACCOUNT':
    case 'REMOVE_MODE_ACCOUNT':
    case 'UPDATE_MODE_ACCOUNT_CREDENTIALS':
    case 'SET_DEFAULT_FALLBACK_ACCOUNT_KEY':
    case 'SET_REC_ENGINE_SETUP_TOKEN':
    case 'SET_TRANSACTIONAL_EMAILS_ENABLED':
    case 'SET_TRANSACTIONAL_CREDENTIALS':
      return 'connection';

    // Subscribers tab
    case 'SET_SUBSCRIBER_SYNC_ENABLED':
    case 'TOGGLE_SYNC_FIELD':
    case 'SET_SYNC_FIELDS':
    case 'SET_WORDPRESS_SUBSCRIPTION_CHECKBOX':
    case 'SET_CHECKOUT_SUBSCRIPTION_CHECKBOX':
    case 'SET_CONTACT_SYNC_MODE':
    case 'SET_INCLUDE_GUESTS':
      return 'subscribers';

    // WooCommerce tab
    case 'SET_WELCOME_ENABLED':
    case 'SET_FIRST_ORDER_ENABLED':
    case 'SET_ABANDONED_CART_ENABLED':
    case 'SET_ABANDONED_CART_CUTOFF_MINUTES':
    case 'UPSERT_AUTOMATION_MAPPING':
    case 'REMOVE_AUTOMATION_MAPPING':
    case 'SET_AUTOMATION_FALLBACK':
    case 'SET_ORDER_CONFIRMATION_ENABLED':
    case 'SET_SHIPPING_CONFIRMATION_ENABLED':
    case 'TOGGLE_SHIPPED_ORDER_STATUS':
      return 'woocommerce';

    // Recommendations tab
    case 'SET_REC_ENGINE_FEATURE':
      return 'recommendations';

    // Non-persistable / orthogonal.
    //
    // Engine-automations actions are here deliberately: they do NOT flip
    // dirtyTabs.woocommerce — the slice tracks its own dirty bit so a
    // partial save failure (local POST ok, engine PUT failed) leaves only
    // the engine section dirty (F3-52). The sticky footer ORs the two
    // dirty sources together.
    case 'TEST_SMAILY_CONNECTION_START':
    case 'TEST_SMAILY_CONNECTION_SUCCESS':
    case 'TEST_SMAILY_CONNECTION_FAILURE':
    case 'TEST_TRANSACTIONAL_CONNECTION_START':
    case 'TEST_TRANSACTIONAL_CONNECTION_SUCCESS':
    case 'TEST_TRANSACTIONAL_CONNECTION_FAILURE':
    case 'TEST_MODE_ACCOUNT_CONNECTION_START':
    case 'TEST_MODE_ACCOUNT_CONNECTION_SUCCESS':
    case 'TEST_MODE_ACCOUNT_CONNECTION_FAILURE':
    case 'TEST_REC_ENGINE_CONNECTION_START':
    case 'TEST_REC_ENGINE_CONNECTION_SUCCESS':
    case 'TEST_REC_ENGINE_CONNECTION_FAILURE':
    case 'BACKFILL_START':
    case 'BACKFILL_PROGRESS':
    case 'BACKFILL_CANCEL':
    case 'ENGINE_AUTOMATIONS_HYDRATED':
    case 'UPDATE_ENGINE_AUTOMATION':
    case 'ENGINE_AUTOMATIONS_SAVE_START':
    case 'ENGINE_AUTOMATIONS_SAVED':
    case 'ENGINE_AUTOMATIONS_SAVE_FAILED':
    case 'WIZARD_GO_TO_STEP':
    case 'WIZARD_NEXT_STEP':
    case 'WIZARD_PREVIOUS_STEP':
    case 'MARK_TAB_DIRTY':
    case 'CLEAR_TAB_DIRTY':
    case 'CLEAR_ALL_TABS_DIRTY':
      return null;
  }
}
