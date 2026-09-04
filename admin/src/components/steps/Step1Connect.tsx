import { useCallback, type Dispatch } from 'react';

import { __, sprintf } from '@admin/lib/i18n';
import {
  emptyCredentials,
  idleAsync,
  type SmailyCredentials,
  type WizardAction,
  type WizardState,
} from '../../state/types';
import { Button, Card, Radio } from '../primitives';
import { MultilingualModePicker } from '../wizard';
import { CredentialBlock } from './CredentialBlock';
import { TransactionalEmailsSection } from './TransactionalEmailsSection';

export interface Step1ConnectProps {
  state: WizardState;
  dispatch: Dispatch<WizardAction>;
  /**
   * True when rendered inside Settings tabs. Hides the wizard-only
   * eyebrow + intro paragraph; the field cards stay identical.
   */
  inSettings?: boolean;
}

/**
 * Step 1 — Connect Smaily account(s).
 *
 *   1. Default-account credential block (always rendered)
 *   2. MultilingualModePicker — auto-hides for single-language sites
 *   3. Mode A: per-language credential blocks + default-fallback radio
 *   4. Optional rec-engine setup-token paste (Phase 3 wires validate)
 *
 * Mode A blocks lazily create perLanguageAccounts entries on first
 * field edit. No useEffect auto-seeding — keeps state minimal and
 * avoids races with the SET_MULTILINGUAL_MODE dispatch.
 */
export function Step1Connect({
  state,
  dispatch,
  inSettings = false,
}: Step1ConnectProps): React.JSX.Element {
  // Default-account handlers
  const onDefaultCredentialsChange = useCallback(
    (payload: Partial<SmailyCredentials>) => {
      dispatch({ type: 'SET_SMAILY_CREDENTIALS', payload });
    },
    [dispatch],
  );

  const onDefaultConnStart = useCallback(
    () => dispatch({ type: 'TEST_SMAILY_CONNECTION_START' }),
    [dispatch],
  );
  const onDefaultConnSuccess = useCallback(
    (accountName?: string) =>
      dispatch({ type: 'TEST_SMAILY_CONNECTION_SUCCESS', payload: { accountName } }),
    [dispatch],
  );
  const onDefaultConnFailure = useCallback(
    (errorMessage: string) =>
      dispatch({ type: 'TEST_SMAILY_CONNECTION_FAILURE', payload: { error: errorMessage } }),
    [dispatch],
  );

  const isModeA = state.multilingualMode === 'A' && state.env.detectedLanguages.length > 1;
  const docsUrl = state.env.docsUrl ?? '';

  return (
    <div className="space-y-6">
      {!inSettings && (
        <div>
          <p className="text-sm font-medium uppercase tracking-wide text-text-tertiary">
            { __( 'Step 1 of 6', 'smaily-connect' ) }
          </p>
          <h2 className="mt-1 text-2xl font-semibold text-text-primary">
            { __( 'Connect your Smaily account', 'smaily-connect' ) }
          </h2>
          <p className="mt-2 text-sm text-text-secondary">
            { __(
              'To create a connection, go to your Smaily account’s settings. Create a new API user under the “Integrations” tab and copy subdomain, API username and API password in the plugin. Test the connection and click “Continue →”.',
              'smaily-connect',
            ) }
            {docsUrl !== '' && (
              <>
                {' '}
                <a
                  href={`${docsUrl}#step1`}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="underline"
                >
                  { __( 'User guide to create API credentials', 'smaily-connect' ) }
                </a>
              </>
            )}
          </p>
          {/* The link to the merchant's own Smaily account (PRO-1718) —
              here, at the step that sends them there for the API user,
              instead of on the closing overview step. It appears as soon
              as a subdomain is known, since that IS the account address. */}
          {smailySubdomain(state) !== '' && (
            <div className="mt-3">
              <Button
                variant="secondary"
                type="button"
                onClick={() => openSmailyDashboard(state)}
              >
                { __( 'Open Smaily dashboard →', 'smaily-connect' ) }
              </Button>
            </div>
          )}
        </div>
      )}

      {/* Layout per Erkki's 2.H.3 spec: multilingual-mode picker is
          always the first interactive section so the page header
          doesn't move when modes flip. The credential surface below
          adapts to the selected mode (shared default vs per-language)
          but its position on the page stays stable. */}
      <MultilingualModePicker
        value={state.multilingualMode}
        onChange={(mode) => dispatch({ type: 'SET_MULTILINGUAL_MODE', payload: mode })}
        detectedLanguages={state.env.detectedLanguages}
      />

      {/* Mode A has no shared "default" credential set — the per-language
          blocks below ARE the credential sets. Mode B/C/single all share
          one default Smaily account, so this block stays for them. */}
      {!isModeA && (
        <CredentialBlock
          title={ __( 'Smaily API credentials', 'smaily-connect' ) }
          credentials={state.smailyCredentials}
          connection={state.smailyConnection}
          onCredentialsChange={onDefaultCredentialsChange}
          onConnectionStart={onDefaultConnStart}
          onConnectionSuccess={onDefaultConnSuccess}
          onConnectionFailure={onDefaultConnFailure}
          idSuffix="default"
          hasStoredPassword={state.smailyHasStoredPassword}
        />
      )}

      {isModeA && (
        <>
          {state.env.detectedLanguages.map((language) => (
            <PerLanguageBlock
              key={language}
              state={state}
              dispatch={dispatch}
              language={language}
            />
          ))}

          <Card
            title={ __( 'Default fallback account', 'smaily-connect' ) }
            description={ __(
              "Used when a contact's language can't be detected (visitors signing up before WPML/Polylang picks a locale).",
              'smaily-connect',
            ) }
          >
            <FallbackPicker state={state} dispatch={dispatch} />
          </Card>
        </>
      )}

      {/* Transactional emails (PRO-1504) — an OPTIONAL capability on top of
          the main Smaily connection above, relocated here by PRO-1540
          after the pilot found it undiscoverable inside WooCommerce
          automations. Its own trigger config lives on the WooCommerce
          tab/step, gated on this connection (TransactionalTriggersSection). */}
      <TransactionalEmailsSection state={state} dispatch={dispatch} inSettings={inSettings} />
    </div>
  );
}

