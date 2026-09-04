/**
 * Storefront beacon ENTRY. Vite builds this to dist/public/js/beacon.js, which
 * StorefrontBeacon enqueues as a classic script. It deliberately exports
 * nothing — all logic lives in (and is tested via) beacon-core.ts — so the
 * built bundle has no top-level export and loads outside a module context.
 */

import { init } from './beacon-core';

if (typeof document !== 'undefined' && window.smailyConnectBeacon !== undefined) {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      init();
    });
  } else {
    init();
  }
}
