import { beforeEach, describe, expect, it, vi } from 'vitest';

import {
  putAutomationsConfig,
  type AutomationCatalogTrigger,
  type AutomationConfigServerRow,
} from '../api/automations';
import {
  buildRows,
  convertAutomationMap,
  defaultRow,
  deriveLanguageMode,
  fallbackLanguage,
  issuesByTrigger,
  pickRecipe,
  saveEngineAutomations,
  setFallbackLanguage,
  updateLanguageWorkflow,
  validateRows,
} from './engine-automations';
import { type EngineAutomationRow, type WizardAction } from './types';

vi.mock('../api/automations', async (importOriginal) => {
  const original = await importOriginal<Record<string, unknown>>();
  return { ...original, putAutomationsConfig: vi.fn() };
});

const putMock = vi.mocked(putAutomationsConfig);

function catalogTrigger(key: string): AutomationCatalogTrigger {
  return {
    key,
    name_et: `${key} (et)`,
    name_en: `${key} (en)`,
    description_et: 'Kirjeldus',
    description_en: 'Description',
    recipe_et: 'Retsept',
  };
}

function configuredRow(overrides: Partial<AutomationConfigServerRow> = {}): AutomationConfigServerRow {
  return {
    trigger_key: 'replenish_due',
    enabled: true,
    language_mode: 'single',
    automation_map: { id: '123' },
    cooldown_days: 14,
    daily_cap: 500,
    test_mode: false,
    test_emails: ['owner@shop.example'],
    configured_via: 'admin',
    updated_at: '2026-07-07T05:15:00.000Z',
    ...overrides,
  };
}

describe('deriveLanguageMode', () => {
  it('is per_language for Modes A/B on a multi-language site', () => {
    expect(deriveLanguageMode('A', ['et', 'en'])).toBe('per_language');
    expect(deriveLanguageMode('B', ['et', 'en'])).toBe('per_language');
  });

  it('is single for Mode C, single mode, and single-language sites', () => {
    expect(deriveLanguageMode('C', ['et', 'en'])).toBe('single');
    expect(deriveLanguageMode('single', ['et'])).toBe('single');
    expect(deriveLanguageMode('B', ['et'])).toBe('single');
  });
});

describe('convertAutomationMap — store-global mode conversion (T2.4/1)', () => {
  it('copies verbatim when the modes match', () => {
    const map = { et: '12', en: '13', fallback: '12' };
    const converted = convertAutomationMap(map, 'per_language', 'per_language');
    expect(converted).toEqual(map);
    expect(converted).not.toBe(map);
  });

  it('single → per_language: the id becomes the fallback, languages unpicked', () => {
    expect(convertAutomationMap({ id: '123' }, 'single', 'per_language')).toEqual({
      fallback: '123',
    });
  });

  it('single → per_language: an empty map stays empty', () => {
    expect(convertAutomationMap({}, 'single', 'per_language')).toEqual({});
  });

  it('per_language → single: the fallback id becomes the single id', () => {
    expect(
      convertAutomationMap({ et: '12', en: '13', fallback: '13' }, 'per_language', 'single'),
    ).toEqual({ id: '13' });
  });

  it('per_language → single: no fallback → empty map (merchant re-picks)', () => {
    expect(convertAutomationMap({ et: '12' }, 'per_language', 'single')).toEqual({});
  });
});

