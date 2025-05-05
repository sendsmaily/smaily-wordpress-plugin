import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { useEffect } from '@wordpress/element';
import { validateLandingPageURL, generateLandingPageURL } from './urlParser';
import { PanelBody, TextControl, Notice, Tip } from '@wordpress/components';

export const Edit = ( { attributes, setAttributes } ) => {
	const blockProps = useBlockProps( {
		className: 'smaily-connect-landingpage-block-edit-wrapper',
		style: {
			height: attributes.height,
			width: attributes.width,
			overflow: 'hidden',
		},
	} );

	useEffect( () => {
		( async () => {
			const config = await apiFetch( {
				path: '/smaily/v1/configuration',
			} );
			setAttributes( { subdomain: config.subdomain } );
		} )();
	}, [ setAttributes ] );

	const handleChangeURL = ( value ) => {
		if ( value === '' ) {
			setAttributes( {
				landingpagePK: '',
				url: '',
				height: 450,
				width: 500,
			} );
			return;
		}

		const { valid, pk } = validateLandingPageURL(
			value,
			attributes.subdomain
		);
		if ( ! valid ) {
			setAttributes( { landingpagePK: '' } );
		} else {
			setAttributes( {
				landingpagePK: pk,
			} );
		}

		setAttributes( {
			url: value,
		} );
	};

	if ( attributes.subdomain === '' ) {
		return (
			<div { ...blockProps }>
				<Notice status="info" isDismissible={ false }>
					{ __(
						'Please configure the plugin first.',
						'smaily-connect'
					) }
				</Notice>
			</div>
		);
	}

	const isURLProvided = attributes.url && attributes.url.trim() !== '';
	const isURLValid = isURLProvided && attributes.landingpagePK !== '';

	return (
		<>
			<div { ...blockProps }>
				{ ! isURLProvided && <SetupSection /> }
				{ isURLProvided && ! isURLValid && <ErrorSection /> }
				{ isURLValid && (
					<iframe
						loading="lazy"
						referrerPolicy="no-referrer"
						title={ __( 'Smaily Landing Page', 'smaily-connect' ) }
						src={ generateLandingPageURL(
							attributes.subdomain,
							attributes.landingpagePK
						) }
					/>
				) }
			</div>
			<InspectorControls>
				<PanelBody title={ __( 'Settings', 'smaily-connect' ) }>
					<TextControl
						value={ attributes.url }
						label={ __( 'URL', 'smaily-connect' ) }
						onChange={ handleChangeURL }
						help={
							! isURLProvided
								? __(
										'Enter the URL of the landing page you want to display.',
										'smaily-connect'
								  )
								: undefined
						}
						placeholder={ __(
							'Landing page URL',
							'smaily-connect'
						) }
					/>
					{ isURLProvided && ! isURLValid && (
						<p className="smaily-connect-landingpage-block-error">
							<em>
								{ __(
									'Invalid landing page URL!',
									'smaily-connect'
								) }
							</em>
						</p>
					) }
					<div className="components-base-control">
						<Tip>
							<a
								target="_blank"
								rel="noreferrer"
								href="https://smaily.com/help/user-manual/landing-pages/adding-a-success-page-to-a-form/"
							>
								{ __(
									'Need a custom thank you page?',
									'smaily-connect'
								) }
							</a>
						</Tip>
					</div>
					<TextControl
						className="components-base-control"
						label={ __( 'Height', 'smaily-connect' ) }
						type="number"
						value={ attributes.height }
						onChange={ ( value ) => {
							setAttributes( {
								height: Number( value ),
							} );
						} }
						min={ 0 }
					/>
					<TextControl
						className="components-base-control"
						label={ __( 'Width', 'smaily-connect' ) }
						type="number"
						value={ attributes.width }
						onChange={ ( value ) => {
							setAttributes( {
								width: Number( value ),
							} );
						} }
						min={ 0 }
					/>
				</PanelBody>
			</InspectorControls>
		</>
	);
};

export const Save = ( { attributes } ) => {
	const blockProps = useBlockProps.save( {
		className: 'smaily-connect-landingpage-block-front-wrapper',
		style: {
			height: attributes.height,
			width: attributes.width,
		},
	} );

	if ( attributes.landingpagePK === '' ) {
		return <SetupSection />;
	}

	return (
		<div { ...blockProps }>
			<iframe
				className="smaily-connect-landingpage-block-front"
				src={ generateLandingPageURL(
					attributes.subdomain,
					attributes.landingpagePK
				) }
				title={ __( 'Smaily Landing Page', 'smaily-connect' ) }
				loading="lazy"
				referrerPolicy="no-referrer"
			/>
		</div>
	);
};

const SetupSection = () => {
	return (
		<div className="smaily-connect-landingpage-block-edit-setup">
			<h3>{ __( 'Smaily Connect Landing Page', 'smaily-connect' ) }</h3>
			<p>
				{ __(
					'Copy the URL of the landing page you want to display in this block and paste it in the Block settings.',
					'smaily-connect'
				) }
			</p>
			<p>
				{ __(
					'If you need any help setting up the landing page, follow our awesome guide:',
					'smaily-connect'
				) }{ ' ' }
				<a
					href="https://smaily.com/help/user-manual/landing-pages/creating-landing-pages/"
					target="_blank"
					rel="noreferrer"
				>
					{ __( 'creating a landing page', 'smaily-connect' ) }
				</a>
				.
			</p>
		</div>
	);
};

const ErrorSection = () => {
	return (
		<div className="smaily-connect-landingpage-block-edit-error">
			<h3>{ __( 'Invalid Landing Page URL!', 'smaily-connect' ) }</h3>
			<p className="smaily-connect-landingpage-block-error">
				{ __(
					'Please check the entered URL. It is invalid!',
					'smaily-connect'
				) }
			</p>
		</div>
	);
};
