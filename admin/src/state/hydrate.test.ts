import { describe, expect, it } from 'vitest';

import { type BootPayload, hydrateState } from './hydrate';
import { DEFAULT_SYNC_FIELDS } from './types';

/**
 * A boot payload as the server really emits it (EnvDetector::snapshot() +
 * saved_settings()), with only the field under test varied per case.
 */
function boot(syncFields: unknown): BootPayload {
  return {
    buildHash: 'test',
    nonce: 'nonce',
    restUrl: 'http://example.test/wp-json/smaily-connect/v1',
    view: 'settings',
    envSnapshot: {
      detectedLanguages: ['en'],
      multilingualPlugin: null,
      elementorPresent: false,
      cf7Present: false,
      wcActive: true,
      hposActive: true,
      storeTotals: { customers: 0, orders: 0, products: 0 },
    },
    savedSettings: {
      smailyCredentials: { subdomain: 'demo', username: 'alice', password: '' },
      smailyConnected: true,
      setupCompleted: true,
      multilingualMode: 'single',
      defaultFallbackAccountKey: 'default',
      subscriberSyncEnabled: true,
      syncFields: syncFields as string[],
      wordpressSubscriptionCheckbox: false,
      checkoutSubscriptionCheckbox: false,
      contactSyncMode: 'consent',
      includeGuests: false,
      abandonedCartCutoffMinutes: 30,
      welcomeEnabled: false,
      firstOrderEnabled: false,
      abandonedCartEnabled: false,
    },
  };
}

describe('hydrateState — the subscriber-field ticks', () => {
  it('shows exactly the fields the server says are being synced', () => {
    const s = hydrateState(boot(['user_phone', 'birthday']), true);

    expect(s.syncFields).toEqual(['user_phone', 'birthday']);
  });

  it('treats an empty selection as an answer, not as "nothing saved"', () => {
    // A store upgraded from the legacy settings page with no optional field
    // ticked syncs none of them (PRO-1684) — showing every box ticked would
    // tell the merchant the opposite of what is happening.
    const s = hydrateState(boot([]), true);

    expect(s.syncFields).toEqual([]);
  });

  it('falls back to the defaults only when the server sent no usable list', () => {
    const s = hydrateState(boot(undefined), true);

    expect(s.syncFields).toEqual([...DEFAULT_SYNC_FIELDS]);
  });
});