describe('buildRows — dynamic catalog-driven rows', () => {
  it('renders an unknown new trigger from the catalog with a fail-closed default row', () => {
    // A key this plugin has never heard of — must appear with no code change.
    const rows = buildRows([catalogTrigger('brand_new_trigger_2027')], [], null, 'single');

    expect(rows).toHaveLength(1);
    expect(rows[0]).toEqual(defaultRow('brand_new_trigger_2027', 'single'));
    expect(rows[0]?.enabled).toBe(false);
    expect(rows[0]?.test_mode).toBe(true);
    expect(rows[0]?.cooldown_days).toBe(7);
    expect(rows[0]?.daily_cap).toBeNull();
    expect(rows[0]?.test_emails).toEqual([]);
  });

  it('derives per_language for default rows on multilingual sites', () => {
    const rows = buildRows([catalogTrigger('t')], [], null, 'per_language');
    expect(rows[0]?.language_mode).toBe('per_language');
  });

  it('keeps all eight fields of a configured row — daily_cap round-trips untouched', () => {
    const rows = buildRows(
      [catalogTrigger('replenish_due')],
      [configuredRow({ daily_cap: 500 })],
      null,
      'single',
    );

    expect(rows[0]?.daily_cap).toBe(500);
    expect(rows[0]?.cooldown_days).toBe(14);
    expect(rows[0]?.test_mode).toBe(false);
    expect(rows[0]?.test_emails).toEqual(['owner@shop.example']);
  });

  it('strips the read-only §12 fields so they cannot reach the PUT body', () => {
    const rows = buildRows([catalogTrigger('replenish_due')], [configuredRow()], null, 'single');

    expect(rows[0]).not.toHaveProperty('configured_via');
    expect(rows[0]).not.toHaveProperty('updated_at');
  });

  it('drops a config row whose trigger is missing from the catalog (not rendered, not sent)', () => {
    const rows = buildRows(
      [catalogTrigger('winback_risk')],
      [configuredRow({ trigger_key: 'retired_trigger' })],
      null,
      'single',
    );

    expect(rows.map((r) => r.trigger_key)).toEqual(['winback_risk']);
  });

  it('preserves a dirty draft over the server row, appending defaults for catalog newcomers', () => {
    const draft: EngineAutomationRow = {
      ...defaultRow('replenish_due', 'single'),
      enabled: true,
      automation_map: { id: '999' },
    };

    const rows = buildRows(
      [catalogTrigger('replenish_due'), catalogTrigger('fresh_from_deploy')],
      [configuredRow()],
      [draft],
      'single',
    );

    expect(rows[0]).toBe(draft);
    expect(rows[1]).toEqual(defaultRow('fresh_from_deploy', 'single'));
  });

  it('renders EVERY row in the store-derived mode — a stored single row on a multilingual store converts, uniformly with its neighbours', () => {
    // The sandbox bug: a walk-saved 'single' replenish_due row rendered one
    // dropdown while never-configured triggers got the per_language table.
    const rows = buildRows(
      [catalogTrigger('replenish_due'), catalogTrigger('winback_rescue')],
      [configuredRow({ trigger_key: 'replenish_due', language_mode: 'single', automation_map: { id: '123' } })],
      null,
      'per_language',
    );

    expect(rows.map((r) => r.language_mode)).toEqual(['per_language', 'per_language']);
    // The stored id survives as the fallback; language fields start unpicked.
    expect(rows[0]?.automation_map).toEqual({ fallback: '123' });
  });

  it('converts a stored per_language row to single on a single-language store — fallback id becomes the id', () => {
    const rows = buildRows(
      [catalogTrigger('replenish_due')],
      [
        configuredRow({
          trigger_key: 'replenish_due',
          language_mode: 'per_language',
          automation_map: { et: '12', en: '13', fallback: '13' },
        }),
      ],
      null,
      'single',
    );

    expect(rows[0]?.language_mode).toBe('single');
    expect(rows[0]?.automation_map).toEqual({ id: '13' });
  });

  it('follows catalog order', () => {
    const rows = buildRows(
      [catalogTrigger('b'), catalogTrigger('a')],
      [configuredRow({ trigger_key: 'a' })],
      null,
      'single',
    );
    expect(rows.map((r) => r.trigger_key)).toEqual(['b', 'a']);
  });
});

describe('pickRecipe — forward-compatible recipe locale pick (T2.4/5)', () => {
  it('shows recipe_en to non-Estonian locales when the engine provides it', () => {
    expect(pickRecipe({ recipe_et: 'Retsept', recipe_en: 'Recipe' }, false)).toBe('Recipe');
  });

  it('falls back to recipe_et when recipe_en is absent or empty (pre-deploy catalogs)', () => {
    expect(pickRecipe({ recipe_et: 'Retsept' }, false)).toBe('Retsept');
    expect(pickRecipe({ recipe_et: 'Retsept', recipe_en: '' }, false)).toBe('Retsept');
  });

  it('always shows recipe_et to Estonian locales', () => {
    expect(pickRecipe({ recipe_et: 'Retsept', recipe_en: 'Recipe' }, true)).toBe('Retsept');
  });
});

