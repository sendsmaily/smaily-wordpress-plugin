import {
  DEFAULT_CONTACT_SYNC_MODE,
  DEFAULT_SYNC_FIELDS,
  emptyCredentials,
  idleAsync,
  idleBackfill,
  idleEngineAutomations,
  normalizeContactSyncMode,
  type AutomationMapping,
  type AutomationTrigger,
  type RssFeedBootData,
  type WizardState,
} from './types';

/**
 * Server-emitted boot payload — admin/wizard.php + admin/settings.php
 * call `wp_localize_script('smaily-connect-admin', 'smailyConnectBoot',
 * boot)` so this lands on `window.smailyConnectBoot` before the React
 * bundle loads.
 *
 * Shape mirrors WizardState.env + the saved-settings tab payloads. The
 * keys are deliberately not 1:1 with WizardState so we have one place
 * (hydrate.ts) that maps PHP option naming → reducer state, and so the
 * UI can render before the server has fully populated every option.
 */
export interface BootPayload {
  /**
   * Short git SHA of the bundle + PHP that staging is running, with
   * `-dirty` suffix if the build tree had uncommitted changes.
   * `dev` when git wasn't available at packaging time. Surfaces in the
   * browser console as `window.smailyConnectBoot.buildHash` so Erkki
   * can confirm "this WP is running THIS commit" without rebuilding.
   */
  buildHash: string;
  nonce: string;
  /** Base URL for the REST namespace — passed to configureApiClient. */
  restUrl: string;
  /** 'wizard' | 'settings' — same string the data-view attribute carries. */
  view: 'wizard' | 'settings' | 'unknown';
  envSnapshot: {
    detectedLanguages: string[];
    multilingualPlugin: 'wpml' | 'polylang' | 'translatepress' | null;
    elementorPresent: boolean;
    cf7Present: boolean;
    wcActive: boolean;
    hposActive: boolean;
    storeTotals: {
      customers: number;
      orders: number;
      products: number;
    };
    /**
     * RSS-feed builder data from EnvDetector::rss_snapshot(); null when
     * WooCommerce is inactive. Optional for forward/backward payload
     * compatibility (an old cached bundle reading a new payload or
     * vice versa must not crash hydrate).
     */
    rss?: RssFeedBootData | null;
    /** Base merchant-docs URL from EnvDetector::snapshot() (PRO-1430). */
    docsUrl?: string;
    /** Registered WC order statuses from EnvDetector::snapshot() (PRO-1504). */
    orderStatuses?: Array<{ slug: string; name: string }>;
  };
  savedSettings: {
    smailyCredentials: { subdomain: string; username: string; password: string };
    /**
     * True when the server marked the default account as previously
     * verified (sub-PR 2.H.15). hydrate.ts seeds `smailyConnection`
     * to success when this is true AND subdomain + username are
     * populated.
     */
    smailyConnected: boolean;
    /**
     * See WizardState.smailyHasStoredPassword (PRO-2286). Optional for
     * payload compatibility — an old cached bundle / payload pair must
     * not crash hydrate.
     */
    smailyHasStoredPassword?: boolean;
    /** True once the merchant clicked Finish on Step 6 (sub-PR 2.H.18). */
    setupCompleted: boolean;
    multilingualMode: string;
    defaultFallbackAccountKey: string;
    subscriberSyncEnabled: boolean;
    syncFields: string[];
    wordpressSubscriptionCheckbox: boolean;
    checkoutSubscriptionCheckbox: boolean;
    contactSyncMode: string;
    includeGuests: boolean;
    abandonedCartCutoffMinutes: number;
    welcomeEnabled: boolean;
    firstOrderEnabled: boolean;
    abandonedCartEnabled: boolean;
    /**
     * Saved (trigger, language, accountKey) → workflowId rows.
     * `automationMappings` was previously hard-zeroed in hydrate; the
     * server-side SettingsEndpoint persists them but the boot payload
     * didn't expose them, so reload always blanked the Step 3 dropdowns.
     * EnvDetector::automation_mappings() now reads the table.
     */
    automationMappings?: Array<{
      triggerType: string;
      language: string;
      accountKey: string;
      workflowId: string;
      isDefaultFallback: boolean;
    }>;
    /**
     * Transactional emails (PRO-1504, stage 1). Credentials mirror
     * smailyCredentials — password never round-trips.
     */
    transactionalEmailsEnabled?: boolean;
    transactionalCredentials?: { subdomain: string; username: string; password: string };
    transactionalConnected?: boolean;
    orderConfirmationEnabled?: boolean;
    shippingConfirmationEnabled?: boolean;
    shippedOrderStatuses?: string[];
    /**
     * Step 4 — rec-engine connection. The api_key intentionally never
     * lands here; the React layer only needs the connected flag plus
     * tenant display info. All authenticated calls flow through the
     * /rec-engine/ping proxy on the server.
     */
    recEngine?: {
      connected: boolean;
      tenantName: string;
      tenantId: string;
      engineVersion: string;
      baseUrl: string;
      issuedAt: string;
      /**
       * The saved browse-tracking merchant preference
       * (smly_plus_rec_track_browsing). Emitted independent of `connected`
       * because disconnect() preserves it — so a re-connect restores the
       * toggle state the merchant last chose. Previously hydrate hardcoded
       * this to false, which both blanked a saved-on preference on reload and
       * made re-connect forget it. The only Step-4 toggle after 3.9.
       */
      trackBrowsing: boolean;
    };
  };
}

