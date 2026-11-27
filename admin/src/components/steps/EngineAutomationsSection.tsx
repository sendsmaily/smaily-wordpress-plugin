import { useEffect, useId, useRef, useState, type Dispatch } from 'react';
import { createPortal } from 'react-dom';

import { __, sprintf } from '@admin/lib/i18n';
import {
  sendAutomationTest,
  switchAutomationTestMode,
  type AutomationActionResult,
  type AutomationCatalogTrigger,
  type AutomationSetupItem,
  type AutomationsFailure,
} from '../../api/automations';
import { useAutomationsData } from '../../hooks/useAutomationsData';
import { useWorkflows } from '../../hooks/useWorkflows';
import {
  buildRows,
  COOLDOWN_MAX,
  COOLDOWN_MIN,
  deriveLanguageMode,
  engineTriggerStatus,
  fallbackLanguage,
  issuesByTrigger,
  pickRecipe,
  setFallbackLanguage,
  updateLanguageWorkflow,
  validateRows,
  type EngineTriggerStatus,
} from '../../state/engine-automations';
import { readBoot } from '../../state/hydrate';
import {
  type EngineAutomationRow,
  type EngineAutomationsIssue,
  type WizardAction,
  type WizardState,
} from '../../state/types';
import {
  Banner,
  Button,
  Card,
  Input,
  Label,
  NumberInput,
  Pill,
  Radio,
  Select,
  Toggle,
} from '../primitives';
import { EditDesign } from './EmailDesigns';

export interface EngineAutomationsSectionProps {
  state: WizardState;
  dispatch: Dispatch<WizardAction>;
  inSettings?: boolean;
}

/**
 * Engine-run recommendation automations (contract §11–§13, T2.2).
 *
 * Rendered as a sub-section UNDER the store-run WooCommerce automations
 * (Step 3 / WooCommerce tab). The distinction matters and is stated in
 * the UI: these are fired by Smaily Campaign Intelligence — the engine
 * enrols contacts into the merchant's Smaily workflows at the predicted
 * moment (replenishment due, win-back, …); the store only CONFIGURES
 * them here.
 *
 * Design rules (F3-51/F3-52):
 *  - Catalog-driven render: triggers come ONLY from the §11 GET —
 *    no hardcoded keys, no assumed count; a new engine-deployed trigger
 *    appears without a plugin release.
 *  - The engine's GET is the truth: catalog+config re-fetch on every
 *    open (the section unmounts on tab switch). A dirty draft survives
 *    the re-fetch; a clean one is replaced.
 *  - Fail-closed: enabled comes only from the merchant's toggle; test
 *    mode starts ON. The "Test mode" switch is not part of the save: it
 *    calls §20 at once (CON-455) — off only after a confirmation dialog
 *    that names the email, back on with no dialog — and "Send test
 *    email" calls §19. After a save, a switch or a test send the §12
 *    config is re-read, and the card shows what the engine stores.
 *  - Save rides the surrounding context's save path (Settings sticky
 *    footer / wizard Continue) via state.engineAutomations — this
 *    component renders and edits; it never PUTs on its own.
 */
export function EngineAutomationsSection({
  state,
  dispatch,
  inSettings = false,
}: EngineAutomationsSectionProps): React.JSX.Element | null {
  const isConnected = state.recEngineConnection.kind === 'success';

  // PRO-1717: in the wizard a store without Campaign Intelligence gets
  // nothing here — the very next step IS the Campaign Intelligence
  // overview + connection path, so an upsell one step earlier is noise.
  // Settings has no such next step, so its upsell (with the tab CTA)
  // stays.
  if (!isConnected && !inSettings) {
    return null;
  }

  return (
    <div className="space-y-4 border-t border-border-subtle pt-6">
      <div>
        <h3 className="text-lg font-semibold text-text-primary">
          { __( 'Campaign Intelligence automation workflows', 'smaily-connect' ) }
        </h3>
        <p className="mt-1 text-sm text-text-secondary">
          { __(
            'Following automated emails are sent by Smaily’s Campaign Intelligence tool that triggers Smaily’s automation workflows at the predicted moment (a replenishment running out, a customer at risk of churning, etc.), based on customer’s previous purchase history and behaviour.',
            'smaily-connect',
          ) }
        </p>
      </div>

      {isConnected ? (
        <ConnectedSection state={state} dispatch={dispatch} inSettings={inSettings} />
      ) : (
        <UpsellBanner inSettings={inSettings} />
      )}
    </div>
  );
}

/**
 * Not-connected state: a modest upsell instead of the section. In
 * Settings the CTA jumps to the Campaign Intelligence tab (hash
 * routing); in the wizard the connection happens in the NEXT step, so
 * the copy points forward instead of linking. Since PRO-1717 the wizard
 * reaches this only through the not_connected LOAD FAILURE below (boot
 * said connected, the proxy disagrees) — a plainly not-connected store
 * renders no section at all there.
 */
