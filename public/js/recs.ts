/**
 * Storefront recommendations ENTRY (PRO-3835). Vite builds this to
 * dist/public/js/sc-recs.js, which StorefrontRecommendations enqueues as a
 * classic script on a page that carries the recommendations block or
 * shortcode. It waits for the page's `load` event, so the request never
 * competes with the page itself. Exports nothing — all logic is in
 * recs-core.ts, tested there — so the built bundle has no top-level export.
 */

import { init } from './recs-core';

if (typeof document !== 'undefined' && window.smailyConnectRecs !== undefined) {
  if (document.readyState === 'complete') {
    init();
  } else {
    window.addEventListener('load', () => {
      init();
    }, { once: true });
  }
}
