import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { wizardInitialState } from '../../state/wizard-reducer';
import { Step6Done } from './Step6Done';

describe('Step6Done', () => {
  it('renders the static summary frame even when nothing is configured', () => {
    render(<Step6Done state={wizardInitialState} />);

    expect(screen.getByText(/overview/i)).toBeInTheDocument();
    expect(screen.getByText(/what's active/i)).toBeInTheDocument();
  });

  it('marks Smaily connected with success indicator when state.smailyConnection is success', () => {
    const seeded = { ...wizardInitialState, smailyConnection: { kind: 'success' as const } };
    render(<Step6Done state={seeded} />);

    // The success row contains a ✓ indicator + label
    const row = screen.getByText('Smaily connected').closest('li');
    expect(row?.textContent).toContain('✓');
  });

  it('reflects automation toggles in the summary list', () => {
    const seeded = {
      ...wizardInitialState,
      welcomeEnabled: true,
      firstOrderEnabled: false,
      abandonedCartEnabled: true,
      abandonedCartCutoffMinutes: 45,
    };

    render(<Step6Done state={seeded} />);

    // Welcome row marked active
    const welcomeRow = screen.getByText('Welcome email').closest('li');
    expect(welcomeRow?.textContent).toContain('✓');

    // First-order row marked inactive
    const firstOrderRow = screen.getByText('First-order email').closest('li');
    expect(firstOrderRow?.textContent).toContain('○');

    // Abandoned-cart detail includes the cutoff minutes
    expect(screen.getByText(/45-minute cutoff/i)).toBeInTheDocument();
  });

  it('hides rec-engine dashboard button when not connected', () => {
    render(<Step6Done state={wizardInitialState} />);

    expect(
      screen.queryByRole('button', { name: /open campaign intelligence dashboard/i }),
    ).not.toBeInTheDocument();
  });

  it('exposes the rec-engine dashboard button when the rec engine is connected', () => {
    const seeded = {
      ...wizardInitialState,
      smailyConnection: { kind: 'success' as const },
      recEngineConnection: { kind: 'success' as const },
    };
    render(<Step6Done state={seeded} />);

    expect(
      screen.getByRole('button', { name: /open campaign intelligence dashboard/i }),
    ).toBeInTheDocument();
  });

  it('does not advertise the Event Log (PRO-1720)', () => {
    render(<Step6Done state={wizardInitialState} />);

    expect(screen.queryByText(/event log/i)).not.toBeInTheDocument();
  });

  it('leaves the Smaily account link to step 1 (PRO-1718)', () => {
    const seeded = {
      ...wizardInitialState,
      smailyConnection: { kind: 'success' as const },
      smailyCredentials: { subdomain: 'petshop', username: 'api', password: 'x' },
    };
    render(<Step6Done state={seeded} />);

    expect(
      screen.queryByRole('button', { name: /open smaily dashboard/i }),
    ).not.toBeInTheDocument();
  });
});
