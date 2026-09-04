import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { RecEngineClient, type RecEngineClientConfig } from './rec-engine-client';

/**
 * 3.4.1: buffering, the 30s batch window, consent-gated flushing, the
 * sendBeacon unload path, and destroy() teardown. captureUrlParams (3.4.2)
 * and mergeIdentity (3.7) still throw and are asserted as such.
 */

const BEACON_URL = '/wp-json/smaily-connect/v1/beacon';

function makeConfig(overrides: Partial<RecEngineClientConfig> = {}): RecEngineClientConfig {
  return {
    beaconUrl: BEACON_URL,
    cookieNames: { visitor: 'smaily_rec_uid', session: 'smaily_anon_sid', recId: 'smaily_rec_id', context: 'smaily_rec_ctx' },
    consentChecker: () => true,
    ...overrides,
  };
}

function makeFetchMock() {
  return vi.fn(
    (_input: RequestInfo | URL, _init?: RequestInit): Promise<Response> =>
      Promise.resolve({ ok: true } as Response),
  );
}

function lastFetchBody(fetchMock: ReturnType<typeof makeFetchMock>): { events: Array<Record<string, unknown>> } {
  const calls = fetchMock.mock.calls;
  const call = calls[calls.length - 1];
  if (call === undefined) {
    throw new Error('fetch was not called');
  }
  return JSON.parse((call[1]?.body as string) ?? '{}');
}

