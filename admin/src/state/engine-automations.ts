import { type Dispatch } from 'react';

import { __ } from '@admin/lib/i18n';

import {
  putAutomationsConfig,
  type AutomationCatalogTrigger,
  type AutomationConfigServerRow,
} from '../api/automations';
import {
  type EngineAutomationRow,
  type EngineAutomationsIssue,
  type WizardAction,
  type WizardState,
} from './types';

/**
 * Pure row/validation/save logic for the engine-triggered automations
 * section (contract §11–§13, T2.2). Kept out of the component so the
 * dynamic-render, default-row, round-trip, and pre-validation rules are
 * unit-testable without a DOM.
 */

export const COOLDOWN_MIN = 1;
export const COOLDOWN_MAX = 365;
export const COOLDOWN_DEFAULT = 7;
export const TEST_EMAILS_MAX = 50;

/**
 * Derive the §13 language_mode the same way AutomationSection decides
 * between multi-language rows and a single row: Modes A/B on a
 * multi-language site render per-language rows (+ fallback), everything
 * else (single-language, Mode C) is one workflow per trigger.
 *
 * The mode is STORE-GLOBAL (F3-52 addendum, 2026-07-07): every row
 * renders in this derived mode, uniformly. A server row's stored
 * `language_mode` is a wire fact about how it was last saved — it is
 * never used to pick a row's display shape (a walk-saved 'single' row
 * on a multilingual store must not render differently from its
 * neighbours). `convertAutomationMap` translates a stored map into the
 * derived mode at hydrate.
 */
export function deriveLanguageMode(
  multilingualMode: WizardState['multilingualMode'],
  detectedLanguages: string[],
): EngineAutomationRow['language_mode'] {
  const isMultiRow =
    (multilingualMode === 'A' || multilingualMode === 'B') && detectedLanguages.length > 1;
  return isMultiRow ? 'per_language' : 'single';
}

/**
 * Default row for a trigger that has never been configured: fail-closed
 * (§11) — off, test mode ON, UI cooldown default, no cap, no addresses.
 */
export function defaultRow(
  triggerKey: string,
  languageMode: EngineAutomationRow['language_mode'],
): EngineAutomationRow {
  return {
    trigger_key: triggerKey,
    enabled: false,
    language_mode: languageMode,
    automation_map: {},
    cooldown_days: COOLDOWN_DEFAULT,
    daily_cap: null,
    test_mode: true,
    test_emails: [],
  };
}

/**
 * Convert a stored §13 automation_map from the mode it was saved in to
 * the store-derived display mode (F3-52 addendum, 2026-07-07):
 *
 *  - single `{id}` → per_language: the id becomes the `fallback`
 *    (the per-language fields start unpicked — the merchant assigns
 *    languages; the fallback keeps the row valid and the workflow
 *    reachable).
 *  - per_language `{et, en, fallback}` → single: the fallback id
 *    becomes `id` (the language split has no meaning on a
 *    single-language store); language entries are dropped. A map
 *    without a fallback converts to `{}` — the merchant re-picks
 *    (validation flags it while enabled).
 *
 * Same mode → verbatim copy.
 */
export function convertAutomationMap(
  map: Record<string, string>,
  fromMode: EngineAutomationRow['language_mode'],
  toMode: EngineAutomationRow['language_mode'],
): Record<string, string> {
  if (fromMode === toMode) {
    return { ...map };
  }
  if (fromMode === 'single') {
    return map.id !== undefined ? { fallback: map.id } : {};
  }
  return map.fallback !== undefined ? { id: map.fallback } : {};
}

/**
 * Build the draft rows from a fresh catalog + config fetch.
 *
 * - The CATALOG drives what renders (dynamic — an unknown new trigger
 *   appears without a plugin change); rows follow catalog order.
 * - A config row missing from the catalog is dropped: not rendered, not
 *   sent (PUT never deletes, so the engine keeps it untouched).
 * - A config row present keeps the §13 fields — including `daily_cap`,
 *   which this UI doesn't edit but must round-trip unchanged — EXCEPT
 *   `language_mode`/`automation_map`: the display mode is store-global
 *   (`languageMode`, derived from the store's structure), so a server
 *   row saved in the other mode is CONVERTED via `convertAutomationMap`
 *   and the PUT sends the derived mode back. The §12 read-only fields
 *   are stripped here so they can never leak into the PUT body.
 * - `previousDraft` (non-null when the slice was dirty) wins over the
 *   server row for triggers it already holds, so a tab switch doesn't
 *   clobber unsaved edits; triggers new to the catalog still get a
 *   default row appended.
 */
