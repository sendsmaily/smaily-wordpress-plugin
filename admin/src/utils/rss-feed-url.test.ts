import { describe, expect, it } from 'vitest';

import { buildRssFeedUrl, type RssFeedUrlParams } from './rss-feed-url';

const BASE = 'https://shop.test/smaily-rss-feed';

const defaults: RssFeedUrlParams = {
  category: '',
  limit: '50',
  sortBy: 'modified',
  order: 'DESC',
  taxRate: '0',
};

describe('buildRssFeedUrl', () => {
  it('builds the default-prefill URL with limit, order_by, order and tax_rate', () => {
    expect(buildRssFeedUrl(BASE, defaults)).toBe(
      `${BASE}?limit=50&order_by=modified&order=DESC&tax_rate=0`,
    );
  });

  it('omits category when empty (All) and includes it when picked', () => {
    expect(buildRssFeedUrl(BASE, defaults)).not.toContain('category=');
    expect(buildRssFeedUrl(BASE, { ...defaults, category: 'hoodies' })).toContain(
      'category=hoodies',
    );
  });

  it('omits limit and tax_rate when their inputs are cleared', () => {
    const url = buildRssFeedUrl(BASE, { ...defaults, limit: '', taxRate: '' });
    expect(url).toBe(`${BASE}?order_by=modified&order=DESC`);
  });

  it("sortBy 'none' omits both order_by and order — legacy contract quirk", () => {
    const url = buildRssFeedUrl(BASE, { ...defaults, sortBy: 'none' });
    expect(url).not.toContain('order_by=');
    expect(url).not.toContain('order=');
  });

  it('appends to a base URL that already has a query string (permalinks off)', () => {
    const url = buildRssFeedUrl('https://shop.test/?smaily-rss-feed=true', defaults);
    expect(url).toBe(
      'https://shop.test/?smaily-rss-feed=true&limit=50&order_by=modified&order=DESC&tax_rate=0',
    );
  });

  it('URL-encodes category slugs that need it', () => {
    const url = buildRssFeedUrl(BASE, { ...defaults, category: 'tooted ja muu' });
    expect(url).toContain('category=tooted+ja+muu');
  });
});
