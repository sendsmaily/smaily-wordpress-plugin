import { type Dispatch } from 'react';

import { __, sprintf } from '@admin/lib/i18n';
import { useWorkflows } from '../../hooks/useWorkflows';
import {
  type AutomationMapping,
  type AutomationTrigger,
  type WizardAction,
  type WizardState,
} from '../../state/types';
import { Banner, Card, Pill, Radio, Select, Toggle } from '../primitives';

export interface AutomationSectionProps {
  state: WizardState;
  dispatch: Dispatch<WizardAction>;
  trigger: AutomationTrigger;
  title: string;
  description: string;
  /** When the toggle is off, mapping rows are dimmed but stay visible. */
  isEnabled: boolean;
  onEnabledChange: (enabled: boolean) => void;
  /** Extra row (e.g. abandoned-cart cutoff slider) rendered above the table. */
  extras?: React.ReactNode;
  /**
   * Fixes the mapping row(s) to a specific Smaily account instead of the
   * multilingual-mode-derived one — used by triggers bound to a separate
   * Smaily account (transactional-email triggers, PRO-1504). When set,
   * the section always renders a single row (language='default')
   * regardless of the site's multilingual mode; the override account has
   * no per-language variants in stage 1.
   */
  accountKeyOverride?: string;
}

/**
 * One automation trigger section — welcome / first_order / abandoned_cart.
 *
 * Renders three UI shapes depending on multilingual mode:
 *
 *   - Mode B: per-language rows in a single table, all using the
 *     default account_key. One radio column marks the default-fallback
 *     row.
 *   - Mode A: same per-language rows but each pinned to its language's
 *     account_key (the workflow dropdown fetches from that account's
 *     /workflows endpoint).
 *   - Mode C and single: one dropdown, no language column — the row
 *     uses language='default'.
 *
 * The workflow list comes from useWorkflows(accountKey) — Step 1's
 * `Test connection` having succeeded is a soft prerequisite. If the
 * fetch fails the dropdown is replaced with an error banner so the
 * user knows credentials are missing rather than seeing an empty list.
 */
export function AutomationSection({
  state,
  dispatch,
  trigger,
  title,
  description,
  isEnabled,
  onEnabledChange,
  extras,
  accountKeyOverride,
}: AutomationSectionProps): React.JSX.Element {
  const isModeA = state.multilingualMode === 'A' && state.env.detectedLanguages.length > 1;
  const isModeB = state.multilingualMode === 'B' && state.env.detectedLanguages.length > 1;
  const isMultiRow = accountKeyOverride === undefined && (isModeA || isModeB);

  const rows = computeRows(state, trigger);

  return (
    <Card
      title={title}
      description={description}
      headerAccessory={isEnabled ? <Pill tone="success">{ __( 'Active', 'smaily-connect' ) }</Pill> : <Pill tone="neutral">{ __( 'Off', 'smaily-connect' ) }</Pill>}
    >
      <Toggle
        name={`smly-automation-${trigger}-enabled`}
        checked={isEnabled}
        onChange={(e) => onEnabledChange(e.target.checked)}
        label={sprintf(
          // translators: %s is the automation name, e.g. "welcome email".
          __( 'Enable %s', 'smaily-connect' ),
          title.toLowerCase(),
        )}
      />

      {extras}

      <div className={isEnabled ? 'mt-5' : 'mt-5 opacity-50 pointer-events-none'}>
        {isMultiRow ? (
          <MultiLanguageRows
            state={state}
            dispatch={dispatch}
            trigger={trigger}
            rows={rows}
            isModeA={isModeA}
          />
        ) : (
          <SingleRow
            state={state}
            dispatch={dispatch}
            trigger={trigger}
            rows={rows}
            accountKey={accountKeyOverride}
          />
        )}
      </div>
    </Card>
  );
}

interface RowsProps {
  state: WizardState;
  dispatch: Dispatch<WizardAction>;
  trigger: AutomationTrigger;
  rows: AutomationMapping[];
}

interface MultiLanguageRowsProps extends RowsProps {
  isModeA: boolean;
}

function MultiLanguageRows({
  state,
  dispatch,
  trigger,
  rows,
  isModeA,
}: MultiLanguageRowsProps): React.JSX.Element {
  return (
    <table className="w-full text-sm">
      <thead className="text-left text-text-tertiary">
        <tr>
          <th className="w-1/4 pb-2 font-medium">{ __( 'Language', 'smaily-connect' ) }</th>
          <th className="pb-2 font-medium">{ __( 'Workflow', 'smaily-connect' ) }</th>
          <th className="w-32 pb-2 text-center font-medium">{ __( 'Default fallback', 'smaily-connect' ) }</th>
        </tr>
      </thead>
      <tbody className="divide-y divide-border-subtle">
        {state.env.detectedLanguages.map((language) => {
          const accountKey = isModeA ? `account_${language}` : 'default';
          const mapping = rows.find(
            (r) => r.language === language && r.accountKey === accountKey,
          );
          return (
            <AutomationRow
              key={language}
              state={state}
              dispatch={dispatch}
              trigger={trigger}
              language={language}
              accountKey={accountKey}
              mapping={mapping}
            />
          );
        })}
      </tbody>
    </table>
  );
}

