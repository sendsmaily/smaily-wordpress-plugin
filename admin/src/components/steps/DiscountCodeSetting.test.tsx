import { fireEvent, render, screen } from '@testing-library/react';
import { useReducer } from 'react';
import { describe, expect, it } from 'vitest';

import { buildTabPayload } from '../../state/buildTabPayload';
import { buildSettingsInitialState } from '../../state/settings-reducer';
import { type DiscountTrigger, type WizardState } from '../../state/types';
import { wizardReducer } from '../../state/wizard-reducer';
import { DiscountCodeSetting } from './DiscountCodeSetting';

const NEW_COUPON_URL = 'https://shop.example.test/wp-admin/post-new.php?post_type=shop_coupon';

function stateWith(templates: Array<{ id: number; label: string }>): WizardState {
  const base = buildSettingsInitialState();
  return {
    ...base,
    env: { ...base.env, discountTemplates: templates, newCouponUrl: NEW_COUPON_URL },
  };
}

function Harness({
  initial,
  trigger = 'welcome',
  onState,
}: {
  initial: WizardState;
  trigger?: DiscountTrigger;
  onState?: (state: WizardState) => void;
}): React.JSX.Element {
  const [state, dispatch] = useReducer(wizardReducer, initial);
  onState?.(state);
  return <DiscountCodeSetting state={state} dispatch={dispatch} trigger={trigger} />;
}

describe('DiscountCodeSetting (CON-199)', () => {
  it('starts off, with seven days, on every automation', () => {
    const state = buildSettingsInitialState();

    for (const trigger of ['welcome', 'first_order', 'abandoned_cart'] as const) {
      expect(state.automationDiscounts[trigger]).toEqual({ enabled: false, templateId: 0, validDays: 7 });
    }
  });

  it('lets the merchant pick one of the store coupons and saves it with the WooCommerce tab', () => {
    let latest = stateWith([
      { id: 12, label: 'WELCOME15 — Percentage discount: 15' },
      { id: 31, label: 'CART5 — Fixed cart discount: 5' },
    ]);
    render(<Harness initial={latest} trigger="abandoned_cart" onState={(s) => { latest = s; }} />);

    expect(screen.queryByRole('combobox', { name: 'Discount (WooCommerce coupon)' })).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('switch', { name: 'Send a discount code' }));
    fireEvent.change(screen.getByRole('combobox', { name: 'Discount (WooCommerce coupon)' }), {
      target: { value: '31' },
    });
    fireEvent.change(screen.getByRole('spinbutton', { name: 'Valid for (days)' }), { target: { value: '14' } });

    expect(buildTabPayload(latest, 'woocommerce')).toMatchObject({
      automationDiscounts: {
        welcome: { enabled: false, templateId: 0, validDays: 7 },
        first_order: { enabled: false, templateId: 0, validDays: 7 },
        abandoned_cart: { enabled: true, templateId: 31, validDays: 14 },
      },
    });
    expect(latest.dirtyTabs.woocommerce).toBe(true);
  });

  it('asks for a coupon while the switch is on and none is picked', () => {
    render(<Harness initial={stateWith([{ id: 12, label: 'WELCOME15 — Percentage discount: 15' }])} />);

    fireEvent.click(screen.getByRole('switch', { name: 'Send a discount code' }));

    expect(
      screen.getByText('Pick a coupon, or switch the discount code off. Until then the email goes without a code.'),
    ).toBeInTheDocument();
  });

  it('explains how to create the discount in WooCommerce when the store has no coupon to clone', () => {
    render(<Harness initial={stateWith([])} />);

    fireEvent.click(screen.getByRole('switch', { name: 'Send a discount code' }));

    expect(screen.getByText('Create the discount in WooCommerce first')).toBeInTheDocument();
    expect(screen.queryByRole('combobox', { name: 'Discount (WooCommerce coupon)' })).not.toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Add a coupon in WooCommerce' })).toHaveAttribute('href', NEW_COUPON_URL);
  });

  it('keeps a saved coupon that can no longer be used visible in the list', () => {
    const initial = stateWith([{ id: 12, label: 'WELCOME15 — Percentage discount: 15' }]);
    initial.automationDiscounts = {
      ...initial.automationDiscounts,
      welcome: { enabled: true, templateId: 99, validDays: 7 },
    };
    render(<Harness initial={initial} />);

    expect(screen.getByRole('combobox', { name: 'Discount (WooCommerce coupon)' })).toHaveValue('99');
    expect(screen.getByRole('option', { name: '#99 — cannot be used' })).toBeInTheDocument();
  });
});
