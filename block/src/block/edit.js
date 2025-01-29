import { __ } from '@wordpress/i18n';

import { TextControl, ToggleControl, PanelBody, Button,  __experimentalNumberControl as NumberControl } from '@wordpress/components';
import { useBlockProps, InspectorControls, InspectorAdvancedControls } from '@wordpress/block-editor';
import './editor.scss';

export default function Edit({ attributes, setAttributes }) {
	const {
		autoresponder_id,
		error_url,
		name_input_label,
		show_name_field,
		subdomain,
		subscribe_button_label,
		email_input_label,
		success_url,
		title,
	} = attributes;

	return (
		<>
			<InspectorControls>
				<PanelBody title={__('Settings', 'smaily')}>
					<TextControl
						label={__('Title', 'smaily')}
						value={title}
						name="title"
						onChange={(val) => setAttributes({ title: val })}
					/>
					<TextControl
						label={__('Subdomain', 'smaily')}
						value={subdomain}
						name="subdomain"
						onChange={(val) => setAttributes({ subdomain: val })}
					/>
					<ToggleControl
						label={__('Display name field?', 'smaily')}
						checked={show_name_field}
						onChange={() => setAttributes({ show_name_field: !show_name_field })}
						name="show_name"
					/>
					{show_name_field &&
						<TextControl
							label={__('Name button label', 'smaily')}
							value={name_input_label}
							name="name_input_label"
							onChange={(val) => setAttributes({ name_input_label: val })}
						/>
					}
					<TextControl
						label={__('Email label', 'smaily')}
						value={email_input_label}
						name="email_input_label"
						onChange={(val) => setAttributes({ email_input_label: val })}
					/>
					<TextControl
						label={__('Subscribe label', 'smaily')}
						value={subscribe_button_label}
						name="subscribe_button_label"
						onChange={(val) => setAttributes({ subscribe_button_label: val })}
					/>
				</PanelBody>
			</InspectorControls>
			<InspectorAdvancedControls>
				<TextControl
					label={__('Success URL', 'smaily')}
					value={success_url}
					name="success_url"
					onChange={(val) => setAttributes({ success_url: val })}
					help={__('Defaults to current page URL.', 'smaily')}
				/>
				<TextControl
					label={__('Failure URL', 'smaily')}
					value={error_url}
					name="failure_url"
					onChange={(val) => setAttributes({ error_url: val })}
					help={__('Defaults to current page URL.', 'smaily')}
				/>

				<NumberControl
					label={__('Autoresponder ID', 'smaily')}
					value={autoresponder_id}
					name="autoresponder_id"
					onChange={(val) => setAttributes({ autoresponder_id: val })}
					min={0}
					__nextHasNoMarginBottom
				/>
			</InspectorAdvancedControls>
			<div {...useBlockProps()}>
				<p>{title}</p>
				<form class="container" action={`https://${subdomain}.sendsmaily.net/api/opt-in/`} method="post" autocomplete="off">
					{show_name_field &&
						<p>
							<label>{name_input_label}</label>
							<br />
							<input type="text" name="name" value="" />
						</p>
					}
					<p>
						<label>{email_input_label}</label>
						<br />
						<input type="text" name="email" value="" required />
					</p>
					<p>
						<Button variant="primary" type="submit">{subscribe_button_label}</Button>
					</p>
				</form>
			</div>
		</>
	);
}
