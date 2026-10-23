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

describe('hydrateState — the consent API flag (PRO-3673)', () => {
  it('carries the server-detected consent API into the env', () => {
    const payload = boot(['first_name']);
    payload.envSnapshot.consentApiPresent = true;

    expect(hydrateState(payload, true).env.consentApiPresent).toBe(true);
  });

  it('reads an absent flag as no consent API', () => {
    expect(hydrateState(boot(['first_name']), true).env.consentApiPresent).toBe(false);
  });
});

describe('hydrateState — the confirmation cards\' send status (PRO-4091)', () => {
  it('carries the server-computed status of each confirmation', () => {
    const payload = boot(['first_name']);
    payload.savedSettings.transactionalStatus = {
      shipping_confirmation: {
        reason: 'no_workflow',
        workflowResolved: false,
        lastSentAt: '',
        untickedRecentStatuses: [],
      },
    };

    expect(hydrateState(payload, true).transactionalStatus).toEqual({
      shipping_confirmation: { reason: 'no_workflow', workflowResolved: false, lastSentAt: '', untickedRecentStatuses: [] },
    });
  });

  it('reads an absent status as nothing to show', () => {
    expect(hydrateState(boot(['first_name']), true).transactionalStatus).toEqual({});
  });
});

describe('hydrateState — the automations\' discount codes (CON-199)', () => {
  it('carries the saved settings, the usable coupons and the add-coupon link', () => {
    const payload = boot(['first_name']);
    payload.envSnapshot.discountTemplates = [{ id: 12, label: 'WELCOME15 — Percentage discount: 15' }];
    payload.envSnapshot.discountValidDays = { min: 1, max: 365, default: 7 };
    payload.envSnapshot.newCouponUrl = 'https://shop.example.test/wp-admin/post-new.php?post_type=shop_coupon';
    payload.savedSettings.automationDiscounts = {
      welcome: { enabled: true, templateId: 12, validDays: 14 },
      first_order: { enabled: false, templateId: 0, validDays: 7 },
      abandoned_cart: { enabled: true, templateId: 0, validDays: 3 },
    };

    const s = hydrateState(payload, true);

    expect(s.automationDiscounts.welcome).toEqual({ enabled: true, templateId: 12, validDays: 14 });
    expect(s.automationDiscounts.abandoned_cart).toEqual({ enabled: true, templateId: 0, validDays: 3 });
    expect(s.env.discountTemplates).toEqual([{ id: 12, label: 'WELCOME15 — Percentage discount: 15' }]);
    expect(s.env.newCouponUrl).toBe('https://shop.example.test/wp-admin/post-new.php?post_type=shop_coupon');
  });

  it('reads absent settings as off with seven days', () => {
    expect(hydrateState(boot(['first_name']), true).automationDiscounts.first_order).toEqual({
      enabled: false,
      templateId: 0,
      validDays: 7,
    });
  });
});
