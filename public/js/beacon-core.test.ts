import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { buildPageEvent, detectConsent, init, pageViewEvent } from './beacon-core';
import type { RecEngineClientConfig } from './lib/rec-engine-client';

/**
 * 3.4.3a: consent resolution, page-type → §6 event mapping, and init() wiring
 * (ensureSession + page-view track, gated on consent). captureUrlParams
 * (attribution-cookie capture) is consent-INDEPENDENT since PRO-1388 — it
 * runs unconditionally at init.
 */

const CONFIG: RecEngineClientConfig = {
  beaconUrl: '/wp-json/smaily-connect/v1/beacon',
  cookieNames: { visitor: 'smaily_rec_uid', session: 'smaily_anon_sid', recId: 'smaily_rec_id', context: 'smaily_rec_ctx' },
  urlParams: { visitorToken: 'smaily_vt', recId: 'smaily_rec', context: 'smaily_ctx' },
  cookieTtlDays: { visitor: 365, recId: 30, context: 30 },
  sessionTtlDays: 30,
};

interface BootBlob {
  config: RecEngineClientConfig;
  context: { pageType: string; sku?: string; categoryPath?: string; searchQuery?: string };
  consent: { category: string };
  consentOverride?: () => boolean;
}

function makeBoot(overrides: Partial<BootBlob> = {}): BootBlob {
  return {
    config: CONFIG,
    context: { pageType: 'product', sku: 'ACA-1', categoryPath: 'food/dry' },
    consent: { category: 'marketing' },
    ...overrides,
  };
}

function lastEvents(fetchMock: ReturnType<typeof vi.fn>): Array<Record<string, unknown>> {
  const calls = fetchMock.mock.calls;
  const call = calls[calls.length - 1];
  if (call === undefined) {
    throw new Error('fetch was not called');
  }
  const body = JSON.parse(((call[1] as RequestInit).body as string) ?? '{}');
  return body.events as Array<Record<string, unknown>>;
}

describe('beacon-core: detectConsent', () => {
  afterEach(() => {
    delete window.smailyConnectBeacon;
    delete window.wp_has_consent;
  });

  it('uses the site override when present', () => {
    const boot = makeBoot({ consentOverride: () => true });
    window.smailyConnectBeacon = boot;
    expect(detectConsent(boot)).toBe(true);
  });

  it('falls back to the WP Consent API', () => {
    const boot = makeBoot();
    window.smailyConnectBeacon = boot;
    window.wp_has_consent = vi.fn((category: string) => category === 'marketing');
    expect(detectConsent(boot)).toBe(true);
    expect(window.wp_has_consent).toHaveBeenCalledWith('marketing');
  });

  it('fails safe to DENY when no consent signal exists', () => {
    const boot = makeBoot();
    window.smailyConnectBeacon = boot;
    expect(detectConsent(boot)).toBe(false);
  });
});

describe('beacon-core: page-type mapping', () => {
  it('maps each storefront page type to its §6 event', () => {
    expect(pageViewEvent('product')).toBe('product_view');
    expect(pageViewEvent('category')).toBe('category_view');
    expect(pageViewEvent('search')).toBe('search');
    expect(pageViewEvent('checkout')).toBe('checkout_start');
    expect(pageViewEvent('order-received')).toBe('checkout_complete');
    expect(pageViewEvent('other')).toBeNull();
  });

  it('carries only the §6-relevant fields per event', () => {
    expect(buildPageEvent('product_view', { pageType: 'product', sku: 'A', categoryPath: 'food/dry' })).toEqual({
      event_type: 'product_view',
      category_path: 'food/dry',
      sku: 'A',
    });
    expect(buildPageEvent('category_view', { pageType: 'category', categoryPath: 'food', sku: 'IGNORED' })).toEqual({
      event_type: 'category_view',
      category_path: 'food',
    });
    expect(buildPageEvent('search', { pageType: 'search', searchQuery: 'dog' })).toEqual({
      event_type: 'search',
      search_query: 'dog',
    });
  });
});

