import { type Dispatch } from 'react';

import { __, sprintf } from '@admin/lib/i18n';

import { type SaveSettingsResponse } from '../api/saveSettings';
import { forgetWorkflows } from '../hooks/useWorkflows';
import { type AutomationMapping, type AutomationTrigger, type WizardAction } from './types';

/**
 * A workflow belongs to the Smaily account it was picked from (CON-431).
 * When a Connection save puts another Smaily account (another subdomain) in
 * the place of a saved one, or leaves the account-per-language mode, the
 * server removes the workflows picked from the replaced accounts, and those
 * automations send nothing until a workflow is chosen again. This module
 * holds the browser's side of that rule: the question before the save and
 * the state after it. SettingsEndpoint::save_connection() is the rule
 * itself.
 */

/** What the last saved Connection payload held: each account key's subdomain and the mode. */
export interface SavedAccounts {
  mode: string;
  subdomains: Record<string, string>;
}

/** The Connection payload's accounts, as savedAccounts() reads them. */
export function savedAccounts(payload: Record<string, unknown>): SavedAccounts {
  return {
    mode: typeof payload['multilingualMode'] === 'string' ? payload['multilingualMode'] : 'single',
    subdomains: accountSubdomains(payload),
  };
}

/**
 * The account keys whose Smaily account the payload replaces: one with a
 * subdomain is saved, and the payload names another one (or none). Another
 * API user of the same subdomain is the same account.
 */
export function changedAccounts(saved: SavedAccounts, payload: Record<string, unknown>): string[] {
  const next = accountSubdomains(payload);
  return Object.keys(next).filter((key) => {
    const before = saved.subdomains[key] ?? '';
    return before !== '' && next[key] !== before;
  });
}

/** The automation workflows the save of this payload removes. */
export function mappingsLosingWorkflow(
  saved: SavedAccounts,
  payload: Record<string, unknown>,
  mappings: readonly AutomationMapping[],
): AutomationMapping[] {
  const changed = new Set(changedAccounts(saved, payload));
  const leavesModeA = saved.mode === 'A' && payload['multilingualMode'] !== 'A';
  return mappings.filter((mapping) => {
    const key = mapping.accountKey.toLowerCase();
    return changed.has(key) || (leavesModeA && key !== 'default' && key !== 'transactional');
  });
}

/**
 * Asks before a save that removes workflows, naming the automations that
 * will need one again. True when nothing is removed or the merchant agrees.
 */
export function confirmWorkflowRemoval(losing: readonly AutomationMapping[]): boolean {
  if (losing.length === 0) {
    return true;
  }
  const names = [...new Set(losing.map((mapping) => automationName(mapping.triggerType)))];
  return window.confirm(
    sprintf(
      // translators: %s is a comma-separated list of automation names, e.g. "Welcome email, Abandoned-cart reminder".
      __(
        'This change replaces a Smaily account. The workflows chosen from that account will be removed from these automations, and they send nothing until you choose a workflow again: %s. Save anyway?',
        'smaily-connect',
      ),
      names.join(', '),
    ),
  );
}

/**
 * After a Connection save: forgets the workflow lists of the replaced
 * accounts, so the new account's list is loaded, and drops from the state
 * the workflows the server removed, so a later WooCommerce save does not
 * put them back. Returns what is now saved.
 */
export function afterConnectionSaved(
  saved: SavedAccounts,
  payload: Record<string, unknown>,
  response: Pick<SaveSettingsResponse, 'workflowsRemoved'>,
  dispatch: Dispatch<WizardAction>,
): SavedAccounts {
  forgetWorkflows(changedAccounts(saved, payload));
  const removed = response.workflowsRemoved ?? [];
  if (removed.length > 0) {
    dispatch({ type: 'REMOVE_ACCOUNT_WORKFLOWS', payload: { accountKeys: removed } });
  }
  return savedAccounts(payload);
}

/**
 * A subdomain as the server compares it: a pasted account address reduced
 * to its subdomain, trimmed and lower case (Settings\Subdomain::normalize()).
 */
export function normaliseSubdomain(value: string): string {
  let subdomain = value.trim();
  const host = /^[a-z][a-z0-9+.-]*:\/\/([^/?#:]+)/i.exec(subdomain);
  if (host !== null && host[1] !== undefined) {
    subdomain = host[1];
  }
  return subdomain
    .replace(/\.sendsmaily\.net.*$/i, '')
    .replace(/^[\s/.]+|[\s/.]+$/g, '')
    .toLowerCase();
}

function accountSubdomains(payload: Record<string, unknown>): Record<string, string> {
  const subdomains: Record<string, string> = {
    default: subdomainOf(payload['smailyCredentials']),
    transactional: subdomainOf(payload['transactionalCredentials']),
  };
  const accounts = Array.isArray(payload['perLanguageAccounts']) ? payload['perLanguageAccounts'] : [];
  for (const account of accounts as Array<{ accountKey?: unknown; credentials?: unknown }>) {
    if (typeof account.accountKey === 'string' && account.accountKey !== '') {
      subdomains[account.accountKey.toLowerCase()] = subdomainOf(account.credentials);
    }
  }
  return subdomains;
}

function subdomainOf(credentials: unknown): string {
  const subdomain = (credentials as { subdomain?: unknown } | null | undefined)?.subdomain;
  return typeof subdomain === 'string' ? normaliseSubdomain(subdomain) : '';
}

function automationName(trigger: AutomationTrigger): string {
  switch (trigger) {
    case 'welcome':
      return __('Welcome email', 'smaily-connect');
    case 'first_order':
      return __('First-order email', 'smaily-connect');
    case 'abandoned_cart':
      return __('Abandoned-cart reminder', 'smaily-connect');
    case 'order_confirmation':
      return __('Order confirmation', 'smaily-connect');
    case 'shipping_confirmation':
      return __('Shipping confirmation', 'smaily-connect');
    case 'follow_up':
      return __('Follow-up email', 'smaily-connect');
    case 'payment_reminder':
      return __('Payment reminder', 'smaily-connect');
  }
}
