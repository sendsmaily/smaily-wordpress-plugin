/* eslint-env jest */
/* eslint-disable import/no-extraneous-dependencies -- React comes with @wordpress/element. */

// Tell React the renders below run inside act().
global.IS_REACT_ACT_ENVIRONMENT = true;

/**
 * Render the checkout opt-in block with the given integration data
 * (`smaily-checkout-optin_data`), which the block reads when it loads.
 *
 * @param {Object}   data               The data the server prints for the block.
 * @param {Function} [interact]         Called with the container and `act` while the block is mounted.
 * @param {Function} [cartUpdate]       Stands in for `extensionCartUpdate`.
 * @param {Function} [setExtensionData] Stands in for the checkout's `setExtensionData`.
 * @return {string} The rendered markup.
 */
const render = (
	data,
	interact = () => {},
	cartUpdate = () => {},
	setExtensionData = () => {}
) => {
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
				CheckboxControl: ( { id, checked, onChange, children } ) => (
					<label htmlFor={ id }>
						<input
							id={ id }
							type="checkbox"
							checked={ checked }
							onChange={ ( event ) =>
								onChange( event.target.checked )
							}
						/>
						{ children }
					</label>
				),
				extensionCartUpdate: cartUpdate,
			} ),
			{ virtual: true }
		);

		// The block and the renderer must share one React copy.
		const { createRoot } = require( 'react-dom/client' );
		const { act } = require( 'react' );
		const Block = require( '../src/block' ).default;

		const container = document.createElement( 'div' );
		document.body.appendChild( container );
		const root = createRoot( container );
		act( () => {
			root.render(
				<Block checkoutExtensionData={ { setExtensionData } } />
			);
		} );
		markup = container.innerHTML;
		interact( container, act );
		act( () => root.unmount() );
		container.remove();
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

test( 'a change of the box is sent to the store at once', () => {
	const cartUpdate = jest.fn();
	const setExtensionData = jest.fn();
	const click = ( container, act ) =>
		act( () =>
			container.querySelector( '#smaily-checkout-optin' ).click()
		);

	render(
		{ ...active, customerSubscribed: false },
		( container, act ) => {
			click( container, act );
			click( container, act );
		},
		cartUpdate,
		setExtensionData
	);

	expect( cartUpdate.mock.calls ).toEqual( [
		[
			{
				namespace: 'smaily-checkout-optin',
				data: { user_newsletter: true },
			},
		],
		[
			{
				namespace: 'smaily-checkout-optin',
				data: { user_newsletter: false },
			},
		],
	] );
	// The placed order still carries the box, as before.
	expect( setExtensionData ).toHaveBeenLastCalledWith(
		'smaily-checkout-optin',
		'user_newsletter',
		false
	);
} );

test( 'nothing is sent to the store before the shopper changes the box', () => {
	const cartUpdate = jest.fn();

	render( { ...active, customerSubscribed: false }, () => {}, cartUpdate );

	expect( cartUpdate ).not.toHaveBeenCalled();
} );