describe('validateRows — client-side §13 pre-validation', () => {
  it('accepts an untouched default row (disabled, empty map)', () => {
    expect(validateRows([defaultRow('t', 'single')])).toEqual([]);
  });

  it('flags enabled single-mode rows without a workflow id', () => {
    const row = { ...defaultRow('t', 'single'), enabled: true };
    const issues = validateRows([row]);
    expect(issues).toHaveLength(1);
    expect(issues[0]).toMatchObject({ index: 0, trigger_key: 't', field: 'automation_map' });
  });

  it('flags enabled per_language rows without a fallback', () => {
    const row: EngineAutomationRow = {
      ...defaultRow('t', 'per_language'),
      enabled: true,
      automation_map: { et: '12' },
    };
    const issues = validateRows([row]);
    expect(issues.map((i) => i.field)).toContain('automation_map.fallback');
  });

  it('flags non-numeric workflow ids on every map entry', () => {
    const row: EngineAutomationRow = {
      ...defaultRow('t', 'per_language'),
      automation_map: { et: '12', en: 'abc', fallback: '12' },
    };
    const issues = validateRows([row]);
    expect(issues).toHaveLength(1);
    expect(issues[0]?.field).toBe('automation_map.en');
  });

  it('flags cooldown outside 1–365 and non-integers', () => {
    expect(validateRows([{ ...defaultRow('t', 'single'), cooldown_days: 0 }])).toHaveLength(1);
    expect(validateRows([{ ...defaultRow('t', 'single'), cooldown_days: 366 }])).toHaveLength(1);
    expect(validateRows([{ ...defaultRow('t', 'single'), cooldown_days: 2.5 }])).toHaveLength(1);
    expect(validateRows([{ ...defaultRow('t', 'single'), cooldown_days: 365 }])).toEqual([]);
  });

  it('flags malformed test emails with an indexed field path', () => {
    const row = { ...defaultRow('t', 'single'), test_emails: ['ok@shop.example', 'nope'] };
    const issues = validateRows([row]);
    expect(issues).toHaveLength(1);
    expect(issues[0]?.field).toBe('test_emails.1');
  });

  it('flags more than 50 test emails', () => {
    const emails = Array.from({ length: 51 }, (_, i) => `t${i}@shop.example`);
    const row = { ...defaultRow('t', 'single'), test_emails: emails };
    expect(validateRows([row]).map((i) => i.field)).toContain('test_emails');
  });
});

describe('per_language map helpers', () => {
  const langs = ['et', 'en'];

  it('first picked workflow becomes the fallback automatically', () => {
    const map = updateLanguageWorkflow({}, langs, 'et', '12');
    expect(map).toEqual({ et: '12', fallback: '12' });
    expect(fallbackLanguage(map, langs)).toBe('et');
  });

  it('changing the fallback language’s workflow moves the fallback id with it', () => {
    const map = updateLanguageWorkflow({ et: '12', en: '13', fallback: '12' }, langs, 'et', '99');
    expect(map.fallback).toBe('99');
  });

  it('clearing the fallback language drops the fallback entry', () => {
    const map = updateLanguageWorkflow({ et: '12', en: '13', fallback: '12' }, langs, 'et', '');
    expect(map).toEqual({ en: '13' });
  });

  it('setFallbackLanguage repoints fallback at that language’s id', () => {
    const map = setFallbackLanguage({ et: '12', en: '13', fallback: '12' }, 'en');
    expect(map.fallback).toBe('13');
  });

  it('setFallbackLanguage is a no-op for a language without a workflow', () => {
    const map = { et: '12', fallback: '12' };
    expect(setFallbackLanguage(map, 'en')).toBe(map);
  });
});

describe('issuesByTrigger — 422 binding', () => {
  const rows = [defaultRow('a', 'single'), defaultRow('b', 'single')];

  it('binds by trigger_key when present, by index otherwise', () => {
    const bound = issuesByTrigger(
      [
        { index: 0, trigger_key: 'a', field: 'automation_map', message: 'x' },
        { index: 1, field: 'cooldown_days', message: 'y' },
      ],
      rows,
    );
    expect(bound.get('a')).toHaveLength(1);
    expect(bound.get('b')).toHaveLength(1);
  });

  it('parks wrapper-level errors (no index, field=configs) under the empty key', () => {
    const bound = issuesByTrigger([{ field: 'configs', message: 'max 50 rida' }], rows);
    expect(bound.get('')).toHaveLength(1);
  });
});

