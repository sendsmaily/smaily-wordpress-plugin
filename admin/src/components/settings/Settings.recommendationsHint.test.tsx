import { render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { listWorkflows } from '../../api/workflows';
import { _resetUseWorkflowsCache } from '../../hooks/useWorkflows';
import { buildSettingsInitialState } from '../../state/settings-reducer';
import { type WizardState } from '../../state/types';
import { Settings } from './Settings';

/**
 * CON-347 — while Campaign Intelligence is connected, its Settings tab says
 * how to show recommendations in the store (the Smaily Recommendations block
 * or the shortcode) and links to that section of the guide.
 */
vi.mock('../../api/workflows', () => ({
  listWorkflows: vi.fn(),
}));

const workflowsMock = vi.mocked(listWorkflows);

const HEADING = 'Show recommendations in your store';
const DOCS_URL = 'https://smaily.com/connect-woo/';
const CONNECTED: Partial<WizardState> = {
  recEngineConnection: { kind: 'success', message: 'Test tenant' },
};

function renderTab(overrides: Partial<WizardState> = {}): void {
  window.location.hash = 'recommendations';
  render(
    <Settings
      initialState={{
        ...buildSettingsInitialState({ smailyConnected: true, docsUrl: DOCS_URL }),
        ...overrides,
      }}
    />,
  );
}

describe('Settings — how to show recommendations in the store (CON-347)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    _resetUseWorkflowsCache();
    workflowsMock.mockResolvedValue({ workflows: [] });
  });

  afterEach(() => {
    _resetUseWorkflowsCache();
    window.location.hash = '';
  });

  it('names the block and shows the shortcode as code while connected', () => {
    renderTab(CONNECTED);

    expect(screen.getByText(HEADING)).toBeInTheDocument();
    expect(screen.getByText(/Add the Smaily Recommendations block to a page or post/)).toBeInTheDocument();
    expect(screen.getByText('[smaily_recommendations]').tagName).toBe('CODE');
  });

  it('links to the Recommendations block section of the guide', () => {
    renderTab(CONNECTED);

    const link = screen.getByRole('link', { name: 'How to add it →' });
    expect(link).toHaveAttribute('href', `${DOCS_URL}#set-recommendations`);
    expect(link).toHaveAttribute('target', '_blank');
  });

  it('is absent while Campaign Intelligence is not connected', () => {
    renderTab();

    expect(screen.queryByText(HEADING)).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'How to add it →' })).not.toBeInTheDocument();
  });
});
