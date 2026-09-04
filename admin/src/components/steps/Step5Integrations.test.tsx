import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { wizardInitialState } from '../../state/wizard-reducer';
import { Step5Integrations } from './Step5Integrations';

describe('Step5Integrations — how-to-add-a-form guide (PRO-1430)', () => {
  it('shows the shortcode and copies it to the clipboard', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.defineProperty(navigator, 'clipboard', {
      value: { writeText },
      configurable: true,
    });
    render(<Step5Integrations state={wizardInitialState} />);

    expect(screen.getByText('[smaily_connect_newsletter_form]')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Copy' }));

    expect(writeText).toHaveBeenCalledWith('[smaily_connect_newsletter_form]');
    expect(await screen.findByRole('button', { name: /copied/i })).toBeInTheDocument();
  });

  it('links to the docs site with the settings anchor when docsUrl is present', () => {
    const state = {
      ...wizardInitialState,
      env: { ...wizardInitialState.env, docsUrl: 'https://smaily.com/connect-woo/' },
    };
    render(<Step5Integrations state={state} />);

    const link = screen.getByRole('link', { name: 'See all attributes' });
    expect(link).toHaveAttribute('href', 'https://smaily.com/connect-woo/#set-integrations');
    expect(link).toHaveAttribute('target', '_blank');
  });

  it('omits the docs link when docsUrl is empty', () => {
    const state = { ...wizardInitialState, env: { ...wizardInitialState.env, docsUrl: '' } };
    render(<Step5Integrations state={state} />);

    expect(screen.queryByRole('link', { name: 'See all attributes' })).not.toBeInTheDocument();
  });

  it('points to the Elementor widget when Elementor is present', () => {
    const state = { ...wizardInitialState, env: { ...wizardInitialState.env, elementorPresent: true } };
    render(<Step5Integrations state={state} />);

    expect(
      screen.getByText(/look under the Smaily category for the Smaily Opt-In Form widget/),
    ).toBeInTheDocument();
  });

  it('says Elementor is not installed when absent', () => {
    const state = { ...wizardInitialState, env: { ...wizardInitialState.env, elementorPresent: false } };
    render(<Step5Integrations state={state} />);

    expect(screen.getByText('Elementor is not installed on this site.')).toBeInTheDocument();
  });

  it('points to the Smaily CF7 tab when Contact Form 7 is present', () => {
    const state = { ...wizardInitialState, env: { ...wizardInitialState.env, cf7Present: true } };
    render(<Step5Integrations state={state} />);

    expect(screen.getByText(/Contact → Contact Forms/)).toBeInTheDocument();
  });

  it('says Contact Form 7 is not installed when absent', () => {
    const state = { ...wizardInitialState, env: { ...wizardInitialState.env, cf7Present: false } };
    render(<Step5Integrations state={state} />);

    expect(screen.getByText('Contact Form 7 is not installed on this site.')).toBeInTheDocument();
  });

  it('mentions the Gutenberg block and the Classic Widget by their real names', () => {
    render(<Step5Integrations state={wizardInitialState} />);

    expect(screen.getByText(/Smaily Sign-Up Form block/)).toBeInTheDocument();
    expect(screen.getByText(/Smaily Classic Subscription Widget/)).toBeInTheDocument();
  });
});
