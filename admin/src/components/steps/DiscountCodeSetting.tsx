import { type Dispatch } from 'react';

import { __, sprintf } from '@admin/lib/i18n';
import {
  FALLBACK_DISCOUNT_VALID_DAYS,
  type AutomationDiscountSetting,
  type DiscountTrigger,
  type WizardAction,
  type WizardState,
} from '../../state/types';
import { Banner, Label, NumberInput, Select, Toggle } from '../primitives';

export interface DiscountCodeSettingProps {
  state: WizardState;
  dispatch: Dispatch<WizardAction>;
  trigger: DiscountTrigger;
}

/**
 * "Send a discount code" on one automation card (CON-199): the switch, the
 * WooCommerce coupon each shopper's single-use code is cloned from, and how
 * many days the code is valid. The coupon list holds only the coupons a
 * code can be cloned from (AutomationDiscount::templates()); a store
 * without one is told how to create one in WooCommerce.
 */
export function DiscountCodeSetting({ state, dispatch, trigger }: DiscountCodeSettingProps): React.JSX.Element {
  const setting = state.automationDiscounts[trigger];
  const templates = state.env.discountTemplates ?? [];
  const limits = state.env.discountValidDays ?? FALLBACK_DISCOUNT_VALID_DAYS;
  const newCouponUrl = state.env.newCouponUrl ?? '';
  const ids = { coupon: `smly-discount-${trigger}-coupon`, days: `smly-discount-${trigger}-days` };

  const update = (patch: Partial<AutomationDiscountSetting>): void => {
    dispatch({ type: 'SET_AUTOMATION_DISCOUNT', payload: { trigger, patch } });
  };

  const options = templates.map((t) => ({ value: String(t.id), label: t.label }));
  // A saved coupon that can no longer be used keeps its own entry, so the
  // list never shows another coupon as the picked one.
  if (setting.templateId > 0 && !templates.some((t) => t.id === setting.templateId)) {
    options.unshift({
      value: String(setting.templateId),
      label: `#${setting.templateId} — ${ __( 'cannot be used', 'smaily-connect' ) }`,
    });
  }

  return (
    <div className="mt-4">
      <Toggle
        name={`smly-discount-${trigger}-enabled`}
        checked={setting.enabled}
        onChange={(e) => update({ enabled: e.target.checked })}
        label={ __( 'Send a discount code', 'smaily-connect' ) }
      />
      <p className="mt-1 text-xs text-text-tertiary">
        { __( 'Each email gets the shopper’s own code, cloned from a WooCommerce coupon: usable once, only with the shopper’s email address, until the end of its last valid day.', 'smaily-connect' ) }
      </p>

      {setting.enabled && (templates.length === 0 ? (
        <div className="mt-3">
          <Banner tone="info" title={ __( 'Create the discount in WooCommerce first', 'smaily-connect' ) }>
            { __( 'Each code is a copy of one of your WooCommerce coupons. In WooCommerce, go to Marketing > Coupons > Add coupon, set the discount — Percentage, Fixed cart or Fixed product, and any usage restrictions — and publish it. Then reload this page: the coupon appears in the list.', 'smaily-connect' ) }
            {newCouponUrl !== '' && (
              <>
                {' '}
                <a href={newCouponUrl} target="_blank" rel="noopener noreferrer" className="underline">
                  { __( 'Add a coupon in WooCommerce', 'smaily-connect' ) }
                </a>
              </>
            )}
          </Banner>
        </div>
      ) : (
        <div className="mt-3 space-y-3">
          <div>
            <Label htmlFor={ids.coupon}>{ __( 'Discount (WooCommerce coupon)', 'smaily-connect' ) }</Label>
            <div className="mt-1">
              <Select
                id={ids.coupon}
                options={options}
                placeholder={ __( '— pick a coupon —', 'smaily-connect' ) }
                value={setting.templateId > 0 ? String(setting.templateId) : ''}
                onChange={(e) => update({ templateId: Number(e.target.value) })}
              />
            </div>
            <p className="mt-1 text-xs text-text-tertiary">
              { __( 'The list shows your published coupons of WooCommerce’s own discount types that have not expired. Each code copies the coupon’s discount and its restrictions.', 'smaily-connect' ) }
            </p>
            {setting.templateId === 0 && (
              <div className="mt-2">
                <Banner tone="warning">
                  { __( 'Pick a coupon, or switch the discount code off. Until then the email goes without a code.', 'smaily-connect' ) }
                </Banner>
              </div>
            )}
          </div>
          <div>
            <Label htmlFor={ids.days}>{ __( 'Valid for (days)', 'smaily-connect' ) }</Label>
            <div className="mt-1 w-40">
              <NumberInput
                id={ids.days}
                value={setting.validDays}
                onChange={(e) => update({ validDays: Number(e.target.value) })}
                min={limits.min}
                max={limits.max}
                step={1}
              />
            </div>
            <p className="mt-1 text-xs text-text-tertiary">
              {sprintf(
                // translators: 1: the shortest validity in days, 2: the longest.
                __( 'From %1$d to %2$d days. The code works until the end of that day in the store’s timezone, and never after the coupon’s own expiry date.', 'smaily-connect' ),
                limits.min,
                limits.max,
              )}
            </p>
          </div>
        </div>
      ))}
    </div>
  );
}
