import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { hasConsent, init, type RecsBoot } from './recs-core';

/**
 * PRO-3835: the storefront recommendations script asks the store only with
 * marketing consent (fail-closed), at most once per page, and shows nothing
 * unless the store answers with cards.
 */

const BOOT: RecsBoot = {
  url: '/wp-json/smaily-connect/v1/recommendations',
  consent: { category: 'marketing' },
};

const CARDS = '<section class="smaily-connect-recommendations">cards</section>';

function slot(): HTMLElement {
  const el = document.createElement('div');
  el.setAttribute('data-smaily-connect-recs', '');
  document.body.appendChild(el);
  return el;
}

function answer(body: unknown, ok = true): ReturnType<typeof vi.fn> {
  const fetchMock = vi.fn().mockResolvedValue({ ok, json: () => Promise.resolve(body) });
  vi.stubGlobal('fetch', fetchMock);
  return fetchMock;
}

/** Let the fetch → json → render promise chain settle. */
async function settle(): Promise<void> {
  for (let i = 0; i < 5; i++) {
    await Promise.resolve();
  }
}

describe('recs-core', () => {
  let listeners: ReturnType<typeof vi.spyOn>;

  beforeEach(() => {
    window.smailyConnectRecs = BOOT;
    listeners = vi.spyOn(document, 'addEventListener');
  });

  afterEach(() => {
    // Each init() listens for consent changes on the shared jsdom document;
    // drop them so one test's listener never fires in the next.
    for (const [type, listener] of listeners.mock.calls) {
      document.removeEventListener(type as string, listener as EventListener);
    }
    listeners.mockRestore();
    delete window.smailyConnectRecs;
    delete window.wp_has_consent;
    document.body.innerHTML = '';
    vi.unstubAllGlobals();
  });

  it('asks nothing without the WP Consent API (fail-closed)', async () => {
    const fetchMock = answer({ html: CARDS });
    slot();

    init();
    await settle();

    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('asks nothing when the shopper has not given marketing consent', async () => {
    const fetchMock = answer({ html: CARDS });
    window.wp_has_consent = vi.fn(() => false);
    slot();

    init();
    await settle();

    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('checks the configured consent category', () => {
    window.wp_has_consent = vi.fn((category: string) => category === 'statistics');

    expect(hasConsent({ ...BOOT, consent: { category: 'statistics' } })).toBe(true);
    expect(hasConsent(BOOT)).toBe(false);
  });

  it('with consent, asks the store once and shows the cards in every container', async () => {
    const fetchMock = answer({ html: CARDS });
    window.wp_has_consent = vi.fn(() => true);
    const first = slot();
    const second = slot();

    init();
    await settle();

    expect(fetchMock).toHaveBeenCalledTimes(1);
    const [url, options] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe(BOOT.url);
    expect(options.credentials).toBe('same-origin');
    expect(first.innerHTML).toBe(CARDS);
    expect(second.innerHTML).toBe(CARDS);
  });

  it('asks when consent is given later, and only once', async () => {
    const fetchMock = answer({ html: CARDS });
    let granted = false;
    window.wp_has_consent = vi.fn(() => granted);
    const el = slot();

    init();
    await settle();
    expect(fetchMock).not.toHaveBeenCalled();

    granted = true;
    document.dispatchEvent(new CustomEvent('wp_listen_for_consent_change'));
    document.dispatchEvent(new CustomEvent('wp_listen_for_consent_change'));
    await settle();

    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(el.innerHTML).toBe(CARDS);
  });

  it('asks nothing on a page without a container', async () => {
    const fetchMock = answer({ html: CARDS });
    window.wp_has_consent = vi.fn(() => true);

    init();
    await settle();

    expect(fetchMock).not.toHaveBeenCalled();
  });

  it.each([
    ['an empty answer', () => answer({ html: '' })],
    ['an error status', () => answer({ ok: false, error: 'rate_limited' }, false)],
    ['an unexpected body', () => answer({ cards: 1 })],
    ['a network error or timeout', () => {
      const fetchMock = vi.fn().mockRejectedValue(new TypeError('Failed to fetch'));
      vi.stubGlobal('fetch', fetchMock);
      return fetchMock;
    }],
  ])('shows nothing on %s', async (_label, stub) => {
    const fetchMock = stub();
    window.wp_has_consent = vi.fn(() => true);
    const el = slot();

    init();
    await settle();

    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(el.innerHTML).toBe('');
  });
});
