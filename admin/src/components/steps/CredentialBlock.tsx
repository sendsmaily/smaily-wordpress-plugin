import { useEffect, useState } from 'react';

import { __, sprintf } from '@admin/lib/i18n';
import { useTestConnection } from '../../hooks/useTestConnection';
import { type AsyncStatus, type SmailyCredentials } from '../../state/types';
import { Banner, Button, Card, Input, Label } from '../primitives';

export interface CredentialBlockProps {
  title: string;
  description?: string;
  credentials: SmailyCredentials;
  connection: AsyncStatus;
  onCredentialsChange: (credentials: Partial<SmailyCredentials>) => void;
  /** Called on each phase of the local Test Connection cycle. */
  onConnectionStart: () => void;
  onConnectionSuccess: (accountName?: string) => void;
  onConnectionFailure: (error: string) => void;
  /** Suffix added to input ids so multiple blocks on the page don't collide. */
  idSuffix: string;
  /**
   * See WizardState.smailyHasStoredPassword (PRO-2286). Only the default
   * account passes it — per-language and transactional blocks keep the
   * "type the password" rule.
   */
  hasStoredPassword?: boolean;
}

/**
 * Reusable Smaily credential card. Step 1 instantiates it once for the
 * default account and N more times in Mode A (one per detected
 * language). Each block owns a useTestConnection hook so the cards are
 * independently exercisable without sharing a pending state.
 *
 * The hook's async lifecycle mirrors the parent reducer's slot via the
 * onConnection* callbacks — Step 1 keeps the canonical AsyncStatus in
 * state.smailyConnection or state.perLanguageAccounts[].connection so
 * Step 6's Done summary + the wizard footer's canAdvance gating both
 * see the same source of truth.
 */
