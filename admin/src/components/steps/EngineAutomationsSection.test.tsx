import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { StrictMode, useReducer } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
  getAutomationsCatalog,
  getAutomationsConfig,
  sendAutomationTest,
  switchAutomationTestMode,
  type AutomationConfigServerRow,
  type AutomationsCatalogResponse,
  type AutomationSetupItem,
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
    sendAutomationTest: vi.fn(),
    switchAutomationTestMode: vi.fn(),
  };
});

vi.mock('../../api/workflows', () => ({
  listWorkflows: vi.fn(),
}));

const catalogMock = vi.mocked(getAutomationsCatalog);
const configMock = vi.mocked(getAutomationsConfig);
const workflowsMock = vi.mocked(listWorkflows);
const sendMock = vi.mocked(sendAutomationTest);
const switchMock = vi.mocked(switchAutomationTestMode);

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

/** Switches the catalog's trigger on once it has loaded — an off card shows only its switch (CON-255). */
async function switchOn(name: RegExp = /enable quantum upsell/i): Promise<void> {
  fireEvent.click(await screen.findByRole('switch', { name }));
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
    // Fail-closed default row: off.
    expect(screen.getByText('Off')).toBeInTheDocument();

    await switchOn();
    expect(screen.getByText(/ehita smailys vastav automatsioon/i)).toBeInTheDocument();

    const docsLink = screen.getByRole('link', { name: /smaily templates guide/i });
    expect(docsLink).toHaveAttribute('href', CATALOG.docs);

    // Test mode starts on, with the test-address field.
    expect(screen.getByText(/the email goes to test addresses only/i)).toBeInTheDocument();
  });

  it('shows only the title, description and switch while the trigger is off (CON-255)', async () => {
    render(<Harness initial={connectedState()} />);

    expect(await screen.findByText('Quantum upsell')).toBeInTheDocument();
    expect(screen.getByText('Description in English.')).toBeInTheDocument();
    expect(screen.getByRole('switch', { name: /enable quantum upsell/i })).not.toBeChecked();

    expect(screen.queryByText(/ehita smailys vastav automatsioon/i)).not.toBeInTheDocument();
    expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
    expect(screen.queryByLabelText(/cooldown/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/the email goes to test addresses only/i)).not.toBeInTheDocument();
    expect(screen.queryByRole('switch', { name: /^test mode$/i })).not.toBeInTheDocument();
  });

  it('shows the workflow, cooldown and test mode in that order once switched on (CON-255)', async () => {
    render(<Harness initial={connectedState()} />);
    await switchOn();

    const recipe = screen.getByText(/ehita smailys vastav automatsioon/i);
    const workflow = await screen.findByRole('combobox');
    const cooldown = screen.getByLabelText(/cooldown/i);
    const testMode = screen.getByText(/the email goes to test addresses only/i);

    const follows = (a: Element, b: Element): boolean =>
      (a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0;
    expect(follows(screen.getByRole('switch', { name: /enable quantum upsell/i }), recipe)).toBe(true);
    expect(follows(recipe, workflow)).toBe(true);
    expect(follows(workflow, cooldown)).toBe(true);
    expect(follows(cooldown, testMode)).toBe(true);
  });

  it('keeps the hidden workflow, cooldown and test addresses in the row across off and on (CON-255)', async () => {
    let latest: WizardState | undefined;
    function Watching(): React.JSX.Element {
      const [state, dispatch] = useReducer(wizardReducer, connectedState());
      latest = state;
      return <EngineAutomationsSection state={state} dispatch={dispatch} inSettings />;
    }
    render(<Watching />);
    await switchOn();

    await screen.findByRole('option', { name: 'Replenishment flow' });
    fireEvent.change(screen.getByRole('combobox'), { target: { value: '123' } });
    const cooldown = screen.getByLabelText(/cooldown/i);
    fireEvent.change(cooldown, { target: { value: '21' } });
    fireEvent.blur(cooldown);
    fireEvent.change(screen.getByLabelText(/test addresses/i), { target: { value: 'owner@shop.example' } });

    fireEvent.click(screen.getByRole('switch', { name: /enable quantum upsell/i }));
    expect(screen.queryByRole('combobox')).not.toBeInTheDocument();

    // The save PUTs the rows from state: the hidden values are all there.
    expect(latest?.engineAutomations.rows[0]).toMatchObject({
      enabled: false,
      automation_map: { id: '123' },
      cooldown_days: 21,
      test_emails: ['owner@shop.example'],
    });

    await switchOn();
    expect(screen.getByRole('combobox')).toHaveValue('123');
    expect(screen.getByLabelText(/cooldown/i)).toHaveValue(21);
    expect(screen.getByLabelText(/test addresses/i)).toHaveValue('owner@shop.example');
  });

  it('keeps an off card open while one of its fields needs fixing (CON-255)', async () => {
    configMock.mockResolvedValue({
      configs: [
        {
          trigger_key: 'quantum_upsell_2027',
          enabled: false,
          language_mode: 'single',
          automation_map: {},
          cooldown_days: 7,
          daily_cap: null,
          test_mode: true,
          test_emails: ['not-an-address'],
          configured_via: 'admin',
          updated_at: '2026-10-05T08:00:00.000Z',
        },
      ],
    });
    render(<Harness initial={connectedState()} />);

    expect(await screen.findByText('Invalid email address.')).toBeInTheDocument();
    expect(screen.getByLabelText(/test addresses/i)).toBeInTheDocument();
  });

  it('uses the _et copy when the admin locale is Estonian', async () => {
    document.documentElement.lang = 'et';
    render(<Harness initial={connectedState()} />);

    expect(await screen.findByText('Kvant-lisamüük')).toBeInTheDocument();
    expect(screen.getByText('Kirjeldus eesti keeles.')).toBeInTheDocument();
  });

  it('filters INACTIVE workflows out of the dropdown', async () => {
    render(<Harness initial={connectedState()} />);
    await switchOn();

    // The workflow list loads AFTER the combobox first renders (placeholder
    // option only) — await the ACTIVE option itself, not just the select;
    // asserting on the intermediate render made this flaky under a loaded
    // parallel run (2× on 2026-07-08).
    expect(await screen.findByRole('option', { name: 'Replenishment flow' })).toBeInTheDocument();

    const select = screen.getByRole('combobox');
    const optionLabels = Array.from(select.querySelectorAll('option')).map((o) => o.textContent);
    expect(optionLabels).not.toContain('Old flow');
  });

  describe('test mode and the test email (CON-455)', () => {
    const KEY = 'quantum_upsell_2027';

    function storedRow(testMode: boolean): AutomationConfigServerRow {
      return {
        trigger_key: KEY,
        enabled: true,
        language_mode: 'single',
        automation_map: { id: '123' },
        cooldown_days: 7,
        daily_cap: null,
        test_mode: testMode,
        test_emails: ['owner@shop.example'],
        configured_via: 'plugin',
        updated_at: '2026-11-26T08:00:00.000Z',
      };
    }

    function setupItem(fields: Partial<AutomationSetupItem>): AutomationSetupItem {
      return {
        trigger_key: KEY,
        setup_state: 'test',
        stop_reason: null,
        go_live_blockers: [],
        notice: null,
        live_since: null,
        daily_limit: null,
        ...fields,
      };
    }

    /**
     * The engine's §12 answer, changed by a mocked switch the way the engine
     * changes it. StrictMode mounts twice, so the first read happens twice:
     * the mock answers by state, never by call order.
     */
    function engineAnswers(initial: { live: boolean; dailyLimit?: number }): void {
      let live = initial.live;
      const dailyLimit = initial.dailyLimit ?? 10;
      configMock.mockImplementation(async () => ({
        configs: [storedRow(!live)],
        setup: [setupItem(live ? { setup_state: 'live', daily_limit: dailyLimit } : {})],
      }));
      switchMock.mockImplementation(async (_key, testMode) => {
        live = !testMode;
        return { ok: true };
      });
    }

    function renderStrict(): void {
      render(
        <StrictMode>
          <Harness initial={connectedState()} />
        </StrictMode>,
      );
    }

    afterEach(() => {
      delete (window as unknown as { smailyConnectBoot?: unknown }).smailyConnectBoot;
    });

    it('turns test mode off only after a dialog that names the email, then shows the state the engine stores', async () => {
      engineAnswers({ live: false, dailyLimit: 25 });
      renderStrict();

      const testMode = await screen.findByRole('switch', { name: /^test mode$/i });
      expect(testMode).toBeChecked();
      expect(screen.getByText('The email goes to test addresses only.')).toBeInTheDocument();
      expect(screen.queryByText(/activate for real/i)).not.toBeInTheDocument();
      expect(screen.queryByText(/waiting for smaily/i)).not.toBeInTheDocument();

      // Cancel: nothing is sent and test mode stays on.
      fireEvent.click(testMode);
      let dialog = screen.getByRole('dialog', { name: 'Turn off test mode?' });
      expect(dialog).toHaveTextContent(
        '“Quantum upsell” will start going to your store\'s real customers, at first up to 10 emails a day, then 25 and 50.',
      );
      fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }));
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
      expect(switchMock).not.toHaveBeenCalled();
      expect(testMode).toBeChecked();

      // Confirm: one switch, then the §12 re-read shows the email live.
      fireEvent.click(testMode);
      dialog = screen.getByRole('dialog', { name: 'Turn off test mode?' });
      fireEvent.click(within(dialog).getByRole('button', { name: 'Turn off test mode' }));

      expect(await screen.findByText('Sending to real customers · up to 25 emails today')).toBeInTheDocument();
      expect(switchMock).toHaveBeenCalledTimes(1);
      expect(switchMock).toHaveBeenCalledWith(KEY, false);
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
      expect(screen.getByRole('switch', { name: /^test mode$/i })).not.toBeChecked();
      expect(screen.getByText('Live — real sends on')).toBeInTheDocument();
    });

    it('lists what blocks the switch in plain words and keeps test mode on', async () => {
      configMock.mockResolvedValue({ configs: [storedRow(true)], setup: [setupItem({})] });
      switchMock.mockResolvedValue({ ok: false, error: 'go_live_blocked', codes: ['no_design', 'stopped_needs_review', 'new_engine_blocker'] });
      renderStrict();

      fireEvent.click(await screen.findByRole('switch', { name: /^test mode$/i }));
      const dialog = screen.getByRole('dialog', { name: 'Turn off test mode?' });
      fireEvent.click(within(dialog).getByRole('button', { name: 'Turn off test mode' }));

      expect(await within(dialog).findByText('Test mode stays on until this is fixed:')).toBeInTheDocument();
      expect(within(dialog).getByText('The email has no design yet.')).toBeInTheDocument();
      expect(within(dialog).getByText('Smaily reviews this stop before the email can send again.')).toBeInTheDocument();
      expect(within(dialog).getByText('Setup is not complete.')).toBeInTheDocument();
      expect(screen.getByRole('switch', { name: /^test mode$/i })).toBeChecked();
    });

    it('turns test mode back on at once, with no dialog', async () => {
      engineAnswers({ live: true });
      renderStrict();

      expect(await screen.findByText('Sending to real customers · up to 10 emails today')).toBeInTheDocument();
      fireEvent.click(screen.getByRole('switch', { name: /^test mode$/i }));

      expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
      expect(await screen.findByText('The email goes to test addresses only.')).toBeInTheDocument();
      expect(switchMock).toHaveBeenCalledWith(KEY, true);
    });

    it('shows a stop Smaily reviews and the engine notice in plain words', async () => {
      configMock.mockResolvedValue({
        configs: [storedRow(true)],
        setup: [
          setupItem({
            setup_state: 'stopped',
            stop_reason: 'auto_stop_complaint',
            go_live_blockers: ['stopped_needs_review'],
            notice: 'starts_when_sender_verified',
          }),
        ],
      });
      renderStrict();

      expect(await screen.findByText('Stopped')).toBeInTheDocument();
      expect(screen.getByText('Smaily reviews this stop before the email can send again.')).toBeInTheDocument();
      expect(screen.getByText('Starts when your sender domain is verified in Smaily.')).toBeInTheDocument();
    });

    it('sends a test email to the admin\'s own address by default and says it joins the Smaily list', async () => {
      (window as unknown as { smailyConnectBoot: unknown }).smailyConnectBoot = { adminEmail: 'admin@shop.example' };
      configMock.mockResolvedValue({ configs: [storedRow(true)], setup: [setupItem({})] });
      sendMock.mockResolvedValue({ ok: true });
      renderStrict();

      const field = await screen.findByLabelText('Send a test email to');
      expect(field).toHaveValue('admin@shop.example');
      expect(screen.getByText('This address is added to the Smaily list as a test contact.')).toBeInTheDocument();
      expect(screen.getByText('The test shows the design your Smaily workflow holds.')).toBeInTheDocument();

      fireEvent.change(field, { target: { value: ' tester@shop.example ' } });
      const readsBefore = configMock.mock.calls.length;
      fireEvent.click(screen.getByRole('button', { name: 'Send test email' }));

      expect(await screen.findByText('Test email sent to tester@shop.example. Check the inbox.')).toBeInTheDocument();
      expect(sendMock).toHaveBeenCalledTimes(1);
      expect(sendMock).toHaveBeenCalledWith(KEY, 'tester@shop.example');
      // The §12 state is read again after the send.
      await waitFor(() => expect(configMock).toHaveBeenCalledTimes(readsBefore + 1));
    });

    it.each([
      [
        { ok: false as const, error: 'not_ready', codes: ['no_workflow', 'no_products'] },
        ['The test email cannot be sent yet:', 'No Smaily workflow is linked to this email.', 'No customer has recommendations for this email yet.'],
      ],
      [
        { ok: false as const, error: 'sender_refused', codes: ['unverified'] },
        ['Smaily refused to send from your sender address: its domain is not verified in Smaily yet, or the address is not allowed. Real sends are not affected.'],
      ],
      [
        { ok: false as const, error: 'rate_limited', codes: [] },
        ['Too many requests. Up to 10 test emails can be sent an hour. Try again later.'],
      ],
      [
        { ok: false as const, error: 'smaily_unavailable', codes: [] },
        ['Smaily did not answer, so the test email was not sent. Press the button again to try once more.'],
      ],
    ])('says why a refused test send was not sent and sends nothing more by itself (%o)', async (result, texts) => {
      configMock.mockResolvedValue({ configs: [storedRow(true)], setup: [setupItem({})] });
      sendMock.mockResolvedValue(result);
      renderStrict();

      fireEvent.change(await screen.findByLabelText('Send a test email to'), { target: { value: 'tester@shop.example' } });
      fireEvent.click(screen.getByRole('button', { name: 'Send test email' }));

      for (const text of texts) {
        expect(await screen.findByText(text)).toBeInTheDocument();
      }
      expect(sendMock).toHaveBeenCalledTimes(1);
    });
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
    await switchOn();

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
    await switchOn();

    const cooldown = (await screen.findByLabelText(/cooldown/i)) as HTMLInputElement;
    fireEvent.change(cooldown, { target: { value: '' } });
    fireEvent.blur(cooldown);

    expect(cooldown.value).toBe('7');
  });

  it('clamps an out-of-range cooldown to 1–365 on blur (T2.4/2)', async () => {
    render(<Harness initial={connectedState()} />);
    await switchOn();

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

    // Off → the card shows no test-mode block, so no warning either.
    await screen.findByText('Quantum upsell');
    expect(screen.queryByText(/no emails will be sent to anyone/i)).not.toBeInTheDocument();

    // Enabled + test mode + empty list = silent nobody-gets-mail → warn.
    await switchOn();
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
    await switchOn();

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
    await switchOn(/kvant-lisamüük/i);

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
