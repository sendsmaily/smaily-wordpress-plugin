import { useState, type Dispatch } from 'react';

import {
  disconnectEngine,
  pingEngine,
  setupExchange,
  type SetupExchangeFailure,
} from '../../api/recEngine';
import { type WizardAction, type WizardState } from '../../state/types';
import { Banner, Button, Card, Input, Label, Toggle } from '../primitives';
import { BackfillPanel } from '../BackfillPanel';
import { __, sprintf } from '@admin/lib/i18n';

export interface Step4RecommendationsProps {
  state: WizardState;
  dispatch: Dispatch<WizardAction>;
  inSettings?: boolean;
}

/**
 * Step 4 — Recommendations engine.
 *
 * Two-state UI driven by state.recEngineConnection:
 *
 *   not-connected  → SetupCard: paste setup URL, Connect button.
 *                    Talks to /rec-engine/setup-exchange which does
 *                    the one-time token round-trip server-side and
 *                    stores the encrypted api_key in wp_options.
 *
 *   connected (4a) → TenantHeader (✓ Connected as <name>) + Test
 *                    connection + Disconnect, then the data-sync
 *                    feature toggles (orders / customers / products
 *                    / cart events / browse). Toggles only render
 *                    once connected — Step-4-inside progressive
 *                    disclosure mirrors the 2.I tab-lock pattern.
 *
 * Step 4 is optional. Continue can advance even when not connected
 * (Wizard.tsx canAdvance returns true for steps other than 1 + 6).
 */
export function Step4Recommendations({
  state,
  dispatch,
  inSettings = false,
}: Step4RecommendationsProps): React.JSX.Element {
  const isConnected = state.recEngineConnection.kind === 'success';

  return (
    <div className="space-y-6">
      {!inSettings && (
        <div>
          <p className="text-sm font-medium uppercase tracking-wide text-text-tertiary">
            {__('Step 4 of 6', 'smaily-connect')}
          </p>
          <h2 className="mt-1 text-2xl font-semibold text-text-primary">
            {__('Campaign Intelligence', 'smaily-connect')}
          </h2>
          <p className="mt-2 text-sm text-text-secondary">
            {__(
              'Campaign Intelligence uses your store’s product, customer and order data to create personalised product recommendations for Smaily campaigns and automations. This helps you send more relevant emails with less manual work.',
              'smaily-connect',
            )}
          </p>
          <p className="mt-2 text-sm text-text-secondary">
            {__(
              'Campaign Intelligence is an optional paid add-on (€250/month), added to your regular Smaily monthly payment. Contact Smaily to activate it, or set it up later.',
              'smaily-connect',
            )}
          </p>
        </div>
      )}

      {/* Wizard-only pointer back to Step 3 (T2.2): the engine-run
          automations section lives under the WooCommerce automations,
          which the merchant walked past BEFORE connecting the engine
          here — so a successful connection offers the way back. Uses
          the existing WIZARD_GO_TO_STEP navigation. */}
      {!inSettings && isConnected && (
        <Banner
          tone="success"
          title={__('Engine connected — now wire up the Campaign Intelligence automation workflows', 'smaily-connect')}
          actions={
            <Button
              variant="secondary"
              size="sm"
              type="button"
              onClick={() => dispatch({ type: 'WIZARD_GO_TO_STEP', payload: { step: 3 } })}
            >
              {__('Back to Step 3', 'smaily-connect')}
            </Button>
          }
        >
          {__(
            'Replenishment and win-back automations are configured in Step 3 (Automated letters), below the store-run triggers.',
            'smaily-connect',
          )}
        </Banner>
      )}

      {isConnected ? (
        <ConnectedView state={state} dispatch={dispatch} />
      ) : (
        <SetupCard dispatch={dispatch} />
      )}
    </div>
  );
}