describe('saveEngineAutomations — save orchestration', () => {
  let dispatched: WizardAction[];
  const dispatch = (action: WizardAction): void => {
    dispatched.push(action);
  };

  beforeEach(() => {
    dispatched = [];
    putMock.mockReset();
  });

  it('PUTs the rows and dispatches SAVED on success', async () => {
    putMock.mockResolvedValue({ ok: true, upserted: 1 });
    const rows = [defaultRow('t', 'single')];

    const ok = await saveEngineAutomations(rows, dispatch);

    expect(ok).toBe(true);
    expect(putMock).toHaveBeenCalledWith(rows);
    expect(dispatched.map((a) => a.type)).toEqual([
      'ENGINE_AUTOMATIONS_SAVE_START',
      'ENGINE_AUTOMATIONS_SAVED',
    ]);
  });

  it('skips the PUT entirely when client-side validation fails', async () => {
    const rows = [{ ...defaultRow('t', 'single'), enabled: true }];

    const ok = await saveEngineAutomations(rows, dispatch);

    expect(ok).toBe(false);
    expect(putMock).not.toHaveBeenCalled();
    expect(dispatched).toHaveLength(1);
    expect(dispatched[0]).toMatchObject({
      type: 'ENGINE_AUTOMATIONS_SAVE_FAILED',
      payload: { errors: [{ trigger_key: 't', field: 'automation_map' }] },
    });
  });

  it('maps an engine 422 to SAVE_FAILED with the indexed errors (all-or-nothing)', async () => {
    putMock.mockResolvedValue({
      ok: false,
      kind: 'validation_failed',
      errors: [{ index: 0, trigger_key: 't', field: 'automation_map.fallback', message: 'nõutav' }],
    });

    const ok = await saveEngineAutomations([defaultRow('t', 'single')], dispatch);

    expect(ok).toBe(false);
    const failed = dispatched.find((a) => a.type === 'ENGINE_AUTOMATIONS_SAVE_FAILED');
    expect(failed).toMatchObject({
      payload: { errors: [{ field: 'automation_map.fallback' }] },
    });
  });

  it('maps key_rejected and network failures to SAVE_FAILED without errors', async () => {
    putMock.mockResolvedValue({ ok: false, kind: 'key_rejected', message: 'rejected' });

    const ok = await saveEngineAutomations([defaultRow('t', 'single')], dispatch);

    expect(ok).toBe(false);
    const failed = dispatched.find((a) => a.type === 'ENGINE_AUTOMATIONS_SAVE_FAILED');
    expect(failed).toMatchObject({ payload: { errors: [] } });
  });

  it('keeps the human summary first and appends the technical detail on a generic failure (T2.4/4)', async () => {
    putMock.mockResolvedValue({
      ok: false,
      kind: 'error',
      message: 'Connecting to Smaily Campaign Intelligence failed (HTTP 500). Check the connection on the Campaign Intelligence tab and try again.',
      detail: 'PUT /rec-engine/automations/config → 500',
    });

    await saveEngineAutomations([defaultRow('t', 'single')], dispatch);

    const failed = dispatched.find((a) => a.type === 'ENGINE_AUTOMATIONS_SAVE_FAILED');
    expect(failed).toMatchObject({
      payload: {
        error:
          'Connecting to Smaily Campaign Intelligence failed (HTTP 500). Check the connection on the Campaign Intelligence tab and try again. (PUT /rec-engine/automations/config → 500)',
      },
    });
  });

  it('treats an empty catalog as a no-op success (§13 forbids an empty PUT)', async () => {
    const ok = await saveEngineAutomations([], dispatch);

    expect(ok).toBe(true);
    expect(putMock).not.toHaveBeenCalled();
    expect(dispatched.map((a) => a.type)).toEqual(['ENGINE_AUTOMATIONS_SAVED']);
  });
});
