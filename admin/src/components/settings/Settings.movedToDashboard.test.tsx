import { render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';

import { _resetUseWorkflowsCache } from '../../hooks/useWorkflows';
import { buildSettingsInitialState } from '../../state/settings-reducer';
import { Settings } from './Settings';

/**
 * CON-464: the User Guide link moved from the Settings header to the
 * Dashboard's quick links, and the Event Log from a Settings tab to the Log
 * page of its own.
 */
describe('Settings — what moved to the Dashboard and the Log page (CON-464)', () => {
  afterEach(() => {
    _resetUseWorkflowsCache();
    window.location.hash = '';
  });

  it('carries no User Guide link', () => {
    render(<Settings initialState={buildSettingsInitialState({ docsUrl: 'https://smaily.com/connect-woo/' })} />);

    expect(screen.queryByRole('link', { name: 'User Guide' })).not.toBeInTheDocument();
  });

  it('has no Event Log tab', () => {
    render(<Settings initialState={buildSettingsInitialState({})} />);

    expect(screen.getByRole('tab', { name: /Connection/ })).toBeInTheDocument();
    expect(screen.queryByRole('tab', { name: /Event Log/ })).not.toBeInTheDocument();
  });
});
