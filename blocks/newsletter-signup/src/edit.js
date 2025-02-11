import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { useState, useEffect, useRef } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	PanelBody,
	SelectControl,
	Spinner,
	TextControl,
	ToggleControl,
} from '@wordpress/components';

export default function Edit( { attributes, setAttributes } ) {
	const [ subdomain, setSubdomain ] = useState( null );
	const [ autoresponders, setAutoresponders ] = useState( null );
	const settingsURL = useRef();

	const blockProps = useBlockProps( {
		className: 'wp-block-smaily-newsletter-block-wrapper',
		style: {
			'--smaily-subscribe-button-bg-color':
				getColorCode(
					attributes.style?.elements?.button?.color?.background
				) ?? attributes.subscribe_button_bg_color,
			'--smaily-subscribe-button-text-color':
				getColorCode(
					attributes.style?.elements?.button?.color?.text
				) ?? attributes.subscribe_button_text_color,
		},
	} );

	const {
		autoresponder_id,
		error_url,
		name_input_label,
		show_name_field,
		success_message,
		error_message,
		subscribe_button_label,
		email_input_label,
		success_url,
	} = attributes;

	useEffect( () => {
		( async () => {
			const ar = await apiFetch( { path: '/smaily/v1/autoresponders' } );
			setAutoresponders( ar );

			const config = await apiFetch( {
				path: '/smaily/v1/configuration',
			} );

			console.log( config );
			setSubdomain( config[ 'subdomain' ] );
			settingsURL.current = config[ 'settings_url' ];
		} )();
	}, [] );

	const handleRedirect = async () => {
		if ( settingsURL.current ) {
			window.location.href = settingsURL.current;
		}
	};

	if ( autoresponders === null || subdomain === null ) {
		return <Spinner />;
	}

	if ( subdomain === '' ) {
		return (
			<Notice
				status="error"
				isDismissible={ false }
				actions={ [
					{
						label: __( 'Go to plugin settings', 'smaily' ),
						onClick: handleRedirect,
						variant: 'primary',
					},
				] }
			>
				<h3>{ __( 'Plugin setup is not complete!', 'smaily' ) }</h3>
				<p>
					{ __(
						'Please connect your Smaily account before adding a form!',
						'smaily'
					) }
				</p>
			</Notice>
		);
	}

	return (
		<>
			<Card isBorderless={ true } { ...blockProps }>
				<CardHeader
					className="smaily-newsletter-block-notice-container"
					style={ { flexDirection: 'column', alignItems: 'inherit' } }
				>
					{ success_message !== '' && (
						<Notice status="success" isDismissible={ false }>
							{ success_message }
						</Notice>
					) }
					{ error_message !== '' && (
						<Notice status="error" isDismissible={ false }>
							{ error_message }
						</Notice>
					) }
				</CardHeader>
				<CardBody>
					<form class="container">
						{ show_name_field && (
							<TextControl
								type="text"
								name="name"
								label={
									name_input_label !== '' && name_input_label
								}
								value=""
							/>
						) }
						<TextControl
							type="email"
							name="email"
							label={
								email_input_label !== '' && email_input_label
							}
							value=""
							required
						/>
						<Button
							className="smaily-newsletter-block-button-submit"
							variant="primary"
							type="submit"
						>
							{ subscribe_button_label }
						</Button>
					</form>
				</CardBody>
			</Card>
			<InspectorControls>
				<PanelBody title={ __( 'Visible fields', 'smaily' ) }>
					<ToggleControl
						label={ __( 'Display name field?', 'smaily' ) }
						checked={ show_name_field }
						onChange={ () =>
							setAttributes( {
								show_name_field: ! show_name_field,
							} )
						}
						name="show_name"
					/>
					{ show_name_field && (
						<TextControl
							label={ __( 'Name field label', 'smaily' ) }
							value={ name_input_label }
							name="name_input_label"
							onChange={ ( val ) =>
								setAttributes( { name_input_label: val } )
							}
						/>
					) }
					<TextControl
						label={ __( 'Email field label', 'smaily' ) }
						value={ email_input_label }
						name="email_input_label"
						onChange={ ( val ) =>
							setAttributes( { email_input_label: val } )
						}
					/>
					<TextControl
						label={ __( 'Subscribe button label', 'smaily' ) }
						value={ subscribe_button_label }
						name="subscribe_button_label"
						onChange={ ( val ) =>
							setAttributes( { subscribe_button_label: val } )
						}
					/>
					<TextControl
						label={ __( 'Success message', 'smaily' ) }
						value={ success_message }
						name="success_message"
						onChange={ ( val ) =>
							setAttributes( { success_message: val } )
						}
					/>
					<TextControl
						label={ __( 'Error message', 'smaily' ) }
						value={ error_message }
						name="error_message"
						onChange={ ( val ) =>
							setAttributes( { error_message: val } )
						}
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Hidden fields', 'smaily' ) }
					initialOpen={ false }
				>
					<TextControl
						label={ __( 'Success URL', 'smaily' ) }
						value={ success_url }
						name="success_url"
						onChange={ ( val ) =>
							setAttributes( { success_url: val } )
						}
						help={ __( 'Defaults to current page URL.', 'smaily' ) }
					/>
					<TextControl
						label={ __( 'Failure URL', 'smaily' ) }
						value={ error_url }
						name="failure_url"
						onChange={ ( val ) =>
							setAttributes( { error_url: val } )
						}
						help={ __( 'Defaults to current page URL.', 'smaily' ) }
					/>
					<SelectControl
						label={ __( 'Autoresponder', 'smaily' ) }
						name="autoresponder_id"
						value={ autoresponder_id }
						onChange={ ( val ) =>
							setAttributes( { autoresponder_id: val } )
						}
						options={ [
							{
								label: __( 'No autoresponder', 'smaily' ),
								value: '',
							},
							...autoresponders,
						] }
					/>
				</PanelBody>
			</InspectorControls>
		</>
	);
}

function getColorCode( color ) {
	if ( typeof color !== 'string' || color == '' ) {
		return null;
	}

	if ( color.startsWith( 'var:preset|' ) ) {
		const colorCode = color
			.replace( 'var:preset|', '--wp--preset--' )
			.replace( '|', '--' );
		return `var(${ colorCode })`;
	}

	// HEX
	return color;
}
