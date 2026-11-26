import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { StrictMode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import * as saveSettingsApi from '../../api/saveSettings';
import * as testConnectionApi from '../../api/testConnection';
import { _resetUseWorkflowsCache } from '../../hooks/useWorkflows';
import { type WizardState } from '../../state/types';
import { wizardInitialState } from '../../state/wizard-reducer';
import { Wizard } from './Wizard';

/**
 * CON-431: a second setup pass that connects another Smaily account asks
 * before its Connect step saves, like Settings — the workflows chosen from
 * the saved account are removed by that save.
 */
const SAVED: WizardState = {
  ...wizardInitialState,
  smailyCredentials: { subdomain: 'oldshop', username: 'alice', password: '' },
  automationMappings: [
    { triggerType: 'first_order', language: 'default', accountKey: 'default', workflowId: '33', isDefaultFallback: true },
  ],
};

function field(name: string): HTMLInputElement {
  return document.querySelector<HTMLInputElement>(`#smaily-${name}-default`)!;
}

describe('Wizard — a Smaily account change asks first', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
    _resetUseWorkflowsCache();
    vi.spyOn(testConnectionApi, 'testSmailyConnection').mockResolvedValue({ connected: true });
  });

  afterEach(() => {
    vi.restoreAllMocks();
    _resetUseWorkflowsCache();
  });

  it('keeps the merchant on the Connect step and saves nothing when they cancel', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
    const save = vi.spyOn(saveSettingsApi, 'saveSettings').mockResolvedValue({ saved: true, errors: [] });

    render(
      <StrictMode>
        <Wizard initialState={SAVED} />
      </StrictMode>,
    );
    fireEvent.change(field('subdomain'), { target: { value: 'newshop' } });
    fireEvent.change(field('password'), { target: { value: 's3cret' } });
    fireEvent.click(screen.getByRole('button', { name: /test connection/i }));
    await waitFor(() => expect(screen.getByRole('button', { name: /continue/i })).toBeEnabled());

    fireEvent.click(screen.getByRole('button', { name: /continue/i }));

    expect(confirm).toHaveBeenCalledWith(expect.stringContaining('First-order email'));
    expect(save).not.toHaveBeenCalled();
    expect(field('subdomain')).toBeInTheDocument();
  });
});