interface PerLanguageBlockProps {
  state: WizardState;
  dispatch: Dispatch<WizardAction>;
  language: string;
}

function PerLanguageBlock({
  state,
  dispatch,
  language,
}: PerLanguageBlockProps): React.JSX.Element {
  const accountKey = `account_${language}`;
  const account = state.perLanguageAccounts.find((a) => a.accountKey === accountKey) ?? null;

  const credentials = account?.credentials ?? emptyCredentials;
  const connection = account?.connection ?? idleAsync;

  return (
    <CredentialBlock
      title={sprintf(
        // translators: %s is a locale code, e.g. en-US.
        __( 'Account for %s', 'smaily-connect' ),
        humaniseLocale(language),
      )}
      description={sprintf(
        // translators: %s is a locale code, e.g. en-US.
        __( 'Contacts with the %s locale will sync to this Smaily account.', 'smaily-connect' ),
        humaniseLocale(language),
      )}
      credentials={credentials}
      connection={connection}
      onCredentialsChange={(payload) => {
        if (account === null) {
          dispatch({
            type: 'ADD_MODE_ACCOUNT',
            payload: {
              accountKey,
              language,
              credentials: { ...credentials, ...payload },
              connection: idleAsync,
            },
          });
        } else {
          dispatch({
            type: 'UPDATE_MODE_ACCOUNT_CREDENTIALS',
            payload: { accountKey, credentials: payload },
          });
        }
      }}
      onConnectionStart={() =>
        dispatch({ type: 'TEST_MODE_ACCOUNT_CONNECTION_START', payload: { accountKey } })
      }
      onConnectionSuccess={(accountName) =>
        dispatch({
          type: 'TEST_MODE_ACCOUNT_CONNECTION_SUCCESS',
          payload: { accountKey, accountName },
        })
      }
      onConnectionFailure={(errorMessage) =>
        dispatch({
          type: 'TEST_MODE_ACCOUNT_CONNECTION_FAILURE',
          payload: { accountKey, error: errorMessage },
        })
      }
      idSuffix={language}
    />
  );
}

interface FallbackPickerProps {
  state: WizardState;
  dispatch: Dispatch<WizardAction>;
}

function FallbackPicker({ state, dispatch }: FallbackPickerProps): React.JSX.Element {
  // Mode A only — the picker isn't rendered outside `isModeA`, so we
  // know every option here is a real per-language account. No
  // "Default account" radio (there is no shared default in Mode A).
  return (
    <div className="space-y-2">
      {state.env.detectedLanguages.map((language) => {
        const accountKey = `account_${language}`;
        return (
          <Radio
            key={accountKey}
            name="smaily-default-fallback"
            value={accountKey}
            checked={state.defaultFallbackAccountKey === accountKey}
            onChange={() =>
              dispatch({ type: 'SET_DEFAULT_FALLBACK_ACCOUNT_KEY', payload: accountKey })
            }
            label={humaniseLocale(language)}
          />
        );
      })}
    </div>
  );
}

function smailySubdomain(state: WizardState): string {
  const sub = state.smailyCredentials.subdomain.trim();
  if (sub !== '') {
    return sub;
  }
  // Mode A — fall back to the configured default-fallback account's subdomain.
  const fallback = state.perLanguageAccounts.find(
    (a) => a.accountKey === state.defaultFallbackAccountKey,
  );
  return fallback?.credentials.subdomain.trim() ?? '';
}

function openSmailyDashboard(state: WizardState): void {
  const sub = smailySubdomain(state);
  if (sub === '' || typeof window === 'undefined') {
    return;
  }
  window.open(`https://${sub}.sendsmaily.net`, '_blank', 'noopener,noreferrer');
}

function humaniseLocale(locale: string): string {
  // Strip variant subtag — turn 'et_EE' into 'et-EE'. WordPress ships a
  // get_locale -> display-name table for the back-end, but the React
  // bundle doesn't have access to it. Phase 4 polish can wire
  // wp.i18n.localize-script for full names; pilot-time the locale code
  // is readable enough.
  return locale.replace(/_/g, '-');
}