function SetupCard({
  dispatch,
}: {
  dispatch: Dispatch<WizardAction>;
}): React.JSX.Element {
  const [setupUrl, setSetupUrl] = useState('');
  const [status, setStatus] = useState<'idle' | 'pending' | 'error'>('idle');
  const [error, setError] = useState<SetupExchangeFailure | null>(null);

  const handleConnect = async (): Promise<void> => {
    if (setupUrl.trim() === '') {
      return;
    }
    setStatus('pending');
    setError(null);
    dispatch({ type: 'TEST_REC_ENGINE_CONNECTION_START' });

    const response = await setupExchange({ setupUrl: setupUrl.trim() });

    if (response.connected) {
      dispatch({
        type: 'TEST_REC_ENGINE_CONNECTION_SUCCESS',
        payload: { message: response.tenantName },
      });
      setStatus('idle');
      // Wipe the local input so the (one-time-used) token doesn't
      // sit in the DOM after success.
      setSetupUrl('');
      return;
    }

    setStatus('error');
    setError(response);
    dispatch({
      type: 'TEST_REC_ENGINE_CONNECTION_FAILURE',
      payload: { error: response.message },
    });
  };

  return (
    <Card title={__('Connect Smaily Campaign Intelligence', 'smaily-connect')}>
      <p className="text-sm text-text-secondary">
        {__(
          'Copy the Campaign Intelligence connection URL in the following field and click on Connect to activate the connection.',
          'smaily-connect',
        )}
      </p>

      <div className="mt-4 space-y-2">
        <Label htmlFor="rec-engine-setup-url" required>
          {__('Setup URL', 'smaily-connect')}
        </Label>
        <Input
          id="rec-engine-setup-url"
          value={setupUrl}
          onChange={(e) => {
            setSetupUrl(e.target.value);
            if (status === 'error') {
              setStatus('idle');
              setError(null);
            }
          }}
          placeholder="https://intelligence.smaily.com/setup/..."
          autoComplete="off"
        />
      </div>

      <div className="mt-5 flex items-center gap-3">
        <Button
          variant="primary"
          type="button"
          onClick={() => void handleConnect()}
          loading={status === 'pending'}
          disabled={setupUrl.trim() === ''}
        >
          {__('Connect', 'smaily-connect')}
        </Button>
      </div>

      {error !== null && (
        <Banner tone="danger" className="mt-4" title={errorTitle(error)}>
          {error.message}
          {error.error === 'token_expired_or_used' && error.regenerateUrl !== undefined && error.regenerateUrl !== '' && (
            <>
              {' '}
              <a
                href={error.regenerateUrl}
                target="_blank"
                rel="noopener noreferrer"
                className="underline"
              >
                {__('Regenerate the link', 'smaily-connect')}
              </a>
              .
            </>
          )}
        </Banner>
      )}
    </Card>
  );
}

