import { describe, expect, it } from 'vitest';

import { type ModeAccount, type WizardState } from './types';
import { wizardInitialState, wizardReducer } from './wizard-reducer';
import { buildSettingsInitialState } from './settings-reducer';

const baseState: WizardState = wizardInitialState;

describe('wizardReducer — Step 1 Connect', () => {
  it('merges credential field updates partially', () => {
    const next = wizardReducer(baseState, {
      type: 'SET_SMAILY_CREDENTIALS',
      payload: { subdomain: 'demo' },
    });

    expect(next.smailyCredentials.subdomain).toBe('demo');
    expect(next.smailyCredentials.username).toBe('');
    expect(next.smailyCredentials.password).toBe('');
  });

  it('invalidates a successful connection when credentials change', () => {
    const connected: WizardState = {
      ...baseState,
      smailyConnection: { kind: 'success' },
    };

    const next = wizardReducer(connected, {
      type: 'SET_SMAILY_CREDENTIALS',
      payload: { username: 'alice' },
    });

    expect(next.smailyConnection).toEqual({ kind: 'idle' });
  });

  it('transitions connection-test pending → success → failure', () => {
    let s = wizardReducer(baseState, { type: 'TEST_SMAILY_CONNECTION_START' });
    expect(s.smailyConnection.kind).toBe('pending');

    s = wizardReducer(s, {
      type: 'TEST_SMAILY_CONNECTION_SUCCESS',
      payload: { accountName: 'My Pet Shop' },
    });
    expect(s.smailyConnection).toEqual({ kind: 'success', message: 'My Pet Shop' });

    s = wizardReducer(s, {
      type: 'TEST_SMAILY_CONNECTION_FAILURE',
      payload: { error: 'invalid password' },
    });
    expect(s.smailyConnection).toEqual({ kind: 'failure', error: 'invalid password' });
  });
});

describe('wizardReducer — multilingual mode', () => {
  it('preserves perLanguageAccounts when toggling B → A', () => {
    const inB: WizardState = { ...baseState, multilingualMode: 'B' };

    const next = wizardReducer(inB, { type: 'SET_MULTILINGUAL_MODE', payload: 'A' });

    expect(next.multilingualMode).toBe('A');
    expect(next.perLanguageAccounts).toEqual([]);
  });

  it('clears perLanguageAccounts when leaving A → B', () => {
    const account: ModeAccount = {
      accountKey: 'et',
      language: 'et_EE',
      credentials: { subdomain: 'demo-et', username: 'u', password: 'p' },
      connection: { kind: 'idle' },
    };
    const inA: WizardState = {
      ...baseState,
      multilingualMode: 'A',
      perLanguageAccounts: [account],
    };

    const next = wizardReducer(inA, { type: 'SET_MULTILINGUAL_MODE', payload: 'B' });

    expect(next.multilingualMode).toBe('B');
    expect(next.perLanguageAccounts).toEqual([]);
  });

  it('refuses to add a duplicate account by accountKey', () => {
    const account: ModeAccount = {
      accountKey: 'et',
      language: 'et_EE',
      credentials: { subdomain: 'demo', username: 'u', password: 'p' },
      connection: { kind: 'idle' },
    };

    const after = wizardReducer(baseState, { type: 'ADD_MODE_ACCOUNT', payload: account });
    const stillOne = wizardReducer(after, { type: 'ADD_MODE_ACCOUNT', payload: account });

    expect(stillOne.perLanguageAccounts).toHaveLength(1);
  });

  it('updates credentials for the matched accountKey only', () => {
    const account: ModeAccount = {
      accountKey: 'et',
      language: 'et_EE',
      credentials: { subdomain: 'old', username: 'u', password: 'p' },
      connection: { kind: 'success' },
    };
    const seeded = wizardReducer(baseState, { type: 'ADD_MODE_ACCOUNT', payload: account });

    const updated = wizardReducer(seeded, {
      type: 'UPDATE_MODE_ACCOUNT_CREDENTIALS',
      payload: { accountKey: 'et', credentials: { subdomain: 'new' } },
    });

    expect(updated.perLanguageAccounts[0]?.credentials.subdomain).toBe('new');
    expect(updated.perLanguageAccounts[0]?.credentials.username).toBe('u');
    expect(updated.perLanguageAccounts[0]?.connection.kind).toBe('idle');
  });
});

