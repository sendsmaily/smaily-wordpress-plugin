/**
 * WizardState + WizardAction — shared by both the wizard (admin/wizard.php)
 * and the settings tabs (admin/settings.php).
 *
 * Per SUGGESTION.md §1 (the "üks komponent, kaks renderdamise-konteksti"
 * decision) the same reducer drives both contexts; only the initial
 * state differs. settings-reducer.ts builds an initial state seeded from
 * the server-loaded option values, wizard-reducer.ts starts at step 1
 * with empty fields. Both call into the same wizardReducer function.
 *
 * Some actions are wizard-only (e.g. WIZARD_GO_TO_STEP) — they simply
 * have no observable effect in Settings because `currentStep` isn't
 * rendered there. The reducer doesn't branch on `inSettings`; the React
 * components do.
 */

/** Smaily API credential triple — same shape as Settings\CredentialSet PHP. */
export interface SmailyCredentials {
  subdomain: string;
  username: string;
  password: string;
}

/**
 * Multilingual mode picker — single-language stores collapse the wizard
 * UX to "single"; multi-language sites pick A / B / C per PLUGIN.md §4.
 */
export type MultilingualMode = 'single' | 'A' | 'B' | 'C';

/**
 * The five Settings tab slugs. Maps 1:1 to URL hashes (#connection,
 * #subscribers, ...). Integrations is informational so it never
 * appears in dirtyTabs.
 */
/**
 * Settings tab keys.
 *
 * The first four are real PillTabs in the Settings UI. `finish` is a
 * wizard-only pseudo-tab — `POST /settings { tab: 'finish' }` toggles
 * `smly_plus_setup_completed`; Settings UI never surfaces it. We
 * fold it into the same endpoint so the boot payload / nonce /
 * permission-check plumbing is reused.
 */
export type SettingsTabKey =
  | 'connection'
  | 'subscribers'
  | 'woocommerce'
  | 'recommendations'
  | 'finish';

/**
 * Discriminated union for API-call lifecycle (connection tests, backfill
 * starts, save-settings round-trips). The discriminator (`kind`) lets
 * components render the right state without nullable bools.
 */
export type AsyncStatus =
  | { kind: 'idle' }
  | { kind: 'pending' }
  | { kind: 'success'; message?: string }
  | { kind: 'failure'; error: string };

/**
 * Backfill progress state, one slot per job_type. Mirrors the shape the
 * BackfillEndpoint REST status response returns.
 */
export interface BackfillProgress {
  status: 'idle' | 'running' | 'completed' | 'failed' | 'cancelled';
  processed: number;
  /** Contacts job (F3-55): audience members handled — the "contacts synced" number. */
  synced: number;
  /** Engine-confirmed rows (sent + deduplicated) for this run (3.10.0). */
  sent: number;
  /** Terminal failed rows for this run — per-row detail in the Event Log. */
  failed: number;
  total: number;
  percent: number;
  etaSeconds: number | null;
  error: string | null;
  /** UTC datetime string from the server, or null when never started. */
  startedAt: string | null;
  /** UTC datetime string set on terminal status, else null. */
  completedAt: string | null;
  /** Contacts job, non-running only: the sync mode's audience size (F3-55). */
  audienceEstimate: number | null;
}

/**
 * Per-language credential set in Mode A. The `accountKey` is the
 * Settings\Credentials lookup key (typically the language code) and
 * gets persisted as `smly_plus_credentials_{accountKey}` on save.
 */
export interface ModeAccount {
  accountKey: string;
  language: string;
  credentials: SmailyCredentials;
  connection: AsyncStatus;
}

/**
 * Workflow → trigger mapping populated by Step 3. One row per
 * (trigger, language, accountKey) combination — Mode B uses
 * accountKey='default' for every row; Mode A varies per language.
 */
export type AutomationTrigger =
  | 'welcome'
  | 'first_order'
  | 'abandoned_cart'
  | 'order_confirmation'
  | 'shipping_confirmation';

export interface AutomationMapping {
  triggerType: AutomationTrigger;
  /** Language code from env.detectedLanguages, or 'default' for single-language sites. */
  language: string;
  accountKey: string;
  workflowId: string;
  isDefaultFallback: boolean;
}

