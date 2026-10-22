import { render, screen, waitFor, within } from '@testing-library/react';
import { useReducer } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { getAutomationsCatalog, getAutomationsConfig } from '../../api/automations';
import { getEmailDesigns, type EmailDesign } from '../../api/emailDesigns';
import { listWorkflows } from '../../api/workflows';
import { _resetUseWorkflowsCache } from '../../hooks/useWorkflows';
import { buildSettingsInitialState } from '../../state/settings-reducer';
import { type WizardState } from '../../state/types';
import { wizardReducer } from '../../state/wizard-reducer';
import { Step3WooCommerce } from './Step3WooCommerce';

vi.mock('../../api/automations', async (importOriginal) => {
  const original = await importOriginal<Record<string, unknown>>();
  return { ...original, getAutomationsCatalog: vi.fn(), getAutomationsConfig: vi.fn() };
});
vi.mock('../../api/emailDesigns', async (importOriginal) => {
  const original = await importOriginal<Record<string, unknown>>();
  return { ...original, getEmailDesigns: vi.fn(), getDesignLink: vi.fn() };
});
vi.mock('../../api/workflows', () => ({ listWorkflows: vi.fn() }));

function item(email_key: string, kind: string): EmailDesign {
  return {
    email_key,
    kind,
    name_et: email_key,
    name_en: email_key,
    sent_through: kind === 'transactional' ? 'transactional' : 'workflow',
    design_state: 'none',
    subject: null,
    sender: null,
    version: 0,
    smaily_state: 'not_available',
    smaily_template_id: null,
    smaily_workflow_id: null,
    smaily_pushed_at: null,
    updated_at: null,
  };
}

/** Card title → the §22 key whose button it must carry. */
const CARDS: Array<[string, string]> = [
  ['Welcome email', 'plugin.welcome'],
  ['First-order email', 'plugin.first_order'],
  ['Abandoned-cart reminder', 'plugin.abandoned_cart'],
  ['Order confirmation', 'transactional.order_confirmation'],
  ['Shipping confirmation', 'transactional.shipping_confirmation'],
  ['Follow-up email', 'plugin.follow_up'],
  ['Replenishment due', 'replenish_due'],
];

function connectedState(): WizardState {
  return {
    ...buildSettingsInitialState({ smailyConnected: true, detectedLanguages: ['en'] }),
    recEngineConnection: { kind: 'success', message: 'Test tenant' },
    transactionalConnection: { kind: 'success' },
  };
}

function Harness({ initial, inSettings }: { initial: WizardState; inSettings: boolean }): React.JSX.Element {
  const [state, dispatch] = useReducer(wizardReducer, initial);
  return <Step3WooCommerce state={state} dispatch={dispatch} inSettings={inSettings} />;
}

function cardOf(title: string): HTMLElement {
  const card = screen.getByRole('heading', { name: title }).parentElement?.parentElement?.parentElement;
  if (!(card instanceof HTMLElement)) {
    throw new Error(`No card for ${title}`);
  }
  return card;
}

describe('Step3WooCommerce — Edit design', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    _resetUseWorkflowsCache();
    vi.mocked(listWorkflows).mockResolvedValue({ workflows: [] });
    vi.mocked(getAutomationsCatalog).mockResolvedValue({
      triggers: [
        {
          key: 'replenish_due',
          name_et: 'Taastäitumine',
          name_en: 'Replenishment due',
          description_et: 'Kirjeldus.',
          description_en: 'Description.',
          recipe_et: 'Retsept.',
        },
      ],
      language_modes: ['single', 'per_language'],
      docs: 'https://intelligence.smaily.com/docs',
    });
    vi.mocked(getAutomationsConfig).mockResolvedValue({ configs: [] });
  });

  for (const inSettings of [true, false]) {
    it(`puts the button on every listed email's card (${inSettings ? 'Settings' : 'setup wizard'})`, async () => {
      vi.mocked(getEmailDesigns).mockResolvedValue({
        emails: [
          ...CARDS.map(([, key]) => item(key, key.startsWith('transactional.') ? 'transactional' : key.startsWith('plugin.') ? 'plugin' : 'engine')),
          item('an_engine_email_with_no_card', 'engine'),
        ],
      });

      render(<Harness initial={connectedState()} inSettings={inSettings} />);

      // The engine card renders only after its own two reads; under a full
      // parallel run that can take longer than waitFor's 1 s default.
      await waitFor(() => expect(screen.getAllByRole('button', { name: 'Edit design' })).toHaveLength(CARDS.length), { timeout: 5000 });
      for (const [title] of CARDS) {
        expect(within(cardOf(title)).getByRole('button', { name: 'Edit design' })).toBeInTheDocument();
      }
      expect(getEmailDesigns).toHaveBeenCalledTimes(1);
    });
  }

  it('shows no button on a card whose email the list leaves out', async () => {
    vi.mocked(getEmailDesigns).mockResolvedValue({ emails: [item('plugin.welcome', 'plugin')] });

    render(<Harness initial={connectedState()} inSettings />);

    await waitFor(() => expect(screen.getAllByRole('button', { name: 'Edit design' })).toHaveLength(1));
    expect(within(cardOf('Welcome email')).getByRole('button', { name: 'Edit design' })).toBeInTheDocument();
  });
});
