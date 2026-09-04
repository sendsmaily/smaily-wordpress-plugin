import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
  getAutomationsCatalog,
  getAutomationsConfig,
  putAutomationsConfig,
  type AutomationsCatalogResponse,
} from '../../api/automations';
import { saveSettings } from '../../api/saveSettings';
import { listWorkflows } from '../../api/workflows';
import { _resetUseWorkflowsCache } from '../../hooks/useWorkflows';
import { buildSettingsInitialState } from '../../state/settings-reducer';
import { type WizardState } from '../../state/types';
import { Settings } from './Settings';

vi.mock('../../api/automations', async (importOriginal) => {
  const original = await importOriginal<Record<string, unknown>>();
  return {
    ...original,
    getAutomationsCatalog: vi.fn(),
    getAutomationsConfig: vi.fn(),
    putAutomationsConfig: vi.fn(),
  };
});

vi.mock('../../api/saveSettings', () => ({
  saveSettings: vi.fn(),
}));

vi.mock('../../api/workflows', () => ({
  listWorkflows: vi.fn(),
}));

const catalogMock = vi.mocked(getAutomationsCatalog);
const configMock = vi.mocked(getAutomationsConfig);
const putMock = vi.mocked(putAutomationsConfig);
const saveMock = vi.mocked(saveSettings);
const workflowsMock = vi.mocked(listWorkflows);

const CATALOG: AutomationsCatalogResponse = {
  triggers: [
    {
      key: 'replenish_due',
      name_et: 'Taastäitumine',
      name_en: 'Replenishment due',
      description_et: 'Kirjeldus.',
      description_en: 'Description.',
      recipe_et: 'Retsept.',
    },
  ],
  language_modes: ['single', 'per_language'],
  docs: 'https://intelligence.smaily.example/docs',
};

/** Engine-admin-configured row — daily_cap 500 must round-trip untouched. */
const SERVER_CONFIG = {
  trigger_key: 'replenish_due',
  enabled: true,
  language_mode: 'single' as const,
  automation_map: { id: '123' },
  cooldown_days: 7,
  daily_cap: 500,
  test_mode: true,
  test_emails: ['owner@shop.example'],
  configured_via: 'admin' as const,
  updated_at: '2026-07-07T05:15:00.000Z',
};

function connectedState(): WizardState {
  return {
    ...buildSettingsInitialState({ smailyConnected: true, detectedLanguages: ['et'] }),
    recEngineConnection: { kind: 'success', message: 'Test tenant' },
  };
}

async function renderOnWooCommerceTab(): Promise<void> {
  window.location.hash = 'woocommerce';
  render(<Settings initialState={connectedState()} />);
  // Section fetched + hydrated once the trigger card is up.
  await screen.findByText('Replenishment due');
}

async function editCooldown(value: string): Promise<HTMLElement> {
  const cooldown = await screen.findByLabelText(/cooldown/i);
  fireEvent.change(cooldown, { target: { value } });
  // T2.4/2: the field keeps a local draft while typing and commits on
  // blur — clicking Save moves focus off the input, so blur precedes
  // the save in the real flow too.
  fireEvent.blur(cooldown);
  return cooldown;
}

describe('Settings — engine automations join the WooCommerce tab save (T2.2)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    _resetUseWorkflowsCache();
    document.documentElement.lang = 'en-US';
    catalogMock.mockResolvedValue(CATALOG);
    configMock.mockResolvedValue({ configs: [SERVER_CONFIG] });
    saveMock.mockResolvedValue({ saved: true, errors: [] });
    workflowsMock.mockResolvedValue({
      workflows: [{ id: '123', name: 'Replenishment flow', status: 'ACTIVE' }],
    });
  });

  afterEach(() => {
    _resetUseWorkflowsCache();
    window.location.hash = '';
    document.documentElement.lang = '';
  });

  it('enables Save on an engine-only edit and fires BOTH requests in parallel', async () => {
    putMock.mockResolvedValue({ ok: true, upserted: 1 });
    await renderOnWooCommerceTab();

    const saveButton = screen.getByRole('button', { name: /save changes/i });
    expect(saveButton).toBeDisabled();

    await editCooldown('21');
    expect(saveButton).not.toBeDisabled();

    fireEvent.click(saveButton);

    await waitFor(() => {
      expect(saveMock).toHaveBeenCalledWith(
        expect.objectContaining({ tab: 'woocommerce' }),
        expect.anything(),
      );
      expect(putMock).toHaveBeenCalledTimes(1);
    });

    // The PUT carries the FULL row: the edited cooldown, the untouched
    // daily_cap from GET (never nulled by the UI), and NO read-only
    // §12 fields.
    const sentRows = putMock.mock.calls[0]?.[0];
    expect(sentRows).toHaveLength(1);
    expect(sentRows?.[0]).toMatchObject({
      trigger_key: 'replenish_due',
      enabled: true,
      language_mode: 'single',
      automation_map: { id: '123' },
      cooldown_days: 21,
      daily_cap: 500,
      test_mode: true,
      test_emails: ['owner@shop.example'],
    });
    expect(sentRows?.[0]).not.toHaveProperty('configured_via');
    expect(sentRows?.[0]).not.toHaveProperty('updated_at');

    expect(await screen.findByText(/engine automations saved/i)).toBeInTheDocument();
    await waitFor(() => {
      expect(screen.getByRole('button', { name: /save changes/i })).toBeDisabled();
    });
  });

  it('keeps ONLY the engine section dirty when the PUT fails but the local POST succeeds', async () => {
    putMock.mockResolvedValue({
      ok: false,
      kind: 'validation_failed',
      errors: [
        { index: 0, trigger_key: 'replenish_due', field: 'cooldown_days', message: 'vahemik 1–365' },
      ],
    });
    await renderOnWooCommerceTab();

    await editCooldown('21');
    fireEvent.click(screen.getByRole('button', { name: /save changes/i }));

    // Local half saved…
    expect(await screen.findByText(/settings saved/i)).toBeInTheDocument();
    // …engine half failed at the section, with the error bound to its field.
    expect(await screen.findByText(/engine automations not saved/i)).toBeInTheDocument();
    expect(screen.getByText(/vahemik 1–365/)).toBeInTheDocument();

    // All-or-nothing: the slice stays dirty so Save stays available for
    // the corrected resubmit — the merchant loses neither half.
    expect(screen.getByRole('button', { name: /save changes/i })).not.toBeDisabled();
  });

  it('does not PUT when only the local WooCommerce half is dirty', async () => {
    await renderOnWooCommerceTab();

    // A store-run automation edit (welcome toggle) — local dirty only.
    fireEvent.click(screen.getByRole('switch', { name: /enable welcome email/i }));
    fireEvent.click(screen.getByRole('button', { name: /save changes/i }));

    await waitFor(() => {
      expect(saveMock).toHaveBeenCalledTimes(1);
    });
    expect(putMock).not.toHaveBeenCalled();
  });
});