/**
 * One engine-triggered automation config row — EXACTLY the eight
 * required §13 wire fields, snake_case, so the PUT sends state rows
 * as-is with zero translation layer (contract v1.1.0 §13; scar 3.5.3a
 * — a camelCased mirror of a snake_case wire shape passes vitest and
 * fails live). Trigger keys come from the §11 catalog at runtime —
 * never hardcode them (a new trigger ships with an engine deploy).
 *
 * The §12 read-only fields (`configured_via`, `updated_at`) are
 * deliberately NOT here — they must not round-trip into the PUT.
 */
export interface EngineAutomationRow {
  trigger_key: string;
  /** Fail-closed master switch — never set true without an explicit merchant action. */
  enabled: boolean;
  language_mode: 'single' | 'per_language';
  /**
   * Smaily workflow ids as numeric strings. Keys: 'id' (single mode);
   * language codes + 'fallback' (per_language mode). Empty {} allowed
   * only while enabled=false.
   */
  automation_map: Record<string, string>;
  /** Integer 1–365; UI default 7 (the engine has no server-side default). */
  cooldown_days: number;
  /**
   * Not edited by this UI — comes from GET and goes back UNCHANGED so
   * an engine-admin-set cap is never wiped. Null = no cap.
   */
  daily_cap: number | null;
  /** UI default TRUE (fail-closed §11) — switching off is a confirmed, separate action. */
  test_mode: boolean;
  /** Max 50 valid emails; may be empty. */
  test_emails: string[];
}

/**
 * §13 422 error entry — also reused for client-side pre-validation
 * issues so the field-binding UI renders both through one path.
 * Wrapper-level entries have no index and field='configs'.
 */
export interface EngineAutomationsIssue {
  index?: number;
  trigger_key?: string;
  field: string;
  message: string;
}

/**
 * Engine-automations slice. Dirty is tracked SEPARATELY from
 * dirtyTabs.woocommerce: the section renders under the WooCommerce tab
 * and joins its sticky-footer Save, but a partial failure (local POST
 * ok, engine PUT failed) must leave ONLY this slice dirty — one shared
 * bit couldn't express that (F3-52).
 */
export interface EngineAutomationsState {
  /** Draft rows in wire shape, catalog order. Empty until first hydrate. */
  rows: EngineAutomationRow[];
  /** True when rows diverge from the last hydrate/save. */
  dirty: boolean;
  saveStatus: 'idle' | 'pending' | 'success' | 'error';
  saveError: string | null;
  /** §13 422 errors (or local pre-validation issues) from the last save attempt. */
  serverErrors: EngineAutomationsIssue[];
}

/**
 * Product RSS-feed builder data — server-computed in
 * EnvDetector::rss_snapshot() and emitted on the boot payload whenever
 * WooCommerce is active. The feed reads every parameter from the URL's
 * query string, so the builder is purely client-side: this is static
 * boot data, never round-tripped to a save endpoint.
 */
export interface RssFeedBootData {
  /**
   * Permalink-aware feed base URL without builder params — either
   * `https://store/smaily-rss-feed` or, on non-permalink installs,
   * `https://store/?smaily-rss-feed=true`.
   */
  baseUrl: string;
  /** Product categories for the feed filter, alphabetical. */
  categories: Array<{ slug: string; name: string }>;
  /** Prefill — the merchant's previously-saved legacy RSS option values. */
  defaults: {
    limit: number;
    category: string;
    sortBy: string;
    order: string;
    taxRate: number;
  };
}

/**
 * Single source-of-truth state for both wizard and settings.
 *
 * Field-population strategy: Phase 2 sub-PR 2.B defines the full shape
 * but only the actions touching Step 1 + Step 2 are reduced. Later
 * sub-PRs (2.D / 2.E) add actions for Steps 3-6 against the same state
 * tree — never a parallel store.
 */
/**
 * Contact-sync mode preset (F3-48) — the merchant's lawful-basis choice for who
 * gets synced to Smaily. Mirrors ContactSyncMode::MODE_* on the PHP side.
 */
export type ContactSyncMode = 'legitimate_interest' | 'consent' | 'checkout_optin';

export const DEFAULT_CONTACT_SYNC_MODE: ContactSyncMode = 'consent';

/**
 * Coerce a server-emitted / stored mode string into the union — an unknown or
 * missing value falls back to the lawful-safe default (F3-48). Single source so
 * the wizard (hydrate) and Settings (settings-reducer) paths can't drift.
 */
export function normalizeContactSyncMode(value: string | undefined): ContactSyncMode {
  return value === 'legitimate_interest' || value === 'consent' || value === 'checkout_optin'
    ? value
    : DEFAULT_CONTACT_SYNC_MODE;
}

