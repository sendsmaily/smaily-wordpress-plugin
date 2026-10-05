import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { wizardInitialState } from '../../state/wizard-reducer';
import { type WizardState } from '../../state/types';
import { Step2Subscribers } from './Step2Subscribers';

/**
 * PRO-3609: the "All customers (legitimate interest)" preset is the EU soft
 * opt-in. Where the merchant picks it, the warning names what soft opt-in
 * requires — marketing only about similar products, a clear way to refuse at
 * purchase, and the merchant's own responsibility for the legal basis. Step 2
 * is the same component in the wizard and in Settings, so one render covers
 * both surfaces.
 */
describe('Step2Subscribers — soft opt-in requirements (PRO-3609)', () => {
  const withMode = (contactSyncMode: WizardState['contactSyncMode']): WizardState => ({
    ...wizardInitialState,
    contactSyncMode,
  });

  it('names the three soft opt-in requirements under the legitimate-interest preset', () => {
    render(<Step2Subscribers state={withMode('legitimate_interest')} dispatch={vi.fn()} />);

    const banner = screen.getByRole('alert');
    expect(banner).toHaveTextContent(/soft opt-in/i);
    expect(banner).toHaveTextContent(/only about products similar to what they bought/i);
    expect(banner).toHaveTextContent(/a clear way to refuse marketing when they buy/i);
    expect(banner).toHaveTextContent(/the legal basis is your responsibility/i);
  });

  it('does not show the soft opt-in text under the consent preset', () => {
    render(<Step2Subscribers state={withMode('consent')} dispatch={vi.fn()} />);

    expect(screen.queryByText(/soft opt-in/i)).not.toBeInTheDocument();
  });
});
