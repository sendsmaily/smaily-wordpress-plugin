import { fireEvent, render, screen } from '@testing-library/react';
import { useReducer } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { listWorkflows } from '../../api/workflows';
import { _resetUseWorkflowsCache } from '../../hooks/useWorkflows';
import { buildTabPayload } from '../../state/buildTabPayload';
import { buildSettingsInitialState } from '../../state/settings-reducer';
import { type AutomationMapping, type WizardState } from '../../state/types';
import { wizardReducer } from '../../state/wizard-reducer';
import { AutomationSection } from './AutomationSection';

vi.mock('../../api/workflows', () => ({
  listWorkflows: vi.fn(),
}));

const workflowsMock = vi.mocked(listWorkflows);

function stateWith(mappings: AutomationMapping[], detectedLanguages: string[] = []): WizardState {
  const base = buildSettingsInitialState({ detectedLanguages });
  return { ...base, multilingualMode: 'B', automationMappings: mappings };
}

function welcomeMapping(workflowId: string, language = 'default'): AutomationMapping {
  return {
    triggerType: 'welcome',
    language,
    accountKey: 'default',
    workflowId,
    isDefaultFallback: true,
  };
}

function Harness({
  initial,
  onState,
}: {
  initial: WizardState;
  onState?: (state: WizardState) => void;
}): React.JSX.Element {
  const [state, dispatch] = useReducer(wizardReducer, initial);
  onState?.(state);
  return (
    <AutomationSection
      state={state}
      dispatch={dispatch}
      trigger="welcome"
      title="Welcome email"
      description="Sent when a shopper registers."
      isEnabled
      onEnabledChange={() => undefined}
    />
  );
}

/** The option the merchant sees in a closed menu. */
function shownOption(select: HTMLSelectElement): HTMLOptionElement | undefined {
  return select.options[select.selectedIndex];
}

/**
 * Picks an option the way a browser does: a change event fires only
 * when the picked option is not the one already shown. fireEvent.change
 * alone would fire it every time and hide the bug where the shown
 * option was never stored.
 */
function pick(select: HTMLSelectElement, value: string): void {
  if (select.value === value) {
    return;
  }
  fireEvent.change(select, { target: { value } });
}

describe('AutomationSection workflow menu', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    _resetUseWorkflowsCache();
    workflowsMock.mockResolvedValue({
      workflows: [
        { id: '11', name: 'Welcome A', status: 'ACTIVE' },
        { id: '22', name: 'Welcome B', status: 'ACTIVE' },
      ],
    });
  });

  afterEach(() => {
    _resetUseWorkflowsCache();
  });

  it('shows that no workflow is chosen when none is saved', async () => {
    render(<Harness initial={stateWith([])} />);
    await screen.findByRole('option', { name: 'Welcome A' });

    const select = screen.getByRole<HTMLSelectElement>('combobox');
    expect(select.value).toBe('');
    expect(shownOption(select)?.textContent).toBe('— pick a workflow —');
    expect(screen.getByRole<HTMLOptionElement>('option', { name: 'Welcome A' }).selected).toBe(false);
  });

  it('shows a saved workflow that is no longer active as not available, and no other workflow as chosen', async () => {
    let latest = stateWith([welcomeMapping('99')]);
    render(<Harness initial={latest} onState={(s) => { latest = s; }} />);
    await screen.findByRole('option', { name: 'Welcome A' });

    const select = screen.getByRole<HTMLSelectElement>('combobox');
    expect(select.value).toBe('99');
    expect(shownOption(select)?.textContent).toBe('#99 — inactive');
    expect(screen.getByRole<HTMLOptionElement>('option', { name: 'Welcome A' }).selected).toBe(false);
    expect(screen.getByRole<HTMLOptionElement>('option', { name: 'Welcome B' }).selected).toBe(false);
    // The saved id stays stored until the merchant picks another one.
    expect(buildTabPayload(latest, 'woocommerce')['automationMappings']).toEqual([welcomeMapping('99')]);
  });

  it('shows the not-available entry on every per-language row', async () => {
    render(
      <Harness
        initial={stateWith([welcomeMapping('99', 'et'), welcomeMapping('11', 'en')], ['et', 'en'])}
      />,
    );
    await screen.findAllByRole('option', { name: 'Welcome A' });

    const [et, en] = screen.getAllByRole<HTMLSelectElement>('combobox');
    expect(shownOption(et!)?.textContent).toBe('#99 — inactive');
    expect(shownOption(en!)?.textContent).toBe('Welcome A');
  });

  it('stores the first workflow when the merchant picks it over a saved workflow that is no longer active', async () => {
    let latest = stateWith([welcomeMapping('99')]);
    render(<Harness initial={latest} onState={(s) => { latest = s; }} />);
    await screen.findByRole('option', { name: 'Welcome A' });

    pick(screen.getByRole<HTMLSelectElement>('combobox'), '11');

    expect(buildTabPayload(latest, 'woocommerce')['automationMappings']).toEqual([welcomeMapping('11')]);
  });

  it('stores the first workflow when the merchant picks it with none saved', async () => {
    let latest = stateWith([]);
    render(<Harness initial={latest} onState={(s) => { latest = s; }} />);
    await screen.findByRole('option', { name: 'Welcome A' });

    pick(screen.getByRole<HTMLSelectElement>('combobox'), '11');

    expect(buildTabPayload(latest, 'woocommerce')['automationMappings']).toEqual([welcomeMapping('11')]);
  });

  it('keeps a saved active workflow chosen and saved without the merchant touching the menu', async () => {
    let latest = stateWith([welcomeMapping('22')]);
    render(<Harness initial={latest} onState={(s) => { latest = s; }} />);
    await screen.findByRole('option', { name: 'Welcome A' });

    const select = screen.getByRole<HTMLSelectElement>('combobox');
    expect(shownOption(select)?.textContent).toBe('Welcome B');
    expect(select.options).toHaveLength(3);
    expect(buildTabPayload(latest, 'woocommerce')['automationMappings']).toEqual([welcomeMapping('22')]);
  });
});

