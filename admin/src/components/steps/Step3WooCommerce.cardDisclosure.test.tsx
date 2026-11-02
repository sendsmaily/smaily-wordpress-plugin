import { fireEvent, render, screen, within } from '@testing-library/react';
import { useReducer } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { listWorkflows } from '../../api/workflows';
import { _resetUseWorkflowsCache } from '../../hooks/useWorkflows';
import { buildTabPayload } from '../../state/buildTabPayload';
import { buildSettingsInitialState } from '../../state/settings-reducer';
import { type WizardState } from '../../state/types';
import { wizardReducer } from '../../state/wizard-reducer';
import { Step3WooCommerce } from './Step3WooCommerce';

vi.mock('../../api/workflows', () => ({ listWorkflows: vi.fn() }));

/**
 * The three store-run cards as the wizard and Settings render them
 * (CON-255): an off card is its title, description and switch; an on card
 * shows the workflow, its own settings, and the discount code block last.
 * Switching a card off hides its values but keeps them in the save.
 */

const CARDS = [
  ['Welcome email', 'Enable welcome email', 'welcome'],
  ['First-order email', 'Enable first-order email', 'first_order'],
  ['Abandoned-cart reminder', 'Enable abandoned-cart reminder', 'abandoned_cart'],
] as const;

function baseState(): WizardState {
  const base = buildSettingsInitialState({ smailyConnected: true });
  return {
    ...base,
    env: { ...base.env, discountTemplates: [{ id: 12, label: 'WELCOME15 — Percentage discount: 15' }] },
  };
}

function Harness({
  initial,
  onState,
}: {
  initial: WizardState;
  onState?: (state: WizardState) => void;
}): React.JSX.Element {
  const [state, dispatch] = useReducer(wizardReducer, initial);
  onState?.(state);
  return <Step3WooCommerce state={state} dispatch={dispatch} inSettings={false} />;
}

function cardOf(title: string): HTMLElement {
  const card = screen.getByRole('heading', { name: title }).parentElement?.parentElement?.parentElement;
  if (!(card instanceof HTMLElement)) {
    throw new Error(`No card for ${title}`);
  }
  return card;
}

function follows(a: Element, b: Element): boolean {
  return (a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0;
}

describe('Step3WooCommerce — automation cards (CON-255)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    _resetUseWorkflowsCache();
    vi.mocked(listWorkflows).mockResolvedValue({
      workflows: [{ id: '11', name: 'Store flow', status: 'ACTIVE' }],
    });
  });

  afterEach(() => {
    _resetUseWorkflowsCache();
  });

  it('shows no workflow, settings or discount code on a card that is off', () => {
    render(<Harness initial={baseState()} />);

    for (const [title, toggleName] of CARDS) {
      const card = cardOf(title);
      expect(within(card).getByRole('switch', { name: toggleName })).not.toBeChecked();
      expect(within(card).getAllByRole('switch')).toHaveLength(1);
      expect(within(card).queryByRole('combobox')).not.toBeInTheDocument();
      expect(within(card).queryByRole('spinbutton')).not.toBeInTheDocument();
      expect(within(card).queryByText('Send a discount code')).not.toBeInTheDocument();
    }
    expect(screen.queryByLabelText('Cutoff time')).not.toBeInTheDocument();
  });

  it('shows the workflow, then the cutoff, then the discount code last on the abandoned-cart card', async () => {
    render(<Harness initial={{ ...baseState(), abandonedCartEnabled: true }} />);
    const card = cardOf('Abandoned-cart reminder');
    await within(card).findByRole('option', { name: 'Store flow' });

    const workflow = within(card).getByRole('combobox');
    const cutoff = within(card).getByLabelText('Cutoff time');
    const discount = within(card).getByRole('switch', { name: 'Send a discount code' });

    expect(follows(workflow, cutoff)).toBe(true);
    expect(follows(cutoff, discount)).toBe(true);
    // Nothing on the card comes after the discount block.
    expect(card.lastElementChild?.lastElementChild?.contains(discount)).toBe(true);
  });

  it.each(CARDS)('offers the discount code on the %s card only while the automation is on', async (title, toggleName) => {
    render(<Harness initial={baseState()} />);
    const card = cardOf(title);

    expect(within(card).queryByRole('switch', { name: 'Send a discount code' })).not.toBeInTheDocument();
    fireEvent.click(within(card).getByRole('switch', { name: toggleName }));
    await within(card).findByRole('option', { name: 'Store flow' });

    const workflow = within(card).getByRole('combobox');
    const discount = within(card).getByRole('switch', { name: 'Send a discount code' });
    expect(follows(workflow, discount)).toBe(true);

    fireEvent.click(within(card).getByRole('switch', { name: toggleName }));
    expect(within(card).queryByRole('switch', { name: 'Send a discount code' })).not.toBeInTheDocument();
  });

  it('keeps the workflow, cutoff and discount code in the save while off, and shows them again when on', async () => {
    let latest: WizardState = { ...baseState(), abandonedCartEnabled: true };
    render(<Harness initial={latest} onState={(s) => { latest = s; }} />);
    const card = cardOf('Abandoned-cart reminder');
    await within(card).findByRole('option', { name: 'Store flow' });

    fireEvent.change(within(card).getByRole('combobox'), { target: { value: '11' } });
    fireEvent.change(within(card).getByLabelText('Cutoff time'), { target: { value: '45' } });
    fireEvent.click(within(card).getByRole('switch', { name: 'Send a discount code' }));
    fireEvent.change(within(card).getByRole('combobox', { name: 'Discount (WooCommerce coupon)' }), {
      target: { value: '12' },
    });
    fireEvent.change(within(card).getByRole('spinbutton', { name: 'Valid for (days)' }), { target: { value: '14' } });

    const saved = {
      abandonedCartCutoffMinutes: 45,
      automationDiscounts: { abandoned_cart: { enabled: true, templateId: 12, validDays: 14 } },
      automationMappings: [expect.objectContaining({ triggerType: 'abandoned_cart', workflowId: '11' })],
    };

    fireEvent.click(within(card).getByRole('switch', { name: 'Enable abandoned-cart reminder' }));
    expect(within(card).queryByRole('combobox')).not.toBeInTheDocument();
    expect(buildTabPayload(latest, 'woocommerce')).toMatchObject({ abandonedCartEnabled: false, ...saved });

    fireEvent.click(within(card).getByRole('switch', { name: 'Enable abandoned-cart reminder' }));
    await within(card).findAllByRole('option', { name: 'Store flow' });
    expect(buildTabPayload(latest, 'woocommerce')).toMatchObject({ abandonedCartEnabled: true, ...saved });
    expect(within(card).getByLabelText('Cutoff time')).toHaveValue(45);
    expect(within(card).getByRole('switch', { name: 'Send a discount code' })).toBeChecked();
    expect(within(card).getByRole('combobox', { name: 'Discount (WooCommerce coupon)' })).toHaveValue('12');
    expect(within(card).getByRole('spinbutton', { name: 'Valid for (days)' })).toHaveValue(14);
  });
});
