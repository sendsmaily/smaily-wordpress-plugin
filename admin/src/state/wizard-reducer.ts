import {
  DEFAULT_CONTACT_SYNC_MODE,
  DEFAULT_SYNC_FIELDS,
  emptyCredentials,
  idleAsync,
  idleBackfill,
  idleEngineAutomations,
  type AutomationMapping,
  type ModeAccount,
  type WizardAction,
  type WizardState,
} from './types';

function mappingKey(m: AutomationMapping): string {
  return `${m.triggerType}|${m.language}|${m.accountKey}`;
}

/**
 * Wizard reducer — drives both /wizard and /settings panels.
 *
 * Same function, two callers:
 *   - admin/wizard.php mounts <App view="wizard"> with wizardInitialState
 *   - admin/settings.php mounts <App view="settings"> with settingsInitialState
 *     (assembled in settings-reducer.ts from data-env attributes the
 *     PHP mount emits)
 *
 * Per Erkki's sub-PR 2.B note: actions whose semantics are
 * wizard-specific (WIZARD_GO_TO_STEP, WIZARD_NEXT_STEP, ...) still
 * dispatch from Settings — they're no-ops there because Settings UI
 * doesn't render currentStep. The reducer doesn't branch on
 * state.inSettings; React components decide what to expose.
 */

export const wizardInitialState: WizardState = {
  inSettings: false,
  currentStep: 1,
  env: {
    detectedLanguages: [],
    elementorPresent: false,
    cf7Present: false,
    storeTotals: { customers: 0, orders: 0, products: 0 },
    docsUrl: '',
  },

  smailyCredentials: { ...emptyCredentials },
  smailyConnection: idleAsync,
  smailyHasStoredPassword: false,
  multilingualMode: 'single',
  perLanguageAccounts: [],
  defaultFallbackAccountKey: 'default',
  recEngineSetupToken: '',
  recEngineConnection: idleAsync,

  subscriberSyncEnabled: true,
  syncFields: [...DEFAULT_SYNC_FIELDS],
  wordpressSubscriptionCheckbox: false,
  checkoutSubscriptionCheckbox: false,
  contactSyncMode: DEFAULT_CONTACT_SYNC_MODE,
  includeGuests: false,
  contactsBackfill: idleBackfill,

  automationMappings: [],
  welcomeEnabled: false,
  firstOrderEnabled: false,
  abandonedCartEnabled: false,
  abandonedCartCutoffMinutes: 30,

  transactionalEmailsEnabled: false,
  transactionalCredentials: { ...emptyCredentials },
  transactionalConnection: idleAsync,
  orderConfirmationEnabled: false,
  shippingConfirmationEnabled: false,
  shippedOrderStatuses: [],

  recEngineFeatures: {
    trackBrowsing: false,
  },

  engineAutomations: idleEngineAutomations,

  dirtyTabs: {
    connection: false,
    subscribers: false,
    woocommerce: false,
    recommendations: false,
  },
};

const MAX_STEP = 6;
const MIN_STEP = 1;