export interface WizardState {
  /** True when rendering inside Settings tabs (sub-PR 2.F). */
  inSettings: boolean;

  /** 1-based wizard step index. Settings ignores this. */
  currentStep: number;

  /** Detected store environment — populated from the server-emitted data-env. */
  env: {
    detectedLanguages: string[];
    elementorPresent: boolean;
    cf7Present: boolean;
    storeTotals: {
      customers: number;
      orders: number;
      products: number;
    };
    /**
     * RSS-feed builder data; null/absent when WooCommerce is inactive
     * (the Integrations RSS section hides). Optional rather than
     * `| null` only, so pre-existing env fixtures stay valid.
     */
    rss?: RssFeedBootData | null;
    /**
     * Base merchant-docs URL (Constants::docs_url(), PRO-1430) — the single
     * place the URL can move/be filtered server-side. Optional so
     * pre-existing env fixtures without it stay valid; UI code should treat
     * an absent/empty value as "no docs link".
     */
    docsUrl?: string;
    /**
     * Registered WooCommerce order statuses (incl. custom ones), from
     * `wc_get_order_statuses()` (PRO-1504). Choices for the "counts as
     * shipped" multi-select. Optional/defaults to [] so pre-existing env
     * fixtures without it stay valid.
     */
    orderStatuses?: Array<{ slug: string; name: string }>;
  };

  /** Step 1 — Connect. */
  smailyCredentials: SmailyCredentials;
  smailyConnection: AsyncStatus;
  /**
   * True when the server still holds a usable password for the default
   * account (PRO-2286). Set on an upgraded store whose credentials work
   * but were never verified by the new code — the password input stays
   * empty and "Test connection" reuses the stored secret. The value
   * itself never reaches the browser.
   */
  smailyHasStoredPassword: boolean;
  multilingualMode: MultilingualMode;
  perLanguageAccounts: ModeAccount[];
  /**
   * Mode A fallback selector — the accountKey of the credential set that
   * handles contacts whose detected language has no per-language entry.
   * Values: 'default' (= smailyCredentials) | any perLanguageAccounts[].accountKey.
   */
  defaultFallbackAccountKey: string;
  recEngineSetupToken: string;
  recEngineConnection: AsyncStatus;

  /** Step 3 — WooCommerce automations. Workflow id per (trigger, account_key). */
  automationMappings: AutomationMapping[];
  welcomeEnabled: boolean;
  firstOrderEnabled: boolean;
  abandonedCartEnabled: boolean;
  /** Minutes a cart stays untouched before the abandoned-cart trigger fires. */
  abandonedCartCutoffMinutes: number;

  /**
   * Transactional emails (PRO-1504, stage 1 — configuration only, no send
   * path). A separate Smaily account, bound under account_key='transactional',
   * with its own enablement toggle and two trigger mappings
   * (order_confirmation, shipping_confirmation) stored the same way as the
   * three triggers above (rows in `automationMappings` with
   * accountKey='transactional').
   */
  transactionalEmailsEnabled: boolean;
  transactionalCredentials: SmailyCredentials;
  transactionalConnection: AsyncStatus;
  orderConfirmationEnabled: boolean;
  shippingConfirmationEnabled: boolean;
  /** WC order-status slugs (bare, no 'wc-' prefix) that count as "shipped". */
  shippedOrderStatuses: string[];

  /**
   * Step 4 — Recommendations. Connecting the rec-engine syncs all domains
   * (products/customers/orders) unconditionally — the system decides, there
   * are no per-domain sync toggles (3.9, PLUGIN.md §Step-4-4a). The one
   * remaining toggle is browse tracking, which is opt-in (off by default,
   * GDPR-sensitive) and is additionally gated by end-user consent (WP Consent
   * API / CookieYes) on top of this merchant preference.
   */
  recEngineFeatures: {
    trackBrowsing: boolean;
  };

  /**
   * Engine-triggered automations (contract §11–§13, T2.2). Rendered as a
   * sub-section under the WooCommerce automations; saved via the
   * rec-engine automations proxy (PUT), not POST /settings.
   */
  engineAutomations: EngineAutomationsState;

  /**
   * Per-tab dirty flag — true when the tab's slice of state diverges from
   * what was last persisted via POST /settings. Settings UI surfaces Save +
   * Discard CTAs based on these flags. Wizard context ignores them — the
   * Finish button saves everything at once.
   */
  dirtyTabs: {
    connection: boolean;
    subscribers: boolean;
    woocommerce: boolean;
    recommendations: boolean;
  };

