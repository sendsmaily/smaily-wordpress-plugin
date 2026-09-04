import { fireEvent, render, screen } from '@testing-library/react';
import { useReducer } from 'react';
import { describe, expect, it } from 'vitest';

import { buildSettingsInitialState } from '../../state/settings-reducer';
import { type WizardState } from '../../state/types';
import { wizardReducer } from '../../state/wizard-reducer';
import { TransactionalEmailsSection } from './TransactionalEmailsSection';

function baseState(): WizardState {
  return { ...buildSettingsInitialState() };
}

function Harness({ initial }: { initial: WizardState }): React.JSX.Element {
  const [state, dispatch] = useReducer(wizardReducer, initial);
  return <TransactionalEmailsSection state={state} dispatch={dispatch} inSettings />;
}

describe('TransactionalEmailsSection', () => {
  it('renders only the enablement toggle when off — no credential fields', () => {
    render(<Harness initial={baseState()} />);

    expect(screen.getByRole('switch', { name: /send transactional emails/i })).not.toBeChecked();
    expect(screen.queryByLabelText(/subdomain/i)).not.toBeInTheDocument();
  });

  it('reveals the transactional credential block when enabled', () => {
    render(<Harness initial={baseState()} />);

    fireEvent.click(screen.getByRole('switch', { name: /send transactional emails/i }));

    expect(screen.getByLabelText(/subdomain/i)).toBeInTheDocument();
    // No trigger sections here anymore — they live on the WooCommerce tab
    // (TransactionalTriggersSection, PRO-1540).
    expect(screen.queryByRole('heading', { name: 'Order confirmation' })).not.toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: 'Shipping confirmation' })).not.toBeInTheDocument();
  });

  it('never describes the account as a "sub-account" (PRO-1540 copy fix)', () => {
    render(<Harness initial={baseState()} />);
    fireEvent.click(screen.getByRole('switch', { name: /send transactional emails/i }));

    expect(document.body.textContent ?? '').not.toMatch(/sub-account/i);
  });
});
