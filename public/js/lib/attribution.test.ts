import { afterEach, beforeEach, describe, expect, it } from 'vitest';
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

    expect(captureAttributionParams(makeConfig())).toBe(true); // the context WAS captured

    expect(document.cookie).not.toContain('smaily_rec_id=');
    expect(document.cookie).toContain('smaily_rec_ctx=welcome');
    expect(window.location.search).toBe('');
  });

  it('refuses an off-shape visitor token and context (PRO-1896 — the PHP twin refuses them)', () => {
    window.history.replaceState({}, '', '/landing?smaily_vt=not-a-token&smaily_ctx=has%20spaces');

    expect(captureAttributionParams(makeConfig())).toBe(false);

    expect(document.cookie).not.toContain('smaily_rec_uid=');
    expect(document.cookie).not.toContain('smaily_rec_ctx=');
    expect(window.location.search).toBe('');
  });

  it('refuses an oversized visitor token and context (>64 chars past the prefix)', () => {
    const long = 'a'.repeat(65);
    window.history.replaceState({}, '', `/landing?smaily_vt=vt_${long}&smaily_ctx=${long}`);

    expect(captureAttributionParams(makeConfig())).toBe(false);

    expect(document.cookie).not.toContain('smaily_rec_uid=');
    expect(document.cookie).not.toContain('smaily_rec_ctx=');
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
