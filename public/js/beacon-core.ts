/**
 * WordPress storefront wrapper logic around the platform-agnostic
 * RecEngineClient — the testable core of the beacon.
 *
 * The PHP side (StorefrontBeacon) enqueues the `beacon.ts` ENTRY (which just
 * calls init() from here) and prints `window.smailyConnectBeacon =
 * { config, context, consent }` just before it. This module wires consent
 * against the WP Consent API. Campaign URL-param (attribution) capture runs
 * unconditionally at init, regardless of consent (PRO-1388); on consent it
 * additionally ensures the anon-session cookie and fires the page-view event.
 *
 * Kept separate from the entry so it exports only functions for vitest; the
 * entry (beacon.ts) holds the auto-boot side effect, so the built bundle has
 * no top-level export and loads as a classic storefront script.
 *
 * 3.4.3a: init + consent + page-view events. Cart events (the WC jQuery
 * `added_to_cart` / `removed_from_cart` listeners) land in 3.4.3b.
 */

import {
  RecEngineClient,
  type EventType,
  type RecEngineClientConfig,
  type TrackingEvent,
} from './lib/rec-engine-client';

interface PageContext {
  pageType: string;
  sku?: string;
  categoryPath?: string;
  searchQuery?: string;
}

interface BeaconBoot {
  config: RecEngineClientConfig;
  context: PageContext;
  consent: { category: string };
  /** Escape-hatch for non-WP-Consent-API plugins (e.g. Cookiebot). */
  consentOverride?: () => boolean;
}

/** A WooCommerce add-to-cart / remove button as jQuery hands it to us. */
interface JQueryButton {
  data: (key: string) => unknown;
}

/** The slice of jQuery the cart listeners need (no @types/jquery dependency). */
interface JQueryCollection {
  on: (event: string, handler: (...args: unknown[]) => void) => void;
}
type JQueryStatic = (selector: unknown) => JQueryCollection;

declare global {
  interface Window {
    smailyConnectBeacon?: BeaconBoot;
    /** WP Consent API JS global (CookieYes / Complianz / Real Cookie Banner). */
    wp_has_consent?: (category: string) => boolean | undefined;
    /** WooCommerce ships jQuery on storefront pages; absent ⇒ no cart events. */
    jQuery?: JQueryStatic;
  }
}

/**
 * Resolve marketing consent. Order: site override → WP Consent API → fail-safe
 * DENY. No consent signal means no tracking — matching the admin promise that a
 * site without a consent banner collects no events.
 */
export function detectConsent(boot: BeaconBoot): boolean {
  const override = window.smailyConnectBeacon?.consentOverride;
  if (typeof override === 'function') {
    return override() === true;
  }
  if (typeof window.wp_has_consent === 'function') {
    return window.wp_has_consent(boot.consent.category) === true;
  }
  return false;
}

/** Map a storefront page type to its §6 event_type (null = no page-view event). */
export function pageViewEvent(pageType: string): EventType | null {
  switch (pageType) {
    case 'product':
      return 'product_view';
    case 'category':
      return 'category_view';
    case 'search':
      return 'search';
    case 'checkout':
      return 'checkout_start';
    case 'order-received':
      return 'checkout_complete';
    default:
      return null;
  }
}

/** Build the TrackingEvent for a page-view, carrying only the §6-relevant fields. */
export function buildPageEvent(evt: EventType, context: PageContext): TrackingEvent {
  const event: TrackingEvent = { event_type: evt };
  if ((evt === 'product_view' || evt === 'category_view') && context.categoryPath !== undefined) {
    event.category_path = context.categoryPath;
  }
  if (evt === 'product_view' && context.sku !== undefined) {
    event.sku = context.sku;
  }
  if (evt === 'search' && context.searchQuery !== undefined) {
    event.search_query = context.searchQuery;
  }
  return event;
}

/**
 * Wire WooCommerce's AJAX cart jQuery events to cart_add / cart_remove. WC
 * fires `added_to_cart` / `removed_from_cart` on document.body with the clicked
 * button as the last arg; that button carries `data-product_id` (WC's own
 * add-to-cart.js refuses to fire the AJAX call at all without one, so it is
 * always present here). We read the platform id, NOT the button's
 * `data-product_sku` — that is the merchant's raw WC SKU field (optional,
 * blank, reused, or garbage on real stores) and must never be sent as the
 * engine's join key (PRO-1390, mirrors the product_view fix, PRO-1224). The
 * canonical `woo-<id>` key is resolved server-side by BeaconEndpoint from this
 * id (multilingual canonicalization needs WP, not just the DOM). §6 requires a
 * sku for cart events, so an event WITHOUT a product_id is skipped (the
 * single-product form-POST add-to-cart fires no JS event at all — an accepted
 * best-effort gap, §14.2). No-op when jQuery isn't present.
 */
export function attachCartListeners(client: RecEngineClient): void {
  const jq = window.jQuery;
  if (typeof jq !== 'function' || typeof document === 'undefined') {
    return;
  }

  const handler = (eventType: EventType) => (...args: unknown[]): void => {
    const button = args[3] as JQueryButton | undefined;
    if (button === undefined || typeof button.data !== 'function') {
      return;
    }
    const raw = button.data('product_id');
    const productId = typeof raw === 'number' ? String(raw) : typeof raw === 'string' ? raw.trim() : '';
    if (productId === '' || !/^\d+$/.test(productId)) {
      return; // product_id is required to resolve a sku — skip rather than send a reject.
    }
    client.track({ event_type: eventType, product_id: productId });
  };

  const body = jq(document.body);
  body.on('added_to_cart', handler('cart_add'));
  body.on('removed_from_cart', handler('cart_remove'));
}

/**
 * Boot the beacon from `window.smailyConnectBeacon`. Returns the client (or null
 * when there is no boot blob) so tests can drive it.
 */
export function init(): RecEngineClient | null {
  const boot = window.smailyConnectBeacon;
  if (!boot || !boot.config || !boot.config.beaconUrl) {
    return null;
  }

  const client = new RecEngineClient({
    ...boot.config,
    consentChecker: (): boolean => detectConsent(boot),
  });

  // Attribution-cookie capture (visitor/rec/ctx) runs unconditionally, before
  // consent is resolved (PRO-1388) — it writes no session cookie and sends
  // nothing, so it is safe consent-independent; see the doc comment on
  // RecEngineClient.captureUrlParams for why (full-page caches skip PHP).
  client.captureUrlParams();

  let started = false;
  const start = (): void => {
    if (started || !detectConsent(boot)) {
      return;
    }
    started = true;
    client.ensureSession();
    const evt = pageViewEvent(boot.context.pageType);
    if (evt !== null) {
      client.track(buildPageEvent(evt, boot.context));
    }
    // Cart events fire throughout the session, not just at load — attach the
    // listeners once consent is granted (so cart tracking is consent-gated too).
    attachCartListeners(client);
    // The page-view is sent on the 30s batch window or, more usually, on
    // pagehide via sendBeacon — no explicit flush needed. The one exception is
    // checkout_complete (order-received), which the client sends immediately
    // because the shopper closes that tab within seconds (PRO-1878).
  };

  // Fire now if consent is already granted; otherwise wait for the visitor to
  // grant it. The WP Consent API dispatches a native CustomEvent on document.
  start();
  if (typeof document !== 'undefined') {
    document.addEventListener('wp_listen_for_consent_change', start);
  }

  return client;
}
