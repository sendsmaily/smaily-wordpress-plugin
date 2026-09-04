import { fireEvent, render, screen } from '@testing-library/react';
import { useReducer } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
  getAutomationsCatalog,
  getAutomationsConfig,
  type AutomationsCatalogResponse,
} from '../../api/automations';
import { ApiError } from '../../api/client';
import { listWorkflows } from '../../api/workflows';
import { _resetUseWorkflowsCache } from '../../hooks/useWorkflows';
import { buildSettingsInitialState } from '../../state/settings-reducer';
import { type WizardState } from '../../state/types';
import { wizardReducer } from '../../state/wizard-reducer';
import { EngineAutomationsSection } from './EngineAutomationsSection';

vi.mock('../../api/automations', async (importOriginal) => {
  const original = await importOriginal<Record<string, unknown>>();
  return {
    ...original,
    getAutomationsCatalog: vi.fn(),
    getAutomationsConfig: vi.fn(),
    putAutomationsConfig: vi.fn(),
  };
});

vi.mock('../../api/workflows', () => ({
  listWorkflows: vi.fn(),
}));

const catalogMock = vi.mocked(getAutomationsCatalog);
const configMock = vi.mocked(getAutomationsConfig);
const workflowsMock = vi.mocked(listWorkflows);

/**
 * A catalog whose trigger key this plugin has never heard of — proving
 * the render is purely catalog-driven (contract §11: a new trigger
 * ships with an engine deploy, no plugin release).
 */
const CATALOG: AutomationsCatalogResponse = {
  triggers: [
    {
      key: 'quantum_upsell_2027',
      name_et: 'Kvant-lisamüük',
      name_en: 'Quantum upsell',
      description_et: 'Kirjeldus eesti keeles.',
      description_en: 'Description in English.',
      recipe_et: 'Ehita Smailys vastav automatsioon.',
    },
  ],
  language_modes: ['single', 'per_language'],
  docs: 'https://intelligence.smaily.example/docs/templates',
};

function connectedState(): WizardState {
  return {
    ...buildSettingsInitialState({ smailyConnected: true, detectedLanguages: ['et'] }),
    recEngineConnection: { kind: 'success', message: 'Test tenant' },
  };
}

function disconnectedState(): WizardState {
  return buildSettingsInitialState({ smailyConnected: true });
}

function Harness({
  initial,
  inSettings = true,
}: {
  initial: WizardState;
  inSettings?: boolean;
}): React.JSX.Element {
  const [state, dispatch] = useReducer(wizardReducer, initial);
  return <EngineAutomationsSection state={state} dispatch={dispatch} inSettings={inSettings} />;
}