export function wizardReducer(state: WizardState, action: WizardAction): WizardState {
  switch (action.type) {
    // Step 1: Connect ------------------------------------------------------
    case 'SET_SMAILY_CREDENTIALS':
      return {
        ...state,
        smailyCredentials: { ...state.smailyCredentials, ...action.payload },
        // Mutating credentials invalidates any previous connection-test result.
        smailyConnection: idleAsync,
      };

    case 'TEST_SMAILY_CONNECTION_START':
      return { ...state, smailyConnection: { kind: 'pending' } };

    case 'TEST_SMAILY_CONNECTION_SUCCESS':
      return {
        ...state,
        smailyConnection: { kind: 'success', message: action.payload.accountName },
      };

    case 'TEST_SMAILY_CONNECTION_FAILURE':
      return {
        ...state,
        smailyConnection: { kind: 'failure', error: action.payload.error },
      };

    case 'SET_MULTILINGUAL_MODE': {
      // Mode change resets per-language accounts only when leaving Mode A
      // — keep the saved list intact when entering it so users don't lose
      // configuration on a B → A toggle.
      const leavingModeA = state.multilingualMode === 'A' && action.payload !== 'A';
      const enteringModeA = state.multilingualMode !== 'A' && action.payload === 'A';

      // Mode A has no notion of a "default" account — per-language entries
      // ARE the credential sets. If the user enters Mode A while
      // defaultFallbackAccountKey is still pointing at the legacy
      // 'default', repoint it at the first detected language so the
      // fallback radio lands on a real account from the start.
      let nextFallback = state.defaultFallbackAccountKey;
      if (enteringModeA && nextFallback === 'default') {
        const firstLanguage = state.env.detectedLanguages[0];
        if (firstLanguage !== undefined) {
          nextFallback = `account_${firstLanguage}`;
        }
      }
      // Leaving Mode A: snap back to the legacy 'default' so the Mode B/C
      // single-account flow has the canonical fallback marker.
      if (leavingModeA) {
        nextFallback = 'default';
      }

      return {
        ...state,
        multilingualMode: action.payload,
        perLanguageAccounts: leavingModeA ? [] : state.perLanguageAccounts,
        defaultFallbackAccountKey: nextFallback,
      };
    }

    case 'ADD_MODE_ACCOUNT': {
      const exists = state.perLanguageAccounts.some(
        (acct) => acct.accountKey === action.payload.accountKey,
      );
      if (exists) {
        return state;
      }
      return {
        ...state,
        perLanguageAccounts: [...state.perLanguageAccounts, action.payload],
      };
    }

    case 'REMOVE_MODE_ACCOUNT':
      return {
        ...state,
        perLanguageAccounts: state.perLanguageAccounts.filter(
          (acct) => acct.accountKey !== action.payload.accountKey,
        ),
      };

    case 'UPDATE_MODE_ACCOUNT_CREDENTIALS':
      return {
        ...state,
        perLanguageAccounts: state.perLanguageAccounts.map((acct): ModeAccount =>
          acct.accountKey === action.payload.accountKey
            ? {
                ...acct,
                credentials: { ...acct.credentials, ...action.payload.credentials },
                // Credential mutation invalidates the connection check.
                connection: idleAsync,
              }
            : acct,
        ),
      };

    case 'TEST_MODE_ACCOUNT_CONNECTION_START':
      return {
        ...state,
        perLanguageAccounts: state.perLanguageAccounts.map((acct): ModeAccount =>
          acct.accountKey === action.payload.accountKey
            ? { ...acct, connection: { kind: 'pending' } }
            : acct,
        ),
      };

    case 'TEST_MODE_ACCOUNT_CONNECTION_SUCCESS':
      return {
        ...state,
        perLanguageAccounts: state.perLanguageAccounts.map((acct): ModeAccount =>
          acct.accountKey === action.payload.accountKey
            ? { ...acct, connection: { kind: 'success', message: action.payload.accountName } }
            : acct,
        ),
      };

    case 'TEST_MODE_ACCOUNT_CONNECTION_FAILURE':
      return {
        ...state,
        perLanguageAccounts: state.perLanguageAccounts.map((acct): ModeAccount =>
          acct.accountKey === action.payload.accountKey
            ? { ...acct, connection: { kind: 'failure', error: action.payload.error } }
            : acct,
        ),
      };

    case 'SET_DEFAULT_FALLBACK_ACCOUNT_KEY':
      return { ...state, defaultFallbackAccountKey: action.payload };

    case 'SET_REC_ENGINE_SETUP_TOKEN':
      return {
        ...state,
        recEngineSetupToken: action.payload,
        recEngineConnection: idleAsync,
      };

    case 'TEST_REC_ENGINE_CONNECTION_START':
      return { ...state, recEngineConnection: { kind: 'pending' } };

    case 'TEST_REC_ENGINE_CONNECTION_SUCCESS':
      return {
        ...state,
        recEngineConnection: { kind: 'success', message: action.payload.message },
      };

    case 'TEST_REC_ENGINE_CONNECTION_FAILURE':
      return {
        ...state,
        recEngineConnection: { kind: 'failure', error: action.payload.error },
      };

    // Step 2: Subscribers --------------------------------------------------
    case 'SET_SUBSCRIBER_SYNC_ENABLED':
      return { ...state, subscriberSyncEnabled: action.payload };

    case 'TOGGLE_SYNC_FIELD': {
      const present = state.syncFields.includes(action.payload.field);
      return {
        ...state,
        syncFields: present
          ? state.syncFields.filter((f) => f !== action.payload.field)
          : [...state.syncFields, action.payload.field],
      };
    }

    case 'SET_SYNC_FIELDS':
      return { ...state, syncFields: action.payload };

    case 'SET_WORDPRESS_SUBSCRIPTION_CHECKBOX':
      return { ...state, wordpressSubscriptionCheckbox: action.payload };

    case 'SET_CHECKOUT_SUBSCRIPTION_CHECKBOX':
      return { ...state, checkoutSubscriptionCheckbox: action.payload };

    case 'SET_CONTACT_SYNC_MODE':
      return { ...state, contactSyncMode: action.payload };

    case 'SET_INCLUDE_GUESTS':
      return { ...state, includeGuests: action.payload };

    case 'BACKFILL_START':
      return {
        ...state,
        contactsBackfill: {
          ...state.contactsBackfill,
          status: 'running',
          processed: 0,
          percent: 0,
          error: null,
        },
      };

    case 'BACKFILL_PROGRESS':
      return {
        ...state,
        contactsBackfill: action.payload.progress,
      };

    case 'BACKFILL_CANCEL':
      return {
        ...state,
        contactsBackfill: { ...state.contactsBackfill, status: 'cancelled' },
      };

    // Step 3: WC automations -----------------------------------------------
    case 'SET_WELCOME_ENABLED':
      return { ...state, welcomeEnabled: action.payload };

    case 'SET_FIRST_ORDER_ENABLED':
      return { ...state, firstOrderEnabled: action.payload };

    case 'SET_ABANDONED_CART_ENABLED':
      return { ...state, abandonedCartEnabled: action.payload };

    case 'SET_ABANDONED_CART_CUTOFF_MINUTES':
      return {
        ...state,
        // PLUGIN.md §5: min 10, default 30. Clamp into the valid range.
        abandonedCartCutoffMinutes: Math.max(10, Math.floor(action.payload)),
      };

    case 'UPSERT_AUTOMATION_MAPPING': {
      const key = mappingKey(action.payload);
      const exists = state.automationMappings.find((m) => mappingKey(m) === key);
      const mappings = exists
        ? state.automationMappings.map((m) => (mappingKey(m) === key ? action.payload : m))
        : [...state.automationMappings, action.payload];
      return { ...state, automationMappings: mappings };
    }

    case 'REMOVE_AUTOMATION_MAPPING':
      return {
        ...state,
        automationMappings: state.automationMappings.filter(
          (m) =>
            !(
              m.triggerType === action.payload.triggerType &&
              m.language === action.payload.language &&
              m.accountKey === action.payload.accountKey
            ),
        ),
      };

    case 'SET_AUTOMATION_FALLBACK': {
      // Exactly one row per triggerType should have is_default_fallback=true.
      return {
        ...state,
        automationMappings: state.automationMappings.map((m) =>
          m.triggerType === action.payload.triggerType
            ? {
                ...m,
                isDefaultFallback:
                  m.language === action.payload.language && m.accountKey === action.payload.accountKey,
              }
            : m,
        ),
      };
    }

    // Transactional emails (PRO-1504, stage 1) ------------------------------
    case 'SET_TRANSACTIONAL_EMAILS_ENABLED':
      return { ...state, transactionalEmailsEnabled: action.payload };

    case 'SET_TRANSACTIONAL_CREDENTIALS':
      return {
        ...state,
        transactionalCredentials: { ...state.transactionalCredentials, ...action.payload },
        // Mutating credentials invalidates any previous connection-test result.
        transactionalConnection: idleAsync,
      };

    case 'TEST_TRANSACTIONAL_CONNECTION_START':
      return { ...state, transactionalConnection: { kind: 'pending' } };

    case 'TEST_TRANSACTIONAL_CONNECTION_SUCCESS':
      return {
        ...state,
        transactionalConnection: { kind: 'success', message: action.payload.accountName },
      };

    case 'TEST_TRANSACTIONAL_CONNECTION_FAILURE':
      return {
        ...state,
        transactionalConnection: { kind: 'failure', error: action.payload.error },
      };

    case 'SET_ORDER_CONFIRMATION_ENABLED':
      return { ...state, orderConfirmationEnabled: action.payload };

    case 'SET_SHIPPING_CONFIRMATION_ENABLED':
      return { ...state, shippingConfirmationEnabled: action.payload };

    case 'TOGGLE_SHIPPED_ORDER_STATUS': {
      const present = state.shippedOrderStatuses.includes(action.payload.status);
      return {
        ...state,
        shippedOrderStatuses: present
          ? state.shippedOrderStatuses.filter((s) => s !== action.payload.status)
          : [...state.shippedOrderStatuses, action.payload.status],
      };
    }

    // Step 4: Recommendations ----------------------------------------------
    case 'SET_REC_ENGINE_FEATURE':
      return {
        ...state,
        recEngineFeatures: {
          ...state.recEngineFeatures,
          [action.payload.feature]: action.payload.enabled,
        },
      };

    // Engine-triggered automations (contract §11–§13) ------------------------
    case 'ENGINE_AUTOMATIONS_HYDRATED':
      return {
        ...state,
        engineAutomations: {
          ...state.engineAutomations,
          rows: action.payload.rows,
          dirty: action.payload.keepDirty,
          saveStatus: 'idle',
          saveError: null,
          // A preserved draft keeps its unresolved save errors visible;
          // a fresh hydrate starts clean.
          serverErrors: action.payload.keepDirty ? state.engineAutomations.serverErrors : [],
        },
      };

    case 'UPDATE_ENGINE_AUTOMATION':
      return {
        ...state,
        engineAutomations: {
          ...state.engineAutomations,
          rows: state.engineAutomations.rows.map((row) =>
            row.trigger_key === action.payload.triggerKey
              ? { ...row, ...action.payload.patch }
              : row,
          ),
          dirty: true,
          // An edit invalidates the previous save outcome banner.
          saveStatus: 'idle',
          saveError: null,
        },
      };

    case 'ENGINE_AUTOMATIONS_SAVE_START':
      return {
        ...state,
        engineAutomations: {
          ...state.engineAutomations,
          saveStatus: 'pending',
          saveError: null,
        },
      };

    case 'ENGINE_AUTOMATIONS_SAVED':
      return {
        ...state,
        engineAutomations: {
          ...state.engineAutomations,
          dirty: false,
          saveStatus: 'success',
          saveError: null,
          serverErrors: [],
        },
      };

    case 'ENGINE_AUTOMATIONS_SAVE_FAILED':
      // All-or-nothing (§13): NOTHING was saved, so the slice stays dirty
      // and the whole selection is resubmitted after the fix.
      return {
        ...state,
        engineAutomations: {
          ...state.engineAutomations,
          saveStatus: 'error',
          saveError: action.payload.error,
          serverErrors: action.payload.errors,
        },
      };

    // Settings dirty-tab tracking ------------------------------------------
    case 'MARK_TAB_DIRTY':
      return {
        ...state,
        dirtyTabs: { ...state.dirtyTabs, [action.payload.tab]: true },
      };

    case 'CLEAR_TAB_DIRTY':
      return {
        ...state,
        dirtyTabs: { ...state.dirtyTabs, [action.payload.tab]: false },
      };

    case 'CLEAR_ALL_TABS_DIRTY':
      return {
        ...state,
        dirtyTabs: {
          connection: false,
          subscribers: false,
          woocommerce: false,
          recommendations: false,
        },
      };

    // Wizard navigation ----------------------------------------------------
    case 'WIZARD_GO_TO_STEP':
      return {
        ...state,
        currentStep: clamp(action.payload.step, MIN_STEP, MAX_STEP),
      };

    case 'WIZARD_NEXT_STEP':
      return { ...state, currentStep: Math.min(MAX_STEP, state.currentStep + 1) };

    case 'WIZARD_PREVIOUS_STEP':
      return { ...state, currentStep: Math.max(MIN_STEP, state.currentStep - 1) };

    default:
      return assertNever(action);
  }
}

function clamp(value: number, min: number, max: number): number {
  return Math.min(max, Math.max(min, value));
}

/**
 * Compile-time exhaustiveness guard — if a new WizardAction case is
 * added to the union and the switch above doesn't handle it,
 * `action` here is inferred as `WizardAction` instead of `never`,
 * which fails the function-return type-check.
 */
function assertNever(action: never): never {
  throw new Error(`wizardReducer: unhandled action ${JSON.stringify(action)}`);
}
