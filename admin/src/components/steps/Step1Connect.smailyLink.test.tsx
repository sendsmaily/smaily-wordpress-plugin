import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { wizardInitialState } from '../../state/wizard-reducer';
import { Step1Connect } from './Step1Connect';

/**
 * PRO-1718 — the link to the merchant's own Smaily account belongs on the
 * first step (where they are told to create the API user there), not on
 * the closing overview step.
 */
describe('Step1Connect — Smaily account link', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('opens the account of the entered subdomain', () => {
    const open = vi.spyOn(window, 'open').mockReturnValue(null);
    const seeded = {
      ...wizardInitialState,
      smailyCredentials: { subdomain: 'petshop', username: 'api', password: 'x' },
    };

    render(<Step1Connect state={seeded} dispatch={vi.fn()} />);
    fireEvent.click(screen.getByRole('button', { name: /open smaily dashboard/i }));

    expect(open).toHaveBeenCalledWith(
      'https://petshop.sendsmaily.net',
      '_blank',
      'noopener,noreferrer',
    );
  });

  it('stays hidden until a subdomain is known — that address IS the account', () => {
    render(<Step1Connect state={wizardInitialState} dispatch={vi.fn()} />);

    expect(
      screen.queryByRole('button', { name: /open smaily dashboard/i }),
    ).not.toBeInTheDocument();
  });

  it('is not rendered inside the Settings connection tab', () => {
    const seeded = {
      ...wizardInitialState,
      smailyCredentials: { subdomain: 'petshop', username: 'api', password: 'x' },
    };

    render(<Step1Connect state={seeded} dispatch={vi.fn()} inSettings />);

    expect(
      screen.queryByRole('button', { name: /open smaily dashboard/i }),
    ).not.toBeInTheDocument();
  });
});
