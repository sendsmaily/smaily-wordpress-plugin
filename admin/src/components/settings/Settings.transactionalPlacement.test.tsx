import { render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { listWorkflows } from '../../api/workflows';
import { _resetUseWorkflowsCache } from '../../hooks/useWorkflows';
import { buildSettingsInitialState } from '../../state/settings-reducer';
import { type WizardState } from '../../state/types';
import { Settings } from './Settings';

/**
 * PRO-1540 — the transactional-emails feature moved from one combined
 * WooCommerce-tab section to two: the account connection (toggle +
 * credentials + test) on the Connection tab, and the two trigger sections
 * on the WooCommerce tab, gated on that connection. This file pins the
 * TAB PLACEMENT + gating in the real Settings shell, complementing the
 * standalone component tests (TransactionalEmailsSection.test.tsx,
 * TransactionalTriggersSection.test.tsx).
 */
vi.mock('../../api/workflows', () => ({
  listWorkflows: vi.fn(),
}));

const workflowsMock = vi.mocked(listWorkflows);

function connectedState(overrides: Partial<WizardState> = {}): WizardState {
  return {
    ...buildSettingsInitialState({ smailyConnected: true }),
    ...overrides,
  };
}

describe('Settings — transactional-emails tab placement (PRO-1540)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    _resetUseWorkflowsCache();
    workflowsMock.mockResolvedValue({ workflows: [] });
  });

  afterEach(() => {
    _resetUseWorkflowsCache();
    window.location.hash = '';
  });

  it('renders the transactional account toggle + credentials on the Connection tab', () => {
    window.location.hash = 'connection';
    render(<Settings initialState={connectedState({ transactionalEmailsEnabled: true })} />);

    expect(screen.getByRole('switch', { name: /send transactional emails/i })).toBeChecked();
    expect(document.getElementById('smaily-subdomain-transactional')).toBeInTheDocument();
  });

  it('renders NOTHING transactional on the WooCommerce tab when the transactional account is not connected', () => {
    window.location.hash = 'woocommerce';
    render(<Settings initialState={connectedState()} />);

    expect(screen.queryByRole('heading', { name: 'Order confirmation' })).not.toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: 'Shipping confirmation' })).not.toBeInTheDocument();
  });

  it('renders the trigger sections on the WooCommerce tab once the transactional account is connected', async () => {
    window.location.hash = 'woocommerce';
    render(
      <Settings
        initialState={connectedState({ transactionalConnection: { kind: 'success' } })}
      />,
    );

    expect(await screen.findByRole('heading', { name: 'Order confirmation' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Shipping confirmation' })).toBeInTheDocument();
  });
});
