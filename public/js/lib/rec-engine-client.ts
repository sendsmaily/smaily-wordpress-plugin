/**
 * Smaily Recommendation Engine — browser-side tracking client.
 *
 * This is a TypeScript class designed to be platform-agnostic: no `wp.*`,
 * no `window.ajaxurl`, no WordPress nonces inside the class. Configuration
 * comes through constructor injection so the same class works behind a
 * WordPress wrapper (public/js/beacon.js) AND behind a Shopify Theme App
 * Extension's beacon.js. Mailstone 2 extracts this file unchanged into
 * the `@smaily/recengine-client` npm package; the platform wrappers stay
 * in their respective plugins.
 *
 * Phase 2 sub-PR 2.A ships only the public API surface: every method
 * throws `Not implemented`. Phase 3 sub-PR 6 fills the bodies. Locking
 * the signatures here means the wrapper code can be written against a
 * stable contract from the start, and the npm extraction in Mailstone 2
 * is a copy-paste rather than a redesign.
 *
 * Public surface follows ROADMAP.md §3.2.
 */

import {
  captureAttributionParams,
  writeCookie,
  DEFAULT_TTL_DAYS,
  DEFAULT_URL_PARAMS,
  type AttributionCookieNames,
  type AttributionTtlDays,
  type AttributionUrlParams,
} from './attribution';

/**
 * Cookie names — supplied per request from the setup-exchange config
 * (the rec-engine's tenants control these so the same client code works
 * across multi-tenant deployments). The three attribution slots come from
 * `attribution.ts`, which the attribution-only bundle shares (PRO-1767);
 * the anonymous session cookie is this client's alone.
 */
export interface CookieNames extends AttributionCookieNames {
  /** Anonymous browser session ID (UUID v4). ~30-day TTL. */
  session: string;
}

/**
 * Configuration handed to the constructor. The defaults reflect the
 * pilot deployment; production values come from the platform wrapper
 * (which reads them from the setup-exchange response).
 */
export interface RecEngineClientConfig {
  /** Same-origin URL the wrapper exposes (e.g. /wp-json/smaily-connect/v1/beacon). */
  beaconUrl: string;

  /** Cookie names — must match what the platform wrapper sets server-side. */
  cookieNames: CookieNames;

  /** TTL of the anonymous-session cookie in days. Defaults to 30. */
  sessionTtlDays?: number;

  /**
   * URL-param names a campaign click leaves (engine config `url_param_*`).
   * Defaults to smaily_vt / smaily_rec / smaily_ctx.
   */
  urlParams?: AttributionUrlParams;

  /**
   * Per-cookie TTLs in days (engine config `*_ttl_days`). The session cookie
   * uses `sessionTtlDays`. Defaults: visitor 365, recId 30, context 30.
   */
  cookieTtlDays?: AttributionTtlDays;

  /** Batch window in milliseconds before the buffer flushes. Defaults to 30_000. */
  batchWindowMs?: number;

  /** Email of the currently signed-in user, if any. */
  customerEmail?: string | null;

  /** Platform-side user identifier — used for cross-device merge hints. */
  customerExternalId?: string | null;

  /**
   * Returns true when the user has granted marketing consent. The wrapper
   * implements this against the host platform's consent API (WP Consent,
   * Cookiebot, Complianz, CookieYes; or Shopify customer privacy).
   */
  consentChecker?: () => boolean;

  /** Logger override — defaults to console. */
  logger?: { log: (...args: unknown[]) => void; warn: (...args: unknown[]) => void };
}

// The full §6 event_type enum — exactly these 9. `wishlist_remove` was missing
// from this union (it had 8); the 3.4.0 context audit caught the drift against
// the contract before a live-walk could (LESSONS §2.7). Kept in §6 order.
export type EventType =
  | 'product_view'
  | 'category_view'
  | 'search'
  | 'cart_add'
  | 'cart_remove'
  | 'wishlist_add'
  | 'wishlist_remove'
  | 'checkout_start'
  | 'checkout_complete';

export interface TrackingEvent {
  event_type: EventType;
  sku?: string;
  category_path?: string;
  search_query?: string;
  dwell_seconds?: number;
  /**
   * WooCommerce platform product id for a cart event (`cart_add`/`cart_remove`)
   * whose canonical `sku` is resolved server-side by BeaconEndpoint (PRO-1390) —
   * the wrapper only has the DOM `data-product_id` to work with and cannot do
   * the multilingual canonicalization `Support\SkuResolver` does. Proxy-internal:
   * stripped by the `/relay` field whitelist before an event reaches the engine.
   */
  product_id?: string;
}