export function CredentialBlock({
  title,
  description,
  credentials,
  connection,
  onCredentialsChange,
  onConnectionStart,
  onConnectionSuccess,
  onConnectionFailure,
  idSuffix,
  hasStoredPassword = false,
}: CredentialBlockProps): React.JSX.Element {
  const { mutate, status, data, error, reset } = useTestConnection();

  // Sub-PR 2.H.15 "already connected" UX.
  //
  // When hydrate.ts marks a saved credential set as previously verified
  // (subdomain + username populated, smailyConnected flag set by the
  // server), we render the compact "✓ Connected" view instead of three
  // empty input fields. Without this the merchant has to retype their
  // password on every wizard pass even though the connection is still
  // working — they'd have to mint a new Smaily API user just to walk
  // through the wizard again.
  //
  // `isVerifiedAndPristine` collapses to false the moment the user
  // edits any field (handled below in handleField) or the parent
  // reducer clears `connection` (e.g. credential mutation flips it
  // back to idle).
  const isVerifiedAndPristine =
    connection.kind === 'success' &&
    credentials.subdomain !== '' &&
    credentials.username !== '' &&
    credentials.password === '';
  const [editMode, setEditMode] = useState<'view' | 'edit'>(
    isVerifiedAndPristine ? 'view' : 'edit',
  );

  // Mirror hook state into the parent reducer. Each lifecycle transition
  // fires exactly one dispatch per phase.
  useEffect(() => {
    if (status === 'pending' && connection.kind !== 'pending') {
      onConnectionStart();
    } else if (status === 'success' && connection.kind !== 'success') {
      onConnectionSuccess(data?.accountName);
    } else if (status === 'error' && connection.kind !== 'failure') {
      onConnectionFailure(error ?? __( 'Connection failed.', 'smaily-connect' ));
    }
  }, [status, data, error, connection.kind, onConnectionStart, onConnectionSuccess, onConnectionFailure]);

  // Reset the hook when the parent clears the connection slot — e.g. on
  // credential edit dispatching SET_SMAILY_CREDENTIALS, which the
  // reducer flips back to idle.
  useEffect(() => {
    if (connection.kind === 'idle' && (status === 'success' || status === 'error')) {
      reset();
    }
  }, [connection.kind, status, reset]);

  const handleField = (field: keyof SmailyCredentials) =>
    (event: React.ChangeEvent<HTMLInputElement>): void => {
      // Any keystroke flips back to edit-mode — the saved verified
      // state no longer reflects what's on screen.
      setEditMode('edit');
      onCredentialsChange({ [field]: event.target.value });
    };

  const handleEditClick = (): void => setEditMode('edit');

  // See WizardState.smailyHasStoredPassword (PRO-2286). Editing the
  // subdomain or username means a DIFFERENT account is being tested, so
  // the stored password no longer applies and today's "type it" rule
  // comes back — hence the comparison against the values hydrate put on
  // screen.
  const [hydratedAccount] = useState(() => ({
    subdomain: credentials.subdomain,
    username: credentials.username,
  }));
  const canUseStoredPassword =
    hasStoredPassword &&
    credentials.password === '' &&
    credentials.subdomain === hydratedAccount.subdomain &&
    credentials.username === hydratedAccount.username;

  const isComplete =
    credentials.subdomain !== '' &&
    credentials.username !== '' &&
    (credentials.password !== '' || canUseStoredPassword);

  if (editMode === 'view' && isVerifiedAndPristine) {
    return (
      <Card title={title} description={description}>
        <div className="flex items-center justify-between gap-4">
          <Banner tone="success" className="flex-1">
            <span className="font-medium">{ __( '✓ Connected', 'smaily-connect' ) }</span>{' '}
            { __( 'as', 'smaily-connect' ) }{' '}
            <span className="font-mono">{credentials.username}</span> @{' '}
            <span className="font-mono">{credentials.subdomain}.sendsmaily.net</span>
          </Banner>
          <Button variant="ghost" type="button" onClick={handleEditClick}>
            { __( 'Edit credentials', 'smaily-connect' ) }
          </Button>
        </div>
      </Card>
    );
  }

  return (
    <Card title={title} description={description}>
      <div className="grid gap-4 md:grid-cols-3">
        <div>
          <Label htmlFor={`smaily-subdomain-${idSuffix}`} required>
            { __( 'Subdomain', 'smaily-connect' ) }
          </Label>
          <Input
            id={`smaily-subdomain-${idSuffix}`}
            value={credentials.subdomain}
            onChange={handleField('subdomain')}
            placeholder={ __( 'mypetshop', 'smaily-connect' ) }
            autoComplete="off"
            className="mt-1"
          />
        </div>

        <div>
          <Label htmlFor={`smaily-username-${idSuffix}`} required>
            { __( 'API username', 'smaily-connect' ) }
          </Label>
          <Input
            id={`smaily-username-${idSuffix}`}
            value={credentials.username}
            onChange={handleField('username')}
            autoComplete="off"
            className="mt-1"
          />
        </div>

        <div>
          <Label htmlFor={`smaily-password-${idSuffix}`} required={!canUseStoredPassword}>
            { __( 'API password', 'smaily-connect' ) }
          </Label>
          <Input
            id={`smaily-password-${idSuffix}`}
            type="password"
            value={credentials.password}
            onChange={handleField('password')}
            autoComplete="new-password"
            className="mt-1"
          />
          {canUseStoredPassword && (
            <p className="mt-1 text-xs text-text-tertiary">
              { __( 'Leave empty to keep using the stored password.', 'smaily-connect' ) }
            </p>
          )}
        </div>
      </div>

      <div className="mt-5 flex items-center justify-between gap-4">
        <Button
          variant="secondary"
          onClick={() => void mutate(credentials)}
          disabled={!isComplete}
          loading={status === 'pending'}
          type="button"
        >
          { __( 'Test connection', 'smaily-connect' ) }
        </Button>

        {connection.kind === 'success' && (
          <Banner tone="success" className="flex-1">
            {connection.message
              ? sprintf(
                  // translators: %s is the connected Smaily account name.
                  __( 'Connected: %s', 'smaily-connect' ),
                  connection.message,
                )
              : __( 'Connected to Smaily.', 'smaily-connect' )}
          </Banner>
        )}
        {connection.kind === 'failure' && (
          <Banner tone="danger" className="flex-1">
            {connection.error}
          </Banner>
        )}
      </div>
    </Card>
  );
}
