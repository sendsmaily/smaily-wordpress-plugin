import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { wizardInitialState } from '../../state/wizard-reducer';
import { type WizardState } from '../../state/types';
import { Step2Subscribers } from './Step2Subscribers';

/**
 * PRO-1716: the "Force opt-in on automation triggers" control is gone.
 * Automation triggers behave the same for every store — they enrol a contact
 * but never override an unsubscribe — so there is nothing per-store to choose.
 * The control only ever rendered under the legitimate-interest preset, which
 * is the state pinned here. Step 2 is the same component in the wizard and in
 * Settings, so one render covers both surfaces.
 */
describe('Step2Subscribers — no force-opt-in control (PRO-1716)', () => {
  const legitimateInterest: WizardState = {
    ...wizardInitialState,
    contactSyncMode: 'legitimate_interest',
  };

  it('renders no force-opt-in control under the legitimate-interest preset', () => {
    render(<Step2Subscribers state={legitimateInterest} dispatch={vi.fn()} />);

    // The preset itself still renders — otherwise this would pass vacuously.
    expect(screen.getByText(/lawful basis \(legitimate interest\)/i)).toBeInTheDocument();

    expect(screen.queryByLabelText(/force opt-in/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/re-subscribe the contact in Smaily/i)).not.toBeInTheDocument();
  });
});
