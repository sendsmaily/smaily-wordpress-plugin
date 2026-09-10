import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { wizardInitialState } from '../../state/wizard-reducer';
import { Step4Recommendations } from './Step4Recommendations';

const INTRO =
  'Campaign Intelligence uses your store’s product, customer and order data to create personalised product recommendations for Smaily campaigns and automations. This helps you send more relevant emails with less manual work.';
const PRICING =
  'Campaign Intelligence is an optional paid add-on (€250/month), added to your regular Smaily monthly payment. Contact Smaily to activate it, or set it up later.';

describe('Step4Recommendations — the wizard header introduces Campaign Intelligence (PRO-2298)', () => {
  it('shows both introduction paragraphs in the wizard', () => {
    render(<Step4Recommendations state={wizardInitialState} dispatch={vi.fn()} />);

    expect(screen.getByText(INTRO)).toBeInTheDocument();
    expect(screen.getByText(PRICING)).toBeInTheDocument();
  });

  it('no longer shows the earlier introduction sentence', () => {
    render(<Step4Recommendations state={wizardInitialState} dispatch={vi.fn()} />);

    expect(screen.queryByText(/Sync product, customer and order data/)).not.toBeInTheDocument();
  });

  it('omits the wizard step heading in Settings but keeps the introduction (PRO-1725)', () => {
    render(<Step4Recommendations state={wizardInitialState} dispatch={vi.fn()} inSettings />);

    expect(screen.queryByText('Step 4 of 6')).not.toBeInTheDocument();
    expect(screen.getByText(INTRO)).toBeInTheDocument();
    expect(screen.getByText(PRICING)).toBeInTheDocument();
  });

  it('renders the very same paragraphs in the wizard and in Settings (PRO-1725)', () => {
    const wizard = render(
      <Step4Recommendations state={wizardInitialState} dispatch={vi.fn()} />,
    );
    const wizardText = [screen.getByText(INTRO), screen.getByText(PRICING)].map(
      (node) => node.textContent,
    );
    wizard.unmount();

    render(<Step4Recommendations state={wizardInitialState} dispatch={vi.fn()} inSettings />);

    expect([screen.getByText(INTRO), screen.getByText(PRICING)].map((n) => n.textContent)).toEqual(
      wizardText,
    );
  });
});

describe('Step4Recommendations — a deactivated account is stated, not disguised (PRO-1893)', () => {
  const connected = {
    ...wizardInitialState,
    recEngineConnection: { kind: 'success', message: 'Acme Pets' },
  } as typeof wizardInitialState;

  it('shows the tenant as connected while the account is live', () => {
    render(<Step4Recommendations state={connected} dispatch={vi.fn()} />);

    expect(screen.getByText('Acme Pets')).toBeInTheDocument();
    expect(screen.queryByText('Account deactivated')).not.toBeInTheDocument();
  });

  it('replaces the connected tick with the deactivated banner', () => {
    render(
      <Step4Recommendations
        state={{ ...connected, recEngineRefused: true }}
        dispatch={vi.fn()}
      />,
    );

    expect(screen.getByText('Account deactivated')).toBeInTheDocument();
    expect(
      screen.getByText(/Contact Smaily to reactivate it\./),
    ).toBeInTheDocument();
    // No green "Connected as <tenant>" while nothing is being sent.
    expect(screen.queryByText('Connected')).not.toBeInTheDocument();
  });
});
