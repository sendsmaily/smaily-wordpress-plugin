import { useEffect, useState, type Dispatch } from 'react';

import { __, sprintf } from '@admin/lib/i18n';
import {
  type AutomationCatalogTrigger,
  type AutomationsFailure,
} from '../../api/automations';
import { useAutomationsData } from '../../hooks/useAutomationsData';
import { useWorkflows } from '../../hooks/useWorkflows';
import {
  buildRows,
  COOLDOWN_MAX,
  COOLDOWN_MIN,
  deriveLanguageMode,
  fallbackLanguage,
  issuesByTrigger,
  pickRecipe,
  setFallbackLanguage,
  updateLanguageWorkflow,
  validateRows,
} from '../../state/engine-automations';
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
 *  - Fail-closed: enabled comes only from the merchant's toggle;
 *    test_mode defaults ON, and switching it off ("activate for real")
 *    is a separate, confirmed action — never the enable checkbox.
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
  const { data, status, failure, refetch } = useAutomationsData(true);
  const engine = state.engineAutomations;

  const derivedMode = deriveLanguageMode(state.multilingualMode, state.env.detectedLanguages);

  // Hydrate the reducer slice from every fresh fetch. A dirty draft is
  // preserved (its rows win per trigger); a clean slice takes the
  // server rows verbatim — the engine's GET is the truth (F3-51).
  useEffect(() => {
    if (data === null) {
      return;
    }
    const rows = buildRows(
      data.catalog.triggers,
      data.configs,
      engine.dirty ? engine.rows : null,
      derivedMode,
    );
    dispatch({ type: 'ENGINE_AUTOMATIONS_HYDRATED', payload: { rows, keepDirty: engine.dirty } });
    // engine.dirty/rows are read on purpose only when data changes — a
    // fetch result hydrates once; edits must not re-trigger it.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [data, dispatch, derivedMode]);

  if (status === 'pending' || status === 'idle') {
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
  state: WizardState;
  dispatch: Dispatch<WizardAction>;
  issues: EngineAutomationsIssue[];
}

function TriggerCard({
  trigger,
  row,
  docsUrl,
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

  const statusPill = row.enabled ? (
    row.test_mode ? (
      <Pill tone="warning">{ __( 'Test mode', 'smaily-connect' ) }</Pill>
    ) : (
      <Pill tone="success">{ __( 'Active', 'smaily-connect' ) }</Pill>
    )
  ) : (
    <Pill tone="neutral">{ __( 'Off', 'smaily-connect' ) }</Pill>
  );

  return (
    <Card title={name} description={description} headerAccessory={statusPill}>
      {/* Recipe — what the Smaily-side automation must contain. Locale pick
          matches name/description: non-et locales read recipe_en when the
          engine provides it (forward-compatible, T2.4/5), else recipe_et. */}
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

      <div className="mt-4">
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
      </div>

      <div className={row.enabled ? 'mt-5 space-y-5' : 'mt-5 space-y-5 opacity-50'}>
        <WorkflowMapping
          trigger={trigger}
          row={row}
          state={state}
          onMapChange={(map) => patch({ automation_map: map })}
          issues={issues}
        />

        <CooldownField trigger={trigger} row={row} patch={patch} issues={issues} />

        <TestModeBlock trigger={trigger} row={row} name={name} patch={patch} issues={issues} />
      </div>
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
 * Test-mode block — the fail-closed heart of the section (§11):
 * test_mode starts ON, fires reach only the listed addresses, and
 * going live is a SEPARATE confirmed action, never the enable toggle.
 */
function TestModeBlock({
  trigger,
  row,
  name,
  patch,
  issues,
}: {
  trigger: AutomationCatalogTrigger;
  row: EngineAutomationRow;
  name: string;
  patch: (fields: Partial<EngineAutomationRow>) => void;
  issues: EngineAutomationsIssue[];
}): React.JSX.Element {
  const [emailsText, setEmailsText] = useState(() => row.test_emails.join(', '));

  const handleActivate = (): void => {
    const confirmed = window.confirm(
      sprintf(
        // translators: %s is the automation name, e.g. "Replenishment due".
        __(
          'Turn off test mode for "%s"? Once you save, the engine will start sending this automation to real customers. You can switch back to test mode at any time.',
          'smaily-connect',
        ),
        name,
      ),
    );
    if (!confirmed) {
      return;
    }
    patch({ test_mode: false });
  };

  if (!row.test_mode) {
    return (
      <Banner
        tone="success"
        title={ __( 'Live — real customers receive this automation once saved.', 'smaily-connect' ) }
        actions={
          <Button variant="ghost" size="sm" type="button" onClick={() => patch({ test_mode: true })}>
            { __( 'Back to test mode', 'smaily-connect' ) }
          </Button>
        }
      />
    );
  }

  return (
    <div className="rounded border border-warning-soft-bg bg-warning-soft-bg p-4">
      <p className="text-sm font-medium text-text-primary">
        { __( 'Test mode is on — emails go only to the test addresses below, never to customers.', 'smaily-connect' ) }
      </p>
      <div className="mt-3">
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
      <div className="mt-3">
        <Button variant="secondary" size="sm" type="button" onClick={handleActivate}>
          { __( 'Activate for real…', 'smaily-connect' ) }
        </Button>
      </div>
    </div>
  );
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
function isEstonianAdminLocale(): boolean {
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