describe('wizardReducer — Step 2 Subscribers', () => {
  it('toggles a sync field on then off', () => {
    const without = wizardReducer(baseState, {
      type: 'TOGGLE_SYNC_FIELD',
      payload: { field: 'first_name' },
    });
    expect(without.syncFields).not.toContain('first_name');

    const restored = wizardReducer(without, {
      type: 'TOGGLE_SYNC_FIELD',
      payload: { field: 'first_name' },
    });
    expect(restored.syncFields).toContain('first_name');
  });

  it('replaces the whole sync-fields list on SET_SYNC_FIELDS', () => {
    const next = wizardReducer(baseState, {
      type: 'SET_SYNC_FIELDS',
      payload: ['phone', 'birthday'],
    });

    expect(next.syncFields).toEqual(['phone', 'birthday']);
  });

  it('defaults the contact-sync mode to consent', () => {
    expect(baseState.contactSyncMode).toBe('consent');
  });

  it('sets the contact-sync mode preset', () => {
    const next = wizardReducer(baseState, {
      type: 'SET_CONTACT_SYNC_MODE',
      payload: 'legitimate_interest',
    });

    expect(next.contactSyncMode).toBe('legitimate_interest');
  });

  it('toggles include-guests', () => {
    const guests = wizardReducer(baseState, { type: 'SET_INCLUDE_GUESTS', payload: true });
    expect(guests.includeGuests).toBe(true);
  });

  it('tracks backfill progress lifecycle', () => {
    let s = wizardReducer(baseState, {
      type: 'BACKFILL_START',
      payload: { jobType: 'contacts' },
    });
    expect(s.contactsBackfill.status).toBe('running');
    expect(s.contactsBackfill.error).toBeNull();

    s = wizardReducer(s, {
      type: 'BACKFILL_PROGRESS',
      payload: {
        jobType: 'contacts',
        progress: {
          status: 'running',
          processed: 25,
          synced: 25,
          sent: 25,
          failed: 0,
          total: 100,
          percent: 25,
          etaSeconds: 180,
          error: null,
          startedAt: '2026-05-21 09:00:00',
          completedAt: null,
          audienceEstimate: null,
        },
      },
    });
    expect(s.contactsBackfill.percent).toBe(25);

    s = wizardReducer(s, {
      type: 'BACKFILL_CANCEL',
      payload: { jobType: 'contacts' },
    });
    expect(s.contactsBackfill.status).toBe('cancelled');
  });
});

describe('wizardReducer — navigation', () => {
  it('clamps WIZARD_GO_TO_STEP into [1, 6]', () => {
    expect(
      wizardReducer(baseState, { type: 'WIZARD_GO_TO_STEP', payload: { step: 99 } }).currentStep,
    ).toBe(6);

    expect(
      wizardReducer(baseState, { type: 'WIZARD_GO_TO_STEP', payload: { step: 0 } }).currentStep,
    ).toBe(1);

    expect(
      wizardReducer(baseState, { type: 'WIZARD_GO_TO_STEP', payload: { step: 3 } }).currentStep,
    ).toBe(3);
  });

  it('walks forward and backward without overshooting', () => {
    let s = baseState;
    for (let i = 0; i < 10; i += 1) {
      s = wizardReducer(s, { type: 'WIZARD_NEXT_STEP' });
    }
    expect(s.currentStep).toBe(6);

    for (let i = 0; i < 10; i += 1) {
      s = wizardReducer(s, { type: 'WIZARD_PREVIOUS_STEP' });
    }
    expect(s.currentStep).toBe(1);
  });
});

