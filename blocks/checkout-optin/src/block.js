import { useEffect, useState } from '@wordpress/element';
import {
	CheckboxControl,
	extensionCartUpdate,
} from '@woocommerce/blocks-checkout';
import { getSetting } from '@woocommerce/settings';

const { optInDefaultText, smailyCheckoutOptinActive, customerSubscribed } =
	getSetting( 'smaily-checkout-optin_data' );

const Block = ( { children, checkoutExtensionData } ) => {
	const [ checked, setChecked ] = useState( false );
	const { setExtensionData } = checkoutExtensionData;

	useEffect( () => {
		// namespace, key, value
		setExtensionData( 'smaily-checkout-optin', 'user_newsletter', checked );
	}, [ checked, setExtensionData ] );

	// The store records the box on the cart as soon as it changes, so a tick
	// counts for the cart reminder before the order is placed (PRO-4263).
	const onChange = ( value ) => {
		setChecked( value );
		extensionCartUpdate( {
			namespace: 'smaily-checkout-optin',
			data: { user_newsletter: value },
		} );
	};

	// A subscribed customer is not asked again, as in the classic checkout.
	if ( ! smailyCheckoutOptinActive || customerSubscribed ) {
		return null;
	}

	return (
		<>
			<CheckboxControl
				id="smaily-checkout-optin"
				checked={ checked }
				onChange={ onChange }
			>
				{ children || optInDefaultText }
			</CheckboxControl>
		</>
	);
};

export default Block;
