import { useEffect, useState } from '@wordpress/element';
import { CheckboxControl } from '@woocommerce/blocks-checkout';
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

	// A subscribed customer is not asked again, as in the classic checkout.
	if ( ! smailyCheckoutOptinActive || customerSubscribed ) {
		return null;
	}

	return (
		<>
			<CheckboxControl
				id="smaily-checkout-optin"
				checked={ checked }
				onChange={ setChecked }
			>
				{ children || optInDefaultText }
			</CheckboxControl>
		</>
	);
};

export default Block;
