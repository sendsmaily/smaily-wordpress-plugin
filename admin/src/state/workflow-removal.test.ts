import { afterEach, describe, expect, it, vi } from 'vitest';

import { type AutomationMapping } from './types';
import { wizardInitialState, wizardReducer } from './wizard-reducer';
import {
  changedAccounts,
  confirmWorkflowRemoval,
  mappingsLosingWorkflow,
  normaliseSubdomain,
  savedAccounts,
} from './workflow-removal';

/**
 * CON-431: a workflow belongs to the Smaily account it was picked from. The
 * browser predicts which workflows a Connection save removes — the same rule
 * SettingsEndpoint::save_connection() applies — to ask before it.
 */
function mapping(triggerType: AutomationMapping['triggerType'], accountKey: string, language = 'default'): AutomationMapping {
  return { triggerType, language, accountKey, workflowId: '11', isDefaultFallback: true };
}

function payload(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    smailyCredentials: { subdomain: 'oldshop', username: 'alice', password: '' },
    multilingualMode: 'single',
    perLanguageAccounts: [],
    transactionalCredentials: { subdomain: 'oldtx', username: 'tx', password: '' },
    ...overrides,
  };
}

describe('workflow removal on a Smaily account change', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('reduces a pasted account address to its subdomain, as the server does', () => {
    expect(normaliseSubdomain(' https://Demo.sendsmaily.net/campaigns ')).toBe('demo');
    expect(normaliseSubdomain('demo.sendsmaily.net')).toBe('demo');
    expect(normaliseSubdomain('Demo')).toBe('demo');
  });

  it('removes the workflows of an account another subdomain replaces, and only those', () => {
    const saved = savedAccounts(payload());
    const next = payload({ smailyCredentials: { subdomain: 'newshop', username: 'alice', password: 'x' } });
    const mappings = [mapping('welcome', 'default'), mapping('order_confirmation', 'transactional')];

    expect(changedAccounts(saved, next)).toEqual(['default']);
    expect(mappingsLosingWorkflow(saved, next, mappings)).toEqual([mapping('welcome', 'default')]);
  });

  it('keeps the workflows when another API user of the same account is saved', () => {
    const saved = savedAccounts(payload());
    const next = payload({ smailyCredentials: { subdomain: 'OldShop.sendsmaily.net', username: 'bob', password: 'x' } });

    expect(mappingsLosingWorkflow(saved, next, [mapping('welcome', 'default')])).toEqual([]);
  });

  it('removes the language accounts’ workflows when the account-per-language mode is left', () => {
    const saved = savedAccounts(payload({ multilingualMode: 'A' }));
    const next = payload({ multilingualMode: 'B' });
    const mappings = [
      mapping('welcome', 'account_et_EE', 'et_EE'),
      mapping('welcome', 'default', 'en_US'),
      mapping('order_confirmation', 'transactional'),
    ];

    expect(mappingsLosingWorkflow(saved, next, mappings)).toEqual([mapping('welcome', 'account_et_EE', 'et_EE')]);
  });

  it('asks before the save, naming each automation once, and saves without asking when nothing goes', () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);

    expect(confirmWorkflowRemoval([])).toBe(true);
    expect(confirm).not.toHaveBeenCalled();

    expect(
      confirmWorkflowRemoval([
        mapping('welcome', 'account_et', 'et'),
        mapping('welcome', 'account_en', 'en'),
        mapping('abandoned_cart', 'account_et', 'et'),
      ]),
    ).toBe(false);
    expect(confirm).toHaveBeenCalledWith(expect.stringContaining('Welcome email, Abandoned-cart reminder.'));
  });

  it('drops the removed accounts’ workflows from the state', () => {
    const state = {
      ...wizardInitialState,
      automationMappings: [mapping('welcome', 'account_et_EE', 'et_EE'), mapping('welcome', 'transactional')],
    };

    const next = wizardReducer(state, { type: 'REMOVE_ACCOUNT_WORKFLOWS', payload: { accountKeys: ['account_et_ee'] } });

    expect(next.automationMappings).toEqual([mapping('welcome', 'transactional')]);
  });
});
