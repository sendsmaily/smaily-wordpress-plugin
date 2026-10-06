import { __ } from '@wordpress/i18n';
import { useBlockProps } from '@wordpress/block-editor';
import { Placeholder } from '@wordpress/components';

/**
 * The editor shows a description, not a preview: the products depend on the
 * shopper who views the page, and the storefront script fills them in after
 * the page has loaded.
 */
export default function Edit() {
	return (
		<div { ...useBlockProps() }>
			<Placeholder
				label={ __( 'Smaily Recommendations', 'smaily-connect' ) }
				instructions={ __(
					'Shoppers who accepted marketing cookies see their personal product recommendations here a moment after the page loads: when logged in, or as a guest who came to the store from a Smaily email before. Other visitors see nothing.',
					'smaily-connect'
				) }
			/>
		</div>
	);
}