function UpsellBanner({ inSettings }: { inSettings: boolean }): React.JSX.Element {
  return (
    <Banner
      tone="info"
      title={ __( 'Connect Campaign Intelligence to unlock', 'smaily-connect' ) }
      actions={
        inSettings ? (
          <Button
            variant="secondary"
            size="sm"
            type="button"
            onClick={() => {
              window.location.hash = 'recommendations';
            }}
          >
            { __( 'Open Campaign Intelligence', 'smaily-connect' ) }
          </Button>
        ) : undefined
      }
    >
      { __(
        'Replenishment reminders, win-back campaigns and other engine-run automations sent at the right moment for each customer through Smaily workflows.',
        'smaily-connect',
      ) }{' '}
      {inSettings
        ? __( 'Connect Smaily Campaign Intelligence on the Campaign Intelligence tab to set them up.', 'smaily-connect' )
        : __( 'You can connect Campaign Intelligence at the next setup step.', 'smaily-connect' )}
    </Banner>
  );
}

function ConnectedSection({
  state,
  dispatch,
  inSettings,
}: {
  state: WizardState;
  dispatch: Dispatch<WizardAction>;
  inSettings: boolean;
}): React.JSX.Element {
  const { data, status, failure, refetch, refetchConfig } = useAutomationsData(true);
  const engine = state.engineAutomations;

  const derivedMode = deriveLanguageMode(state.multilingualMode, state.env.detectedLanguages);

  // Re-read §12 after every save: the engine may store a row
  // differently from what was sent (a real-sends request stays in test
  // mode, §13), and the screen must show what the engine stored. Fires
  // on the pending → success transition only, so a remount after an
  // earlier save does not re-read twice.
  const previousSaveStatus = useRef(engine.saveStatus);
  const rereadAfterSave = useRef(false);
  useEffect(() => {
    if (previousSaveStatus.current === 'pending' && engine.saveStatus === 'success') {
      rereadAfterSave.current = true;
      refetchConfig();
    }
    previousSaveStatus.current = engine.saveStatus;
  }, [engine.saveStatus, refetchConfig]);

  // Hydrate the reducer slice from every fresh fetch. A dirty draft is
  // preserved (its rows win per trigger); a clean slice takes the
  // server rows verbatim — the engine's GET is the truth (F3-51).
  useEffect(() => {
    if (data === null) {
      return;
    }
    const { rows, mapConverted } = buildRows(
      data.catalog.triggers,
      data.configs,
      engine.dirty ? engine.rows : null,
      derivedMode,
    );
    dispatch({
      type: 'ENGINE_AUTOMATIONS_HYDRATED',
      payload: {
        rows,
        keepDirty: engine.dirty,
        afterSave: rereadAfterSave.current,
        mapConverted,
      },
    });
    rereadAfterSave.current = false;
    // engine.dirty/rows are read on purpose only when data changes — a
    // fetch result hydrates once; edits must not re-trigger it.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [data, dispatch, derivedMode]);

  // The post-save re-read keeps the cards on screen; only a first load
  // (no data yet) shows the skeleton.
  if (data === null && (status === 'pending' || status === 'idle')) {
    return <SkeletonCard />;
  }

  if (status === 'error' && failure !== null) {
    return <LoadFailure failure={failure} onRetry={refetch} inSettings={inSettings} />;
  }

  if (data === null) {
    return <SkeletonCard />;
  }

  if (data.catalog.triggers.length === 0) {
    return (
      <Banner tone="info">
        { __( 'No engine automations are available for your store yet. New triggers appear here automatically when the engine adds them.', 'smaily-connect' ) }
      </Banner>
    );
  }

  const rowByKey = new Map(engine.rows.map((r) => [r.trigger_key, r]));
  const hydrated = data.catalog.triggers.every((t) => rowByKey.has(t.key));
  if (!hydrated) {
    return <SkeletonCard />;
  }

  const localIssues = validateRows(engine.rows);
  const boundLocal = issuesByTrigger(localIssues, engine.rows);
  const boundServer = issuesByTrigger(engine.serverErrors, engine.rows);
  // Wrapper-level / unbindable server errors surface in the section banner.
  const unboundServer = boundServer.get('') ?? [];
  const storedByKey = new Map(data.configs.map((c) => [c.trigger_key, c]));
  const setupByKey = new Map(data.setup.map((item) => [item.trigger_key, item]));

  return (
    <div className="space-y-4">
      {engine.saveStatus === 'error' && engine.saveError !== null && (
        <Banner tone="danger" title={ __( 'Engine automations not saved', 'smaily-connect' ) }>
          {engine.saveError}
          {unboundServer.length > 0 && (
            <>
              {' '}
              {unboundServer.map((issue) => `${issue.field}: ${issue.message}`).join('; ')}
            </>
          )}
        </Banner>
      )}
      {engine.saveStatus === 'success' && (
        <Banner tone="success">{ __( 'Engine automations saved.', 'smaily-connect' ) }</Banner>
      )}

      {data.catalog.triggers.map((trigger) => {
        const row = rowByKey.get(trigger.key);
        if (row === undefined) {
          return null;
        }
        return (
          <TriggerCard
            key={trigger.key}
            trigger={trigger}
            row={row}
            docsUrl={data.catalog.docs}
            status={engineTriggerStatus(storedByKey.get(trigger.key), setupByKey.get(trigger.key))}
            setup={setupByKey.get(trigger.key)}
            onEngineChanged={refetchConfig}
            state={state}
            dispatch={dispatch}
            issues={[
              ...(boundLocal.get(trigger.key) ?? []),
              ...(boundServer.get(trigger.key) ?? []),
            ]}
          />
        );
      })}
    </div>
  );
}

function SkeletonCard(): React.JSX.Element {
  return (
    <Card>
      <div className="animate-pulse space-y-3" aria-hidden data-testid="engine-automations-skeleton">
        <div className="h-5 w-1/3 rounded bg-surface-muted" />
        <div className="h-4 w-2/3 rounded bg-surface-muted" />
        <div className="h-10 w-full rounded bg-surface-muted" />
      </div>
      <span className="sr-only">{ __( 'Loading engine automations…', 'smaily-connect' ) }</span>
    </Card>
  );
}

function LoadFailure({
  failure,
  onRetry,
  inSettings,
}: {
  failure: AutomationsFailure;
  onRetry: () => void;
  inSettings: boolean;
}): React.JSX.Element {
  if (failure.kind === 'not_connected') {
    // The proxy 503'd even though the boot payload said connected —
    // stored config is gone/incomplete. Same answer as not connected.
    return <UpsellBanner inSettings={inSettings} />;
  }

  if (failure.kind === 'key_rejected') {
    return (
      <Banner tone="danger" title={ __( 'Campaign Intelligence rejected the stored API key', 'smaily-connect' ) }>
        { __( 'The key was revoked or rotated. Disconnect and reconnect with a fresh setup link on the Campaign Intelligence tab, then come back here.', 'smaily-connect' ) }
      </Banner>
    );
  }

  return (
    <Banner
      tone="danger"
      title={ __( "Couldn't load engine automations", 'smaily-connect' ) }
      actions={
        <Button variant="secondary" size="sm" type="button" onClick={onRetry}>
          { __( 'Retry', 'smaily-connect' ) }
        </Button>
      }
    >
      {/* Human summary as the banner body; the raw technical error
          (e.g. "GET /… → 502") demoted to a small detail line (T2.4/4). */}
      {failure.message}
      {failure.detail !== undefined && (
        // span, not p — the Banner body already renders inside a <p>.
        <span className="mt-1 block text-xs opacity-80">{failure.detail}</span>
      )}
    </Banner>
  );
}

// ---------------------------------------------------------------------------
// Per-trigger card
// ---------------------------------------------------------------------------

interface TriggerCardProps {
  trigger: AutomationCatalogTrigger;
  row: EngineAutomationRow;
  docsUrl: string;
  /** The trigger's state as the engine stores it (§12) — not the draft. */
  status: EngineTriggerStatus;
  /** The §12 `setup[]` item; undefined from an engine before v1.15.0. */
  setup: AutomationSetupItem | undefined;
  /** Re-read §12 after a test send or a test-mode switch. */
  onEngineChanged: () => void;
  state: WizardState;
  dispatch: Dispatch<WizardAction>;
  issues: EngineAutomationsIssue[];
}

function TriggerCard({
  trigger,
  row,
  docsUrl,
  status,
  setup,
  onEngineChanged,
  state,
  dispatch,
  issues,
}: TriggerCardProps): React.JSX.Element {
  const et = isEstonianAdminLocale();
  const name = et ? trigger.name_et : trigger.name_en;
  const description = et ? trigger.description_et : trigger.description_en;

  const patch = (fields: Partial<EngineAutomationRow>): void => {
    dispatch({
      type: 'UPDATE_ENGINE_AUTOMATION',
      payload: { triggerKey: trigger.key, patch: fields },
    });
  };

  const statusPill = {
    off: <Pill tone="neutral">{ __( 'Off', 'smaily-connect' ) }</Pill>,
    test: <Pill tone="warning">{ __( 'Test mode', 'smaily-connect' ) }</Pill>,
    live: <Pill tone="success">{ __( 'Live — real sends on', 'smaily-connect' ) }</Pill>,
    stopped: <Pill tone="danger">{ __( 'Stopped', 'smaily-connect' ) }</Pill>,
  }[status];

  // CON-255: an off card is its title, description and switch only. The
  // row stays whole in the reducer slice, and the save PUTs every row from
  // there, so nothing hidden is lost. A card with a field to fix stays open
  // even while off — "Fix the highlighted fields" must point at something
  // the merchant can see.
  const open = row.enabled || issues.length > 0;

  return (
    <Card title={name} description={description} headerAccessory={statusPill}>
      <Toggle
        name={`smly-engine-automation-${trigger.key}-enabled`}
        checked={row.enabled}
        onChange={(e) => patch({ enabled: e.target.checked })}
        label={sprintf(
          // translators: %s is the automation name, e.g. "Replenishment due".
          __( 'Enable %s', 'smaily-connect' ),
          name,
        )}
      />

      {open && (
        <div className="mt-5 space-y-5">
          {/* Recipe — what the Smaily-side automation must contain. Locale
              pick matches name/description: non-et locales read recipe_en
              when the engine provides it (forward-compatible, T2.4/5), else
              recipe_et. */}
          <div className="rounded border border-border-subtle bg-surface-soft px-3 py-2 text-sm text-text-secondary">
            {pickRecipe(trigger, et)}
            {isHttpUrl(docsUrl) && (
              <>
                {' '}
                <a href={docsUrl} target="_blank" rel="noopener noreferrer" className="underline">
                  { __( 'Smaily templates guide', 'smaily-connect' ) }
                </a>
              </>
            )}
          </div>

          <WorkflowMapping
            trigger={trigger}
            row={row}
            state={state}
            onMapChange={(map) => patch({ automation_map: map })}
            issues={issues}
          />

          <EditDesign emailKey={trigger.key} />

          <CooldownField trigger={trigger} row={row} patch={patch} issues={issues} />

          <TestModeBlock
            trigger={trigger}
            row={row}
            name={name}
            status={status}
            setup={setup}
            onEngineChanged={onEngineChanged}
            patch={patch}
            issues={issues}
          />
        </div>
      )}
    </Card>
  );
}

/**
 * Cooldown field with a local typing draft (T2.4/2). The old inline
 * onChange committed every keystroke through parseInt, so clearing the
 * field snapped to 0 and typing a fresh number produced "0…" — here the
 * raw string (empty included) lives in a local draft while the field is
 * focused, and blur commits: a parseable value is clamped to 1–365, an
 * empty/garbage draft reverts to the last committed value (never 0).
 * Deliberately NOT built into the shared NumberInput primitive — its
 * other call sites rely on the immediate-commit behaviour.
 */
function CooldownField({
  trigger,
  row,
  patch,
  issues,
}: {
  trigger: AutomationCatalogTrigger;
  row: EngineAutomationRow;
  patch: (fields: Partial<EngineAutomationRow>) => void;
  issues: EngineAutomationsIssue[];
}): React.JSX.Element {
  // null = not editing → render the committed row value.
  const [draft, setDraft] = useState<string | null>(null);

  const commit = (): void => {
    if (draft === null) {
      return;
    }
    const parsed = parseInt(draft, 10);
    if (!Number.isNaN(parsed)) {
      const clamped = Math.min(COOLDOWN_MAX, Math.max(COOLDOWN_MIN, parsed));
      if (clamped !== row.cooldown_days) {
        patch({ cooldown_days: clamped });
      }
    }
    setDraft(null);
  };

  return (
    <div>
      <Label htmlFor={`smly-engine-automation-${trigger.key}-cooldown`}>
        { __( 'Cooldown', 'smaily-connect' ) }
      </Label>
      <div className="mt-1 w-40">
        <NumberInput
          id={`smly-engine-automation-${trigger.key}-cooldown`}
          value={draft ?? String(row.cooldown_days)}
          min={COOLDOWN_MIN}
          max={COOLDOWN_MAX}
          unit={ __( 'days', 'smaily-connect' ) }
          invalid={hasIssue(issues, 'cooldown_days')}
          onChange={(e) => setDraft(e.target.value)}
          onBlur={commit}
        />
      </div>
      <p className="mt-1 text-xs text-text-tertiary">
        { __( 'Minimum days between two fires of this automation for the same customer (1–365).', 'smaily-connect' ) }
      </p>
      <FieldIssues issues={issues} field="cooldown_days" />
    </div>
  );
}

/**
 * Workflow picker — mirrors AutomationSection's language handling: the
 * row's language_mode decides between one dropdown ('single') and a
 * per-language table with a default-fallback radio ('per_language').
 * Mode A pins each language row to its own credential set's workflow
 * list, exactly like the store-run automations above.
 */
function WorkflowMapping({
  trigger,
  row,
  state,
  onMapChange,
  issues,
}: {
  trigger: AutomationCatalogTrigger;
  row: EngineAutomationRow;
  state: WizardState;
  onMapChange: (map: Record<string, string>) => void;
  issues: EngineAutomationsIssue[];
}): React.JSX.Element {
  const isModeA = state.multilingualMode === 'A';
  const languages = state.env.detectedLanguages;

  if (row.language_mode === 'single') {
    return (
      <div>
        <Label htmlFor={`smly-engine-automation-${trigger.key}-workflow`}>
          { __( 'Smaily workflow', 'smaily-connect' ) }
        </Label>
        <div className="mt-1">
          <WorkflowSelect
            id={`smly-engine-automation-${trigger.key}-workflow`}
            accountKey="default"
            value={row.automation_map.id ?? ''}
            invalid={hasIssue(issues, 'automation_map') || hasIssue(issues, 'automation_map.id')}
            onChange={(workflowId) =>
              onMapChange(workflowId === '' ? {} : { id: workflowId })
            }
          />
        </div>
        <FieldIssues issues={issues} field="automation_map" />
        <FieldIssues issues={issues} field="automation_map.id" />
      </div>
    );
  }

  const currentFallback = fallbackLanguage(row.automation_map, languages);

  return (
    <div>
      <Label>{ __( 'Smaily workflow per language', 'smaily-connect' ) }</Label>
      <table className="mt-1 w-full text-sm">
        <thead className="text-left text-text-tertiary">
          <tr>
            <th className="w-1/4 pb-2 font-medium">{ __( 'Language', 'smaily-connect' ) }</th>
            <th className="pb-2 font-medium">{ __( 'Workflow', 'smaily-connect' ) }</th>
            <th className="w-32 pb-2 text-center font-medium">{ __( 'Default fallback', 'smaily-connect' ) }</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-border-subtle">
          {languages.map((language) => (
            <tr key={language}>
              <td className="py-3 align-middle text-text-secondary">{humaniseLocale(language)}</td>
              <td className="py-3 align-middle">
                <WorkflowSelect
                  accountKey={isModeA ? `account_${language}` : 'default'}
                  value={row.automation_map[language] ?? ''}
                  invalid={hasIssue(issues, `automation_map.${language}`)}
                  onChange={(workflowId) =>
                    onMapChange(
                      updateLanguageWorkflow(row.automation_map, languages, language, workflowId),
                    )
                  }
                />
                <FieldIssues issues={issues} field={`automation_map.${language}`} />
              </td>
              <td className="py-3 text-center align-middle">
                <Radio
                  name={`smly-engine-fallback-${trigger.key}`}
                  checked={currentFallback === language}
                  disabled={row.automation_map[language] === undefined}
                  onChange={() => onMapChange(setFallbackLanguage(row.automation_map, language))}
                  aria-label={sprintf(
                    // translators: %s is a locale code, e.g. en-US.
                    __( 'Mark %s as default fallback', 'smaily-connect' ),
                    humaniseLocale(language),
                  )}
                />
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      <FieldIssues issues={issues} field="automation_map" />
      <FieldIssues issues={issues} field="automation_map.fallback" />
    </div>
  );
}

/**
 * One workflow dropdown backed by useWorkflows(accountKey) — ACTIVE
 * workflows only, but a saved id that is no longer in the active list
 * stays selectable so opening the section never silently rewires a
 * working config. Empty list → a manual numeric-id input; fetch error →
 * the same warning banner treatment AutomationSection uses.
 */
function WorkflowSelect({
  accountKey,
  value,
  invalid,
  onChange,
  id,
}: {
  accountKey: string;
  value: string;
  invalid?: boolean;
  onChange: (workflowId: string) => void;
  id?: string;
}): React.JSX.Element {
  const { workflows, status, error } = useWorkflows(accountKey);

  if (status === 'error' && error !== null) {
    return (
      <Banner tone="warning">
        {sprintf(
          // translators: %s is the error message.
          __( "Couldn't load workflows: %s", 'smaily-connect' ),
          error,
        )}
      </Banner>
    );
  }

  if (status === 'success' && workflows.length === 0) {
    return (
      <div>
        <Input
          id={id}
          value={value}
          invalid={invalid}
          inputMode="numeric"
          placeholder={ __( 'Workflow id, e.g. 123', 'smaily-connect' ) }
          onChange={(e) => onChange(e.target.value.trim())}
        />
        <p className="mt-1 text-xs text-text-tertiary">
          { __( 'No active workflows found in Smaily — enter the workflow id manually or create the workflow first.', 'smaily-connect' ) }
        </p>
      </div>
    );
  }

  const active = workflows.filter((w) => w.status !== 'INACTIVE');
  const options = active.map((w) => ({ value: w.id, label: w.name }));
  if (value !== '' && !options.some((o) => o.value === value)) {
    const known = workflows.find((w) => w.id === value);
    options.unshift({
      value,
      label: known !== undefined
        ? `${known.name} — ${ __( 'inactive', 'smaily-connect' ) }`
        : `#${value}`,
    });
  }

  return (
    <Select
      id={id}
      options={options}
      placeholder={status === 'pending' ? __( 'Loading workflows…', 'smaily-connect' ) : __( '— pick a workflow —', 'smaily-connect' )}
      value={value}
      invalid={invalid}
      onChange={(e) => onChange(e.target.value)}
      disabled={status !== 'success'}
    />
  );
}

/**
 * Test-mode block — the fail-closed heart of the section (§11, CON-455).
 * The "Test mode" switch shows what the engine stores (§12) and changes
 * it at once through §20: off only after a dialog that names the email
 * and says that the store's customers will receive it, back on with no
 * dialog. While test mode is on, the email goes to the test addresses
 * only, and "Send test email" sends one test through §19. Every call is
 * followed by a §12 re-read; nothing here is part of the save.
 */
function TestModeBlock({
  trigger,
  row,
  name,
  status,
  setup,
  onEngineChanged,
  patch,
  issues,
}: {
  trigger: AutomationCatalogTrigger;
  row: EngineAutomationRow;
  name: string;
  status: EngineTriggerStatus;
  setup: AutomationSetupItem | undefined;
  onEngineChanged: () => void;
  patch: (fields: Partial<EngineAutomationRow>) => void;
  issues: EngineAutomationsIssue[];
}): React.JSX.Element {
  const [emailsText, setEmailsText] = useState(() => row.test_emails.join(', '));
  const [confirming, setConfirming] = useState(false);
  const [switching, setSwitching] = useState(false);
  const [switchFailed, setSwitchFailed] = useState(false);
  const testMode = status !== 'live';

  const turnTestModeOn = async (): Promise<void> => {
    setSwitching(true);
    setSwitchFailed(false);
    const result = await switchAutomationTestMode(trigger.key, true);
    setSwitching(false);
    setSwitchFailed(!result.ok);
    onEngineChanged();
  };

  return (
    <div
      className={
        testMode
          ? 'space-y-3 rounded border border-warning-soft-bg bg-warning-soft-bg p-4'
          : 'space-y-3 rounded border border-success-soft-bg bg-success-soft-bg p-4'
      }
    >
      <Toggle
        name={`smly-engine-automation-${trigger.key}-test-mode`}
        checked={testMode}
        disabled={switching}
        onChange={(e) => {
          if (e.target.checked) {
            void turnTestModeOn();
          } else {
            setSwitchFailed(false);
            setConfirming(true);
          }
        }}
        label={ __( 'Test mode', 'smaily-connect' ) }
      />
      {switchFailed && (
        <p className="text-sm text-danger-soft-text">{failureText('')}</p>
      )}
      <EngineStateLine status={status} setup={setup} />

      {testMode && (
        <>
          <div>
            <Label htmlFor={`smly-engine-automation-${trigger.key}-test-emails`}>
              { __( 'Test addresses', 'smaily-connect' ) }
            </Label>
            <div className="mt-1">
              <Input
                id={`smly-engine-automation-${trigger.key}-test-emails`}
                value={emailsText}
                invalid={hasIssuePrefix(issues, 'test_emails')}
                placeholder="owner@shop.example, marketing@shop.example"
                onChange={(e) => {
                  setEmailsText(e.target.value);
                  patch({ test_emails: parseEmails(e.target.value) });
                }}
              />
            </div>
            <p className="mt-1 text-xs text-text-tertiary">
              { __( 'Comma-separated, up to 50 addresses.', 'smaily-connect' ) }
            </p>
            {/* T2.4/3: the engine's test-mode fire path sends ONLY to the listed
                addresses — enabled + test mode + empty list means nobody ever
                gets an email, silently. A warning (saving stays allowed), not
                an error. */}
            {row.enabled && row.test_emails.length === 0 && (
              <p className="mt-1 text-xs font-medium text-warning-soft-text">
                { __(
                  'This automation is enabled in test mode without any test addresses — no emails will be sent to anyone until you add at least one address.',
                  'smaily-connect',
                ) }
              </p>
            )}
            <FieldIssuesPrefix issues={issues} prefix="test_emails" />
          </div>
          <TestSendForm triggerKey={trigger.key} onSent={onEngineChanged} />
        </>
      )}

      {confirming && (
        <TestModeOffDialog
          triggerKey={trigger.key}
          name={name}
          onClose={() => setConfirming(false)}
          onEngineChanged={onEngineChanged}
        />
      )}
    </div>
  );
}

/**
 * The email's state line under the switch (CON-455), from §12: who the
 * email goes to now and today's limit, a stop Smaily reviews, and the
 * engine's `notice` (only the codes the contract names; an unknown one
 * shows nothing).
 */
function EngineStateLine({
  status,
  setup,
}: {
  status: EngineTriggerStatus;
  setup: AutomationSetupItem | undefined;
}): React.JSX.Element {
  const dailyLimit = setup?.daily_limit ?? null;
  return (
    <div className="space-y-1 text-sm">
      <p className="font-medium text-text-primary">
        {status === 'live'
          ? dailyLimit !== null
            ? sprintf(
                // translators: %d is the number of emails, e.g. 25.
                __( 'Sending to real customers · up to %d emails today', 'smaily-connect' ),
                dailyLimit,
              )
            : __( 'Sending to real customers', 'smaily-connect' )
          : __( 'The email goes to test addresses only.', 'smaily-connect' )}
      </p>
      {(status === 'stopped' || setup?.go_live_blockers.includes('stopped_needs_review') === true) && (
        <p className="text-text-secondary">{reasonText('stopped_needs_review')}</p>
      )}
      {setup?.notice === 'starts_when_sender_verified' && (
        <p className="text-text-secondary">
          { __( 'Starts when your sender domain is verified in Smaily.', 'smaily-connect' ) }
        </p>
      )}
    </div>
  );
}

/**
 * "Send test email" (§19): one email to one address, the admin's own by
 * default. Never retried — the admin presses again (a retry can send a
 * second email).
 */
function TestSendForm({
  triggerKey,
  onSent,
}: {
  triggerKey: string;
  onSent: () => void;
}): React.JSX.Element {
  const [email, setEmail] = useState(() => readBoot()?.adminEmail ?? '');
  const [pending, setPending] = useState(false);
  const [outcome, setOutcome] = useState<{ sentTo: string } | AutomationActionResult | null>(null);
  const fieldId = `smly-engine-automation-${triggerKey}-test-send`;

  const send = async (): Promise<void> => {
    const to = email.trim();
    setPending(true);
    setOutcome(null);
    const result = await sendAutomationTest(triggerKey, to);
    setPending(false);
    setOutcome(result.ok ? { sentTo: to } : result);
    onSent();
  };

  return (
    <div>
      <Label htmlFor={fieldId}>{ __( 'Send a test email to', 'smaily-connect' ) }</Label>
      <div className="mt-1 flex flex-wrap items-center gap-2">
        <div className="w-72 max-w-full">
          <Input
            id={fieldId}
            type="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
          />
        </div>
        <Button
          variant="secondary"
          size="sm"
          type="button"
          loading={pending}
          disabled={pending || email.trim() === ''}
          onClick={() => void send()}
        >
          { __( 'Send test email', 'smaily-connect' ) }
        </Button>
      </div>
      <p className="mt-1 text-xs text-text-tertiary">
        { __( 'This address is added to the Smaily list as a test contact.', 'smaily-connect' ) }
      </p>
      <p className="mt-1 text-xs text-text-tertiary">
        { __( 'The test shows the design your Smaily workflow holds.', 'smaily-connect' ) }
      </p>
      {outcome !== null && 'sentTo' in outcome && (
        <p role="status" className="mt-1 text-sm text-success-soft-text">
          {sprintf(
            // translators: %s is the email address the test was sent to.
            __( 'Test email sent to %s. Check the inbox.', 'smaily-connect' ),
            outcome.sentTo,
          )}
        </p>
      )}
      {outcome !== null && !('sentTo' in outcome) && !outcome.ok && (
        <ActionFailure result={outcome} />
      )}
    </div>
  );
}

/**
 * "Turn off test mode?" — the confirmation §20 asks for: it names the
 * email and says that the store's customers will receive it. Confirming
 * sends `live: true, confirmed: true`; a blocked switch lists what blocks
 * it and keeps test mode on.
 */
function TestModeOffDialog({
  triggerKey,
  name,
  onClose,
  onEngineChanged,
}: {
  triggerKey: string;
  name: string;
  onClose: () => void;
  onEngineChanged: () => void;
}): React.JSX.Element {
  const titleId = useId();
  const cancelRef = useRef<HTMLButtonElement>(null);
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<AutomationActionResult | null>(null);

  useEffect(() => {
    cancelRef.current?.focus();
    const onKeyDown = (event: KeyboardEvent): void => {
      if (event.key === 'Escape') {
        event.preventDefault();
        onClose();
      }
    };
    document.addEventListener('keydown', onKeyDown);
    return () => document.removeEventListener('keydown', onKeyDown);
  }, [onClose]);

  const confirm = async (): Promise<void> => {
    setPending(true);
    setFailure(null);
    const result = await switchAutomationTestMode(triggerKey, false);
    setPending(false);
    onEngineChanged();
    if (result.ok) {
      onClose();
    } else {
      setFailure(result);
    }
  };

  return createPortal(
    <div className="fixed inset-0 z-[100000] flex items-center justify-center p-4 font-sans">
      <button
        type="button"
        tabIndex={-1}
        aria-hidden="true"
        className="absolute inset-0 h-full w-full cursor-default bg-text-primary/60"
        onClick={onClose}
      />
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        className="relative z-10 w-full max-w-md space-y-4 rounded-lg bg-surface p-6 shadow-xl"
      >
        <h2 id={titleId} className="text-lg font-semibold text-text-primary">
          { __( 'Turn off test mode?', 'smaily-connect' ) }
        </h2>
        <p className="text-sm text-text-secondary">
          {sprintf(
            // translators: %s is the email's name, e.g. "Replenishment due".
            __( '“%s” will start going to your store\'s real customers, at first up to 10 emails a day, then 25 and 50.', 'smaily-connect' ),
            name,
          )}
        </p>
        {failure !== null && !failure.ok && <ActionFailure result={failure} />}
        <div className="flex justify-end gap-2">
          <Button ref={cancelRef} variant="ghost" size="sm" type="button" onClick={onClose}>
            { __( 'Cancel', 'smaily-connect' ) }
          </Button>
          <Button variant="primary" size="sm" type="button" loading={pending} disabled={pending} onClick={() => void confirm()}>
            { __( 'Turn off test mode', 'smaily-connect' ) }
          </Button>
        </div>
      </div>
    </div>,
    document.body,
  );
}

/** A refused test send or switch, in the plugin's own words (CON-301: never the engine's text). */
function ActionFailure({ result }: { result: Extract<AutomationActionResult, { ok: false }> }): React.JSX.Element {
  const listed = result.error === 'not_ready' || result.error === 'go_live_blocked';
  return (
    <div role="alert" className="mt-1 text-sm text-danger-soft-text">
      <p>{failureText(result.error)}</p>
      {listed && (
        <ul className="mt-1 list-disc pl-5">
          {(result.codes.length > 0 ? result.codes : ['']).map((code) => (
            <li key={code}>{reasonText(code)}</li>
          ))}
        </ul>
      )}
    </div>
  );
}

/** The headline of a refused §19 or §20 call, by the route's error code. */
function failureText(error: string): string {
  switch (error) {
    case 'not_ready':
      return __( 'The test email cannot be sent yet:', 'smaily-connect' );
    case 'go_live_blocked':
      return __( 'Test mode stays on until this is fixed:', 'smaily-connect' );
    case 'sender_refused':
      return __( 'Smaily refused to send from your sender address: its domain is not verified in Smaily yet, or the address is not allowed. Real sends are not affected.', 'smaily-connect' );
    case 'rate_limited':
      return __( 'Too many requests. Up to 10 test emails can be sent an hour. Try again later.', 'smaily-connect' );
    case 'smaily_unavailable':
      return __( 'Smaily did not answer, so the test email was not sent. Press the button again to try once more.', 'smaily-connect' );
    case 'invalid_email':
    case 'validation_failed':
      return __( 'Enter a valid email address.', 'smaily-connect' );
    default:
      return __( 'Campaign Intelligence did not take the request. Try again in a moment.', 'smaily-connect' );
  }
}

/** One engine reason (§19 `not_ready`) or blocker (§12/§20), in plain words. */
function reasonText(code: string): string {
  switch (code) {
    case 'no_workflow':
      return __( 'No Smaily workflow is linked to this email.', 'smaily-connect' );
    case 'workflow_not_active':
      return __( 'The email\'s Smaily workflow is not active yet, for example because its sender domain is not verified.', 'smaily-connect' );
    case 'no_design':
      return __( 'The email has no design yet.', 'smaily-connect' );
    case 'design_check_failed':
      return __( 'The design check failed — open the design to fix it.', 'smaily-connect' );
    case 'missing_subject':
      return __( 'The email has no subject.', 'smaily-connect' );
    case 'no_sender':
      return __( 'The email has no sender address.', 'smaily-connect' );
    case 'no_products':
      return __( 'No customer has recommendations for this email yet.', 'smaily-connect' );
    case 'stopped_needs_review':
      return __( 'Smaily reviews this stop before the email can send again.', 'smaily-connect' );
    case 'operator_switch_required':
      return __( 'A Smaily operator switches real sends on for this email.', 'smaily-connect' );
    default:
      return __( 'Setup is not complete.', 'smaily-connect' );
  }
}

// ---------------------------------------------------------------------------
// Small helpers
// ---------------------------------------------------------------------------

function parseEmails(text: string): string[] {
  return text
    .split(/[\s,;]+/)
    .map((s) => s.trim())
    .filter((s) => s !== '');
}

function hasIssue(issues: EngineAutomationsIssue[], field: string): boolean {
  return issues.some((i) => i.field === field);
}

function hasIssuePrefix(issues: EngineAutomationsIssue[], prefix: string): boolean {
  return issues.some((i) => i.field === prefix || i.field.startsWith(`${prefix}.`));
}

function FieldIssues({
  issues,
  field,
}: {
  issues: EngineAutomationsIssue[];
  field: string;
}): React.JSX.Element | null {
  const matching = issues.filter((i) => i.field === field);
  if (matching.length === 0) {
    return null;
  }
  return (
    <div className="mt-1 space-y-0.5">
      {matching.map((issue, idx) => (
        <p key={`${issue.field}-${idx}`} className="text-xs text-danger-soft-text">
          {issue.message}
        </p>
      ))}
    </div>
  );
}

function FieldIssuesPrefix({
  issues,
  prefix,
}: {
  issues: EngineAutomationsIssue[];
  prefix: string;
}): React.JSX.Element | null {
  const matching = issues.filter(
    (i) => i.field === prefix || i.field.startsWith(`${prefix}.`),
  );
  if (matching.length === 0) {
    return null;
  }
  return (
    <div className="mt-1 space-y-0.5">
      {matching.map((issue, idx) => (
        <p key={`${issue.field}-${idx}`} className="text-xs text-danger-soft-text">
          {issue.message}
        </p>
      ))}
    </div>
  );
}

/**
 * Locale pick for the bilingual catalog copy: Estonian admin locales
 * (et, et-EE, …) read the _et fields, everything else the _en ones.
 * wp-admin stamps the locale on <html lang="…"> — no boot-payload
 * addition needed.
 */
export function isEstonianAdminLocale(): boolean {
  if (typeof document === 'undefined') {
    return false;
  }
  return (document.documentElement.lang || '').toLowerCase().startsWith('et');
}

function humaniseLocale(locale: string): string {
  return locale.replace(/_/g, '-');
}

/**
 * The docs link href comes from the engine's §11 response. The engine is
 * an authenticated TLS peer, but an anchor href is a scheme-sensitive
 * sink (`javascript:` would execute in wp-admin) — so only render the
 * link for plain http(s) URLs. Defense-in-depth, not a distrust of the
 * engine (2026-07-07 T2 security pass, LOW-1).
 */
function isHttpUrl(url: string): boolean {
  return /^https?:\/\//i.test(url);
}
