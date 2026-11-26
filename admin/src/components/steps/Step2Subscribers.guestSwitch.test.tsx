import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { wizardInitialState } from '../../state/wizard-reducer';
import { type WizardState } from '../../state/types';
import { Step2Subscribers } from './Step2Subscribers';

/**
 * CON-439: a guest who ticks the checkout newsletter box reaches Smaily in
 * every mode, so the guest switch only adds guests who did not tick. Only
 * "All customers (legitimate interest)" sends those, so only that preset shows
 * the switch. Step 2 is the same component in the wizard and in Settings.
 */
describe('Step2Subscribers — guest switch (CON-439)', () => {
  const withMode = (contactSyncMode: WizardState['contactSyncMode']): WizardState => ({
    ...wizardInitialState,
    contactSyncMode,
    includeGuests: true,
  });

  const label = /also sync guest buyers who did not tick the newsletter box/i;

  it('shows the switch under the legitimate-interest preset', () => {
    render(<Step2Subscribers state={withMode('legitimate_interest')} dispatch={vi.fn()} />);

    expect(screen.getByLabelText(label)).toBeChecked();
  });

  it.each(['consent', 'checkout_optin'] as const)('does not show the switch under the %s preset', (mode) => {
    render(<Step2Subscribers state={withMode(mode)} dispatch={vi.fn()} />);

    expect(screen.queryByLabelText(label)).not.toBeInTheDocument();
  });
});