/** A card whose switch really toggles the welcome flag, with a settings row and a discount block. */
function TogglingHarness({
  initial,
  onState,
}: {
  initial: WizardState;
  onState?: (state: WizardState) => void;
}): React.JSX.Element {
  const [state, dispatch] = useReducer(wizardReducer, initial);
  onState?.(state);
  return (
    <AutomationSection
      state={state}
      dispatch={dispatch}
      trigger="welcome"
      title="Welcome email"
      description="Sent when a shopper subscribes."
      isEnabled={state.welcomeEnabled}
      onEnabledChange={(enabled) => dispatch({ type: 'SET_WELCOME_ENABLED', payload: enabled })}
      extras={<p>The automation’s own setting</p>}
      discount={<p>The discount block</p>}
    />
  );
}

function follows(a: Element, b: Element): boolean {
  return (a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0;
}

describe('AutomationSection progressive disclosure (CON-255)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    _resetUseWorkflowsCache();
    workflowsMock.mockResolvedValue({
      workflows: [
        { id: '11', name: 'Welcome A', status: 'ACTIVE' },
        { id: '22', name: 'Welcome B', status: 'ACTIVE' },
      ],
    });
  });

  afterEach(() => {
    _resetUseWorkflowsCache();
  });

  it('shows only the title, description and switch while the automation is off', () => {
    render(<TogglingHarness initial={{ ...stateWith([welcomeMapping('22')]), welcomeEnabled: false }} />);

    expect(screen.getByRole('heading', { name: 'Welcome email' })).toBeInTheDocument();
    expect(screen.getByText('Sent when a shopper subscribes.')).toBeInTheDocument();
    expect(screen.getByRole('switch', { name: 'Enable welcome email' })).not.toBeChecked();

    expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
    expect(screen.queryByRole('table')).not.toBeInTheDocument();
    expect(screen.queryByText('The automation’s own setting')).not.toBeInTheDocument();
    expect(screen.queryByText('The discount block')).not.toBeInTheDocument();
    expect(workflowsMock).not.toHaveBeenCalled();
  });

  it('shows the workflow, then the automation’s own settings, then the discount block last once on', async () => {
    render(<TogglingHarness initial={{ ...stateWith([]), welcomeEnabled: true }} />);
    await screen.findByRole('option', { name: 'Welcome A' });

    const toggle = screen.getByRole('switch', { name: 'Enable welcome email' });
    const workflow = screen.getByRole('combobox');
    const settings = screen.getByText('The automation’s own setting');
    const discount = screen.getByText('The discount block');

    expect(follows(toggle, workflow)).toBe(true);
    expect(follows(workflow, settings)).toBe(true);
    expect(follows(settings, discount)).toBe(true);
  });

  it('shows the per-language workflow table first on a multilingual store', async () => {
    render(
      <TogglingHarness
        initial={{ ...stateWith([welcomeMapping('11', 'et')], ['et', 'en']), welcomeEnabled: true }}
      />,
    );
    await screen.findAllByRole('option', { name: 'Welcome A' });

    const table = screen.getAllByRole('table')[0]!;
    expect(follows(table, screen.getByText('The automation’s own setting'))).toBe(true);
    expect(follows(screen.getByText('The automation’s own setting'), screen.getByText('The discount block'))).toBe(true);
  });

  it('keeps the saved workflows in the save while off and shows them again when switched back on', async () => {
    let latest: WizardState = {
      ...stateWith([welcomeMapping('22', 'et'), welcomeMapping('11', 'en')], ['et', 'en']),
      welcomeEnabled: true,
    };
    render(<TogglingHarness initial={latest} onState={(s) => { latest = s; }} />);
    await screen.findAllByRole('option', { name: 'Welcome A' });

    fireEvent.click(screen.getByRole('switch', { name: 'Enable welcome email' }));
    expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
    expect(buildTabPayload(latest, 'woocommerce')).toMatchObject({
      welcomeEnabled: false,
      automationMappings: [welcomeMapping('22', 'et'), welcomeMapping('11', 'en')],
    });

    fireEvent.click(screen.getByRole('switch', { name: 'Enable welcome email' }));
    const [et, en] = await screen.findAllByRole<HTMLSelectElement>('combobox');
    expect(et).toHaveValue('22');
    expect(en).toHaveValue('11');
    expect(buildTabPayload(latest, 'woocommerce')).toMatchObject({
      welcomeEnabled: true,
      automationMappings: [welcomeMapping('22', 'et'), welcomeMapping('11', 'en')],
    });
  });
});