  /** Step 2 — Subscribers. */
  subscriberSyncEnabled: boolean;
  syncFields: string[];
  wordpressSubscriptionCheckbox: boolean;
  checkoutSubscriptionCheckbox: boolean;
  /** Contact-sync mode preset (F3-48) — who is synced + the consent posture. */
  contactSyncMode: ContactSyncMode;
  includeGuests: boolean;
  contactsBackfill: BackfillProgress;

  /** Step 3 — WooCommerce automations. Wizard sub-PR 2.E fills in the shape. */
  // Reserved.

  /** Step 4 — Recommendations. Wizard sub-PR 2.E fills in the shape. */
  // Reserved.
}

/**
 * Default sub-set of subscriber-sync fields — matches the upstream
 * Options::SUBSCRIBER_SYNC_DEFAULT_FIELDS list. UI in Step 2 (sub-PR 2.E)
 * lets the user toggle each one.
 *
 * These names are what the merchant's selection is SAVED as, so every one
 * of them must be a name SubscriberPayloadBuilder::SUPPORTED_FIELDS knows —
 * a name only this list uses is silently discarded before anything is sent
 * (PRO-1683: `phone` / `gender` were, for as long as the wizard existed).
 * The canonical spelling is the field-mapping standard, spec/FIELD_MAPPING.md
 * §2/§3 — not this file.
 */
export const DEFAULT_SYNC_FIELDS = [
  'first_name',
  'last_name',
  'user_phone',
  'birthday',
  'user_gender',
  'customer_group',
  'customer_id',
  'first_registered',
  'nickname',
  'site_title',
] as const;

export const emptyCredentials: SmailyCredentials = {
  subdomain: '',
  username: '',
  password: '',
};

export const idleAsync: AsyncStatus = { kind: 'idle' };

export const idleEngineAutomations: EngineAutomationsState = {
  rows: [],
  dirty: false,
  saveStatus: 'idle',
  saveError: null,
  serverErrors: [],
};

export const idleBackfill: BackfillProgress = {
  status: 'idle',
  processed: 0,
  synced: 0,
  sent: 0,
  failed: 0,
  total: 0,
  percent: 0,
  etaSeconds: null,
  error: null,
  startedAt: null,
  completedAt: null,
  audienceEstimate: null,
};

/**
 * The action union. Names use SCREAMING_SNAKE_CASE per the Redux
 * convention the prototype already established; payloads are typed
 * exhaustively so the reducer's switch is checked at compile time
 * (`assertNever` helper in wizard-reducer.ts).
 */