export type MergeReason =
  | 'user_logged_in'
  | 'email_provided_at_checkout'
  | 'email_link_click';

/** Default batch window before the buffer auto-flushes (ms). */
const DEFAULT_BATCH_WINDOW_MS = 30_000;

/**
 * The one event type that does NOT wait for the batch window (PRO-1878).
 * `checkout_complete` fires on the order-received page, which shoppers close
 * within seconds — the 30s timer rarely elapses, so the event depended on the
 * pagehide sendBeacon path and a real share of them was lost (the engine
 * measured only ~52% of orders producing a checkout_complete). Every other
 * event type rides a page the shopper stays on long enough.
 */
const IMMEDIATE_FLUSH_EVENT: EventType = 'checkout_complete';

/** The wire shape of a single browse event (§6). */
interface WireEvent {
  event_id: string;
  session_id: string;
  event_type: EventType;
  event_ts: string;
  source: string;
  sku?: string;
  category_path?: string;
  search_query?: string;
  dwell_seconds?: number;
  /**
   * Cart-event platform product id, present only until BeaconEndpoint resolves
   * it into `sku` server-side (PRO-1390) — see TrackingEvent.product_id. Never
   * reaches the engine (the `/relay` whitelist has no `product_id` entry).
   */
  product_id?: string;
  /**
   * Persistent visitor token (from the campaign-click cookie), when present.
   * Identity — NOT attribution: browse attribution rides ORDER signals (F3-49,
   * engine-confirmed). Sent only so the engine can bind the browse row to the
   * customer for future cold-start personalization. rec_id / email are
   * deliberately NOT put on browse events (data-minimization, engine request).
   */
  smaily_visitor_token?: string;
}

type Logger = { log: (...args: unknown[]) => void; warn: (...args: unknown[]) => void };

/**
 * Browse-tracking client used by both WordPress and Shopify wrappers.
 *
 * Sub-PR 3.4.1 fills track / flush / destroy + the buffering and unload
 * transport. captureUrlParams (cookie/URL-param capture) lands in 3.4.2 and
 * mergeIdentity (identity.merge) in 3.7 — both still throw so accidental use
 * is impossible.
 *
 * Transport: events buffer in memory for `batchWindowMs` (default 30s), then
 * POST same-origin to `beaconUrl` as `{events:[...]}` (the WP wrapper points
 * that at /wp-json/smaily-connect/v1/beacon). On page-hide the remaining
 * buffer is flushed via `navigator.sendBeacon` so it survives unload. One
 * event type — `checkout_complete` — sends immediately instead of buffering
 * (PRO-1878).
 *
 * Consent: nothing is ever SENT without consent. track() always buffers
 * (cheap, synchronous), but every flush checks `consentChecker` first and
 * DROPS the buffer when consent is absent — matching the admin promise that a
 * site with no consent banner collects no events. No checker ⇒ no consent.
 */
export class RecEngineClient {
  private readonly config: RecEngineClientConfig;
  private buffer: WireEvent[] = [];
  private flushTimer: ReturnType<typeof setTimeout> | null = null;
  private pageHideHandler: (() => void) | null = null;
  private destroyed = false;

  public constructor(config: RecEngineClientConfig) {
    this.config = config;

    // Final flush on unload — pagehide fires on real navigations AND on the
    // bfcache path where 'unload' does not. sendBeacon keeps it reliable.
    if (typeof window !== 'undefined' && typeof window.addEventListener === 'function') {
      this.pageHideHandler = (): void => {
        this.flushOnUnload();
      };
      window.addEventListener('pagehide', this.pageHideHandler);
    }
  }

  /**
   * Queue a browse event for the next batched flush. Synchronous; never
   * throws on consent denial — the consent check happens at flush time.
   *
   * `checkout_complete` is the exception: it flushes immediately instead of
   * waiting for the window (PRO-1878, see IMMEDIATE_FLUSH_EVENT). No
   * double-send risk — flush() takes the buffer synchronously before its first
   * await, so a pagehide landing mid-flight finds nothing left to send; the
   * consent gate is unchanged (flush() still drops the buffer without it).
   */
  public track(event: TrackingEvent): void {
    if (this.destroyed) {
      return;
    }
    this.buffer.push(this.enrich(event));
    if (event.event_type === IMMEDIATE_FLUSH_EVENT) {
      void this.flush();
      return;
    }
    this.scheduleFlush();
  }