describe('beacon-core: init', () => {
  let fetchMock: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    fetchMock = vi.fn((_input: RequestInfo | URL, _init?: RequestInit): Promise<Response> => Promise.resolve({ ok: true } as Response));
    vi.stubGlobal('fetch', fetchMock);
    window.history.replaceState({}, '', '/');
  });

  afterEach(() => {
    delete window.smailyConnectBeacon;
    delete window.wp_has_consent;
    vi.unstubAllGlobals();
    for (const n of ['smaily_rec_uid', 'smaily_anon_sid', 'smaily_rec_id', 'smaily_rec_ctx']) {
      document.cookie = `${n}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`;
    }
  });

  it('returns null when there is no boot blob', () => {
    expect(init()).toBeNull();
  });

  it('ensures a session and tracks the page-view when consent is granted', async () => {
    window.smailyConnectBeacon = makeBoot({ consentOverride: () => true });
    const client = init();
    expect(client).not.toBeNull();

    // Session cookie created.
    expect(document.cookie).toContain('smaily_anon_sid=');

    // The product_view was buffered; flushing sends it.
    await client?.flush();
    const events = lastEvents(fetchMock);
    expect(events).toHaveLength(1);
    expect(events[0]).toMatchObject({ event_type: 'product_view', sku: 'ACA-1', category_path: 'food/dry' });
    expect(events[0]?.session_id).not.toBe('');
  });

  it('captures campaign URL params on init', () => {
    window.history.replaceState({}, '', '/landing?smaily_vt=vt_9&keep=1');
    window.smailyConnectBeacon = makeBoot({ consentOverride: () => true, context: { pageType: 'other' } });

    init();

    expect(document.cookie).toContain('smaily_rec_uid=vt_9');
    expect(window.location.search).toBe('?keep=1');
  });

  it('captures campaign URL params on init even without consent (PRO-1388), but sends nothing', async () => {
    window.history.replaceState({}, '', '/landing?smaily_vt=vt_9&keep=1');
    window.smailyConnectBeacon = makeBoot({ consentOverride: () => false, context: { pageType: 'other' } });

    const client = init();

    // Attribution cookie written and URL stripped despite no consent.
    expect(document.cookie).toContain('smaily_rec_uid=vt_9');
    expect(window.location.search).toBe('?keep=1');
    // No session cookie, no send.
    expect(document.cookie).not.toContain('smaily_anon_sid');
    await client?.flush();
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('tracks nothing without consent', async () => {
    window.smailyConnectBeacon = makeBoot({ consentOverride: () => false });
    const client = init();

    expect(document.cookie).not.toContain('smaily_anon_sid');
    await client?.flush();
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('starts on a later consent-change event', async () => {
    let consent = false;
    window.smailyConnectBeacon = makeBoot({ consentOverride: () => consent });
    const client = init();

    // No consent yet → nothing buffered.
    await client?.flush();
    expect(fetchMock).not.toHaveBeenCalled();

    // Visitor accepts → the WP Consent API fires a native event.
    consent = true;
    document.dispatchEvent(new Event('wp_listen_for_consent_change'));

    await client?.flush();
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(lastEvents(fetchMock)[0]).toMatchObject({ event_type: 'product_view' });
  });

  it('a token captured pre-consent from the URL rides later events once consent is granted (PRO-1388)', async () => {
    window.history.replaceState({}, '', '/landing?smaily_vt=vt_preconsent');
    let consent = false;
    window.smailyConnectBeacon = makeBoot({ consentOverride: () => consent, context: { pageType: 'other' } });
    const client = init();

    // Captured immediately, before any consent — no send yet.
    expect(document.cookie).toContain('smaily_rec_uid=vt_preconsent');
    await client?.flush();
    expect(fetchMock).not.toHaveBeenCalled();

    // Consent granted later; a cart event now carries the pre-consent token.
    consent = true;
    document.dispatchEvent(new Event('wp_listen_for_consent_change'));
    client?.track({ event_type: 'cart_add', product_id: '1' });
    await client?.flush();
    expect(lastEvents(fetchMock)[0]).toMatchObject({ smaily_visitor_token: 'vt_preconsent' });
  });

});

describe('beacon-core: WC cart events (3.4.3b)', () => {
  let fetchMock: ReturnType<typeof vi.fn>;
  let handlers: Record<string, (...args: unknown[]) => void>;

  beforeEach(() => {
    fetchMock = vi.fn((_input: RequestInfo | URL, _init?: RequestInit): Promise<Response> => Promise.resolve({ ok: true } as Response));
    vi.stubGlobal('fetch', fetchMock);
    handlers = {};
    window.jQuery = ((_selector: unknown): { on: (e: string, h: (...a: unknown[]) => void) => void } => ({
      on: (event: string, handler: (...args: unknown[]) => void): void => {
        handlers[event] = handler;
      },
    })) as Window['jQuery'];
  });

  afterEach(() => {
    delete window.smailyConnectBeacon;
    delete window.jQuery;
    vi.unstubAllGlobals();
    document.cookie = 'smaily_anon_sid=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
  });

  /** A jQuery button stub carrying (or not) a data-product_id. */
  function button(productId: string | number | undefined): { data: (key: string) => unknown } {
    return { data: (key: string): unknown => (key === 'product_id' ? productId : undefined) };
  }

  /** Invoke a registered WC jQuery handler (asserting it exists). */
  function fire(event: string, ...args: unknown[]): void {
    const handler = handlers[event];
    if (handler === undefined) {
      throw new Error(`no handler registered for ${event}`);
    }
    handler(...args);
  }

  function startBeacon(): ReturnType<typeof init> {
    window.smailyConnectBeacon = makeBoot({ consentOverride: () => true, context: { pageType: 'other' } });
    return init();
  }

  it('tracks cart_add with the button product_id on added_to_cart (jQuery hands back a number)', async () => {
    const client = startBeacon();
    fire('added_to_cart', {}, {}, 'hash', button(42));

    await client?.flush();
    const events = lastEvents(fetchMock);
    expect(events).toHaveLength(1);
    expect(events[0]).toMatchObject({ event_type: 'cart_add', product_id: '42' });
    expect(events[0]).not.toHaveProperty('sku');
  });

  it('tracks cart_remove on removed_from_cart', async () => {
    const client = startBeacon();
    fire('removed_from_cart', {}, {}, 'hash', button('99'));

    await client?.flush();
    expect(lastEvents(fetchMock)[0]).toMatchObject({ event_type: 'cart_remove', product_id: '99' });
  });

  it('skips a cart event with no product_id (single-product form-POST gap)', async () => {
    const client = startBeacon();
    fire('added_to_cart', {}, {}, 'hash', button(undefined));

    await client?.flush();
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('does not attach cart listeners before consent', () => {
    window.smailyConnectBeacon = makeBoot({ consentOverride: () => false, context: { pageType: 'other' } });
    init();
    expect(handlers['added_to_cart']).toBeUndefined();
  });

  it('is a no-op when jQuery is absent', () => {
    delete window.jQuery;
    expect(() => startBeacon()).not.toThrow();
  });
});
