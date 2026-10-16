import { type Dispatch } from 'react';

import { __ } from '@admin/lib/i18n';
import { type WizardAction, type WizardState } from '../../state/types';
import { Card, Checkbox, Label, NumberInput, Toggle } from '../primitives';
import { AutomationSection } from './AutomationSection';
import { TransactionalStatusLine } from './TransactionalStatusLine';

export interface TransactionalTriggersSectionProps {
  state: WizardState;
  dispatch: Dispatch<WizardAction>;
}

/** account_key the transactional Smaily account is bound under (PRO-1504). */
const TRANSACTIONAL_ACCOUNT_KEY = 'transactional';

/**
 * Transactional-email triggers — order confirmation + shipping
 * confirmation (PRO-1504 stage 1) + the follow-up email (PRO-4095).
 * Relocated here from the old combined TransactionalEmailsSection by
 * PRO-1540: the account connection itself
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
        active={state.transactionalEmailsEnabled && state.orderConfirmationEnabled}
        onEnabledChange={(enabled) =>
          dispatch({ type: 'SET_ORDER_CONFIRMATION_ENABLED', payload: enabled })
        }
        accountKeyOverride={TRANSACTIONAL_ACCOUNT_KEY}
        extras={
          <TransactionalStatusLine
            state={state}
            trigger="order_confirmation"
            accountKey={TRANSACTIONAL_ACCOUNT_KEY}
          />
        }
      />

      <AutomationSection
        state={state}
        dispatch={dispatch}
        trigger="shipping_confirmation"
        title={ __( 'Shipping confirmation', 'smaily-connect' ) }
        description={ __( 'Sent when the order has been completed and is ready to be sent.', 'smaily-connect' ) }
        isEnabled={state.shippingConfirmationEnabled}
        active={state.transactionalEmailsEnabled && state.shippingConfirmationEnabled}
        onEnabledChange={(enabled) =>
          dispatch({ type: 'SET_SHIPPING_CONFIRMATION_ENABLED', payload: enabled })
        }
        accountKeyOverride={TRANSACTIONAL_ACCOUNT_KEY}
        extras={
          <>
            <TransactionalStatusLine
              state={state}
              trigger="shipping_confirmation"
              accountKey={TRANSACTIONAL_ACCOUNT_KEY}
            />
            <StatusPicker
              state={state}
              title={ __( 'Counts as shipped', 'smaily-connect' ) }
              description={ __( 'Order statuses that trigger the shipping-confirmation email.', 'smaily-connect' ) }
              namePrefix="smly-shipped-status"
              selected={state.shippedOrderStatuses}
              onToggle={(status) => dispatch({ type: 'TOGGLE_SHIPPED_ORDER_STATUS', payload: { status } })}
            />
          </>
        }
      />

      <AutomationSection
        state={state}
        dispatch={dispatch}
        trigger="follow_up"
        title={ __( 'Follow-up email', 'smaily-connect' ) }
        description={ __( 'Sent a set number of days after an order reaches a ticked status — a feedback request, say.', 'smaily-connect' ) }
        isEnabled={state.followUpEnabled}
        active={state.transactionalEmailsEnabled && state.followUpEnabled}
        onEnabledChange={(enabled) => dispatch({ type: 'SET_FOLLOW_UP_ENABLED', payload: enabled })}
        accountKeyOverride={TRANSACTIONAL_ACCOUNT_KEY}
        extras={
          <>
            <TransactionalStatusLine
              state={state}
              trigger="follow_up"
              accountKey={TRANSACTIONAL_ACCOUNT_KEY}
            />
            <div className="mt-4">
              <Label htmlFor="smly-follow-up-delay">{ __( 'Days to wait', 'smaily-connect' ) }</Label>
              <div className="mt-1 w-40">
                <NumberInput
                  id="smly-follow-up-delay"
                  value={state.followUpDelayDays}
                  onChange={(e) => dispatch({ type: 'SET_FOLLOW_UP_DELAY_DAYS', payload: Number(e.target.value) })}
                  min={1}
                  max={90}
                  step={1}
                />
              </div>
              <p className="mt-1 text-xs text-text-tertiary">
                { __( 'From 1 to 90 days after the order reaches a ticked status.', 'smaily-connect' ) }
              </p>
            </div>
            <StatusPicker
              state={state}
              title={ __( 'Starts the wait', 'smaily-connect' ) }
              description={ __( 'Order statuses that start the follow-up email’s wait. It is sent only if the order is still at one of them when the wait ends, and never for a cancelled, refunded or failed order.', 'smaily-connect' ) }
              namePrefix="smly-follow-up-status"
              selected={state.followUpOrderStatuses}
              onToggle={(status) => dispatch({ type: 'TOGGLE_FOLLOW_UP_ORDER_STATUS', payload: { status } })}
            />
          </>
        }
      />

      <Card
        title={ __( 'Customer details', 'smaily-connect' ) }
        description={ __( 'Personal data sent with the transactional emails. Off by default — switch it on only if your email template shows these details.', 'smaily-connect' ) }
      >
        <Toggle
          name="smly-transactional-personal-data-enabled"
          checked={state.transactionalPersonalData}
          onChange={(e) =>
            dispatch({ type: 'SET_TRANSACTIONAL_PERSONAL_DATA', payload: e.target.checked })
          }
          label={ __( 'Include addresses, phone and order note', 'smaily-connect' ) }
          description={ __( 'The billing and delivery address, the delivery name, the phone number and the customer\'s order note.', 'smaily-connect' ) }
        />
      </Card>
    </div>
  );
}

interface StatusPickerProps {
  state: WizardState;
  title: string;
  description: string;
  /** Prefix of each checkbox's name; the status slug is appended. */
  namePrefix: string;
  selected: string[];
  onToggle: (status: string) => void;
}

/**
 * An order-status multi-select — "Counts as shipped" (PRO-1504) and the
 * follow-up email's statuses (PRO-4095). Choices come from the
 * server-registered statuses (incl. custom ones) via
 * EnvDetector::order_statuses() — the same checkbox-grid pattern Step 2
 * uses for sync fields.
 */
function StatusPicker({ state, title, description, namePrefix, selected, onToggle }: StatusPickerProps): React.JSX.Element {
  const statuses = state.env.orderStatuses ?? [];

  return (
    <div className="mt-4">
      <p className="text-sm font-medium text-text-primary">{title}</p>
      <p className="mt-1 text-xs text-text-tertiary">{description}</p>
      <div className="mt-3 grid grid-cols-1 gap-2 md:grid-cols-2">
        {statuses.map((status) => (
          <Checkbox
            key={status.slug}
            name={`${namePrefix}-${status.slug}`}
            checked={selected.includes(status.slug)}
            onChange={() => onToggle(status.slug)}
            label={status.name}
          />
        ))}
      </div>
    </div>
  );
}
