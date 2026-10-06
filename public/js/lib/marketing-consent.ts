/**
 * The store's marketing-consent rule in the browser — the mirror of the PHP
 * `Smaily\Connect\Support\MarketingConsent` (PRO-3849, extends PRO-3845).
 *
 * Consent counts only as an explicit yes (Erkki, 2026-10-06; parity with the
 * Magento plugin's PRO-3664 decision). The WP Consent API's `wp_has_consent()`
 * answers true when no consent plugin has set a consent type (the API reads
 * that as "no consent management"), which is not a yes from the shopper. So
 * consent is given only when BOTH hold: a consent plugin has set a consent type
 * (a non-empty string), and `wp_has_consent()` is true for the category.
 * Everything else — no API, no type, no consent for the category, a value of
 * the wrong type, an API call that throws — is no consent.
 *
 * The consent type is read the way the WP Consent API's own `wp_has_consent()`
 * reads it: `window.wp_consent_type` (set by the consent banner) when defined,
 * else `window.wp_fallback_consent_type` (the server-side `wp_get_consent_type()`
 * the API prints into the page).
 */

declare global {
  interface Window {
    /** WP Consent API JS global (CookieYes / Complianz / Real Cookie Banner). */
    wp_has_consent?: (category: string) => boolean | undefined;
    /** Consent type a consent banner sets in the browser (`optin`, `optout`, …). */
    wp_consent_type?: unknown;
    /** Consent type the WP Consent API prints from `wp_get_consent_type()`. */
    wp_fallback_consent_type?: unknown;
  }
}

/** The WP Consent API event a banner fires when it sets the consent type late. */
export const CONSENT_TYPE_DEFINED_EVENT = 'wp_consent_type_defined';

/** The WP Consent API event fired when the visitor changes a consent category. */
export const CONSENT_CHANGE_EVENT = 'wp_listen_for_consent_change';

/** The rule itself, on the two WP Consent API answers. */
export function decide(consentType: unknown, hasConsent: unknown): boolean {
  return typeof consentType === 'string' && consentType !== '' && hasConsent === true;
}

/** Whether the current visitor gave an explicit yes for the category. */
export function marketingConsentGiven(category: string): boolean {
  if (typeof window.wp_has_consent !== 'function') {
    return false;
  }
  try {
    const consentType = typeof window.wp_consent_type !== 'undefined'
      ? window.wp_consent_type
      : window.wp_fallback_consent_type;
    return decide(consentType, window.wp_has_consent(category));
  } catch {
    return false;
  }
}
