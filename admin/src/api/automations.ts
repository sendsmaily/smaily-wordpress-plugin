import { __, sprintf } from '@admin/lib/i18n';

import {
  type EngineAutomationRow,
  type EngineAutomationsIssue,
} from '../state/types';
import { apiRequest, ApiError } from './client';

/**
 * Plugin-side proxies for the engine's automations-config API
 * (contract §11–§13, REST\AutomationsEndpoint — T2.1). The engine is
 * the single source of truth: the UI re-reads catalog + config via
 * these GETs on every open and PUTs the full selection back; nothing
 * is cached locally (DECISIONS F3-51).
 *
 * All wire shapes are snake_case — exactly the §11–§13 field names.
 * Do NOT camelCase them here (scar 3.5.3a: a camelCased mock passed
 * vitest and failed live).
 */

/**
 * One §11 catalog entry. `recipe_en` is forward-compatible (T2.4/5):
 * the engine deploy that adds it is pending, so the UI must render with
 * or without it — non-Estonian locales use it when present, else
 * `recipe_et`. Do NOT make it required until the contract sync lands.
 */
export interface AutomationCatalogTrigger {
  key: string;
  name_et: string;
  name_en: string;
  description_et: string;
  description_en: string;
  recipe_et: string;
  recipe_en?: string;
}

export interface AutomationsCatalogResponse {
  /** Sector-filtered, may grow/shrink per engine deploy — render dynamically. */
  triggers: AutomationCatalogTrigger[];
  /** Closed set of valid language_mode values (currently single/per_language). */
  language_modes: string[];
  /** Merchant help page URL — link to THIS, never a hardcoded URL. */
  docs: string;
}

/**
 * §12 config row = the eight §13 fields plus two engine-stamped
 * read-only fields. The read-only pair is display-only and MUST NOT be
 * round-tripped into the PUT body.
 */
export interface AutomationConfigServerRow extends EngineAutomationRow {
  configured_via?: 'plugin' | 'admin';
  updated_at?: string;
}

export interface AutomationsConfigResponse {
  configs: AutomationConfigServerRow[];
}

/**
 * Non-validation failure surface shared by all three proxy routes:
 *   not_connected  → proxy 503 (not_configured / configuration_incomplete)
 *   key_rejected   → proxy 502 api_key_rejected (engine 401'd the stored key)
 *   error          → any other 502 / network / non-JSON failure
 */
export type AutomationsFailureKind = 'not_connected' | 'key_rejected' | 'error';

export interface AutomationsFailure {
  ok: false;
  kind: AutomationsFailureKind;
  /** Human-readable summary — safe to show as THE banner text. */
  message: string;
  /**
   * The raw technical error (proxy body message or `ApiError.message`,
   * e.g. "GET /… → 502") for the generic `error` kind — rendered as a
   * smaller detail line, never as the headline (T2.4/4).
   */
  detail?: string;
}

export type PutAutomationsResult =
  | { ok: true; upserted: number }
  | { ok: false; kind: 'validation_failed'; errors: EngineAutomationsIssue[] }
  | AutomationsFailure;

/** GET catalog — throws ApiError; map it with classifyAutomationsFailure. */
export function getAutomationsCatalog(
  signal?: AbortSignal,
): Promise<AutomationsCatalogResponse> {
  return apiRequest<AutomationsCatalogResponse>('/rec-engine/automations/catalog', { signal });
}

/** GET config — throws ApiError; map it with classifyAutomationsFailure. */
export function getAutomationsConfig(
  signal?: AbortSignal,
): Promise<AutomationsConfigResponse> {
  return apiRequest<AutomationsConfigResponse>('/rec-engine/automations/config', { signal });
}

/**
 * PUT the full selection. §13 is ALL-OR-NOTHING: a validation_failed
 * result means NOTHING was saved (not even the valid rows) — the whole
 * corrected selection must be resubmitted. `errors[].index` refers to
 * positions in the `configs` array as sent here.
 */
export async function putAutomationsConfig(
  configs: EngineAutomationRow[],
  signal?: AbortSignal,
): Promise<PutAutomationsResult> {
  try {
    const response = await apiRequest<{ ok: boolean; upserted: number }>(
      '/rec-engine/automations/config',
      { method: 'PUT', body: { configs }, signal },
    );
    return { ok: true, upserted: response.upserted };
  } catch (err) {
    if (err instanceof ApiError && err.status === 422) {
      const body = err.body as { errors?: EngineAutomationsIssue[] } | null;
      return {
        ok: false,
        kind: 'validation_failed',
        errors: Array.isArray(body?.errors) ? body.errors : [],
      };
    }
    return classifyAutomationsFailure(err);
  }
}

/**
 * Map a thrown fetch error onto the shared failure union. Recognises
 * the proxy's typed bodies (503 not_configured / configuration_incomplete,
 * 502 api_key_rejected); anything else is a generic retryable error.
 *
 * The generic kind carries a HUMAN `message` (what happened + where to
 * go) and keeps the raw technical text in `detail` (T2.4/4) — a raw
 * "GET /… → 401" headline told Erkki's deleted-key case nothing.
 */
export function classifyAutomationsFailure(err: unknown): AutomationsFailure {
  if (err instanceof ApiError) {
    const body = err.body as { error?: string; message?: string } | null;
    if (err.status === 503) {
      return {
        ok: false,
        kind: 'not_connected',
        message: body?.message ?? __( 'Smaily Campaign Intelligence is not connected.', 'smaily-connect' ),
      };
    }
    if (body?.error === 'api_key_rejected') {
      return {
        ok: false,
        kind: 'key_rejected',
        message: body.message ?? __( 'The engine rejected the stored API key.', 'smaily-connect' ),
      };
    }
    return {
      ok: false,
      kind: 'error',
      message: sprintf(
        // translators: %d is the HTTP status code of the failed request.
        __(
          'Connecting to Smaily Campaign Intelligence failed (HTTP %d). Check the connection on the Campaign Intelligence tab and try again.',
          'smaily-connect',
        ),
        err.status,
      ),
      detail: body?.message ?? err.message,
    };
  }
  return {
    ok: false,
    kind: 'error',
    message: __(
      'Connecting to Smaily Campaign Intelligence failed. Check your network connection and try again.',
      'smaily-connect',
    ),
    detail: err instanceof Error ? err.message : undefined,
  };
}
