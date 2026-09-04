/**
 * Tiny REST client used by every admin/src/api/* wrapper.
 *
 * Configuration arrives at runtime from the PHP mount (sub-PR 2.H):
 * admin/settings.php and admin/wizard.php emit a script-localised
 * object with the REST namespace URL + the wp_rest nonce. The mount
 * code (admin/src/index.tsx) calls configureApiClient before rendering
 * <App>, so by the time hooks fire the client is ready.
 *
 * In unit / integration tests configureApiClient is called from the
 * test setup with a stub. Calling apiRequest before configuration
 * throws — fails loud rather than silently posting to undefined.
 */

export interface ApiClientConfig {
  /** Base URL, e.g. "https://shop.example/wp-json/smaily-connect/v1". */
  restUrl: string;
  /** wp_create_nonce('wp_rest') value — sent as X-WP-Nonce header. */
  nonce: string;
}

let _config: ApiClientConfig | null = null;

export function configureApiClient(config: ApiClientConfig): void {
  _config = {
    restUrl: config.restUrl.replace(/\/$/, ''),
    nonce: config.nonce,
  };
}

/** Test-only escape hatch — production code never calls this. */
export function _resetApiClient(): void {
  _config = null;
}

export class ApiError extends Error {
  public status: number;
  public body: unknown;
  public constructor(message: string, status: number, body: unknown) {
    super(message);
    this.status = status;
    this.body = body;
    this.name = 'ApiError';
  }
}

interface ApiRequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'DELETE';
  body?: unknown;
  signal?: AbortSignal;
}

export async function apiRequest<T>(path: string, options: ApiRequestOptions = {}): Promise<T> {
  if (_config === null) {
    throw new Error('apiRequest called before configureApiClient — wire it up in admin/src/index.tsx');
  }

  const method = options.method ?? 'GET';
  const init: RequestInit = {
    method,
    headers: {
      Accept: 'application/json',
      'X-WP-Nonce': _config.nonce,
      ...(options.body !== undefined ? { 'Content-Type': 'application/json' } : {}),
    },
    signal: options.signal,
  };
  if (options.body !== undefined) {
    init.body = JSON.stringify(options.body);
  }

  const url = _config.restUrl + (path.startsWith('/') ? path : `/${path}`);
  const response = await fetch(url, init);

  if (!response.ok) {
    let body: unknown = null;
    try {
      body = await response.json();
    } catch {
      // Server returned non-JSON (HTML error page) — leave body as null.
    }
    throw new ApiError(
      `${method} ${path} → ${response.status}`,
      response.status,
      body,
    );
  }

  // Treat empty bodies (204 No Content) as undefined.
  if (response.status === 204) {
    return undefined as T;
  }

  return response.json() as Promise<T>;
}
