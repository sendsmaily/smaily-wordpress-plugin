import { useBlockProps, RichText } from '@wordpress/block-editor';
import { CheckboxControl } from '@woocommerce/blocks-checkout';
import { getSetting } from '@woocommerce/settings';

import './style.scss';
const { optInDefaultText, smailyCheckoutOptinActive } = getSetting('smaily-checkout-optin_data');

export const Edit = ( { attributes, setAttributes } ) => {
	const { text } = attributes;
	const blockProps = useBlockProps();

	if ( ! smailyCheckoutOptinActive ) {
		return null;
	}

	return (
		<div { ...blockProps }>
			<CheckboxControl
				id="newsletter-text"
				checked={ false }
				disabled={ true }
			/>
			<RichText
				value={ text || optInDefaultText }
				onChange={ ( value ) => setAttributes( { text: value } ) }
			/>
		</div>
	);
};

export const Save = ( { attributes } ) => {
	const { text } = attributes;
	return (
		<div { ...useBlockProps.save() }>
			<RichText.Content value={ text || optInDefaultText } />
		</div>
	);
};
