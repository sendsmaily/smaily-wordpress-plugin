import { apiRequest, ApiError } from './client';

/**
 * Plugin-side proxies for "Try Intelligence free" (contract §21,
 * REST\OnboardingEndpoint — CON-175). The server sends the Smaily login to
 * the engine; the browser only ever sees the one-time start link.
 */

export interface OnboardingStatus {
  /** The engine takes a start now and this store may make one. */
  start_open: boolean;
  /** The start window may open in a frame over this admin. */
  frame: boolean;
}

/** Is the start open? Any failure reads as closed. */
export async function getOnboardingStatus(): Promise<OnboardingStatus> {
  try {
    const status = await apiRequest<OnboardingStatus>('/rec-engine/onboarding/status');
    return { start_open: status.start_open === true, frame: status.frame === true };
  } catch {
    return { start_open: false, frame: false };
  }
}

export interface OnboardingStartLink {
  ok: true;
  /** One-time link, valid 5 minutes — open it at once, never keep or log it. */
  start_url: string;
  display: 'frame' | 'window';
  /** The engine's origin: the only origin whose messages count. */
  engine_origin: string;
}

/**
 * Error codes the route answers: `smaily_login_refused`,
 * `smaily_paid_package_required`, `not_found`, `store_exists` (+
 * `console_url`), `frame_origin_not_allowed`, `rate_limited`, `unavailable`,
 * `start_failed`, `already_connected` — and `network` when no answer came.
 */
export interface OnboardingStartRefusal {
  ok: false;
  error: string;
  console_url?: string;
}

export type OnboardingStartResponse = OnboardingStartLink | OnboardingStartRefusal;

export async function startOnboarding(display: 'frame' | 'window'): Promise<OnboardingStartResponse> {
  try {
    const link = await apiRequest<Omit<OnboardingStartLink, 'ok'>>('/rec-engine/onboarding/start', {
      method: 'POST',
      body: { display },
    });
    return { ok: true, ...link };
  } catch (err) {
    const body = err instanceof ApiError ? (err.body as { error?: unknown; console_url?: unknown } | null) : null;
    return {
      ok: false,
      error: typeof body?.error === 'string' ? body.error : 'network',
      ...(typeof body?.console_url === 'string' ? { console_url: body.console_url } : {}),
    };
  }
}