describe('wizardReducer — Mode A per-account connection', () => {
  const seed: WizardState = {
    ...baseState,
    multilingualMode: 'A',
    perLanguageAccounts: [
      {
        accountKey: 'account_et',
        language: 'et_EE',
        credentials: { subdomain: 'demo-et', username: 'u', password: 'p' },
        connection: { kind: 'idle' },
      },
      {
        accountKey: 'account_en',
        language: 'en_US',
        credentials: { subdomain: 'demo-en', username: 'u', password: 'p' },
        connection: { kind: 'idle' },
      },
    ],
  };

  it('flips only the matched per-account connection to pending', () => {
    const next = wizardReducer(seed, {
      type: 'TEST_MODE_ACCOUNT_CONNECTION_START',
      payload: { accountKey: 'account_et' },
    });

    expect(next.perLanguageAccounts[0]?.connection.kind).toBe('pending');
    expect(next.perLanguageAccounts[1]?.connection.kind).toBe('idle');
  });

  it('stamps a success message with the account name', () => {
    const next = wizardReducer(seed, {
      type: 'TEST_MODE_ACCOUNT_CONNECTION_SUCCESS',
      payload: { accountKey: 'account_en', accountName: 'Pet Shop EN' },
    });

    expect(next.perLanguageAccounts[1]?.connection).toEqual({
      kind: 'success',
      message: 'Pet Shop EN',
    });
  });

  it('records failures with the error string', () => {
    const next = wizardReducer(seed, {
      type: 'TEST_MODE_ACCOUNT_CONNECTION_FAILURE',
      payload: { accountKey: 'account_et', error: 'rejected' },
    });

    expect(next.perLanguageAccounts[0]?.connection).toEqual({
      kind: 'failure',
      error: 'rejected',
    });
  });

  it('SET_DEFAULT_FALLBACK_ACCOUNT_KEY swaps the chosen fallback', () => {
    const next = wizardReducer(baseState, {
      type: 'SET_DEFAULT_FALLBACK_ACCOUNT_KEY',
      payload: 'account_et',
    });
    expect(next.defaultFallbackAccountKey).toBe('account_et');
  });
});

describe('wizardReducer — Step 3 WC automations', () => {
  it('toggles welcomeEnabled / firstOrderEnabled / abandonedCartEnabled', () => {
    let s = wizardReducer(baseState, { type: 'SET_WELCOME_ENABLED', payload: true });
    expect(s.welcomeEnabled).toBe(true);

    s = wizardReducer(s, { type: 'SET_FIRST_ORDER_ENABLED', payload: true });
    expect(s.firstOrderEnabled).toBe(true);

    s = wizardReducer(s, { type: 'SET_ABANDONED_CART_ENABLED', payload: true });
    expect(s.abandonedCartEnabled).toBe(true);
  });

  it('clamps abandoned-cart cutoff to a 10-minute minimum', () => {
    const next = wizardReducer(baseState, {
      type: 'SET_ABANDONED_CART_CUTOFF_MINUTES',
      payload: 5,
    });
    expect(next.abandonedCartCutoffMinutes).toBe(10);

    const okay = wizardReducer(baseState, {
      type: 'SET_ABANDONED_CART_CUTOFF_MINUTES',
      payload: 45,
    });
    expect(okay.abandonedCartCutoffMinutes).toBe(45);
  });

  it('upserts a mapping (insert + update by composite key)', () => {
    const inserted = wizardReducer(baseState, {
      type: 'UPSERT_AUTOMATION_MAPPING',
      payload: {
        triggerType: 'welcome',
        language: 'et_EE',
        accountKey: 'default',
        workflowId: '42',
        isDefaultFallback: true,
      },
    });
    expect(inserted.automationMappings).toHaveLength(1);

    const updated = wizardReducer(inserted, {
      type: 'UPSERT_AUTOMATION_MAPPING',
      payload: {
        triggerType: 'welcome',
        language: 'et_EE',
        accountKey: 'default',
        workflowId: '99', // re-mapped
        isDefaultFallback: true,
      },
    });
    expect(updated.automationMappings).toHaveLength(1);
    expect(updated.automationMappings[0]?.workflowId).toBe('99');
  });

  it('removes a mapping by composite key', () => {
    const seed = wizardReducer(baseState, {
      type: 'UPSERT_AUTOMATION_MAPPING',
      payload: {
        triggerType: 'welcome',
        language: 'et_EE',
        accountKey: 'default',
        workflowId: '42',
        isDefaultFallback: false,
      },
    });

    const removed = wizardReducer(seed, {
      type: 'REMOVE_AUTOMATION_MAPPING',
      payload: { triggerType: 'welcome', language: 'et_EE', accountKey: 'default' },
    });
    expect(removed.automationMappings).toHaveLength(0);
  });

  it('SET_AUTOMATION_FALLBACK gives exactly one row per trigger the fallback flag', () => {
    let s = baseState;
    s = wizardReducer(s, {
      type: 'UPSERT_AUTOMATION_MAPPING',
      payload: {
        triggerType: 'welcome',
        language: 'et_EE',
        accountKey: 'default',
        workflowId: '1',
        isDefaultFallback: true,
      },
    });
    s = wizardReducer(s, {
      type: 'UPSERT_AUTOMATION_MAPPING',
      payload: {
        triggerType: 'welcome',
        language: 'en_US',
        accountKey: 'default',
        workflowId: '2',
        isDefaultFallback: false,
      },
    });

    const switched = wizardReducer(s, {
      type: 'SET_AUTOMATION_FALLBACK',
      payload: { triggerType: 'welcome', language: 'en_US', accountKey: 'default' },
    });

    const fallback = switched.automationMappings.filter((m) => m.isDefaultFallback);
    expect(fallback).toHaveLength(1);
    expect(fallback[0]?.language).toBe('en_US');
  });
});

