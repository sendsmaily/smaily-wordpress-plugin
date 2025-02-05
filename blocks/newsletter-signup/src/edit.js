import { __ } from '@wordpress/i18n';

import {
	Notice,
	Card,
	CardHeader,
	CardBody,
	Flex,
	FlexItem,
	TextControl,
	ToggleControl,
	PanelBody,
	Button,
} from '@wordpress/components';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';

export default function Edit( { attributes, setAttributes } ) {
	const {
		autoresponder_id,
		error_url,
		name_input_label,
		show_name_field,
		success_message,
		error_message,
		subdomain,
		subscribe_button_label,
		email_input_label,
		success_url,
	} = attributes;

	return (
		<>
			<Card { ...useBlockProps() } isBorderless={ true }>
				<CardHeader
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
					<form
						class="container"
						action={ `https://${ subdomain }.sendsmaily.net/api/opt-in/` }
						method="post"
						autocomplete="off"
					>
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
						<Button variant="primary" type="submit">
							{ subscribe_button_label }
						</Button>
					</form>
				</CardBody>
			</Card>
			<InspectorControls>
				<PanelBody title={ __( 'Visible fields', 'smaily' ) }>
					<TextControl
						label={ __( 'Subdomain', 'smaily' ) }
						value={ subdomain }
						name="subdomain"
						onChange={ ( val ) =>
							setAttributes( { subdomain: val } )
						}
					/>
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
					<TextControl
						label={ __( 'Autoresponder ID', 'smaily' ) }
						value={ autoresponder_id }
						name="autoresponder_id"
						onChange={ ( val ) =>
							setAttributes( { autoresponder_id: val } )
						}
					/>
				</PanelBody>
			</InspectorControls>
		</>
	);
}