  /**
   * Force-flush the buffer immediately. Resolves when the request settles
   * (or right away when nothing is queued / consent is absent). Best-effort:
   * a network error is logged, not thrown — telemetry must never break a page.
   */
  public async flush(): Promise<void> {
    if (this.flushTimer !== null) {
      clearTimeout(this.flushTimer);
      this.flushTimer = null;
    }
    if (this.buffer.length === 0) {
      return;
    }
    if (!this.hasConsent()) {
      this.buffer = [];
      return;
    }
    const events = this.buffer;
    this.buffer = [];
    try {
      await fetch(this.config.beaconUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ events }),
        credentials: 'same-origin',
        keepalive: true,
      });
    } catch (err) {
      this.logger().warn('[smaily-rec] beacon flush failed', err);
    }
  }

  /**
   * Inspect the current URL for the campaign-click params (smaily_vt /
   * smaily_rec / smaily_ctx by default) and persist them as cookies, then
   * strip them from the URL via history.replaceState. Returns true if any
   * param was captured.
   *
   * ORDER MATTERS: every captured value is written to its cookie BEFORE the
   * URL is stripped. Stripping first would lose the attribution silently if a
   * cookie write threw. The replaceState runs once, after all saves.
   *
   * Consent-INDEPENDENT (PRO-1388): these are first-party attribution
   * cookies — the same class `LandingCapture` already writes server-side
   * unconditionally (F3-46). The browser-side capture needs the same
   * independence because a full-page cache can serve the landing hit without
   * ever executing PHP, so `LandingCapture` never runs on that request. This
   * method writes ONLY the attribution cookies (visitor/rec/ctx) — it creates
   * no session cookie and sends nothing; the anonymous session and every
   * event send stay fully consent-gated (see ensureSession / track / flush).
   *
   * The capture itself lives in `attribution.ts`, shared with the
   * attribution-only bundle a browse-tracking-off store loads instead of this
   * client (PRO-1767) — one implementation, so the two writers of the same
   * cookies cannot drift.
   */
  public captureUrlParams(): boolean {
    return captureAttributionParams({
      cookieNames: this.config.cookieNames,
      urlParams: this.urlParamNames(),
      cookieTtlDays: {
        visitor: this.cookieTtl('visitor'),
        recId: this.cookieTtl('recId'),
        context: this.cookieTtl('context'),
      },
    });
  }

  /**
   * Ensure the anonymous-session cookie exists, generating a v4 UUID when it
   * doesn't. Returns the session id (empty when consent is absent — a tracking
   * id is a tracking cookie). The wrapper calls this on init (post-consent).
   */
  public ensureSession(): string {
    const existing = this.readCookie(this.config.cookieNames.session);
    if (existing !== '') {
      return existing;
    }
    if (!this.hasConsent()) {
      return '';
    }
    const sid = uuidV4();
    this.setCookie(this.config.cookieNames.session, sid, this.config.sessionTtlDays ?? 30);
    return sid;
  }

  /**
   * Promote the anonymous session to an identified one. Called from the
   * platform wrapper when the user logs in, provides an email at
   * checkout, or clicks a campaign email link.
   *
   * Returns a promise that resolves once the identity.merge event has
   * been accepted by the server.
   */
  public async mergeIdentity(_email: string, _reason: MergeReason): Promise<void> {
    throw new Error('RecEngineClient.mergeIdentity: Not implemented (Phase 3 sub-PR 3.7)');
  }

  /**
   * Cancel any pending batch flush and detach event listeners. The wrapper
   * calls this on SPA teardown / component unmount. It does NOT send — the
   * pagehide listener owns the final unload flush; destroy() just stops
   * tracking and drops anything still buffered.
   */
  public destroy(): void {
    this.destroyed = true;
    if (this.flushTimer !== null) {
      clearTimeout(this.flushTimer);
      this.flushTimer = null;
    }
    if (this.pageHideHandler !== null && typeof window !== 'undefined') {
      window.removeEventListener('pagehide', this.pageHideHandler);
      this.pageHideHandler = null;
    }
    this.buffer = [];
  }

  // --- internals ------------------------------------------------------

  private scheduleFlush(): void {
    if (this.flushTimer !== null) {
      return;
    }
    this.flushTimer = setTimeout(() => {
      this.flushTimer = null;
      void this.flush();
    }, this.batchWindowMs());
  }

  /**
   * Synchronous unload flush via sendBeacon — fetch (even keepalive) is not
   * reliable once the page is unloading. Drops the buffer when consent is
   * absent, same as flush().
   */
  private flushOnUnload(): void {
    if (this.buffer.length === 0) {
      return;
    }
    if (!this.hasConsent()) {
      this.buffer = [];
      return;
    }
    const events = this.buffer;
    this.buffer = [];
    const body = JSON.stringify({ events });
    if (typeof navigator !== 'undefined' && typeof navigator.sendBeacon === 'function') {
      navigator.sendBeacon(this.config.beaconUrl, new Blob([body], { type: 'application/json' }));
    } else {
      // Last resort — keepalive fetch, fire and forget.
      void this.flushBody(body);
    }
  }

  private async flushBody(body: string): Promise<void> {
    try {
      await fetch(this.config.beaconUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body,
        credentials: 'same-origin',
        keepalive: true,
      });
    } catch (err) {
      this.logger().warn('[smaily-rec] beacon unload flush failed', err);
    }
  }

  private enrich(event: TrackingEvent): WireEvent {
    const wire: WireEvent = {
      event_id: uuidV4(),
      session_id: this.sessionId(),
      event_type: event.event_type,
      event_ts: new Date().toISOString(),
      source: 'plugin_woo',
    };
    if (event.sku !== undefined) {
      wire.sku = event.sku;
    }
    if (event.product_id !== undefined) {
      wire.product_id = event.product_id;
    }
    if (event.category_path !== undefined) {
      wire.category_path = event.category_path;
    }
    if (event.search_query !== undefined) {
      wire.search_query = event.search_query;
    }
    if (event.dwell_seconds !== undefined) {
      wire.dwell_seconds = event.dwell_seconds;
    }
    // Identity (not attribution): carry the visitor token when a campaign click
    // left one, so the engine can bind this browse row to the customer for
    // future cold-start personalization. Omitted when absent (most organic
    // visitors have none) — never sent empty. F3-49.
    const visitor = this.visitorToken();
    if (visitor !== '') {
      wire.smaily_visitor_token = visitor;
    }
    return wire;
  }

  /**
   * Read the anonymous-session cookie. 3.4.1 only READS it (so events carry a
   * session_id when one exists); generating/persisting the cookie + the
   * URL-param capture is 3.4.2 (captureUrlParams). Empty until then.
   */
  private sessionId(): string {
    return this.readCookie(this.config.cookieNames.session);
  }

  /**
   * Read the persistent visitor-token cookie (set by captureUrlParams from a
   * campaign-click `smaily_vt`). Empty when absent — most organic visitors have
   * none, so the field is omitted rather than sent empty. Identity for the
   * engine's cold-start binding, NOT attribution (F3-49).
   */
  private visitorToken(): string {
    return this.readCookie(this.config.cookieNames.visitor);
  }

  private readCookie(name: string): string {
    if (typeof document === 'undefined') {
      return '';
    }
    const match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.*+?^${}()|[\]\\])/g, '\\$1') + '=([^;]*)'));
    const value = match?.[1];
    return value !== undefined ? decodeURIComponent(value) : '';
  }

  /** First-party cookie write — shared with the attribution-only bundle. */
  private setCookie(name: string, value: string, ttlDays: number): void {
    writeCookie(name, value, ttlDays);
  }

  private urlParamNames(): AttributionUrlParams {
    return this.config.urlParams ?? DEFAULT_URL_PARAMS;
  }

  private cookieTtl(key: 'visitor' | 'recId' | 'context'): number {
    return this.config.cookieTtlDays?.[key] ?? DEFAULT_TTL_DAYS[key];
  }

  private hasConsent(): boolean {
    return this.config.consentChecker ? this.config.consentChecker() : false;
  }

  private batchWindowMs(): number {
    return this.config.batchWindowMs ?? DEFAULT_BATCH_WINDOW_MS;
  }

  private logger(): Logger {
    return this.config.logger ?? console;
  }
}

/**
 * RFC-4122 v4 UUID. Uses crypto.randomUUID when available, falling back to
 * getRandomValues, then Math.random (only on ancient/locked-down runtimes).
 */
function uuidV4(): string {
  const cryptoObj = typeof crypto !== 'undefined' ? crypto : undefined;
  if (cryptoObj && typeof cryptoObj.randomUUID === 'function') {
    return cryptoObj.randomUUID();
  }
  const bytes = new Uint8Array(16);
  if (cryptoObj && typeof cryptoObj.getRandomValues === 'function') {
    cryptoObj.getRandomValues(bytes);
  } else {
    for (let i = 0; i < 16; i++) {
      bytes[i] = Math.floor(Math.random() * 256);
    }
  }
  // Version (4) + variant (10xx) bits.
  bytes[6] = ((bytes[6] ?? 0) & 0x0f) | 0x40;
  bytes[8] = ((bytes[8] ?? 0) & 0x3f) | 0x80;
  let out = '';
  for (let i = 0; i < 16; i++) {
    out += ((bytes[i] ?? 0) + 0x100).toString(16).slice(1);
    if (i === 3 || i === 5 || i === 7 || i === 9) {
      out += '-';
    }
  }
  return out;
}
