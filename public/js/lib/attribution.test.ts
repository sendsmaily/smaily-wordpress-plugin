import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { captureAttributionParams, type AttributionConfig } from './attribution';

/**
 * The capture as the ATTRIBUTION-ONLY bundle uses it (PRO-1767): no client, no
 * consent checker, no transport — the whole surface a browse-tracking-off store
 * loads. RecEngineClient's own tests drive the same code through
 * captureUrlParams, which is the point: one implementation, two callers.
 */

const REC_UUID = '11111111-2222-4333-8444-555555555555';

function makeConfig(): AttributionConfig {
  return {
    cookieNames: { visitor: 'smaily_rec_uid', recId: 'smaily_rec_id', context: 'smaily_rec_ctx' },
    urlParams: { visitorToken: 'smaily_vt', recId: 'smaily_rec', context: 'smaily_ctx' },
    cookieTtlDays: { visitor: 365, recId: 30, context: 30 },
  };
}

describe('captureAttributionParams (PRO-1767 attribution-only writer)', () => {
  beforeEach(() => {
    window.history.replaceState({}, '', '/');
  });

  afterEach(() => {
    window.history.replaceState({}, '', '/');
    for (const name of ['smaily_rec_uid', 'smaily_rec_id', 'smaily_rec_ctx']) {
      document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`;
    }
  });

  it('writes the three attribution cookies and strips the params', () => {
    window.history.replaceState({}, '', `/landing?smaily_vt=vt_1&smaily_rec=${REC_UUID}&smaily_ctx=welcome&keep=1`);

    expect(captureAttributionParams(makeConfig())).toBe(true);

    expect(document.cookie).toContain('smaily_rec_uid=vt_1');
    expect(document.cookie).toContain(`smaily_rec_id=${REC_UUID}`);
    expect(document.cookie).toContain('smaily_rec_ctx=welcome');
    expect(window.location.search).toBe('?keep=1');
  });

  it('refuses a non-uuid rec id (PRO-1710 holds for this bundle too)', () => {
    window.history.replaceState({}, '', '/landing?smaily_rec=junk-value&smaily_ctx=welcome');

    // A refused rec id writes no rec cookie, so the context is not set either.
    expect(captureAttributionParams(makeConfig())).toBe(false);

    expect(document.cookie).not.toContain('smaily_rec_id=');
    expect(document.cookie).not.toContain('smaily_rec_ctx=');
    expect(window.location.search).toBe('');
  });

  it('refuses an off-shape visitor token and context (PRO-1896 — the PHP twin refuses them)', () => {
    window.history.replaceState({}, '', '/landing?smaily_vt=not-a-token&smaily_ctx=has%20spaces');

    expect(captureAttributionParams(makeConfig())).toBe(false);

    expect(document.cookie).not.toContain('smaily_rec_uid=');
    expect(document.cookie).not.toContain('smaily_rec_ctx=');
    expect(window.location.search).toBe('');
  });

  it('refuses a store-created vs_ token in a link (PRO-3857 — a link carries the engine token only)', () => {
    window.history.replaceState({}, '', '/landing?smaily_vt=vs_0123456789ABCDEFabcdef');

    expect(captureAttributionParams(makeConfig())).toBe(false);

    expect(document.cookie).not.toContain('smaily_rec_uid=');
    expect(window.location.search).toBe('');
  });

  it('accepts a visitor token of exactly 64 characters in all', () => {
    const token = `vt_${'b'.repeat(61)}`;
    window.history.replaceState({}, '', `/landing?smaily_vt=${token}`);

    expect(captureAttributionParams(makeConfig())).toBe(true);

    expect(document.cookie).toContain(`smaily_rec_uid=${token}`);
  });

  it('refuses a visitor token of 65 characters in all and an oversized context', () => {
    const long = 'a'.repeat(65);
    window.history.replaceState({}, '', `/landing?smaily_vt=vt_${'b'.repeat(62)}&smaily_ctx=${long}`);

    expect(captureAttributionParams(makeConfig())).toBe(false);

    expect(document.cookie).not.toContain('smaily_rec_uid=');
    expect(document.cookie).not.toContain('smaily_rec_ctx=');
  });

  // Contract v1.9.0 context cookie rule: the rec id and the context always
  // describe the same landing — LandingCapture::resolve() is the PHP twin.
  it('clears the context cookie when a smaily_rec landing carries no smaily_ctx', () => {
    document.cookie = 'smaily_rec_ctx=storefront; path=/';
    window.history.replaceState({}, '', `/landing?smaily_rec=${REC_UUID}`);

    expect(captureAttributionParams(makeConfig())).toBe(true);

    expect(document.cookie).toContain(`smaily_rec_id=${REC_UUID}`);
    expect(document.cookie).not.toContain('smaily_rec_ctx=');
  });

  it('clears the context cookie when a smaily_rec landing carries an off-shape smaily_ctx', () => {
    document.cookie = 'smaily_rec_ctx=storefront; path=/';
    window.history.replaceState({}, '', `/landing?smaily_rec=${REC_UUID}&smaily_ctx=has%20spaces`);

    captureAttributionParams(makeConfig());

    expect(document.cookie).not.toContain('smaily_rec_ctx=');
  });

  it('keeps the context cookie when a link carries a context but no smaily_rec', () => {
    document.cookie = 'smaily_rec_ctx=storefront; path=/';
    window.history.replaceState({}, '', '/landing?smaily_vt=vt_1&smaily_ctx=welcome');

    captureAttributionParams(makeConfig());

    expect(document.cookie).toContain('smaily_rec_uid=vt_1');
    expect(document.cookie).toContain('smaily_rec_ctx=storefront');
    expect(window.location.search).toBe('');
  });

  it('keeps the context cookie when a refused smaily_rec carries a context', () => {
    document.cookie = 'smaily_rec_ctx=storefront; path=/';
    window.history.replaceState({}, '', '/landing?smaily_rec=junk-value&smaily_ctx=welcome');

    captureAttributionParams(makeConfig());

    expect(document.cookie).toContain('smaily_rec_ctx=storefront');
  });

  it('keeps the context cookie when the smaily_rec is refused', () => {
    document.cookie = 'smaily_rec_ctx=storefront; path=/';
    window.history.replaceState({}, '', '/landing?smaily_rec=junk-value');

    captureAttributionParams(makeConfig());

    expect(document.cookie).toContain('smaily_rec_ctx=storefront');
  });

  it('writes no session cookie — this bundle only does attribution', () => {
    window.history.replaceState({}, '', `/landing?smaily_rec=${REC_UUID}`);

    captureAttributionParams(makeConfig());

    expect(document.cookie).not.toContain('smaily_anon_sid');
  });

  it('does nothing on a page without campaign params', () => {
    window.history.replaceState({}, '', '/page?keep=1');

    expect(captureAttributionParams(makeConfig())).toBe(false);
    expect(window.location.search).toBe('?keep=1');
  });
});

/**
 * CON-250: with a `landUrl` the store's server sets the cookies — the capture
 * posts the checked values to the `/land` route and writes the cookies itself
 * only when that request fails (Safari caps a script-set cookie at 7 days).
 */
describe('captureAttributionParams with the store route (CON-250)', () => {
  const LAND_URL = '/wp-json/smaily-connect/v1/land';

  function landConfig(): AttributionConfig {
    return { ...makeConfig(), landUrl: LAND_URL };
  }

  function stubFetch(answer: () => Promise<Response>): ReturnType<typeof vi.fn> {
    const fetchMock = vi.fn(answer);
    vi.stubGlobal('fetch', fetchMock);
    return fetchMock;
  }

  function postedFields(fetchMock: ReturnType<typeof vi.fn>): Record<string, string> {
    const init = fetchMock.mock.calls[0]?.[1] as RequestInit;
    return Object.fromEntries(new URLSearchParams(init.body as URLSearchParams));
  }

  const flush = (): Promise<void> => new Promise((resolve) => setTimeout(resolve, 0));

  beforeEach(() => {
    window.history.replaceState({}, '', '/');
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    window.history.replaceState({}, '', '/');
    for (const name of ['smaily_rec_uid', 'smaily_rec_id', 'smaily_rec_ctx']) {
      document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`;
    }
  });

  it('posts the checked values to the store route and leaves the cookies to the server', async () => {
    const fetchMock = stubFetch(() => Promise.resolve(new Response(null, { status: 204 })));
    window.history.replaceState({}, '', `/landing?smaily_vt=vt_1&smaily_rec=${REC_UUID}&smaily_ctx=welcome&keep=1`);

    expect(captureAttributionParams(landConfig())).toBe(true);
    // The URL is stripped at once, before the answer.
    expect(window.location.search).toBe('?keep=1');
    await flush();

    expect(fetchMock).toHaveBeenCalledTimes(1);
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe(LAND_URL);
    expect(init.method).toBe('POST');
    expect(init.credentials).toBe('same-origin');
    expect(init.keepalive).toBe(true);
    expect(postedFields(fetchMock)).toEqual({ smaily_vt: 'vt_1', smaily_rec: REC_UUID, smaily_ctx: 'welcome' });
    // The store set them; the script wrote none of its own.
    expect(document.cookie).not.toContain('smaily_rec_uid=');
    expect(document.cookie).not.toContain('smaily_rec_id=');
  });

  it('writes the cookies itself when the store route answers with an error', async () => {
    stubFetch(() => Promise.resolve(new Response(null, { status: 429 })));
    window.history.replaceState({}, '', `/landing?smaily_vt=vt_1&smaily_rec=${REC_UUID}&smaily_ctx=welcome`);

    captureAttributionParams(landConfig());
    await flush();

    expect(document.cookie).toContain('smaily_rec_uid=vt_1');
    expect(document.cookie).toContain(`smaily_rec_id=${REC_UUID}`);
    expect(document.cookie).toContain('smaily_rec_ctx=welcome');
  });

  it('writes the cookies itself when the request does not get through', async () => {
    stubFetch(() => Promise.reject(new TypeError('Failed to fetch')));
    window.history.replaceState({}, '', `/landing?smaily_rec=${REC_UUID}`);

    captureAttributionParams(landConfig());
    await flush();

    expect(document.cookie).toContain(`smaily_rec_id=${REC_UUID}`);
  });

  it('posts a rec id without a context alone, and clears the context only when it writes itself', async () => {
    const fetchMock = stubFetch(() => Promise.resolve(new Response(null, { status: 500 })));
    document.cookie = 'smaily_rec_ctx=storefront; path=/';
    window.history.replaceState({}, '', `/landing?smaily_rec=${REC_UUID}&smaily_ctx=has%20spaces`);

    captureAttributionParams(landConfig());
    // The route applies the context cookie rule itself.
    expect(postedFields(fetchMock)).toEqual({ smaily_rec: REC_UUID });
    await flush();

    expect(document.cookie).not.toContain('smaily_rec_ctx=storefront');
  });

  it('posts under the route field names when the engine config renames the URL params', async () => {
    const fetchMock = stubFetch(() => Promise.resolve(new Response(null, { status: 204 })));
    const config: AttributionConfig = {
      ...landConfig(),
      urlParams: { visitorToken: 'vt', recId: 'rec', context: 'ctx' },
    };
    window.history.replaceState({}, '', `/landing?vt=vt_1&rec=${REC_UUID}&ctx=welcome`);

    captureAttributionParams(config);

    expect(postedFields(fetchMock)).toEqual({ smaily_vt: 'vt_1', smaily_rec: REC_UUID, smaily_ctx: 'welcome' });
  });

  it('posts nothing when no value has its shape', () => {
    const fetchMock = stubFetch(() => Promise.resolve(new Response(null, { status: 204 })));
    window.history.replaceState({}, '', '/landing?smaily_vt=vs_0123456789ABCDEFabcdef&smaily_rec=junk-value');

    expect(captureAttributionParams(landConfig())).toBe(false);

    expect(fetchMock).not.toHaveBeenCalled();
    expect(window.location.search).toBe('');
  });

  it('writes the cookies at once where there is no fetch', () => {
    vi.stubGlobal('fetch', undefined);
    window.history.replaceState({}, '', `/landing?smaily_rec=${REC_UUID}`);

    captureAttributionParams(landConfig());

    expect(document.cookie).toContain(`smaily_rec_id=${REC_UUID}`);
  });
});
