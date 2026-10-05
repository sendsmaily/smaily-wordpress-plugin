import { __ } from '@wordpress/i18n';
import { useBlockProps } from '@wordpress/block-editor';
import { Placeholder } from '@wordpress/components';

/**
 * The editor shows a description, not a preview: the products depend on the
 * logged-in shopper who views the page.
 */
export default function Edit() {
	return (
		<div { ...useBlockProps() }>
			<Placeholder
				label={ __( 'Smaily Recommendations', 'smaily-connect' ) }
				instructions={ __(
					'Logged-in shoppers see their personal product recommendations here. Visitors who are not logged in see nothing.',
					'smaily-connect'
				) }
			/>
		</div>
	);
}