describe('EngineAutomationsSection', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    _resetUseWorkflowsCache();
    window.location.hash = '';
    document.documentElement.lang = 'en-US';
    catalogMock.mockResolvedValue(CATALOG);
    configMock.mockResolvedValue({ configs: [] });
    workflowsMock.mockResolvedValue({
      workflows: [
        { id: '123', name: 'Replenishment flow', status: 'ACTIVE' },
        { id: '124', name: 'Old flow', status: 'INACTIVE' },
      ],
    });
  });

  afterEach(() => {
    _resetUseWorkflowsCache();
    window.location.hash = '';
    document.documentElement.lang = '';
  });

  it('shows the upsell banner with a tab CTA when the engine is not connected (settings)', () => {
    render(<Harness initial={disconnectedState()} />);

    expect(screen.getByText(/connect campaign intelligence to unlock/i)).toBeInTheDocument();
    expect(catalogMock).not.toHaveBeenCalled();

    fireEvent.click(screen.getByRole('button', { name: /open campaign intelligence/i }));
    expect(window.location.hash).toBe('#recommendations');
  });

  it('renders nothing at all in the wizard when the engine is not connected (PRO-1717)', () => {
    const { container } = render(<Harness initial={disconnectedState()} inSettings={false} />);

    // The next wizard step is the Campaign Intelligence overview itself,
    // so step 3 says nothing about it.
    expect(container).toBeEmptyDOMElement();
    expect(screen.queryByText(/campaign intelligence/i)).not.toBeInTheDocument();
    expect(catalogMock).not.toHaveBeenCalled();
  });

  it('still renders the section in the wizard when the engine IS connected (PRO-1717)', async () => {
    render(<Harness initial={connectedState()} inSettings={false} />);

    expect(
      screen.getByText(/campaign intelligence automation workflows/i),
    ).toBeInTheDocument();
    expect(await screen.findByText('Quantum upsell')).toBeInTheDocument();
  });

  it('renders an unknown catalog trigger dynamically with the locale-matched copy + docs link', async () => {
    render(<Harness initial={connectedState()} />);

    // English admin locale → _en fields; recipe_et is always shown.
    expect(await screen.findByText('Quantum upsell')).toBeInTheDocument();
    expect(screen.getByText('Description in English.')).toBeInTheDocument();
    expect(screen.getByText(/ehita smailys vastav automatsioon/i)).toBeInTheDocument();

    const docsLink = screen.getByRole('link', { name: /smaily templates guide/i });
    expect(docsLink).toHaveAttribute('href', CATALOG.docs);

    // Fail-closed default row: off + test mode with the test-address field.
    expect(screen.getByText('Off')).toBeInTheDocument();
    expect(screen.getByText(/test mode is on/i)).toBeInTheDocument();
  });

  it('uses the _et copy when the admin locale is Estonian', async () => {
    document.documentElement.lang = 'et';
    render(<Harness initial={connectedState()} />);

    expect(await screen.findByText('Kvant-lisamüük')).toBeInTheDocument();
    expect(screen.getByText('Kirjeldus eesti keeles.')).toBeInTheDocument();
  });

  it('filters INACTIVE workflows out of the dropdown', async () => {
    render(<Harness initial={connectedState()} />);

    // The workflow list loads AFTER the combobox first renders (placeholder
    // option only) — await the ACTIVE option itself, not just the select;
    // asserting on the intermediate render made this flaky under a loaded
    // parallel run (2× on 2026-07-08).
    expect(await screen.findByRole('option', { name: 'Replenishment flow' })).toBeInTheDocument();

    const select = screen.getByRole('combobox');
    const optionLabels = Array.from(select.querySelectorAll('option')).map((o) => o.textContent);
    expect(optionLabels).not.toContain('Old flow');
  });

  it('requires a confirm before switching test mode off, and offers the way back', async () => {
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);
    render(<Harness initial={connectedState()} />);

    const activate = await screen.findByRole('button', { name: /activate for real/i });

    // Declined → still in test mode.
    fireEvent.click(activate);
    expect(confirmSpy).toHaveBeenCalledTimes(1);
    expect(screen.getByText(/test mode is on/i)).toBeInTheDocument();

    // Confirmed → live, with a "back to test mode" escape hatch.
    confirmSpy.mockReturnValue(true);
    fireEvent.click(activate);
    expect(await screen.findByText(/live — real customers/i)).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /back to test mode/i }));
    expect(await screen.findByText(/test mode is on/i)).toBeInTheDocument();
  });

  it('flags an enabled trigger without a workflow at the field before any PUT', async () => {
    render(<Harness initial={connectedState()} />);

    const toggle = await screen.findByRole('switch', { name: /enable quantum upsell/i });
    fireEvent.click(toggle);

    expect(
      await screen.findByText(/pick a workflow before enabling/i),
    ).toBeInTheDocument();
  });

  it('keeps a cleared cooldown field empty while typing and commits the new value on blur (T2.4/2)', async () => {
    render(<Harness initial={connectedState()} />);

    const cooldown = (await screen.findByLabelText(/cooldown/i)) as HTMLInputElement;
    expect(cooldown.value).toBe('7');

    // Clearing must NOT snap to 0 — the draft shows exactly what was typed.
    fireEvent.change(cooldown, { target: { value: '' } });
    expect(cooldown.value).toBe('');

    fireEvent.change(cooldown, { target: { value: '14' } });
    expect(cooldown.value).toBe('14');

    fireEvent.blur(cooldown);
    expect(cooldown.value).toBe('14');
  });

  it('reverts an empty cooldown to the previous value on blur, never 0 (T2.4/2)', async () => {
    render(<Harness initial={connectedState()} />);

    const cooldown = (await screen.findByLabelText(/cooldown/i)) as HTMLInputElement;
    fireEvent.change(cooldown, { target: { value: '' } });
    fireEvent.blur(cooldown);

    expect(cooldown.value).toBe('7');
  });

  it('clamps an out-of-range cooldown to 1–365 on blur (T2.4/2)', async () => {
    render(<Harness initial={connectedState()} />);

    const cooldown = (await screen.findByLabelText(/cooldown/i)) as HTMLInputElement;
    fireEvent.change(cooldown, { target: { value: '999' } });
    fireEvent.blur(cooldown);
    expect(cooldown.value).toBe('365');

    fireEvent.change(cooldown, { target: { value: '0' } });
    fireEvent.blur(cooldown);
    expect(cooldown.value).toBe('1');
  });

  it('warns when an enabled test-mode row has no test addresses (T2.4/3)', async () => {
    render(<Harness initial={connectedState()} />);

    // Off + empty list → no warning (nothing can fire anyway).
    await screen.findByText(/test mode is on/i);
    expect(screen.queryByText(/no emails will be sent to anyone/i)).not.toBeInTheDocument();

    // Enabled + test mode + empty list = silent nobody-gets-mail → warn.
    fireEvent.click(screen.getByRole('switch', { name: /enable quantum upsell/i }));
    expect(await screen.findByText(/no emails will be sent to anyone/i)).toBeInTheDocument();

    // Adding an address clears the warning.
    fireEvent.change(screen.getByLabelText(/test addresses/i), {
      target: { value: 'owner@shop.example' },
    });
    expect(screen.queryByText(/no emails will be sent to anyone/i)).not.toBeInTheDocument();
  });

  it('shows recipe_en to non-Estonian locales when the catalog provides it (T2.4/5)', async () => {
    catalogMock.mockResolvedValue({
      ...CATALOG,
      triggers: [{ ...CATALOG.triggers[0]!, recipe_en: 'Build the matching Smaily automation.' }],
    });

    render(<Harness initial={connectedState()} />);

    expect(await screen.findByText('Build the matching Smaily automation.')).toBeInTheDocument();
    expect(screen.queryByText(/ehita smailys vastav automatsioon/i)).not.toBeInTheDocument();
  });

  it('shows recipe_et to Estonian locales even when recipe_en exists (T2.4/5)', async () => {
    document.documentElement.lang = 'et';
    catalogMock.mockResolvedValue({
      ...CATALOG,
      triggers: [{ ...CATALOG.triggers[0]!, recipe_en: 'Build the matching Smaily automation.' }],
    });

    render(<Harness initial={connectedState()} />);

    expect(await screen.findByText(/ehita smailys vastav automatsioon/i)).toBeInTheDocument();
  });

  it('renders a human summary with the raw error demoted to a detail line on a generic load failure (T2.4/4)', async () => {
    catalogMock.mockRejectedValue(
      new ApiError('GET /rec-engine/automations/catalog → 401', 401, null),
    );

    render(<Harness initial={connectedState()} />);

    expect(
      await screen.findByText(/connecting to smaily campaign intelligence failed \(http 401\)/i),
    ).toBeInTheDocument();
    expect(screen.getByText(/check the connection on the campaign intelligence tab/i)).toBeInTheDocument();
    expect(screen.getByText('GET /rec-engine/automations/catalog → 401')).toBeInTheDocument();
  });

  it('shows the key-rejected banner when the proxy reports 502 api_key_rejected', async () => {
    catalogMock.mockRejectedValue(
      new ApiError('GET → 502', 502, { error: 'api_key_rejected', message: 'nope' }),
    );

    render(<Harness initial={connectedState()} />);

    expect(
      await screen.findByText(/rejected the stored api key/i),
    ).toBeInTheDocument();
  });

  it('offers Retry on a generic load failure and re-fetches on click', async () => {
    catalogMock.mockRejectedValueOnce(new ApiError('GET → 502', 502, { error: 'engine_unreachable', message: 'down' }));

    render(<Harness initial={connectedState()} />);

    const retry = await screen.findByRole('button', { name: /retry/i });
    fireEvent.click(retry);

    expect(await screen.findByText('Quantum upsell')).toBeInTheDocument();
    expect(catalogMock).toHaveBeenCalledTimes(2);
  });
});