export type WizardAction =
  // Step 1: Connect ----------------------------------------------------------
  | { type: 'SET_SMAILY_CREDENTIALS'; payload: Partial<SmailyCredentials> }
  | { type: 'TEST_SMAILY_CONNECTION_START' }
  | { type: 'TEST_SMAILY_CONNECTION_SUCCESS'; payload: { accountName?: string } }
  | { type: 'TEST_SMAILY_CONNECTION_FAILURE'; payload: { error: string } }
  | { type: 'SET_MULTILINGUAL_MODE'; payload: MultilingualMode }
  | { type: 'ADD_MODE_ACCOUNT'; payload: ModeAccount }
  | { type: 'REMOVE_MODE_ACCOUNT'; payload: { accountKey: string } }
  | { type: 'UPDATE_MODE_ACCOUNT_CREDENTIALS'; payload: { accountKey: string; credentials: Partial<SmailyCredentials> } }
  | { type: 'TEST_MODE_ACCOUNT_CONNECTION_START'; payload: { accountKey: string } }
  | { type: 'TEST_MODE_ACCOUNT_CONNECTION_SUCCESS'; payload: { accountKey: string; accountName?: string } }
  | { type: 'TEST_MODE_ACCOUNT_CONNECTION_FAILURE'; payload: { accountKey: string; error: string } }
  | { type: 'SET_DEFAULT_FALLBACK_ACCOUNT_KEY'; payload: string }
  | { type: 'SET_REC_ENGINE_SETUP_TOKEN'; payload: string }
  | { type: 'TEST_REC_ENGINE_CONNECTION_START' }
  | { type: 'TEST_REC_ENGINE_CONNECTION_SUCCESS'; payload: { message?: string } }
  | { type: 'TEST_REC_ENGINE_CONNECTION_FAILURE'; payload: { error: string } }

  // Step 2: Subscribers ------------------------------------------------------
  | { type: 'SET_SUBSCRIBER_SYNC_ENABLED'; payload: boolean }
  | { type: 'TOGGLE_SYNC_FIELD'; payload: { field: string } }
  | { type: 'SET_SYNC_FIELDS'; payload: string[] }
  | { type: 'SET_WORDPRESS_SUBSCRIPTION_CHECKBOX'; payload: boolean }
  | { type: 'SET_CHECKOUT_SUBSCRIPTION_CHECKBOX'; payload: boolean }
  | { type: 'SET_CONTACT_SYNC_MODE'; payload: ContactSyncMode }
  | { type: 'SET_INCLUDE_GUESTS'; payload: boolean }
  | { type: 'BACKFILL_START'; payload: { jobType: 'contacts' } }
  | { type: 'BACKFILL_PROGRESS'; payload: { jobType: 'contacts'; progress: BackfillProgress } }
  | { type: 'BACKFILL_CANCEL'; payload: { jobType: 'contacts' } }

  // Step 3: WooCommerce automations -----------------------------------------
  | { type: 'SET_WELCOME_ENABLED'; payload: boolean }
  | { type: 'SET_FIRST_ORDER_ENABLED'; payload: boolean }
  | { type: 'SET_ABANDONED_CART_ENABLED'; payload: boolean }
  | { type: 'SET_ABANDONED_CART_CUTOFF_MINUTES'; payload: number }
  | { type: 'UPSERT_AUTOMATION_MAPPING'; payload: AutomationMapping }
  | { type: 'REMOVE_AUTOMATION_MAPPING'; payload: { triggerType: AutomationTrigger; language: string; accountKey: string } }
  | { type: 'SET_AUTOMATION_FALLBACK'; payload: { triggerType: AutomationTrigger; language: string; accountKey: string } }

  // Transactional emails (PRO-1504, stage 1) ---------------------------------
  | { type: 'SET_TRANSACTIONAL_EMAILS_ENABLED'; payload: boolean }
  | { type: 'SET_TRANSACTIONAL_CREDENTIALS'; payload: Partial<SmailyCredentials> }
  | { type: 'TEST_TRANSACTIONAL_CONNECTION_START' }
  | { type: 'TEST_TRANSACTIONAL_CONNECTION_SUCCESS'; payload: { accountName?: string } }
  | { type: 'TEST_TRANSACTIONAL_CONNECTION_FAILURE'; payload: { error: string } }
  | { type: 'SET_ORDER_CONFIRMATION_ENABLED'; payload: boolean }
  | { type: 'SET_SHIPPING_CONFIRMATION_ENABLED'; payload: boolean }
  | { type: 'TOGGLE_SHIPPED_ORDER_STATUS'; payload: { status: string } }

  // Step 4: Recommendations -------------------------------------------------
  | { type: 'SET_REC_ENGINE_FEATURE'; payload: { feature: 'trackBrowsing'; enabled: boolean } }

  // Engine-triggered automations (contract §11–§13) ---------------------------
  //
  // HYDRATED fires on every section open with the freshly fetched
  // catalog+config (the engine's GET is the truth — F3-51); keepDirty=true
  // preserves an unsaved draft across a tab switch. UPDATE marks the slice
  // dirty; SAVED/SAVE_FAILED come from the PUT round-trip (all-or-nothing:
  // a failure keeps the WHOLE slice dirty).
  | { type: 'ENGINE_AUTOMATIONS_HYDRATED'; payload: { rows: EngineAutomationRow[]; keepDirty: boolean } }
  | { type: 'UPDATE_ENGINE_AUTOMATION'; payload: { triggerKey: string; patch: Partial<EngineAutomationRow> } }
  | { type: 'ENGINE_AUTOMATIONS_SAVE_START' }
  | { type: 'ENGINE_AUTOMATIONS_SAVED' }
  | { type: 'ENGINE_AUTOMATIONS_SAVE_FAILED'; payload: { error: string; errors: EngineAutomationsIssue[] } }

  // Settings dirty-tab tracking ---------------------------------------------
  | { type: 'MARK_TAB_DIRTY'; payload: { tab: SettingsTabKey } }
  | { type: 'CLEAR_TAB_DIRTY'; payload: { tab: SettingsTabKey } }
  | { type: 'CLEAR_ALL_TABS_DIRTY' }

  // Wizard navigation --------------------------------------------------------
  | { type: 'WIZARD_GO_TO_STEP'; payload: { step: number } }
  | { type: 'WIZARD_NEXT_STEP' }
  | { type: 'WIZARD_PREVIOUS_STEP' };
