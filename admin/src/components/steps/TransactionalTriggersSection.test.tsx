import { fireEvent, render, screen, within } from '@testing-library/react';
import { useReducer } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { listWorkflows } from '../../api/workflows';
import { buildTabPayload } from '../../state/buildTabPayload';
import { _resetUseWorkflowsCache } from '../../hooks/useWorkflows';
import { buildSettingsInitialState } from '../../state/settings-reducer';
import { type WizardState } from '../../state/types';
import { wizardReducer } from '../../state/wizard-reducer';
import { TransactionalTriggersSection } from './TransactionalTriggersSection';

vi.mock('../../api/workflows', () => ({
  listWorkflows: vi.fn(),
}));

const workflowsMock = vi.mocked(listWorkflows);

function baseState(): WizardState {
  return {
    ...buildSettingsInitialState({
      orderStatuses: [
        { slug: 'completed', name: 'Completed' },
        { slug: 'shipped', name: 'Shipped' },
      ],
    }),
  };
}

function connectedState(): WizardState {
  return { ...baseState(), transactionalConnection: { kind: 'success' } };
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
  return <TransactionalTriggersSection state={state} dispatch={dispatch} />;
}

describe('TransactionalTriggersSection', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    _resetUseWorkflowsCache();
    workflowsMock.mockResolvedValue({
      workflows: [{ id: '77', name: 'Order confirmed', status: 'ACTIVE' }],
    });
  });

  afterEach(() => {
    _resetUseWorkflowsCache();
  });

  it('renders nothing — no placeholder, no pointer — when the transactional account is not connected', () => {
    const { container } = render(<Harness initial={baseState()} />);

    expect(container).toBeEmptyDOMElement();
    expect(screen.queryByRole('heading', { name: 'Order confirmation' })).not.toBeInTheDocument();
    expect(workflowsMock).not.toHaveBeenCalled();
  });

  it('renders both trigger sections once the transactional account is connected', async () => {
    render(<Harness initial={connectedState()} />);

    expect(screen.getByRole('heading', { name: 'Order confirmation' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Shipping confirmation' })).toBeInTheDocument();

    // Workflow dropdowns for the two triggers fetch the TRANSACTIONAL
    // account's list, never the default account's — both dropdowns show
    // the same option, hence findAllByRole (findByRole would race between
    // the two independently-resolving selects).
    await screen.findAllByRole('option', { name: 'Order confirmed' });
    expect(workflowsMock).toHaveBeenCalledWith('transactional');
    expect(workflowsMock).not.toHaveBeenCalledWith('default');
  });

  it('lists the "counts as shipped" statuses from env.orderStatuses and toggles selection', async () => {
    render(<Harness initial={connectedState()} />);

    // Let the workflow-fetch effects settle before interacting, so the
    // background state update doesn't land after the test finishes.
    await screen.findAllByRole('option', { name: 'Order confirmed' });

    const completed = screen.getByRole('checkbox', { name: 'Completed' });
    const shipped = screen.getByRole('checkbox', { name: 'Shipped' });
    expect(completed).not.toBeChecked();
    expect(shipped).not.toBeChecked();

    fireEvent.click(completed);
    expect(completed).toBeChecked();
    expect(shipped).not.toBeChecked();
  });

  it('shows one row per language on a multilingual store, whatever the multilingual mode, all on the transactional account', async () => {
    // PRO-3187: Mode C (branching inside Smaily) can't apply to a
    // single-section transactional workflow, so the rows show anyway.
    const initial: WizardState = {
      ...connectedState(),
      multilingualMode: 'C',
      env: { ...connectedState().env, detectedLanguages: ['et', 'en'] },
    };
    let latest = initial;
    render(<Harness initial={initial} onState={(s) => { latest = s; }} />);

    await screen.findAllByRole('option', { name: 'Order confirmed' });
    // Two triggers × two languages.
    expect(screen.getAllByRole('combobox')).toHaveLength(4);
    expect(screen.getAllByRole('radio', { name: 'Mark en as default fallback' })).toHaveLength(2);

    const [, orderEn] = screen.getAllByRole('combobox');
    fireEvent.change(orderEn!, { target: { value: '77' } });

    expect(latest.automationMappings).toContainEqual(
      expect.objectContaining({
        triggerType: 'order_confirmation',
        language: 'en',
        accountKey: 'transactional',
        workflowId: '77',
      }),
    );
    expect(workflowsMock).not.toHaveBeenCalledWith('account_en');
  });

  it('keeps the customer-details switch off by default and saves it with the WooCommerce tab once switched on', async () => {
    // PRO-3190: addresses, phone and order note go into the confirmations
    // only when the merchant opts in.
    let latest = connectedState();
    render(<Harness initial={latest} onState={(s) => { latest = s; }} />);
    await screen.findAllByRole('option', { name: 'Order confirmed' });

    const toggle = screen.getByRole('switch', { name: /Include addresses, phone and order note/ });
    expect(toggle).not.toBeChecked();
    expect(buildTabPayload(latest, 'woocommerce')).toMatchObject({ transactionalPersonalData: false });

    fireEvent.click(toggle);

    expect(toggle).toBeChecked();
    expect(latest.transactionalPersonalData).toBe(true);
    expect(buildTabPayload(latest, 'woocommerce')).toMatchObject({ transactionalPersonalData: true });
  });

  describe('the status line on each confirmation card (PRO-4091)', () => {
    function readyState(overrides: Partial<WizardState> = {}): WizardState {
      return {
        ...connectedState(),
        transactionalEmailsEnabled: true,
        orderConfirmationEnabled: true,
        shippingConfirmationEnabled: true,
        transactionalStatus: {
          order_confirmation: { reason: '', workflowResolved: true, lastSentAt: '', untickedRecentStatuses: [] },
          shipping_confirmation: { reason: '', workflowResolved: true, lastSentAt: '', untickedRecentStatuses: [] },
        },
        ...overrides,
      };
    }

    function card(name: string): HTMLElement {
      const heading = screen.getByRole('heading', { name });
      const section = heading.parentElement?.parentElement?.parentElement;
      if (!(section instanceof HTMLElement)) {
        throw new Error(`No card for ${name}`);
      }
      return section;
    }

    it.each([
      ['switched_off', false, 'Not sending: Transactional emails are switched off. Switch on “Send transactional emails” on the Connection tab.'],
      ['trigger_off', false, 'Not sending: This email is switched off.'],
      ['no_workflow', false, 'Not sending: No workflow is chosen for this email.'],
      ['connection_incomplete', true, 'Not sending: The transactional Smaily account is not fully connected. Check its subdomain, username and password on the Connection tab.'],
      ['password_unreadable', true, 'Not sending: The saved password of the transactional Smaily account can no longer be read. Re-enter the password on the Connection tab.'],
    ])('names the first blocking reason: %s', async (reason, workflowResolved, sentence) => {
      render(
        <Harness
          initial={readyState({
            transactionalStatus: {
              shipping_confirmation: { reason, workflowResolved, lastSentAt: '2026-10-15 08:30:00', untickedRecentStatuses: [] },
            },
          })}
        />,
      );
      await screen.findAllByRole('option', { name: 'Order confirmed' });

      expect(within(card('Shipping confirmation')).getByText(sentence)).toBeInTheDocument();
      expect(within(card('Shipping confirmation')).queryByText(/Last sent/)).not.toBeInTheDocument();
    });

    it('says the saved workflow is not active in Smaily when the loaded list lacks it', async () => {
      render(
        <Harness
          initial={readyState({
            automationMappings: [
              { triggerType: 'shipping_confirmation', language: 'default', accountKey: 'transactional', workflowId: '99', isDefaultFallback: true },
            ],
          })}
        />,
      );
      await screen.findAllByRole('option', { name: 'Order confirmed' });

      expect(
        within(card('Shipping confirmation')).getByText(
          'Not sending: The chosen workflow is not active in Smaily. Activate it in Smaily or choose another workflow.',
        ),
      ).toBeInTheDocument();
    });

    it('shows when the email was last sent, or that nothing went out lately', async () => {
      render(
        <Harness
          initial={readyState({
            transactionalStatus: {
              order_confirmation: { reason: '', workflowResolved: true, lastSentAt: '2026-10-15 08:30:00', untickedRecentStatuses: [] },
              shipping_confirmation: { reason: '', workflowResolved: true, lastSentAt: '', untickedRecentStatuses: [] },
            },
          })}
        />,
      );
      await screen.findAllByRole('option', { name: 'Order confirmed' });

      expect(within(card('Order confirmation')).getByText(/^Last sent /)).toBeInTheDocument();
      expect(within(card('Shipping confirmation')).getByText('Nothing sent in the last 30 days.')).toBeInTheDocument();
    });

    it('warns a store whose orders never reach a ticked status, naming the statuses they reach', async () => {
      render(
        <Harness
          initial={readyState({
            transactionalStatus: {
              shipping_confirmation: { reason: '', workflowResolved: true, lastSentAt: '', untickedRecentStatuses: ['completed', 'shipped'] },
            },
          })}
        />,
      );
      await screen.findAllByRole('option', { name: 'Order confirmed' });

      expect(
        within(card('Shipping confirmation')).getByText(
          'No order in the last 30 days reached a status ticked under “Counts as shipped”. Recent orders are at: Completed, Shipped. Nothing is sent until orders reach a ticked status.',
        ),
      ).toBeInTheDocument();
    });

    it('explains that these emails skip the workflow, on each card', async () => {
      render(<Harness initial={readyState()} />);
      await screen.findAllByRole('option', { name: 'Order confirmed' });

      expect(screen.getAllByText(/the workflow shows no runs/)).toHaveLength(2);
    });

    it('does not call an email active while transactional emails are switched off', async () => {
      render(<Harness initial={readyState({ transactionalEmailsEnabled: false })} />);
      await screen.findAllByRole('option', { name: 'Order confirmed' });

      expect(within(card('Order confirmation')).queryByText('Active')).not.toBeInTheDocument();
      expect(within(card('Order confirmation')).getByText('Off')).toBeInTheDocument();
    });
  });

  it('keeps a single row on a one-language store', async () => {
    const initial: WizardState = {
      ...connectedState(),
      multilingualMode: 'B',
      env: { ...connectedState().env, detectedLanguages: ['et'] },
    };
    render(<Harness initial={initial} />);

    await screen.findAllByRole('option', { name: 'Order confirmed' });
    expect(screen.getAllByRole('combobox')).toHaveLength(2);
    expect(screen.queryByRole('radio')).not.toBeInTheDocument();
  });
});