describe('wizardReducer — Step 4 rec-engine features', () => {
  it('toggles the browse-tracking preference (the only Step-4 toggle after 3.9)', () => {
    const next = wizardReducer(baseState, {
      type: 'SET_REC_ENGINE_FEATURE',
      payload: { feature: 'trackBrowsing', enabled: true },
    });

    expect(next.recEngineFeatures.trackBrowsing).toBe(true);
  });

  it('does not mutate the source state', () => {
    const next = wizardReducer(baseState, {
      type: 'SET_REC_ENGINE_FEATURE',
      payload: { feature: 'trackBrowsing', enabled: true },
    });

    expect(next).not.toBe(baseState);
    expect(baseState.recEngineFeatures.trackBrowsing).toBe(false);
  });
});

describe('wizardReducer — exhaustiveness guard', () => {
  it('throws when handed an action type the switch does not cover', () => {
    // This intentionally bypasses the TypeScript narrowing — production code
    // can never reach here, but a malformed dispatch from non-typed sources
    // (browser dev-tools, malicious extension) should fail loudly rather
    // than silently return state.
    expect(() =>
      // @ts-expect-error — deliberately invalid action to exercise assertNever
      wizardReducer(baseState, { type: 'NEVER_DEFINED_ACTION' }),
    ).toThrow(/unhandled action/);
  });
});

describe('buildSettingsInitialState', () => {
  it('marks the state as inSettings and zeroes currentStep', () => {
    const s = buildSettingsInitialState();
    expect(s.inSettings).toBe(true);
    expect(s.currentStep).toBe(0);
  });

  it('hydrates Smaily credentials but never password', () => {
    const s = buildSettingsInitialState({
      smailyCredentials: { subdomain: 'demo', username: 'alice' },
      smailyConnected: true,
    });

    expect(s.smailyCredentials.subdomain).toBe('demo');
    expect(s.smailyCredentials.username).toBe('alice');
    expect(s.smailyCredentials.password).toBe('');
    expect(s.smailyConnection.kind).toBe('success');
  });

  it('returns sane defaults for an empty env payload', () => {
    const s = buildSettingsInitialState();
    expect(s.subscriberSyncEnabled).toBe(true);
    expect(s.syncFields).not.toHaveLength(0);
    expect(s.multilingualMode).toBe('single');
  });

  it('lets settings re-use the wizardReducer end-to-end', () => {
    let s = buildSettingsInitialState({
      smailyCredentials: { subdomain: 'demo', username: 'alice' },
      smailyConnected: true,
    });

    s = wizardReducer(s, {
      type: 'SET_SMAILY_CREDENTIALS',
      payload: { username: 'eve' },
    });

    expect(s.smailyCredentials.username).toBe('eve');
    expect(s.smailyConnection.kind).toBe('idle');
    expect(s.inSettings).toBe(true);
  });
});

