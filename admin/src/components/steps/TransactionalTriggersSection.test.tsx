import { fireEvent, render, screen } from '@testing-library/react';
import { useReducer } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { listWorkflows } from '../../api/workflows';
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

function Harness({ initial }: { initial: WizardState }): React.JSX.Element {
  const [state, dispatch] = useReducer(wizardReducer, initial);
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
});