/**
 * Read window.smailyConnectBoot with cautious typing. The PHP mount
 * always sets this, but tests + Vite dev (no PHP) need a graceful
 * fallback to wizard-defaults so the bundle still mounts.
 */
export function readBoot(): BootPayload | null {
  const raw = (window as unknown as { smailyConnectBoot?: BootPayload }).smailyConnectBoot;
  if (raw === undefined || raw === null) {
    return null;
  }
  return raw;
}

/**
 * Map a BootPayload to a WizardState. The `inSettings` flag toggles
 * whether the rendered context is Settings (currentStep ignored) or
 * the wizard.
 */
export function hydrateState(boot: BootPayload | null, inSettings: boolean): WizardState {
  if (boot === null) {
    return {
      inSettings,
      currentStep: 1,
      env: {
        detectedLanguages: [],
        elementorPresent: false,
        cf7Present: false,
        storeTotals: { customers: 0, orders: 0, products: 0 },
        rss: null,
        docsUrl: '',
        orderStatuses: [],
      },
      smailyCredentials: { ...emptyCredentials },
      smailyConnection: idleAsync,
      smailyHasStoredPassword: false,
      multilingualMode: 'single',
      perLanguageAccounts: [],
      defaultFallbackAccountKey: 'default',
      recEngineSetupToken: '',
      recEngineConnection: idleAsync,
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
      subscriberSyncEnabled: true,
      syncFields: [...DEFAULT_SYNC_FIELDS],
      wordpressSubscriptionCheckbox: false,
      checkoutSubscriptionCheckbox: false,
      contactSyncMode: DEFAULT_CONTACT_SYNC_MODE,
      includeGuests: false,
      contactsBackfill: idleBackfill,
    };
  }

  const env = boot.envSnapshot;
  const s = boot.savedSettings;

  // Mode default per Erkki's 2.H.5 spec: until the merchant explicitly
  // picks one, multilingual sites land on Mode B (single Smaily account
  // with per-language automation branches — PLUGIN.md §4: "kõige
  // tüüpilisem"). Single-language sites land on 'single'. A stored
  // mode always wins — we never overwrite a deliberate choice.
  const validModes = ['single', 'A', 'B', 'C'] as const;
  const hasSavedMode = (validModes as readonly string[]).includes(s.multilingualMode);
  const envDefault: WizardState['multilingualMode'] =
    env.detectedLanguages.length > 1 ? 'B' : 'single';
  const mode: WizardState['multilingualMode'] = hasSavedMode
    ? (s.multilingualMode as WizardState['multilingualMode'])
    : envDefault;

  return {
    inSettings,
    currentStep: 1,
    env: {
      detectedLanguages: env.detectedLanguages,
      elementorPresent: env.elementorPresent,
      cf7Present: env.cf7Present,
      storeTotals: env.storeTotals,
      rss: env.rss ?? null,
      docsUrl: env.docsUrl ?? '',
      orderStatuses: env.orderStatuses ?? [],
    },
    smailyCredentials: { ...s.smailyCredentials },
    smailyConnection: deriveCredentialConnection(s.smailyConnected, s.smailyCredentials),
    smailyHasStoredPassword: s.smailyHasStoredPassword ?? false,
    multilingualMode: mode,
    perLanguageAccounts: [],
    defaultFallbackAccountKey: s.defaultFallbackAccountKey || 'default',
    recEngineSetupToken: '',
    recEngineConnection: deriveRecEngineConnection(s.recEngine),
    automationMappings: normaliseAutomationMappings(s.automationMappings),
    welcomeEnabled: s.welcomeEnabled,
    firstOrderEnabled: s.firstOrderEnabled,
    abandonedCartEnabled: s.abandonedCartEnabled,
    abandonedCartCutoffMinutes: s.abandonedCartCutoffMinutes,
    transactionalEmailsEnabled: s.transactionalEmailsEnabled ?? false,
    transactionalCredentials: {
      ...emptyCredentials,
      ...s.transactionalCredentials,
    },
    transactionalConnection: deriveCredentialConnection(
      s.transactionalConnected,
      s.transactionalCredentials,
    ),
    orderConfirmationEnabled: s.orderConfirmationEnabled ?? false,
    shippingConfirmationEnabled: s.shippingConfirmationEnabled ?? false,
    shippedOrderStatuses: s.shippedOrderStatuses ?? [],
    // Read the saved browse preference so reload AND re-connect restore the
    // merchant's last choice (disconnect preserves the option server-side).
    recEngineFeatures: {
      trackBrowsing: s.recEngine?.trackBrowsing ?? false,
    },
    // Deliberately NOT part of the boot payload — the engine's GET is the
    // source of truth (F3-51); the section fetches catalog+config on open.
    engineAutomations: idleEngineAutomations,
    dirtyTabs: {
      connection: false,
      subscribers: false,
      woocommerce: false,
      recommendations: false,
    },
    subscriberSyncEnabled: s.subscriberSyncEnabled,
    // An empty selection is a real answer — the merchant unticked every
    // optional field, or upgraded from a store whose legacy settings had
    // none ticked (PRO-1684). Only a missing/mis-shaped value falls back to
    // the defaults; treating empty as "nothing saved" showed every box
    // ticked while nothing optional was being sent.
    syncFields: Array.isArray(s.syncFields) ? s.syncFields : [...DEFAULT_SYNC_FIELDS],
    wordpressSubscriptionCheckbox: s.wordpressSubscriptionCheckbox,
    checkoutSubscriptionCheckbox: s.checkoutSubscriptionCheckbox,
    contactSyncMode: normalizeContactSyncMode(s.contactSyncMode),
    includeGuests: s.includeGuests,
    contactsBackfill: idleBackfill,
  };
}

