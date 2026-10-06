/**
 * Storefront recommendations — the testable core of `sc-recs.js` (PRO-3835).
 *
 * The `smaily/recommendations` block and the `[smaily_recommendations]`
 * shortcode print an empty container that is the same for every visitor, so
 * the page asks the engine nothing and may stay in a full-page cache.
 * StorefrontRecommendations prints `window.smailyConnectRecs = { url, consent }`
 * just before this script. After the page has loaded, and only when the
 * shopper has given marketing consent — a consent banner stored the shopper's
 * yes in the WP Consent API's consent cookie for the category (the same rule
 * and category as the browse runtime, PRO-3849; no signal = no request) — this asks the
 * store's own route ONCE and puts the cards it answers into every container.
 * An empty answer, an error or a timeout leaves the containers empty — the
 * shopper never sees an error.
 *
 * The store decides who the shopper is from its own cookies (logged-in
 * account, else the visitor-token cookie); this sends nothing about the
 * shopper. The cards are built and escaped on the server.
 *
 * Exports functions only, for vitest; the entry (recs.ts) holds the boot.
 */

import {
  CONSENT_CHANGE_EVENT,
  CONSENT_TYPE_DEFINED_EVENT,
  marketingConsentGiven,
} from './lib/marketing-consent';

export interface RecsBoot {
  /** The store's GET route (RecommendationsEndpoint). */
  url: string;
  consent: { category: string };
}

declare global {
  interface Window {
    /** Boot blob printed by StorefrontRecommendations just before this script. */
    smailyConnectRecs?: RecsBoot;
  }
}

/** The containers the block and the shortcode print. */
export const SLOT_SELECTOR = '[data-smaily-connect-recs]';

/** Marketing consent by the store's consent rule — fail-closed without it. */
export function hasConsent(boot: RecsBoot): boolean {
  return marketingConsentGiven(boot.consent.category);
}

/** Ask the store and fill the containers; anything but cards shows nothing. */
export async function fill(boot: RecsBoot, slots: Element[]): Promise<void> {
  let html = '';
  try {
    const response = await fetch(boot.url, {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { Accept: 'application/json' },
    });
    if (!response.ok) {
      return;
    }
    const body: unknown = await response.json();
    if (typeof body === 'object' && body !== null && typeof (body as { html?: unknown }).html === 'string') {
      html = (body as { html: string }).html;
    }
  } catch {
    return;
  }
  if (html === '') {
    return;
  }
  for (const slot of slots) {
    slot.innerHTML = html;
  }
}

/**
 * Boot from `window.smailyConnectRecs`: ask now when consent is already
 * given, else when the shopper gives it or a banner sets the consent type
 * late (the WP Consent API's change and consent-type events).
 * Never more than once per page.
 */
export function init(): void {
  const boot = window.smailyConnectRecs;
  if (!boot || !boot.url) {
    return;
  }
  const slots = Array.from(document.querySelectorAll(SLOT_SELECTOR));
  if (slots.length === 0) {
    return;
  }

  let asked = false;
  const ask = (): void => {
    if (asked || !hasConsent(boot)) {
      return;
    }
    asked = true;
    void fill(boot, slots);
  };

  ask();
  document.addEventListener(CONSENT_CHANGE_EVENT, ask);
  document.addEventListener(CONSENT_TYPE_DEFINED_EVENT, ask);
}
