import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { StepRail, type StepRailItem } from './StepRail';

const fakeSteps: StepRailItem[] = [
  { id: 1, label: 'Connect', completed: true },
  { id: 2, label: 'Subscribers', completed: true },
  { id: 3, label: 'WooCommerce', completed: false },
  { id: 4, label: 'Recommendations', completed: false },
  { id: 5, label: 'Integrations', completed: false },
  { id: 6, label: 'Done', completed: false },
];

describe('StepRail', () => {
  it('marks the active step with aria-current="step"', () => {
    render(<StepRail currentStep={3} steps={fakeSteps} />);

    const wooCommerceLi = screen.getByText('WooCommerce').closest('li');
    expect(wooCommerceLi?.querySelector('[aria-current="step"]')).toBeTruthy();
  });

  it('renders completed steps as buttons when onStepClick is provided', () => {
    const onClick = vi.fn();
    render(<StepRail currentStep={3} steps={fakeSteps} onStepClick={onClick} />);

    // Connect and Subscribers are completed → clickable
    const connectButton = screen.getByRole('button', { name: /connect/i });
    fireEvent.click(connectButton);
    expect(onClick).toHaveBeenCalledWith(1);
  });

  it('renders pending steps as non-interactive (no button role)', () => {
    render(<StepRail currentStep={3} steps={fakeSteps} onStepClick={vi.fn()} />);

    // Step 4 (Recommendations) is pending and not active → no button
    expect(screen.queryByRole('button', { name: /recommendations/i })).not.toBeInTheDocument();
  });

  it('makes the active step clickable too (when onStepClick is set)', () => {
    const onClick = vi.fn();
    render(<StepRail currentStep={3} steps={fakeSteps} onStepClick={onClick} />);

    fireEvent.click(screen.getByRole('button', { name: /woocommerce/i }));
    expect(onClick).toHaveBeenCalledWith(3);
  });

  it('renders all steps as non-interactive when onStepClick is omitted', () => {
    render(<StepRail currentStep={3} steps={fakeSteps} />);

    expect(screen.queryByRole('button')).not.toBeInTheDocument();
  });
});