export function buildRows(
  catalog: AutomationCatalogTrigger[],
  configs: AutomationConfigServerRow[],
  previousDraft: EngineAutomationRow[] | null,
  languageMode: EngineAutomationRow['language_mode'],
): EngineAutomationRow[] {
  const configByKey = new Map(configs.map((c) => [c.trigger_key, c]));
  const draftByKey = new Map((previousDraft ?? []).map((r) => [r.trigger_key, r]));

  return catalog.map((trigger) => {
    const draft = draftByKey.get(trigger.key);
    if (draft !== undefined) {
      return draft;
    }
    const server = configByKey.get(trigger.key);
    if (server !== undefined) {
      // Explicit eight-field copy — strips configured_via/updated_at.
      // Display mode is store-global: the server row's stored mode is
      // only used as the conversion SOURCE, never as the display shape.
      return {
        trigger_key: server.trigger_key,
        enabled: server.enabled,
        language_mode: languageMode,
        automation_map: convertAutomationMap(
          server.automation_map,
          server.language_mode,
          languageMode,
        ),
        cooldown_days: server.cooldown_days,
        daily_cap: server.daily_cap,
        test_mode: server.test_mode,
        test_emails: [...server.test_emails],
      };
    }
    return defaultRow(trigger.key, languageMode);
  });
}

/**
 * Locale pick for the catalog recipe, consistent with the name/
 * description `_et`/`_en` logic: a non-Estonian admin locale reads
 * `recipe_en` WHEN the engine provides it; everything else falls back
 * to `recipe_et`. `recipe_en` is forward-compatible (T2.4/5) — the
 * engine deploy that adds it is on its way, so the field is optional
 * and an absent/empty value must never blank the recipe box.
 */
export function pickRecipe(
  trigger: Pick<AutomationCatalogTrigger, 'recipe_et' | 'recipe_en'>,
  estonianLocale: boolean,
): string {
  if (!estonianLocale && trigger.recipe_en !== undefined && trigger.recipe_en !== '') {
    return trigger.recipe_en;
  }
  return trigger.recipe_et;
}

const NUMERIC_ID = /^\d+$/;
// Deliberately simple — the engine's Zod email check is the backstop.
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/**
 * Client-side pre-validation mirroring the §13 rules the UI can catch
 * before a PUT. The engine's indexed 422 remains the backstop — this is
 * a UX layer, not the validator (F3-51: no duplicate authority).
 * Issues use the same shape as the server errors so one binding path
 * renders both.
 */
export function validateRows(rows: EngineAutomationRow[]): EngineAutomationsIssue[] {
  const issues: EngineAutomationsIssue[] = [];

  rows.forEach((row, index) => {
    const add = (field: string, message: string): void => {
      issues.push({ index, trigger_key: row.trigger_key, field, message });
    };

    for (const [key, value] of Object.entries(row.automation_map)) {
      if (!NUMERIC_ID.test(value)) {
        add(`automation_map.${key}`, __( 'Workflow id must be a numeric string.', 'smaily-connect' ));
      }
    }

    if (row.enabled) {
      if (row.language_mode === 'single' && row.automation_map.id === undefined) {
        add('automation_map', __( 'Pick a workflow before enabling this automation.', 'smaily-connect' ));
      }
      if (row.language_mode === 'per_language' && row.automation_map.fallback === undefined) {
        add(
          'automation_map.fallback',
          __( 'Mark one language as the default fallback before enabling.', 'smaily-connect' ),
        );
      }
    }

    if (
      !Number.isInteger(row.cooldown_days) ||
      row.cooldown_days < COOLDOWN_MIN ||
      row.cooldown_days > COOLDOWN_MAX
    ) {
      add('cooldown_days', __( 'Cooldown must be between 1 and 365 days.', 'smaily-connect' ));
    }

    if (row.test_emails.length > TEST_EMAILS_MAX) {
      add('test_emails', __( 'At most 50 test addresses are allowed.', 'smaily-connect' ));
    }
    row.test_emails.forEach((email, i) => {
      if (!EMAIL_RE.test(email)) {
        issues.push({
          index,
          trigger_key: row.trigger_key,
          field: `test_emails.${i}`,
          message: __( 'Invalid email address.', 'smaily-connect' ),
        });
      }
    });
  });

  return issues;
}

/**
 * Bind save errors to rows: by trigger_key when present, else by index
 * into the rows array AS SENT (the PUT sends `rows` in order, so the
 * engine's `errors[].index` maps 1:1). Entries that bind to no row —
 * wrapper-level errors (no index, field='configs') — land under the ''
 * key for the section-level banner.
 */
