import { type Dispatch } from 'react';

import { __ } from '@admin/lib/i18n';
import { type WizardAction, type WizardState } from '../../state/types';
import { Checkbox } from '../primitives';
import { AutomationSection } from './AutomationSection';

export interface TransactionalTriggersSectionProps {
  state: WizardState;
  dispatch: Dispatch<WizardAction>;
}

/** account_key the transactional Smaily account is bound under (PRO-1504). */
const TRANSACTIONAL_ACCOUNT_KEY = 'transactional';

/**
 * Transactional-email triggers — order confirmation + shipping
 * confirmation (PRO-1504 stage 1). Relocated here from the old combined
 * TransactionalEmailsSection by PRO-1540: the account connection itself
 * (toggle + credentials + test) now lives on the Connection tab; this
 * section only renders once that connection is established, mirroring
 * how EngineAutomationsSection gates its own sub-section on the rec-engine
 * connection.
 *
 * Erkki's explicit choice (PRO-1540): when there is no active
 * transactional connection, this renders NOTHING — no placeholder, no
 * pointer back to the Connection tab.
 */
export function TransactionalTriggersSection({
  state,
  dispatch,
}: TransactionalTriggersSectionProps): React.JSX.Element | null {
  if (state.transactionalConnection.kind !== 'success') {
    return null;
  }

  return (
    <div className="space-y-4 border-t border-border-subtle pt-6">
      <div>
        <h3 className="text-lg font-semibold text-text-primary">
          { __( 'Transactional emails', 'smaily-connect' ) }
        </h3>
        <p className="mt-1 text-sm text-text-secondary">
          { __(
            'Send transactional emails via transactional Smaily account, which you can connect in the first setup step. Transactional emails should be sent from a separate account than marketing emails.',
            'smaily-connect',
          ) }
        </p>
      </div>

      <AutomationSection
        state={state}
        dispatch={dispatch}
        trigger="order_confirmation"
        title={ __( 'Order confirmation', 'smaily-connect' ) }
        description={ __( 'Sent when a customer places an order.', 'smaily-connect' ) }
        isEnabled={state.orderConfirmationEnabled}
        onEnabledChange={(enabled) =>
          dispatch({ type: 'SET_ORDER_CONFIRMATION_ENABLED', payload: enabled })
        }
        accountKeyOverride={TRANSACTIONAL_ACCOUNT_KEY}
      />

      <AutomationSection
        state={state}
        dispatch={dispatch}
        trigger="shipping_confirmation"
        title={ __( 'Shipping confirmation', 'smaily-connect' ) }
        description={ __( 'Sent when the order has been completed and is ready to be sent.', 'smaily-connect' ) }
        isEnabled={state.shippingConfirmationEnabled}
        onEnabledChange={(enabled) =>
          dispatch({ type: 'SET_SHIPPING_CONFIRMATION_ENABLED', payload: enabled })
        }
        accountKeyOverride={TRANSACTIONAL_ACCOUNT_KEY}
        extras={<ShippedStatusPicker state={state} dispatch={dispatch} />}
      />
    </div>
  );
}

interface ShippedStatusPickerProps {
  state: WizardState;
  dispatch: Dispatch<WizardAction>;
}

/**
 * "Counts as shipped" order-status multi-select (PRO-1504). Choices come
 * from the server-registered statuses (incl. custom ones) via
 * EnvDetector::order_statuses() — the same checkbox-grid pattern Step 2
 * uses for sync fields. Setting only; no status-change listener yet.
 */
function ShippedStatusPicker({ state, dispatch }: ShippedStatusPickerProps): React.JSX.Element {
  const statuses = state.env.orderStatuses ?? [];

  return (
    <div className="mt-4">
      <p className="text-sm font-medium text-text-primary">
        { __( 'Counts as shipped', 'smaily-connect' ) }
      </p>
      <p className="mt-1 text-xs text-text-tertiary">
        { __( 'Order statuses that trigger the shipping-confirmation email.', 'smaily-connect' ) }
      </p>
      <div className="mt-3 grid grid-cols-1 gap-2 md:grid-cols-2">
        {statuses.map((status) => (
          <Checkbox
            key={status.slug}
            name={`smly-shipped-status-${status.slug}`}
            checked={state.shippedOrderStatuses.includes(status.slug)}
            onChange={() => dispatch({ type: 'TOGGLE_SHIPPED_ORDER_STATUS', payload: { status: status.slug } })}
            label={status.name}
          />
        ))}
      </div>
    </div>
  );
}
