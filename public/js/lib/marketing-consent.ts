/**
 * The store's marketing-consent rule in the browser — the mirror of the PHP
 * `Smaily\Connect\Support\MarketingConsent` (PRO-3849).
 *
 * Consent counts only as an explicit yes (Erkki, 2026-10-06; parity with the
 * Magento plugin's PRO-3664 decision). The WP Consent API's `wp_has_consent()`
 * answers true when no consent plugin has set a consent type and, in an
 * opt-out region, until the visitor opts out — neither is a yes from the
 * shopper. A yes is what a consent banner stores through the API's
 * `wp_set_consent()`: the consent cookie for the category
 * (`<consent_api.cookie_prefix>_<category>`, by default `wp_consent_marketing`)
 * holding `allow`. So consent is given only when BOTH hold: that cookie is
 * `allow`, and `wp_has_consent()` is true for the category. Everything else —
 * no API, no cookie, `deny`, a value of the wrong type, an API call that
 * throws — is no consent.
 */

declare global {
  interface Window {
    /** WP Consent API JS global (CookieYes / Complianz / Real Cookie Banner). */
    wp_has_consent?: (category: string) => boolean | undefined;
    /** The settings the WP Consent API prints for its script. */
    consent_api?: { cookie_prefix?: unknown };
  }
}

/** The WP Consent API event a banner fires when it sets the consent type late. */
export const CONSENT_TYPE_DEFINED_EVENT = 'wp_consent_type_defined';

/** The WP Consent API event fired when the visitor changes a consent category. */
export const CONSENT_CHANGE_EVENT = 'wp_listen_for_consent_change';

/** The value the WP Consent API stores for a yes. */
const ALLOW = 'allow';

/** The rule itself, on the two WP Consent API answers. */
export function decide(hasConsent: unknown, storedConsent: unknown): boolean {
  return hasConsent === true && storedConsent === ALLOW;
}

/**
 * The consent a banner stored for the category through the WP Consent API,
 * read from the API's consent cookie the way the API's own
 * `consent_api_get_cookie()` reads it; null when there is none.
 */
export function storedConsent(category: string): string | null {
  const prefix = window.consent_api?.cookie_prefix;
  if (typeof prefix !== 'string' || prefix === '') {
    return null;
  }
  const name = `${prefix}_${category}=`;
  for (const cookie of document.cookie.split(';')) {
    const trimmed = cookie.trim();
    if (trimmed.indexOf(name) === 0) {
      return trimmed.substring(name.length);
    }
  }
  return null;
}

/** Whether the current visitor gave an explicit yes for the category. */
export function marketingConsentGiven(category: string): boolean {
  if (typeof window.wp_has_consent !== 'function') {
    return false;
  }
  try {
    return decide(window.wp_has_consent(category), storedConsent(category));
  } catch {
    return false;
  }
}
