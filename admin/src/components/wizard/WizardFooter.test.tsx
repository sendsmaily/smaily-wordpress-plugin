import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { WizardFooter } from './WizardFooter';

/**
 * The footer's logic (which button renders + when Back shows) is the
 * one piece of wizard-shell behaviour that genuinely needs test coverage
 * — the rest is visual styling. Coverage threshold for this directory
 * stays unset per Erkki's sub-PR 2.D spec; these tests exist for
 * confidence, not as a gate.
 */

describe('WizardFooter', () => {
  it('hides Back on step 1', () => {
    render(
      <WizardFooter
        currentStep={1}
        totalSteps={6}
        canAdvance
        onBack={vi.fn()}
        onContinue={vi.fn()}
      />,
    );

    expect(screen.queryByRole('button', { name: /back/i })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: /continue/i })).toBeInTheDocument();
  });

  it('shows Back on every step after the first', () => {
    render(
      <WizardFooter
        currentStep={3}
        totalSteps={6}
        canAdvance
        onBack={vi.fn()}
        onContinue={vi.fn()}
      />,
    );

    expect(screen.getByRole('button', { name: /back/i })).toBeInTheDocument();
  });

  it('renders Finish (pink, NOT green) on the last step', () => {
    render(
      <WizardFooter
        currentStep={6}
        totalSteps={6}
        canAdvance
        onBack={vi.fn()}
        onFinish={vi.fn()}
      />,
    );

    const finish = screen.getByRole('button', { name: /finish/i });
    expect(finish).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /continue/i })).not.toBeInTheDocument();

    // STYLE_MAPPING.md §2.2 correction: Finish uses primary brand-pink,
    // not the earlier green proposal. Asserting the class is fragile
    // but worth pinning here so a future refactor doesn't quietly
    // resurrect the green variant.
    expect(finish.className).toContain('bg-brand');
    expect(finish.className).not.toContain('bg-success');
  });

  it('disables Continue when canAdvance is false', () => {
    render(
      <WizardFooter
        currentStep={2}
        totalSteps={6}
        canAdvance={false}
        onContinue={vi.fn()}
      />,
    );

    expect(screen.getByRole('button', { name: /continue/i })).toBeDisabled();
  });

  it('surfaces advanceHint only when canAdvance is false', () => {
    const { rerender } = render(
      <WizardFooter
        currentStep={2}
        totalSteps={6}
        canAdvance={false}
        advanceHint="Fill the Smaily credentials first."
        onContinue={vi.fn()}
      />,
    );

    expect(screen.getByText(/fill the smaily credentials/i)).toBeInTheDocument();

    rerender(
      <WizardFooter
        currentStep={2}
        totalSteps={6}
        canAdvance
        advanceHint="Fill the Smaily credentials first."
        onContinue={vi.fn()}
      />,
    );

    expect(screen.queryByText(/fill the smaily credentials/i)).not.toBeInTheDocument();
  });

  it('calls onContinue for non-last steps, onFinish on the last', async () => {
    const onContinue = vi.fn();
    const onFinish = vi.fn();
    const user = (await import('@testing-library/react')).fireEvent;

    const { rerender } = render(
      <WizardFooter
        currentStep={4}
        totalSteps={6}
        canAdvance
        onContinue={onContinue}
        onFinish={onFinish}
      />,
    );

    user.click(screen.getByRole('button', { name: /continue/i }));
    expect(onContinue).toHaveBeenCalledTimes(1);
    expect(onFinish).not.toHaveBeenCalled();

    rerender(
      <WizardFooter
        currentStep={6}
        totalSteps={6}
        canAdvance
        onContinue={onContinue}
        onFinish={onFinish}
      />,
    );

    user.click(screen.getByRole('button', { name: /finish/i }));
    expect(onFinish).toHaveBeenCalledTimes(1);
    expect(onContinue).toHaveBeenCalledTimes(1); // Unchanged
  });
});
