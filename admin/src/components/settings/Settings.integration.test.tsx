import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import * as saveApi from '../../api/saveSettings';
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

  it('locks Subscribers / WooCommerce / Campaign Intelligence until connected and bounces hash deep-links back', async () => {
    render(<Settings />);

    // Banner explains the lock.
    expect(screen.getByText(/Smaily connection required/i)).toBeInTheDocument();

    // PillTabs render the locked tabs as disabled buttons.
    expect(screen.getByRole('tab', { name: /contacts/i })).toBeDisabled();
    expect(screen.getByRole('tab', { name: /woocommerce/i })).toBeDisabled();
    expect(screen.getByRole('tab', { name: /campaign intelligence/i })).toBeDisabled();
    // Connection + Integrations stay accessible.
    expect(screen.getByRole('tab', { name: /^connection$/i })).not.toBeDisabled();
    expect(screen.getByRole('tab', { name: /integrations/i })).not.toBeDisabled();

    // Hash deep-link to a locked tab gets bounced back to Connection.
    window.location.hash = 'subscribers';
    window.dispatchEvent(new HashChangeEvent('hashchange'));

    await waitFor(() => {
      // Bounce target — Connection panel still showing subdomain input.
      expect(screen.getByLabelText(/subdomain/i)).toBeInTheDocument();
    });
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
