import { type Dispatch } from 'react';

import { __ } from '@admin/lib/i18n';
import { type WizardAction, type WizardState } from '../../state/types';
import { Label, NumberInput } from '../primitives';
import { AutomationSection } from './AutomationSection';
import { EngineAutomationsSection } from './EngineAutomationsSection';
import { TransactionalTriggersSection } from './TransactionalTriggersSection';

export interface Step3WooCommerceProps {
  state: WizardState;
  dispatch: Dispatch<WizardAction>;
  inSettings?: boolean;
}

/**
 * Step 3 — WooCommerce automations.
 *
 * Three independently-togglable sections:
 *   3a Welcome email — fires on the woocommerce_created_customer hook (PRO-1682)
 *   3b First-order email — fires on the customer's first paid order
 *   3c Abandoned-cart reminder — cron-driven (PLUGIN.md §5)
 *
 * Mode-aware workflow mappings live inside AutomationSection (one per
 * trigger). The shared reducer carries the rows in state.automationMappings
 * so Step 6's Done summary + the PHP-side persistence later read from
 * one place.
 *
 * The abandoned-cart section gets an extra Cutoff input (10-min minimum
 * per PLUGIN.md §5.3c).
 */
export function Step3WooCommerce({
  state,
  dispatch,
  inSettings = false,
}: Step3WooCommerceProps): React.JSX.Element {
  const docsUrl = state.env.docsUrl ?? '';

  return (
    <div className="space-y-6">
      {!inSettings && (
        <div>
          <p className="text-sm font-medium uppercase tracking-wide text-text-tertiary">
            { __( 'Step 3 of 6', 'smaily-connect' ) }
          </p>
          <h2 className="mt-1 text-2xl font-semibold text-text-primary">{ __( 'Automated letters', 'smaily-connect' ) }</h2>
          <p className="mt-2 text-sm text-text-secondary">
            { __( 'Send marketing and transactional emails via Smaily automation workflows.', 'smaily-connect' ) }
          </p>
          <p className="mt-2 text-sm text-text-secondary">
            { __(
              'When your page is in a single language, you can connect one workflow. Multi language sites allow connecting multiple workflows or even accounts.',
              'smaily-connect',
            ) }
          </p>
          <p className="mt-2 text-sm text-text-secondary">
            { __( 'Create and activate automation workflows in Smaily.', 'smaily-connect' ) }
            {docsUrl !== '' && (
              <>
                {' '}
                <a
                  href={`${docsUrl}#set-automations`}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="underline"
                >
                  { __( 'Read more here.', 'smaily-connect' ) }
                </a>
              </>
            )}
          </p>
          <p className="mt-2 text-sm text-text-secondary">
            { __(
              'Note! Newsletter subscription welcome emails can be configured under subscription forms settings.',
              'smaily-connect',
            ) }
          </p>
        </div>
      )}

      <AutomationSection
        state={state}
        dispatch={dispatch}
        trigger="welcome"
        title={ __( 'Welcome email', 'smaily-connect' ) }
        description={ __( 'Sent when a shopper creates an account in your store (checkout or My Account registration) — not for accounts added in WordPress admin or created by another plugin.', 'smaily-connect' ) }
        isEnabled={state.welcomeEnabled}
        onEnabledChange={(enabled) => dispatch({ type: 'SET_WELCOME_ENABLED', payload: enabled })}
      />

      <AutomationSection
        state={state}
        dispatch={dispatch}
        trigger="first_order"
        title={ __( 'First-order email', 'smaily-connect' ) }
        description={ __( 'Sent when a customer purchases for the first time.', 'smaily-connect' ) }
        isEnabled={state.firstOrderEnabled}
        onEnabledChange={(enabled) =>
          dispatch({ type: 'SET_FIRST_ORDER_ENABLED', payload: enabled })
        }
      />

      <AutomationSection
        state={state}
        dispatch={dispatch}
        trigger="abandoned_cart"
        title={ __( 'Abandoned-cart reminder', 'smaily-connect' ) }
        description={ __( 'Sent when the cart has been abandoned for a set amount of time. Note! Abandoned cart check runs every 15 minutes.', 'smaily-connect' ) }
        isEnabled={state.abandonedCartEnabled}
        onEnabledChange={(enabled) =>
          dispatch({ type: 'SET_ABANDONED_CART_ENABLED', payload: enabled })
        }
        extras={
          <div className="mt-4">
            <Label htmlFor="smly-cart-cutoff">{ __( 'Cutoff time', 'smaily-connect' ) }</Label>
            <div className="mt-1 w-40">
              <NumberInput
                id="smly-cart-cutoff"
                value={state.abandonedCartCutoffMinutes}
                onChange={(e) =>
                  dispatch({
                    type: 'SET_ABANDONED_CART_CUTOFF_MINUTES',
                    payload: parseInt(e.target.value, 10) || 30,
                  })
                }
                min={10}
                unit="min"
              />
            </div>
            <p className="mt-1 text-xs text-text-tertiary">
              { __( 'Minimum 10 minutes. Abandoned carts older than the set period are checked.', 'smaily-connect' ) }
            </p>
          </div>
        }
      />

      {/* Transactional-email triggers (PRO-1504, stage 1) — the account
          connection itself lives on the Connection tab (PRO-1540); this
          renders the two trigger mappings only once that connection is
          established, and nothing at all otherwise (Erkki's call — no
          placeholder, no pointer). */}
      <TransactionalTriggersSection state={state} dispatch={dispatch} />

      {/* Engine-run automations (contract §11–§13, T2.2) — a separate
          sub-section: unlike the three store-run triggers above, these
          fire engine-side and are only CONFIGURED here. Saved via the
          rec-engine automations proxy, not POST /settings. */}
      <EngineAutomationsSection state={state} dispatch={dispatch} inSettings={inSettings} />
    </div>
  );
}