export function issuesByTrigger(
  issues: EngineAutomationsIssue[],
  rows: EngineAutomationRow[],
): Map<string, EngineAutomationsIssue[]> {
  const map = new Map<string, EngineAutomationsIssue[]>();
  for (const issue of issues) {
    const key =
      issue.trigger_key ??
      (issue.index !== undefined ? rows[issue.index]?.trigger_key : undefined) ??
      '';
    const list = map.get(key) ?? [];
    list.push(issue);
    map.set(key, list);
  }
  return map;
}

/**
 * Set/clear one language's workflow id in a per_language map, keeping
 * the `fallback` entry coherent:
 *  - first workflow picked → becomes the fallback automatically
 *    (mirrors AutomationSection's first-row-marks-fallback behaviour);
 *  - the fallback language's id changes → fallback follows it;
 *  - the fallback language is cleared → fallback is dropped (the
 *    merchant re-picks; validation flags it while enabled).
 */
export function updateLanguageWorkflow(
  map: Record<string, string>,
  languages: string[],
  language: string,
  workflowId: string,
): Record<string, string> {
  const next = { ...map };
  const wasFallbackLanguage = fallbackLanguage(map, languages) === language;

  if (workflowId === '') {
    delete next[language];
    if (wasFallbackLanguage) {
      delete next.fallback;
    }
    return next;
  }

  next[language] = workflowId;
  if (wasFallbackLanguage || next.fallback === undefined) {
    next.fallback = workflowId;
  }
  return next;
}

/** Point the `fallback` entry at the given language's workflow id. */
export function setFallbackLanguage(
  map: Record<string, string>,
  language: string,
): Record<string, string> {
  const id = map[language];
  if (id === undefined) {
    return map;
  }
  return { ...map, fallback: id };
}

/**
 * Which language row currently holds the fallback marker — the first
 * language whose id equals `fallback`. (Two languages sharing one
 * workflow id are indistinguishable; the first wins, harmless on the
 * wire since only the id is sent.)
 */
export function fallbackLanguage(
  map: Record<string, string>,
  languages: string[],
): string | null {
  const fb = map.fallback;
  if (fb === undefined) {
    return null;
  }
  return languages.find((lang) => map[lang] === fb) ?? null;
}

/**
 * Save the full selection through the proxy. Shared by the Settings
 * sticky-footer Save and the wizard Step-3 Continue so the two paths
 * can't drift (F3-52).
 *
 * Pre-validates client-side; a local failure skips the PUT entirely.
 * §13 is all-or-nothing, so any failure keeps the slice dirty (the
 * reducer's SAVE_FAILED handler) and the caller gets `false` — in the
 * wizard that means "stay on the step".
 */
export async function saveEngineAutomations(
  rows: EngineAutomationRow[],
  dispatch: Dispatch<WizardAction>,
): Promise<boolean> {
  const localIssues = validateRows(rows);
  if (localIssues.length > 0) {
    dispatch({
      type: 'ENGINE_AUTOMATIONS_SAVE_FAILED',
      payload: {
        error: __( 'Fix the highlighted fields, then save again.', 'smaily-connect' ),
        errors: localIssues,
      },
    });
    return false;
  }

  if (rows.length === 0) {
    // Empty catalog — nothing to send (§13 requires 1..50 rows).
    dispatch({ type: 'ENGINE_AUTOMATIONS_SAVED' });
    return true;
  }

  dispatch({ type: 'ENGINE_AUTOMATIONS_SAVE_START' });
  const result = await putAutomationsConfig(rows);

  if (result.ok) {
    dispatch({ type: 'ENGINE_AUTOMATIONS_SAVED' });
    return true;
  }

  if (result.kind === 'validation_failed') {
    dispatch({
      type: 'ENGINE_AUTOMATIONS_SAVE_FAILED',
      payload: {
        error: __(
          'The engine rejected the configuration — nothing was saved. Fix the highlighted fields and save again.',
          'smaily-connect',
        ),
        errors: result.errors,
      },
    });
    return false;
  }

  dispatch({
    type: 'ENGINE_AUTOMATIONS_SAVE_FAILED',
    payload: {
      error:
        result.kind === 'key_rejected'
          ? __(
              'The engine rejected the stored API key — reconnect Campaign Intelligence, then save again.',
              'smaily-connect',
            )
          : // Human summary first (T2.4/4); the raw technical error
            // rides along in parentheses so support isn't blinded.
            result.detail !== undefined
            ? `${result.message} (${result.detail})`
            : result.message,
      errors: [],
    },
  });
  return false;
}
