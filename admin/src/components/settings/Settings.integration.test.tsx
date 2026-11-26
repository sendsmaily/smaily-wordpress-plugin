import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { ApiError } from '../../api/client';
import * as saveApi from '../../api/saveSettings';
import * as testConnectionApi from '../../api/testConnection';
import { _resetUseWorkflowsCache } from '../../hooks/useWorkflows';
import { Settings } from './Settings';

describe('Settings — tab routing + dirty + save', () => {
  beforeEach(() => {
    _resetUseWorkflowsCache();
    vi.restoreAllMocks();
    window.location.hash = '';
  });

  afterEach(() => {
    _resetUseWorkflowsCache();
    vi.restoreAllMocks();
    window.location.hash = '';
  });

  it('lands on Connection tab by default with Save + Discard disabled', () => {
    render(<Settings />);

    // Connection tab is the active panel — subdomain field is from Step1Connect.
    expect(screen.getByLabelText(/subdomain/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /save changes/i })).toBeDisabled();
    expect(screen.getByRole('button', { name: /discard changes/i })).toBeDisabled();
  });

  it('marks the Connection tab dirty after a credential edit and enables Save', () => {
    render(<Settings />);

    fireEvent.change(screen.getByLabelText(/subdomain/i), { target: { value: 'mypetshop' } });

    expect(screen.getByRole('button', { name: /save changes/i })).not.toBeDisabled();
    expect(screen.getByRole('button', { name: /discard changes/i })).not.toBeDisabled();
  });

  it('dispatches the connection payload on Save and clears the dirty flag', async () => {
    const saveSpy = vi.spyOn(saveApi, 'saveSettings').mockResolvedValue({
      saved: true,
      errors: [],
    });

    render(<Settings />);
    fireEvent.change(screen.getByLabelText(/subdomain/i), { target: { value: 'mypetshop' } });
    fireEvent.change(screen.getByLabelText(/api username/i), { target: { value: 'alice' } });
    fireEvent.change(screen.getByLabelText(/api password/i), { target: { value: 's3cret' } });

    fireEvent.click(screen.getByRole('button', { name: /save changes/i }));

    await waitFor(() => {
      expect(saveSpy).toHaveBeenCalledWith(
        expect.objectContaining({ tab: 'connection' }),
        expect.anything(),
      );
    });

    const callArg = saveSpy.mock.calls[0]?.[0];
    expect(callArg?.data).toMatchObject({
      smailyCredentials: {
        subdomain: 'mypetshop',
        username: 'alice',
        password: 's3cret',
      },
    });

    await waitFor(() => {
      expect(screen.getByRole('button', { name: /save changes/i })).toBeDisabled();
    });
    expect(screen.getByText(/settings saved/i)).toBeInTheDocument();
  });

  it('switches tabs based on a hash change without rerendering everything', async () => {
    // Sub-PR 2.I — Subscribers / WC / Rec are locked until smailyConnection
    // is success. Seed a connected state so this test exercises routing,
    // not the lock-and-bounce behaviour (the lock has its own assertion
    // below).
    render(<Settings initialEnv={{ smailyConnected: true }} />);

    // Initial — connection panel
    expect(screen.getByLabelText(/subdomain/i)).toBeInTheDocument();

    // Programmatic hash change should swap the panel
    window.location.hash = 'subscribers';
    window.dispatchEvent(new HashChangeEvent('hashchange'));

    await waitFor(() => {
      expect(screen.getByText(/sync contacts to smaily/i)).toBeInTheDocument();
    });
    expect(screen.queryByLabelText(/subdomain/i)).not.toBeInTheDocument();
  });

  /**
   * CON-431: while the Smaily connection does not work, every tab stays
   * open; a notice at the top says why and leads back to the Connection tab.
   */
  it('keeps every tab open while the connection test fails and says so at the top', async () => {
    vi.spyOn(testConnectionApi, 'testSmailyConnection').mockResolvedValue({
      connected: false,
      error: 'Smaily did not accept those credentials.',
    });

    render(<Settings />);
    fireEvent.change(screen.getByLabelText(/subdomain/i), { target: { value: 'mypetshop' } });
    fireEvent.change(screen.getByLabelText(/api username/i), { target: { value: 'alice' } });
    fireEvent.change(screen.getByLabelText(/api password/i), { target: { value: 'wrong' } });
    fireEvent.click(screen.getByRole('button', { name: /test connection/i }));

    expect(
      await screen.findByText('The Smaily connection does not work: Smaily did not accept those credentials.'),
    ).toBeInTheDocument();
    for (const name of [/contacts/i, /woocommerce/i, /campaign intelligence/i, /integrations/i, /event log/i]) {
      expect(screen.getByRole('tab', { name })).not.toBeDisabled();
    }
    // On the Connection tab the notice needs no way there.
    expect(screen.queryByRole('button', { name: 'Fix the connection' })).not.toBeInTheDocument();

    // A deep link to another tab opens it; the notice stays on top.
    window.location.hash = 'subscribers';
    window.dispatchEvent(new HashChangeEvent('hashchange'));
    expect(await screen.findByText(/sync contacts to smaily/i)).toBeInTheDocument();
    expect(
      screen.getByText('The Smaily connection does not work: Smaily did not accept those credentials.'),
    ).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Fix the connection' }));
    expect(await screen.findByLabelText(/subdomain/i)).toBeInTheDocument();
  });

  it('shows no connection notice before a test fails', () => {
    render(<Settings />);

    expect(screen.queryByText(/The Smaily connection does not work/)).not.toBeInTheDocument();
    expect(screen.getByRole('tab', { name: /contacts/i })).not.toBeDisabled();
  });

  /** CON-431: a subdomain the save refuses is said next to its field. */
  it('shows a refused subdomain next to the field', async () => {
    vi.spyOn(saveApi, 'saveSettings').mockRejectedValue(
      new ApiError('POST /settings → 400', 400, {
        saved: false,
        errors: [
          {
            field: 'subdomain',
            message: 'The subdomain must be a plain Smaily subdomain such as "demo": letters, digits and hyphens only.',
          },
        ],
      }),
    );

    render(<Settings />);
    fireEvent.change(screen.getByLabelText(/subdomain/i), { target: { value: 'my shop' } });
    fireEvent.change(screen.getByLabelText(/api username/i), { target: { value: 'alice' } });
    fireEvent.click(screen.getByRole('button', { name: /save changes/i }));

    const message = await screen.findByText(
      'The subdomain must be a plain Smaily subdomain such as "demo": letters, digits and hyphens only.',
      { selector: 'p' },
    );
    expect(message).toHaveAttribute('id', 'smaily-subdomain-default-error');
    expect(screen.getByLabelText(/subdomain/i)).toHaveAttribute('aria-invalid', 'true');
  });

  it('hides Save / Discard footer on the Integrations tab', async () => {
    render(<Settings />);

    window.location.hash = 'integrations';
    window.dispatchEvent(new HashChangeEvent('hashchange'));

    await waitFor(() => {
      expect(screen.queryByRole('button', { name: /save changes/i })).not.toBeInTheDocument();
      expect(screen.queryByRole('button', { name: /discard changes/i })).not.toBeInTheDocument();
    });
  });
});