const VALID_TRIGGERS: readonly AutomationTrigger[] = [
  'welcome',
  'first_order',
  'abandoned_cart',
  'order_confirmation',
  'shipping_confirmation',
];

/**
 * Map a saved Smaily credential pair + its verified flag into the
 * AsyncStatus slot the wizard reads — the same rule for the default
 * and the transactional account: verified AND a usable
 * subdomain+username pair → success with the username as the display
 * message, else idle.
 */
function deriveCredentialConnection(
  connected: boolean | undefined,
  creds: { subdomain: string; username: string } | undefined,
): WizardState['smailyConnection'] {
  if (connected && creds !== undefined && creds.subdomain !== '' && creds.username !== '') {
    return { kind: 'success', message: creds.username };
  }
  return idleAsync;
}

/**
 * Map the rec-engine boot snapshot into the existing AsyncStatus slot
 * the wizard + Step 6 summary read. Connected → kind='success' with
 * the tenant name as the display message. Not connected (or payload
 * missing) → idle. Failure states surface via the live ping endpoint
 * call inside Step 4, not at hydrate time.
 */
function deriveRecEngineConnection(
  rec: BootPayload['savedSettings']['recEngine'],
): WizardState['recEngineConnection'] {
  if (rec && rec.connected) {
    return {
      kind: 'success',
      message: rec.tenantName !== '' ? rec.tenantName : undefined,
    };
  }
  return idleAsync;
}

function normaliseAutomationMappings(
  raw: BootPayload['savedSettings']['automationMappings'],
): AutomationMapping[] {
  if (!Array.isArray(raw)) {
    return [];
  }
  const out: AutomationMapping[] = [];
  for (const row of raw) {
    if (!(VALID_TRIGGERS as readonly string[]).includes(row.triggerType)) {
      continue;
    }
    if (row.workflowId === '' || row.language === '' || row.accountKey === '') {
      continue;
    }
    out.push({
      triggerType: row.triggerType as AutomationTrigger,
      language: row.language,
      accountKey: row.accountKey,
      workflowId: row.workflowId,
      isDefaultFallback: !!row.isDefaultFallback,
    });
  }
  return out;
}
