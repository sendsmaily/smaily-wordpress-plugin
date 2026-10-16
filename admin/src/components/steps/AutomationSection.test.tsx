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
