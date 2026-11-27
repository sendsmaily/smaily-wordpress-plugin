import { render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';

import { _resetUseWorkflowsCache } from '../../hooks/useWorkflows';
import { buildSettingsInitialState } from '../../state/settings-reducer';
import { Settings } from './Settings';

/**
 * CON-463: the header of Settings, the plugin's main screen, links to the
 * merchant guide — the docs address the boot payload carries.
 */
const DOCS_URL = 'https://smaily.com/connect-woo/';

describe('Settings — User Guide link (CON-463)', () => {
  afterEach(() => {
    _resetUseWorkflowsCache();
    window.location.hash = '';
  });

  it('opens the merchant guide in a new tab', () => {
    render(<Settings initialState={buildSettingsInitialState({ docsUrl: DOCS_URL })} />);

    const link = screen.getByRole('link', { name: 'User Guide' });
    expect(link).toHaveAttribute('href', DOCS_URL);
    expect(link).toHaveAttribute('target', '_blank');
    expect(link).toHaveAttribute('rel', expect.stringContaining('noopener'));
    expect(link.closest('header')).not.toBeNull();
  });

  it('shows no link when the boot payload has no docs address', () => {
    render(<Settings initialState={buildSettingsInitialState({})} />);

    expect(screen.queryByRole('link', { name: 'User Guide' })).not.toBeInTheDocument();
  });
});
