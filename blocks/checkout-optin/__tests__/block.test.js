/* eslint-env jest */
/* eslint-disable import/no-extraneous-dependencies -- React comes with @wordpress/element. */

// Tell React the renders below run inside act().
global.IS_REACT_ACT_ENVIRONMENT = true;

/**
 * Render the checkout opt-in block with the given integration data
 * (`smaily-checkout-optin_data`), which the block reads when it loads.
 *
 * @param {Object} data The data the server prints for the block.
 * @return {string} The rendered markup.
 */
const render = ( data ) => {
	let markup = '';

	jest.isolateModules( () => {
		jest.doMock(
			'@woocommerce/settings',
			() => ( { getSetting: () => data } ),
			{ virtual: true }
		);
		jest.doMock(
			'@woocommerce/blocks-checkout',
			() => ( {
				CheckboxControl: ( { id, children } ) => (
					<label htmlFor={ id }>{ children }</label>
				),
			} ),
			{ virtual: true }
		);

		// The block and the renderer must share one React copy.
		const { createRoot } = require( 'react-dom/client' );
		const { act } = require( 'react' );
		const Block = require( '../src/block' ).default;

		const container = document.createElement( 'div' );
		const root = createRoot( container );
		act( () => {
			root.render(
				<Block
					checkoutExtensionData={ { setExtensionData: () => {} } }
				/>
			);
		} );
		markup = container.innerHTML;
		act( () => root.unmount() );
	} );

	return markup;
};

const active = {
	smailyCheckoutOptinActive: '1',
	optInDefaultText: 'Subscribe to newsletter',
};

test( 'the box is shown to a customer who is not subscribed', () => {
	expect( render( { ...active, customerSubscribed: false } ) ).toContain(
		'smaily-checkout-optin'
	);
} );

test( 'the box is not shown to a customer who is already subscribed', () => {
	expect( render( { ...active, customerSubscribed: true } ) ).toBe( '' );
} );

test( 'the box is not shown when the merchant turned it off', () => {
	expect(
		render( {
			...active,
			smailyCheckoutOptinActive: '',
			customerSubscribed: false,
		} )
	).toBe( '' );
} );
