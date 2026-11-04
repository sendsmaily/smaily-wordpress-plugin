import { render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';

import { _resetUseWorkflowsCache } from '../../hooks/useWorkflows';
import { Settings } from './Settings';

type BootWindow = { smailyConnectBoot?: unknown; wp?: unknown };

/**
 * CON-302: the bottom of Settings names the installed version, so support
 * can ask which release a store runs (the Magento extension's CON-235).
 */
describe('Settings — installed version line', () => {
  afterEach(() => {
    _resetUseWorkflowsCache();
    delete (window as unknown as BootWindow).smailyConnectBoot;
    delete (window as unknown as BootWindow).wp;
    window.location.hash = '';
  });

  it('shows the version the boot payload carries', () => {
    (window as unknown as BootWindow).smailyConnectBoot = { version: '3.21.1' };

    render(<Settings />);

    expect(screen.getByText('Smaily Connect 3.21.1')).toBeInTheDocument();
  });

  it('translates the line through wp.i18n', () => {
    (window as unknown as BootWindow).smailyConnectBoot = { version: '3.21.1' };
    (window as unknown as BootWindow).wp = {
      i18n: {
        __: (text: string) => (text === 'Smaily Connect %s' ? 'Smaily Connect %s (et)' : text),
        _n: (single: string, plural: string, n: number) => (n === 1 ? single : plural),
        sprintf: (format: string, ...args: Array<string | number>) =>
          format.replace('%s', String(args[0])),
      },
    };

    render(<Settings />);

    expect(screen.getByText('Smaily Connect 3.21.1 (et)')).toBeInTheDocument();
  });

  it('shows no version line when the boot payload has none', () => {
    render(<Settings />);

    expect(screen.queryByText(/^Smaily Connect \d/)).not.toBeInTheDocument();
  });
});
