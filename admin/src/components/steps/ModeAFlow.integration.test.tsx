import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useReducer } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import * as testConnectionApi from '../../api/testConnection';
import { _resetUseWorkflowsCache } from '../../hooks/useWorkflows';
import { wizardInitialState, wizardReducer } from '../../state/wizard-reducer';
import { Step1Connect } from './Step1Connect';

describe('Step 1 — Mode A multi-account flow', () => {
  beforeEach(() => {
    _resetUseWorkflowsCache();
    vi.restoreAllMocks();
  });

  afterEach(() => {
    _resetUseWorkflowsCache();
    vi.restoreAllMocks();
  });

  it('shows per-language credential blocks + fallback radio when Mode A is picked', () => {
    const seeded = {
      ...wizardInitialState,
      multilingualMode: 'A' as const,
      env: {
        ...wizardInitialState.env,
        detectedLanguages: ['et_EE', 'en_US'],
      },
    };

    function Host(): React.JSX.Element {
      const [state, dispatch] = useReducer(wizardReducer, seeded);
      return <Step1Connect state={state} dispatch={dispatch} />;
    }

    render(<Host />);

    // Sub-PR 2.H.2: Mode A has NO shared "default account" — the only
    // surface labelled "default" is the fallback CARD title ("Default
    // fallback account"). The default credential block + the "Default
    // account" radio option are both removed.
    expect(screen.queryByRole('radio', { name: /^default account$/i })).toBeNull();
    expect(screen.getByText(/account for et-EE/i)).toBeInTheDocument();
    expect(screen.getByText(/account for en-US/i)).toBeInTheDocument();

    // MultilingualModePicker (3 radios A/B/C) + fallback picker (2 radios:
    // et, en — no "default") = 5 total in Mode A with 2 detected languages.
    expect(screen.getAllByRole('radio').length).toBe(5);
  });

  it('retargets fallback to the first language on B → A switch', () => {
    const seeded = {
      ...wizardInitialState,
      multilingualMode: 'B' as const,
      env: {
        ...wizardInitialState.env,
        detectedLanguages: ['et_EE', 'en_US'],
      },
      // Default-fallback starts at 'default' in Mode B — that's the
      // canonical value.
      defaultFallbackAccountKey: 'default',
    };

    function Host(): React.JSX.Element {
      const [state, dispatch] = useReducer(wizardReducer, seeded);
      return (
        <>
          <Step1Connect state={state} dispatch={dispatch} />
          <output data-testid="fallback">{state.defaultFallbackAccountKey}</output>
        </>
      );
    }

    render(<Host />);

    expect(screen.getByTestId('fallback')).toHaveTextContent('default');

    // Switch to Mode A — reducer must retarget fallback to account_et_EE
    // (first detected language) since 'default' has no meaning in Mode A.
    fireEvent.click(screen.getByLabelText(/separate smaily accounts/i));

    expect(screen.getByTestId('fallback')).toHaveTextContent('account_et_EE');
  });

  it('lazily creates perLanguageAccounts on first field edit', async () => {
    vi.spyOn(testConnectionApi, 'testSmailyConnection').mockResolvedValue({
      connected: true,
      accountName: 'Estonian shop',
    });

    const seeded = {
      ...wizardInitialState,
      multilingualMode: 'A' as const,
      env: {
        ...wizardInitialState.env,
        // Mode A only renders per-language blocks when there's more than
        // one detected language — single-language sites collapse back to
        // the default-only flow regardless of mode.
        detectedLanguages: ['et_EE', 'en_US'],
      },
    };

    function Host(): React.JSX.Element {
      const [state, dispatch] = useReducer(wizardReducer, seeded);
      return (
        <>
          <Step1Connect state={state} dispatch={dispatch} />
          <output data-testid="account-count">{state.perLanguageAccounts.length}</output>
          <output data-testid="et-subdomain">
            {state.perLanguageAccounts.find((a) => a.accountKey === 'account_et_EE')?.credentials
              .subdomain ?? ''}
          </output>
        </>
      );
    }

    render(<Host />);

    expect(screen.getByTestId('account-count')).toHaveTextContent('0');

    // Per-language Subdomain inputs use idSuffix={language} — sub-PR
    // 2.H.2 removed the default credential block in Mode A so the
    // Estonian block is now the first one rendered, but we still
    // target it by id to be explicit.
    const subdomainInput = document.querySelector<HTMLInputElement>('#smaily-subdomain-et_EE');
    expect(subdomainInput).not.toBeNull();
    fireEvent.change(subdomainInput!, { target: { value: 'estonia' } });

    await waitFor(() => {
      expect(screen.getByTestId('account-count')).toHaveTextContent('1');
    });
    expect(screen.getByTestId('et-subdomain')).toHaveTextContent('estonia');
  });
});
