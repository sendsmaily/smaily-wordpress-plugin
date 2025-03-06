import { useEffect, useState } from '@wordpress/element';
import { CheckboxControl } from '@woocommerce/blocks-checkout';
import { getSetting } from '@woocommerce/settings';

const { optInDefaultText, smailyCheckoutOptinActive } = getSetting(
	'smaily-checkout-optin_data'
);

const Block = ( { children, checkoutExtensionData } ) => {
	const [ checked, setChecked ] = useState( false );
	const { setExtensionData } = checkoutExtensionData;

	useEffect( () => {
		// namespace, key, value
		setExtensionData( 'smaily-checkout-optin', 'user_newsletter', checked );
	}, [ checked, setExtensionData ] );

	if ( ! smailyCheckoutOptinActive ) {
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