interface SingleRowProps extends RowsProps {
  /** Defaults to 'default' — see AutomationSectionProps.accountKeyOverride. */
  accountKey?: string;
}

function SingleRow({
  state,
  dispatch,
  trigger,
  rows,
  accountKey = 'default',
}: SingleRowProps): React.JSX.Element {
  const mapping = rows.find((r) => r.language === 'default' && r.accountKey === accountKey);
  return (
    <AutomationRow
      state={state}
      dispatch={dispatch}
      trigger={trigger}
      language="default"
      accountKey={accountKey}
      mapping={mapping}
      singleRowMode
    />
  );
}

interface AutomationRowProps {
  state: WizardState;
  dispatch: Dispatch<WizardAction>;
  trigger: AutomationTrigger;
  language: string;
  accountKey: string;
  mapping: AutomationMapping | undefined;
  /** Hides the language label + fallback radio columns when there's only one row. */
  singleRowMode?: boolean;
}

function AutomationRow({
  state,
  dispatch,
  trigger,
  language,
  accountKey,
  mapping,
  singleRowMode = false,
}: AutomationRowProps): React.JSX.Element {
  const { workflows, status, error } = useWorkflows(accountKey);

  const options =
    status === 'success'
      ? workflows.map((w) => ({
          value: w.id,
          label: w.name,
          hint: w.status === 'INACTIVE' ? __( 'inactive', 'smaily-connect' ) : undefined,
        }))
      : [];

  const value = mapping?.workflowId ?? '';

  const handleChange = (event: React.ChangeEvent<HTMLSelectElement>): void => {
    const workflowId = event.target.value;
    if (workflowId === '') {
      dispatch({
        type: 'REMOVE_AUTOMATION_MAPPING',
        payload: { triggerType: trigger, language, accountKey },
      });
      return;
    }
    dispatch({
      type: 'UPSERT_AUTOMATION_MAPPING',
      payload: {
        triggerType: trigger,
        language,
        accountKey,
        workflowId,
        isDefaultFallback: mapping?.isDefaultFallback ?? !state.automationMappings.some(
          (m) => m.triggerType === trigger && m.isDefaultFallback,
        ),
      },
    });
  };

  const handleFallback = (): void => {
    dispatch({
      type: 'SET_AUTOMATION_FALLBACK',
      payload: { triggerType: trigger, language, accountKey },
    });
  };

  if (singleRowMode) {
    return (
      <tr>
        <td colSpan={3} className="py-3">
          {status === 'error' && error !== null ? (
            <Banner tone="warning">{sprintf(
              // translators: %s is the error message.
              __( "Couldn't load workflows: %s", 'smaily-connect' ),
              error,
            )}</Banner>
          ) : (
            <Select
              options={options}
              placeholder={status === 'pending' ? __( 'Loading workflows…', 'smaily-connect' ) : __( '— pick a workflow —', 'smaily-connect' )}
              value={value}
              onChange={handleChange}
              disabled={status !== 'success'}
            />
          )}
        </td>
      </tr>
    );
  }

  return (
    <tr>
      <td className="py-3 align-middle text-text-secondary">{humaniseLocale(language)}</td>
      <td className="py-3 align-middle">
        {status === 'error' && error !== null ? (
          <Banner tone="warning">{sprintf(
            // translators: %s is the error message.
            __( "Couldn't load: %s", 'smaily-connect' ),
            error,
          )}</Banner>
        ) : (
          <Select
            options={options}
            placeholder={status === 'pending' ? __( 'Loading workflows…', 'smaily-connect' ) : __( '— pick a workflow —', 'smaily-connect' )}
            value={value}
            onChange={handleChange}
            disabled={status !== 'success'}
          />
        )}
      </td>
      <td className="py-3 text-center align-middle">
        <Radio
          name={`smly-fallback-${trigger}`}
          checked={mapping?.isDefaultFallback ?? false}
          onChange={handleFallback}
          aria-label={sprintf(
            // translators: %s is a locale code, e.g. en-US.
            __( 'Mark %s as default fallback', 'smaily-connect' ),
            humaniseLocale(language),
          )}
        />
      </td>
    </tr>
  );
}

function computeRows(state: WizardState, trigger: AutomationTrigger): AutomationMapping[] {
  return state.automationMappings.filter((m) => m.triggerType === trigger);
}

function humaniseLocale(locale: string): string {
  return locale.replace(/_/g, '-');
}
