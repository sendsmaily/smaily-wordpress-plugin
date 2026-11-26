import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { StrictMode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import * as saveApi from '../../api/saveSettings';
import { _resetUseWorkflowsCache } from '../../hooks/useWorkflows';
import { buildSettingsInitialState } from '../../state/settings-reducer';
import { type AutomationMapping, type WizardState } from '../../state/types';
import { Settings } from './Settings';

/**
 * CON-431: a workflow belongs to the Smaily account it was picked from. A
 * Connection save that puts another Smaily account in the saved one's place
 * asks first, naming the automations that will need a workflow again; the
 * server removes those workflows, and the page drops them too, so a later
 * WooCommerce save cannot put them back.
 */
function mapping(triggerType: AutomationMapping['triggerType'], workflowId: string): AutomationMapping {
  return { triggerType, language: 'default', accountKey: 'default', workflowId, isDefaultFallback: true };
}

function connectedState(): WizardState {
  return {
    ...buildSettingsInitialState({
      smailyCredentials: { subdomain: 'oldshop', username: 'alice' },
      smailyConnected: true,
      smailyHasStoredPassword: true,
    }),
    welcomeEnabled: true,
    abandonedCartEnabled: true,
    automationMappings: [mapping('welcome', '11'), mapping('abandoned_cart', '22')],
  };
}

function typeSubdomain(value: string): void {
  fireEvent.change(screen.getByLabelText(/subdomain/i), { target: { value } });
}

describe('Settings — a Smaily account change removes its workflows', () => {
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

  it('asks before saving another account and saves nothing when the merchant cancels', () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
    const save = vi.spyOn(saveApi, 'saveSettings').mockResolvedValue({ saved: true, errors: [] });

    render(
      <StrictMode>
        <Settings initialState={connectedState()} />
      </StrictMode>,
    );
    fireEvent.click(screen.getByRole('button', { name: /edit credentials/i }));
    typeSubdomain('newshop');
    fireEvent.click(screen.getByRole('button', { name: /save changes/i }));

    expect(confirm).toHaveBeenCalledTimes(1);
    expect(confirm.mock.calls[0]?.[0]).toContain('Welcome email, Abandoned-cart reminder');
    expect(save).not.toHaveBeenCalled();
  });

  it('saves after the merchant agrees and forgets the removed workflows', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);
    const save = vi
      .spyOn(saveApi, 'saveSettings')
      .mockResolvedValue({ saved: true, errors: [], workflowsRemoved: ['default'] });

    render(
      <StrictMode>
        <Settings initialState={connectedState()} />
      </StrictMode>,
    );
    fireEvent.click(screen.getByRole('button', { name: /edit credentials/i }));
    typeSubdomain('newshop');
    fireEvent.click(screen.getByRole('button', { name: /save changes/i }));

    await waitFor(() => expect(save).toHaveBeenCalledTimes(1));
    await waitFor(() => expect(screen.getByRole('button', { name: /save changes/i })).toBeDisabled());
    expect(confirm).toHaveBeenCalledTimes(1);

    // The next account change has no workflow left to remove: no question.
    typeSubdomain('thirdshop');
    fireEvent.click(screen.getByRole('button', { name: /save changes/i }));
    await waitFor(() => expect(save).toHaveBeenCalledTimes(2));
    expect(confirm).toHaveBeenCalledTimes(1);
  });

  it('saves another API user of the same account without asking', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
    const save = vi.spyOn(saveApi, 'saveSettings').mockResolvedValue({ saved: true, errors: [] });

    render(<Settings initialState={connectedState()} />);
    fireEvent.click(screen.getByRole('button', { name: /edit credentials/i }));
    fireEvent.change(screen.getByLabelText(/api username/i), { target: { value: 'bob' } });
    fireEvent.click(screen.getByRole('button', { name: /save changes/i }));

    await waitFor(() => expect(save).toHaveBeenCalledTimes(1));
    expect(confirm).not.toHaveBeenCalled();
  });
});