function ConnectedView({
  state,
  dispatch,
}: {
  state: WizardState;
  dispatch: Dispatch<WizardAction>;
}): React.JSX.Element {
  type FeatureKey = keyof WizardState['recEngineFeatures'];

  const tenantName =
    state.recEngineConnection.kind === 'success'
      ? state.recEngineConnection.message ?? __('Smaily Campaign Intelligence', 'smaily-connect')
      : __('Smaily Campaign Intelligence', 'smaily-connect');

  const [pingStatus, setPingStatus] = useState<'idle' | 'pending' | 'success' | 'error'>('idle');
  const [pingMessage, setPingMessage] = useState<string>('');

  const handlePing = async (): Promise<void> => {
    setPingStatus('pending');
    setPingMessage('');
    const result = await pingEngine();
    if (result.ok) {
      setPingStatus('success');
      setPingMessage(
        sprintf(
          /* translators: 1: engine version, 2: tenant status. */
          __('Engine v%1$s responded — tenant status: %2$s.', 'smaily-connect'),
          result.engineVersion,
          result.tenantStatus || __('active', 'smaily-connect'),
        ),
      );
    } else {
      setPingStatus('error');
      setPingMessage(result.message);
    }
  };

  const handleDisconnect = async (): Promise<void> => {
    const confirmed = window.confirm(
      __(
        'Disconnect Smaily Campaign Intelligence? The plugin will stop syncing data; existing campaigns on the engine side continue to work until you remove them there.',
        'smaily-connect',
      ),
    );
    if (!confirmed) {
      return;
    }
    await disconnectEngine();
    dispatch({
      type: 'TEST_REC_ENGINE_CONNECTION_FAILURE',
      payload: { error: __('Disconnected.', 'smaily-connect') },
    });
    // FAILURE reset is the cleanest existing action; the UI immediately
    // collapses to the SetupCard since recEngineConnection.kind !== 'success'.
    // (We deliberately don't add a dedicated DISCONNECT action — the
    // existing reducer surface handles the state transition.)
  };

  const toggle = (feature: FeatureKey) => (e: React.ChangeEvent<HTMLInputElement>): void => {
    dispatch({
      type: 'SET_REC_ENGINE_FEATURE',
      payload: { feature, enabled: e.target.checked },
    });
  };

  return (
    <>
      <Card title={__('Engine connection', 'smaily-connect')}>
        <div className="flex items-center justify-between gap-4">
          <Banner tone="success" className="flex-1">
            <span className="font-medium">✓ {__('Connected', 'smaily-connect')}</span>{' '}
            {__('as', 'smaily-connect')}{' '}
            <span className="font-mono">{tenantName}</span>
          </Banner>
          <div className="flex shrink-0 gap-2">
            <Button
              variant="secondary"
              type="button"
              onClick={() => void handlePing()}
              loading={pingStatus === 'pending'}
            >
              {__('Test connection', 'smaily-connect')}
            </Button>
            <Button variant="ghost" type="button" onClick={() => void handleDisconnect()}>
              {__('Disconnect', 'smaily-connect')}
            </Button>
          </div>
        </div>
        {pingStatus !== 'idle' && pingMessage !== '' && (
          <Banner
            tone={pingStatus === 'success' ? 'success' : 'danger'}
            className="mt-4"
          >
            {pingMessage}
          </Banner>
        )}
      </Card>

      <Card
        title={__('Data synchronisation', 'smaily-connect')}
        description={__(
          'While connected, the engine learns from your joined order, customer, and product data — all three sync automatically.',
          'smaily-connect',
        )}
      >
        <p className="text-sm text-text-secondary">
          {__(
            "Syncing starts as soon as you connect and runs in the background — there's nothing to switch on per data type. Use",
            'smaily-connect',
          )}{' '}
          <span className="font-medium">{__('Import existing data', 'smaily-connect')}</span>{' '}
          {__(
            "below to seed history into the engine; future changes sync on their own. Browsing telemetry is the one exception — it's opt-in and configured separately below.",
            'smaily-connect',
          )}
        </p>
      </Card>

      <Card
        title={__('Import existing data', 'smaily-connect')}
        description={__(
          'The toggles above sync future changes. Import your existing catalog, customers, and orders into the engine once so recommendations have history to learn from. Runs in the background in batches.',
          'smaily-connect',
        )}
      >
        <div className="space-y-3">
          <BackfillPanel
            jobType="products"
            label={__('Products', 'smaily-connect')}
            recordCount={state.env.storeTotals.products}
            countNote={
              state.env.detectedLanguages.length > 1
                ? sprintf(
                    /* translators: %d: number of detected languages. */
                    __(
                      'Counts one entry per language (%d detected). Translations are merged into a single product during import, so the synced total will be lower.',
                      'smaily-connect',
                    ),
                    state.env.detectedLanguages.length,
                  )
                : undefined
            }
          />
          <BackfillPanel
            jobType="customers"
            label={__('Customers', 'smaily-connect')}
            recordCount={state.env.storeTotals.customers}
          />
          <BackfillPanel
            jobType="orders"
            label={__('Orders', 'smaily-connect')}
            recordCount={state.env.storeTotals.orders}
          />
        </div>
      </Card>

      <Card
        title={__('Browsing telemetry', 'smaily-connect')}
        description={__(
          "Tracks product / category views to power 'similar products' recommendations.",
          'smaily-connect',
        )}
      >
        <Toggle
          name="rec-track-browsing"
          checked={state.recEngineFeatures.trackBrowsing}
          onChange={toggle('trackBrowsing')}
          label={__('Track browsing behaviour', 'smaily-connect')}
          description={__(
            'Requires marketing consent (WP Consent API / Cookiebot / Complianz / CookieYes).',
            'smaily-connect',
          )}
        />
        {state.recEngineFeatures.trackBrowsing && (
          <Banner tone="warning" className="mt-4">
            {__(
              "Browsing telemetry only fires when the visitor has granted marketing consent. If your site doesn't have a consent banner installed, the beacon won't collect events.",
              'smaily-connect',
            )}
          </Banner>
        )}
      </Card>
    </>
  );
}

function errorTitle(failure: SetupExchangeFailure): string {
  switch (failure.error) {
    case 'invalid_setup_url':
      return __('Setup URL not recognised', 'smaily-connect');
    case 'token_expired_or_used':
      return __('Setup link already used', 'smaily-connect');
    case 'token_not_found':
      return __('Setup link not found', 'smaily-connect');
    case 'engine_unreachable':
    default:
      return __('Engine unreachable', 'smaily-connect');
  }
}
