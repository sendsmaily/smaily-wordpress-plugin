/**
 * Campaign-attribution capture — the URL params an email recommendation link
 * carries (`smaily_vt` / `smaily_rec` / `smaily_ctx`) written into the plugin's
 * first-party cookies, and stripped from the visible URL.
 *
 * This module is the ONE implementation of that capture, imported by BOTH
 * storefront bundles: the full browse runtime (`sc-runtime.js`, via
 * RecEngineClient.captureUrlParams) and the attribution-only writer
 * (`sc-landing.js`, PRO-1767), which loads whenever the engine is connected —
 * including on a store with browse tracking OFF, where the server-side
 * `LandingCapture` is blind behind a full-page cache. Two writers of the same
 * cookies must never drift apart, so neither one owns the logic.
 *
 * Consent-INDEPENDENT by decision (F3-46 / PRO-1388): these are first-party
 * attribution cookies, the same ones `LandingCapture` writes server-side
 * unconditionally. This module writes ONLY those three — it creates no session
 * cookie and sends nothing. The anonymous session and every event send stay
 * fully consent-gated inside RecEngineClient.
 */

/** Cookie names for the three attribution slots (engine config `*_cookie_name`). */
export interface AttributionCookieNames {
  /** Visitor token issued on first email-link click. ~365-day TTL. */
  visitor: string;
  /** Last-touch recommendation id from a campaign click. ~30-day TTL. */
  recId: string;
  /** Last-touch campaign context label (welcome / cart_abandoned / ...). */
  context: string;
}

/** URL-param names a campaign click leaves (engine config `url_param_*`). */
export interface AttributionUrlParams {
  visitorToken: string;
  recId: string;
  context: string;
}

/** Per-cookie TTLs in days (engine config `*_ttl_days`). */
export interface AttributionTtlDays {
  visitor: number;
  recId: number;
  context: number;
}

export interface AttributionConfig {
  cookieNames: AttributionCookieNames;
  urlParams: AttributionUrlParams;
  cookieTtlDays: AttributionTtlDays;
}

/** §6 defaults, used when the engine config carries no override. */
export const DEFAULT_URL_PARAMS: AttributionUrlParams = {
  visitorToken: 'smaily_vt',
  recId: 'smaily_rec',
  context: 'smaily_ctx',
};

export const DEFAULT_TTL_DAYS: AttributionTtlDays = { visitor: 365, recId: 30, context: 30 };

/**
 * The shape the engine enforces for a recommendation id (`z.string().uuid()`
 * on the orders route — 8-4-4-4-12 hex, no version/variant constraint). The
 * PHP side has the same definition in `Smaily\Connect\Smaily\RecEngine\
 * Support\RecId`; every writer of the rec-id cookie must agree (PRO-1710 — a
 * non-UUID cookied here would ride the order to the engine and get that one
 * order permanently D6-rejected).
 */
export const REC_ID_PATTERN = /^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/;

/**
 * The engine's visitor-token shape (`vt_` + alphanumerics) and the campaign
 * context slug, mirroring `LandingCapture::is_visitor_token()` /
 * `::is_context()` verbatim. Two writers of the same cookies must accept the
 * same values (PRO-1896): before this, a crafted landing URL could plant an
 * arbitrary/oversized value in a 30/365-day cookie here that the strict PHP
 * writer would have refused — and PRO-1767 newly hands this writer to
 * browse-OFF stores, whose only writer used to be that strict one.
 */
export const VISITOR_TOKEN_PATTERN = /^vt_[A-Za-z0-9]{1,64}$/;
export const CONTEXT_PATTERN = /^[A-Za-z0-9._-]{1,64}$/;

/**
 * First-party cookie write. SameSite=Lax so a campaign param survives the
 * top-level email-link → shop navigation (Lax allows cookies on top-level
 * GET); Secure only on https. Path=/ so the whole storefront sees it.
 */
export function writeCookie(name: string, value: string, ttlDays: number): void {
  if (typeof document === 'undefined') {
    return;
  }
  const maxAge = Math.max(0, Math.floor(ttlDays * 86400));
  const secure = typeof window !== 'undefined' && window.location?.protocol === 'https:' ? '; Secure' : '';
  document.cookie = name + '=' + encodeURIComponent(value) + '; Max-Age=' + maxAge + '; Path=/; SameSite=Lax' + secure;
}

/**
 * Inspect the current URL for the campaign-click params and persist them as
 * cookies, then strip them from the URL via history.replaceState. Returns true
 * if any param was captured.
 *
 * ORDER MATTERS: every captured value is written to its cookie BEFORE the URL
 * is stripped. Stripping first would lose the attribution silently if a cookie
 * write threw. The replaceState runs once, after all saves.
 */
export function captureAttributionParams(config: AttributionConfig): boolean {
  if (typeof window === 'undefined' || typeof document === 'undefined' || !window.location) {
    return false;
  }

  const url = new URL(window.location.href);
  const params = url.searchParams;
  const mapping: Array<{ param: string; cookie: string; ttl: number; isValid?: (v: string) => boolean }> = [
    {
      param: config.urlParams.visitorToken,
      cookie: config.cookieNames.visitor,
      ttl: config.cookieTtlDays.visitor,
      isValid: (v) => VISITOR_TOKEN_PATTERN.test(v),
    },
    {
      param: config.urlParams.recId,
      cookie: config.cookieNames.recId,
      ttl: config.cookieTtlDays.recId,
      // A rec id that isn't a UUID is not one the engine will accept on the
      // order (PRO-1710) — never cookie it. The param is still stripped from
      // the URL below; it's the cookie write that is refused.
      isValid: (v) => REC_ID_PATTERN.test(v),
    },
    {
      param: config.urlParams.context,
      cookie: config.cookieNames.context,
      ttl: config.cookieTtlDays.context,
      isValid: (v) => CONTEXT_PATTERN.test(v),
    },
  ];

  let captured = false;
  let present = false;
  // 1) SAVE every present value to its cookie first.
  for (const { param, cookie, ttl, isValid } of mapping) {
    const value = params.get(param);
    if (value !== null && value !== '' && (isValid === undefined || isValid(value))) {
      writeCookie(cookie, value, ttl);
      captured = true;
    }
    if (params.has(param)) {
      params.delete(param);
      present = true;
    }
  }
  // 2) Only now strip the params from the visible URL.
  if (present) {
    const search = params.toString();
    const newUrl = url.pathname + (search ? '?' + search : '') + url.hash;
    window.history.replaceState(window.history.state, '', newUrl);
  }
  return captured;
}