describe('RecEngineClient (3.4.1 transport)', () => {
  let fetchMock: ReturnType<typeof makeFetchMock>;
  let client: RecEngineClient | null = null;

  beforeEach(() => {
    vi.useFakeTimers();
    fetchMock = makeFetchMock();
    vi.stubGlobal('fetch', fetchMock);
  });

  afterEach(() => {
    client?.destroy();
    client = null;
    vi.useRealTimers();
    vi.unstubAllGlobals();
    for (const name of ['smaily_anon_sid', 'smaily_rec_uid', 'smaily_rec_id', 'smaily_rec_ctx']) {
      document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT`;
    }
  });

  it('buffers track() and does not POST before the batch window', () => {
    client = new RecEngineClient(makeConfig());
    client.track({ event_type: 'product_view', sku: 'ACA-1' });
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('auto-flushes the buffer after the 30s window', async () => {
    client = new RecEngineClient(makeConfig());
    client.track({ event_type: 'product_view', sku: 'ACA-1' });

    await vi.advanceTimersByTimeAsync(30_000);

    expect(fetchMock).toHaveBeenCalledTimes(1);
    const call = fetchMock.mock.calls[0];
    expect(call?.[0]).toBe(BEACON_URL);
    expect(call?.[1]?.method).toBe('POST');
    const body = lastFetchBody(fetchMock);
    expect(body.events).toHaveLength(1);
    expect(body.events[0]).toMatchObject({ event_type: 'product_view', sku: 'ACA-1', source: 'plugin_woo' });
  });

  it('batches multiple events in one window into a single POST', async () => {
    client = new RecEngineClient(makeConfig());
    client.track({ event_type: 'product_view', sku: 'A' });
    client.track({ event_type: 'cart_add', sku: 'A' });
    client.track({ event_type: 'category_view', category_path: 'food/dry' });

    await vi.advanceTimersByTimeAsync(30_000);

    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(lastFetchBody(fetchMock).events).toHaveLength(3);
  });

  it('enriches each event with a unique event_id and an ISO-Z event_ts', async () => {
    client = new RecEngineClient(makeConfig());
    client.track({ event_type: 'product_view', sku: 'A' });
    client.track({ event_type: 'product_view', sku: 'B' });

    await client.flush();

    const [a, b] = lastFetchBody(fetchMock).events;
    if (a === undefined || b === undefined) {
      throw new Error('expected two enriched events');
    }
    expect(a.event_id).not.toBe(b.event_id);
    expect(a.event_id).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
    expect(a.event_ts).toMatch(/Z$/);
  });

  it('flush() sends immediately and empties the buffer', async () => {
    client = new RecEngineClient(makeConfig());
    client.track({ event_type: 'search', search_query: 'dog food' });

    await client.flush();
    expect(fetchMock).toHaveBeenCalledTimes(1);

    // Buffer drained — a second flush with nothing queued is a no-op.
    await client.flush();
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it('sends checkout_complete immediately instead of waiting for the window (PRO-1878)', () => {
    client = new RecEngineClient(makeConfig());
    client.track({ event_type: 'checkout_complete' });

    expect(fetchMock).toHaveBeenCalledTimes(1);
    const body = lastFetchBody(fetchMock);
    expect(body.events).toHaveLength(1);
    expect(body.events[0]).toMatchObject({ event_type: 'checkout_complete' });
  });

  it('takes the whole buffer with the immediate checkout_complete flush', () => {
    client = new RecEngineClient(makeConfig());
    client.track({ event_type: 'product_view', sku: 'A' });
    expect(fetchMock).not.toHaveBeenCalled();

    client.track({ event_type: 'checkout_complete' });

    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(lastFetchBody(fetchMock).events).toHaveLength(2);
  });

  it('does not re-send the immediately-flushed checkout_complete on pagehide or the timer', async () => {
    const beacon = vi.fn((_url: string, _data?: BodyInit): boolean => true);
    Object.defineProperty(navigator, 'sendBeacon', { value: beacon, configurable: true });

    client = new RecEngineClient(makeConfig());
    client.track({ event_type: 'checkout_complete' });
    window.dispatchEvent(new Event('pagehide'));

    expect(beacon).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(60_000);
    expect(fetchMock).toHaveBeenCalledTimes(1);

    // @ts-expect-error cleanup
    delete navigator.sendBeacon;
  });

  it('still buffers checkout_start for the 30s window (only checkout_complete is immediate)', async () => {
    client = new RecEngineClient(makeConfig());
    client.track({ event_type: 'checkout_start' });

    await vi.advanceTimersByTimeAsync(29_000);
    expect(fetchMock).not.toHaveBeenCalled();

    await vi.advanceTimersByTimeAsync(1_000);
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it('drops a checkout_complete without consent, exactly like any other event', () => {
    client = new RecEngineClient(makeConfig({ consentChecker: () => false }));
    client.track({ event_type: 'checkout_complete' });

    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('drops the buffer and never POSTs when consent is absent', async () => {
    client = new RecEngineClient(makeConfig({ consentChecker: () => false }));
    client.track({ event_type: 'product_view', sku: 'A' });

    await client.flush();
    expect(fetchMock).not.toHaveBeenCalled();

    // The dropped buffer must not resurface if consent is later granted.
    await client.flush();
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('treats a missing consentChecker as no consent', async () => {
    client = new RecEngineClient(makeConfig({ consentChecker: undefined }));
    client.track({ event_type: 'product_view', sku: 'A' });

    await client.flush();
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('reads session_id from the anon-session cookie when present', async () => {
    document.cookie = 'smaily_anon_sid=sess-xyz';
    client = new RecEngineClient(makeConfig());
    client.track({ event_type: 'product_view', sku: 'A' });

    await client.flush();
    expect(lastFetchBody(fetchMock).events[0]?.session_id).toBe('sess-xyz');
  });

  it('carries smaily_visitor_token from the visitor cookie when present (identity, not attribution — F3-49)', async () => {
    document.cookie = 'smaily_rec_uid=vt-abc';
    client = new RecEngineClient(makeConfig());
    client.track({ event_type: 'product_view', sku: 'A' });

    await client.flush();
    expect(lastFetchBody(fetchMock).events[0]?.smaily_visitor_token).toBe('vt-abc');
  });

  it('omits smaily_visitor_token when the visitor cookie is absent (never sent empty)', async () => {
    client = new RecEngineClient(makeConfig());
    client.track({ event_type: 'product_view', sku: 'A' });

    await client.flush();
    const event = lastFetchBody(fetchMock).events[0];
    expect(event).toBeDefined();
    expect(event).not.toHaveProperty('smaily_visitor_token');
  });

  it('carries product_id through to the wire when present (cart events — sku resolved server-side, PRO-1390)', async () => {
    client = new RecEngineClient(makeConfig());
    client.track({ event_type: 'cart_add', product_id: '42' });

    await client.flush();
    const event = lastFetchBody(fetchMock).events[0];
    expect(event).toBeDefined();
    expect(event?.product_id).toBe('42');
    expect(event).not.toHaveProperty('sku');
  });

  it('never puts rec_id / ctx / email on a browse event (data-minimization — attribution rides orders)', async () => {
    document.cookie = 'smaily_rec_uid=vt-abc';
    document.cookie = 'smaily_rec_id=rec-1';
    document.cookie = 'smaily_rec_ctx=welcome';
    client = new RecEngineClient(makeConfig({ customerEmail: 'buyer@example.com' }));
    client.track({ event_type: 'product_view', sku: 'A' });

    await client.flush();
    const event = lastFetchBody(fetchMock).events[0];
    expect(event).toBeDefined();
    // The ONE identity field we send.
    expect(event?.smaily_visitor_token).toBe('vt-abc');
    // Deliberately excluded from browse events.
    expect(event).not.toHaveProperty('smaily_rec_id');
    expect(event).not.toHaveProperty('smaily_ctx');
    expect(event).not.toHaveProperty('customer_email');
  });

  it('flushes the buffer via sendBeacon on pagehide', () => {
    const beacon = vi.fn((_url: string, _data?: BodyInit): boolean => true);
    Object.defineProperty(navigator, 'sendBeacon', { value: beacon, configurable: true });

    client = new RecEngineClient(makeConfig());
    client.track({ event_type: 'checkout_start' });

    window.dispatchEvent(new Event('pagehide'));

    expect(beacon).toHaveBeenCalledTimes(1);
    const call = beacon.mock.calls[0];
    expect(call).toBeDefined();
    expect(call?.[0]).toBe(BEACON_URL);
    expect(call?.[1]).toBeInstanceOf(Blob);
    // fetch must NOT be used on the unload path.
    expect(fetchMock).not.toHaveBeenCalled();

    // @ts-expect-error cleanup
    delete navigator.sendBeacon;
  });

  it('destroy() cancels a pending flush, detaches the listener, and drops the buffer', async () => {
    client = new RecEngineClient(makeConfig());
    client.track({ event_type: 'product_view', sku: 'A' });

    client.destroy();

    // Pending 30s flush must not fire.
    await vi.advanceTimersByTimeAsync(60_000);
    expect(fetchMock).not.toHaveBeenCalled();

    // track() after destroy is a no-op.
    client.track({ event_type: 'product_view', sku: 'B' });
    await client.flush();
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('mergeIdentity still throws (lands in 3.7)', async () => {
    client = new RecEngineClient(makeConfig());
    await expect(client.mergeIdentity('a@b.test', 'user_logged_in')).rejects.toThrow(/3\.7/);
  });
});

describe('RecEngineClient (3.4.2 cookies + URL-param capture)', () => {
  let client: RecEngineClient | null = null;

  beforeEach(() => {
    window.history.replaceState({}, '', '/');
  });

  afterEach(() => {
    client?.destroy();
    client = null;
    window.history.replaceState({}, '', '/');
    for (const name of ['smaily_rec_uid', 'smaily_anon_sid', 'smaily_rec_id', 'smaily_rec_ctx']) {
      document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`;
    }
  });

  const REC_UUID = '11111111-2222-4333-8444-555555555555';

  it('captures campaign params into cookies and strips them from the URL', () => {
    window.history.replaceState(
      {},
      '',
      `/landing?smaily_vt=vt_1&smaily_rec=${REC_UUID}&smaily_ctx=welcome&keep=1`
    );
    client = new RecEngineClient(makeConfig());

    const captured = client.captureUrlParams();

    expect(captured).toBe(true);
    expect(document.cookie).toContain('smaily_rec_uid=vt_1');
    expect(document.cookie).toContain(`smaily_rec_id=${REC_UUID}`);
    expect(document.cookie).toContain('smaily_rec_ctx=welcome');
    // Campaign params stripped; unrelated params kept.
    expect(window.location.search).toBe('?keep=1');
  });

  it('never cookies a non-uuid rec id, but still strips it (PRO-1710)', () => {
    // The engine validates smaily_rec_id as a uuid per ORDER (D6) — cookieing
    // a junk value here would ride the shopper's order and reject it
    // permanently. Same rule as the PHP LandingCapture (shared cookie).
    window.history.replaceState({}, '', '/landing?smaily_rec=junk-value&smaily_ctx=welcome');
    client = new RecEngineClient(makeConfig());

    const captured = client.captureUrlParams();

    expect(captured).toBe(true); // the context WAS captured
    expect(document.cookie).not.toContain('smaily_rec_id=');
    expect(document.cookie).toContain('smaily_rec_ctx=welcome');
    expect(window.location.search).toBe('');
  });

  it('saves the cookie BEFORE stripping the URL (attribution must not be lost)', () => {
    window.history.replaceState({}, '', '/landing?smaily_vt=vt_order');
    client = new RecEngineClient(makeConfig());

    let cookieAtStripTime = '';
    const realReplace = window.history.replaceState.bind(window.history);
    const spy = vi
      .spyOn(window.history, 'replaceState')
      .mockImplementation((data: unknown, unused: string, url?: string | URL | null) => {
        cookieAtStripTime = document.cookie;
        realReplace(data, unused, url ?? null);
      });

    client.captureUrlParams();

    // At the moment the URL was stripped, the cookie was already written.
    expect(cookieAtStripTime).toContain('smaily_rec_uid=vt_order');
    spy.mockRestore();
  });

  it('does nothing when there are no campaign params', () => {
    window.history.replaceState({}, '', '/page?keep=1');
    client = new RecEngineClient(makeConfig());

    expect(client.captureUrlParams()).toBe(false);
    expect(window.location.search).toBe('?keep=1');
  });

  it('captures and strips even without consent (PRO-1388: attribution is consent-independent)', () => {
    window.history.replaceState({}, '', '/landing?smaily_vt=vt_1');
    client = new RecEngineClient(makeConfig({ consentChecker: () => false }));

    expect(client.captureUrlParams()).toBe(true);
    expect(document.cookie).toContain('smaily_rec_uid=vt_1');
    expect(window.location.search).toBe('');
  });

  it('captureUrlParams without consent writes no session cookie and sends nothing', async () => {
    const fetchMock = makeFetchMock();
    vi.stubGlobal('fetch', fetchMock);
    window.history.replaceState({}, '', '/landing?smaily_vt=vt_1');
    client = new RecEngineClient(makeConfig({ consentChecker: () => false }));

    client.captureUrlParams();

    expect(document.cookie).not.toContain('smaily_anon_sid');
    client.track({ event_type: 'product_view', sku: 'A' });
    await client.flush();
    expect(fetchMock).not.toHaveBeenCalled();
    vi.unstubAllGlobals();
  });

  it('ensureSession generates a v4 session cookie when absent and reuses it', () => {
    client = new RecEngineClient(makeConfig());

    const sid = client.ensureSession();
    expect(sid).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
    expect(document.cookie).toContain('smaily_anon_sid=' + sid);

    // Second call reuses the existing cookie.
    expect(client.ensureSession()).toBe(sid);
  });

  it('ensureSession sets no cookie without consent', () => {
    client = new RecEngineClient(makeConfig({ consentChecker: () => false }));
    expect(client.ensureSession()).toBe('');
    expect(document.cookie).not.toContain('smaily_anon_sid');
  });

  it('writes cookies with SameSite=Lax and a Max-Age TTL', () => {
    const writes: string[] = [];
    const proto = Object.getPrototypeOf(document) as object;
    const desc = Object.getOwnPropertyDescriptor(proto, 'cookie');
    Object.defineProperty(document, 'cookie', {
      configurable: true,
      get(): string {
        return desc?.get?.call(document) ?? '';
      },
      set(v: string): void {
        writes.push(v);
        desc?.set?.call(document, v);
      },
    });

    client = new RecEngineClient(makeConfig());
    client.ensureSession();

    expect(writes.some((w) => /SameSite=Lax/.test(w))).toBe(true);
    expect(writes.some((w) => /Max-Age=\d+/.test(w))).toBe(true);

    delete (document as unknown as { cookie?: unknown }).cookie;
  });
});