describe('wizardReducer — engine-run automations slice (T2.2)', () => {
  const row = {
    trigger_key: 'replenish_due',
    enabled: false,
    language_mode: 'single' as const,
    automation_map: {},
    cooldown_days: 7,
    daily_cap: null,
    test_mode: true,
    test_emails: [],
  };

  it('hydrates rows without marking the slice dirty', () => {
    const s = wizardReducer(baseState, {
      type: 'ENGINE_AUTOMATIONS_HYDRATED',
      payload: { rows: [row], keepDirty: false },
    });

    expect(s.engineAutomations.rows).toEqual([row]);
    expect(s.engineAutomations.dirty).toBe(false);
    expect(s.engineAutomations.serverErrors).toEqual([]);
  });

  it('hydrate with keepDirty preserves the dirty flag and unresolved errors', () => {
    let s = wizardReducer(baseState, {
      type: 'ENGINE_AUTOMATIONS_SAVE_FAILED',
      payload: { error: 'nope', errors: [{ field: 'configs', message: 'x' }] },
    });
    s = wizardReducer(s, {
      type: 'ENGINE_AUTOMATIONS_HYDRATED',
      payload: { rows: [row], keepDirty: true },
    });

    expect(s.engineAutomations.dirty).toBe(true);
    expect(s.engineAutomations.serverErrors).toHaveLength(1);
  });

  it('UPDATE patches the matching row, marks dirty, and resets the save outcome', () => {
    let s = wizardReducer(baseState, {
      type: 'ENGINE_AUTOMATIONS_HYDRATED',
      payload: { rows: [row], keepDirty: false },
    });
    s = wizardReducer(s, {
      type: 'ENGINE_AUTOMATIONS_SAVED',
    });
    s = wizardReducer(s, {
      type: 'UPDATE_ENGINE_AUTOMATION',
      payload: { triggerKey: 'replenish_due', patch: { enabled: true } },
    });

    expect(s.engineAutomations.rows[0]?.enabled).toBe(true);
    expect(s.engineAutomations.dirty).toBe(true);
    expect(s.engineAutomations.saveStatus).toBe('idle');
    // Engine-automations dirty is deliberately NOT dirtyTabs.woocommerce
    // (F3-52) — partial save failure keeps only this slice dirty.
    expect(s.dirtyTabs.woocommerce).toBe(false);
  });

  it('SAVED clears dirty + errors; SAVE_FAILED keeps dirty (all-or-nothing §13)', () => {
    let s = wizardReducer(baseState, {
      type: 'ENGINE_AUTOMATIONS_HYDRATED',
      payload: { rows: [row], keepDirty: false },
    });
    s = wizardReducer(s, {
      type: 'UPDATE_ENGINE_AUTOMATION',
      payload: { triggerKey: 'replenish_due', patch: { cooldown_days: 14 } },
    });
    s = wizardReducer(s, {
      type: 'ENGINE_AUTOMATIONS_SAVE_FAILED',
      payload: {
        error: 'validation failed',
        errors: [{ index: 0, trigger_key: 'replenish_due', field: 'automation_map', message: 'x' }],
      },
    });

    expect(s.engineAutomations.dirty).toBe(true);
    expect(s.engineAutomations.saveStatus).toBe('error');
    expect(s.engineAutomations.serverErrors).toHaveLength(1);

    s = wizardReducer(s, { type: 'ENGINE_AUTOMATIONS_SAVED' });
    expect(s.engineAutomations.dirty).toBe(false);
    expect(s.engineAutomations.saveStatus).toBe('success');
    expect(s.engineAutomations.serverErrors).toEqual([]);
  });
});
