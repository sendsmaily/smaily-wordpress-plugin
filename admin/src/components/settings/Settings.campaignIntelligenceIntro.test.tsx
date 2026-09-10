import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { listWorkflows } from '../../api/workflows';
import { _resetUseWorkflowsCache } from '../../hooks/useWorkflows';
import { buildSettingsInitialState } from '../../state/settings-reducer';
import { type WizardState } from '../../state/types';
import { Settings } from './Settings';

/**
 * PRO-1725 — the Campaign Intelligence tab carries the same two-paragraph
 * introduction the wizard step has, while the engine is not connected. Once
 * it is connected the tab is a management screen and the pitch is gone.
 */
vi.mock('../../api/workflows', () => ({
  listWorkflows: vi.fn(),
}));

const workflowsMock = vi.mocked(listWorkflows);

const INTRO =
  'Campaign Intelligence uses your store’s product, customer and order data to create personalised product recommendations for Smaily campaigns and automations. This helps you send more relevant emails with less manual work.';
const PRICING =
  'Campaign Intelligence is an optional paid add-on (€250/month), added to your regular Smaily monthly payment. Contact Smaily to activate it, or set it up later.';

/** The Estonian msgstr the shipped catalog holds for a source string. */
function estonianFor(msgid: string): string {
  const catalog = readFileSync(
    resolve(__dirname, '../../../../languages/smaily-connect-et.po'),
    'utf8',
  );
  const escaped = msgid.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const match = catalog.match(new RegExp(`^msgid "${escaped}"\\nmsgstr "(.+)"$`, 'm'));
  return match?.[1] ?? '';
}

function settingsState(overrides: Partial<WizardState> = {}): WizardState {
  return {
    ...buildSettingsInitialState({ smailyConnected: true }),
    ...overrides,
  };
}

function renderTab(overrides: Partial<WizardState> = {}): void {
  window.location.hash = 'recommendations';
  render(<Settings initialState={settingsState(overrides)} />);
}

describe('Settings — Campaign Intelligence introduction on the tab (PRO-1725)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    _resetUseWorkflowsCache();
    workflowsMock.mockResolvedValue({ workflows: [] });
  });

  afterEach(() => {
    _resetUseWorkflowsCache();
    window.location.hash = '';
    delete (window as unknown as { wp?: unknown }).wp;
  });

  it('shows both paragraphs while Campaign Intelligence is not connected', () => {
    renderTab();

    expect(screen.getByText(INTRO)).toBeInTheDocument();
    expect(screen.getByText(PRICING)).toBeInTheDocument();
  });

  it('shows them in Estonian when the admin runs in Estonian', () => {
    const intro = estonianFor(INTRO);
    const pricing = estonianFor(PRICING);
    expect(intro).not.toBe('');
    expect(pricing).not.toBe('');

    const translations: Record<string, string> = { [INTRO]: intro, [PRICING]: pricing };
    (window as unknown as { wp: unknown }).wp = {
      i18n: { __: (text: string): string => translations[text] ?? text },
    };

    renderTab();

    expect(screen.getByText(intro)).toBeInTheDocument();
    expect(screen.getByText(pricing)).toBeInTheDocument();
  });

  it('drops them once Campaign Intelligence is connected', () => {
    renderTab({ recEngineConnection: { kind: 'success', message: 'Test tenant' } });

    expect(screen.queryByText(INTRO)).not.toBeInTheDocument();
    expect(screen.queryByText(PRICING)).not.toBeInTheDocument();
  });
});
