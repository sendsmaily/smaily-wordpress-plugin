/**
 * Attribution-capture ENTRY (PRO-1767). Vite builds this to
 * dist/public/js/sc-landing.js, which StorefrontBeacon enqueues as a classic
 * script whenever the engine is connected but the full browse runtime is NOT
 * loaded (browse tracking off) — the case where a full-page cache would
 * otherwise lose every campaign landing, because the cached response never
 * executes the server-side LandingCapture.
 *
 * It is deliberately the SMALLEST thing that writes the cookies: URL params in,
 * three first-party cookies out, params stripped. No event pipeline, no
 * transport, no consent surface, no session cookie — those live in the browse
 * runtime and stay behind the browse toggle + marketing consent.
 *
 * Exports nothing (all logic is in lib/attribution.ts, tested there), so the
 * built bundle has no top-level export and loads outside a module context.
 */

import { captureAttributionParams, type AttributionConfig } from './lib/attribution';

declare global {
  interface Window {
    /** Boot blob printed by StorefrontBeacon just before this script. */
    smailyConnectLanding?: AttributionConfig;
  }
}

if (typeof document !== 'undefined' && window.smailyConnectLanding !== undefined) {
  captureAttributionParams(window.smailyConnectLanding);
}
