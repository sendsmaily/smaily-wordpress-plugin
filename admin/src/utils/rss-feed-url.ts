/**
 * Product RSS-feed URL builder — the client half of the Integrations
 * RSS section. Mirrors the legacy admin's live-update logic
 * (admin/js/smaily-admin.js `.smaily-rss-options` change handler) so a
 * URL built here is byte-compatible with what the old settings tab
 * produced; the pilot's existing template URLs and new ones must
 * behave identically.
 *
 * The feed endpoint (public/template/smaily-rss-feed.php via the legacy
 * Rss class) reads every parameter from the query string — building the
 * URL IS the whole configuration; nothing is persisted server-side.
 */

export interface RssFeedUrlParams {
  /** Product-category slug; '' (All) omits the param. */
  category: string;
  /** Raw input value; '' omits the param. */
  limit: string;
  /**
   * order_by field. The sentinel 'none' omits BOTH order_by and order —
   * a legacy contract quirk the feed endpoint relies on. The UI never
   * offers 'none' but the builder honours it for parity.
   */
  sortBy: string;
  /** ASC | DESC; only emitted alongside a real sortBy. */
  order: string;
  /** Raw input value; '' omits the param. */
  taxRate: string;
}

/**
 * Append feed params to the server-computed base URL. The base may
 * already carry a query string (`?smaily-rss-feed=true` on
 * non-permalink installs) — URL/searchParams handles that; never
 * string-concatenate onto it.
 */
export function buildRssFeedUrl(baseUrl: string, params: RssFeedUrlParams): string {
  const url = new URL(baseUrl);

  if (params.category !== '') {
    url.searchParams.set('category', params.category);
  }
  if (params.limit !== '') {
    url.searchParams.set('limit', params.limit);
  }
  if (params.sortBy !== 'none') {
    url.searchParams.set('order_by', params.sortBy);
    if (params.order !== '') {
      url.searchParams.set('order', params.order);
    }
  }
  if (params.taxRate !== '') {
    url.searchParams.set('tax_rate', params.taxRate);
  }

  return url.toString();
}
