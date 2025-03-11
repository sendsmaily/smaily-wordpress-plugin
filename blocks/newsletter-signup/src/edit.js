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
	RangeControl,
	SelectControl,
	Spinner,
	TextControl,
	ToggleControl,
} from '@wordpress/components';

const DEFAULT_BUTTON_TEXT_COLOR =
	'var(--wp-components-color-accent-inverted, #ffffff)';
const DEFAULT_BUTTON_BACKGROUND_COLOR = 'var(--wp-admin-theme-color, #007cba)';
const DEFAULT_BUTTON_WIDTH = 'auto';

export default function Edit({ attributes, setAttributes }) {
	const [autoresponders, setAutoresponders] = useState(null);

	const settingsURL = useRef();

	const blockProps = useBlockProps({
		className: 'wp-block-smaily-newsletter-block-wrapper',
		style: {
			'--smaily-subscribe-button-bg-color':
				attributes.subscribeButtonBackgroundColor,
			'--smaily-subscribe-button-text-color':
				attributes.subscribeButtonTextColor,
			'--smaily-subscribe-button-width': attributes.subscribeButtonWidth,
			'--smaily-subscribe-button-border-radius': `${attributes.subscribeButtonBorderRadius}px`,
		},
	});

	useEffect(() => {
		if (attributes.style?.elements?.button?.color?.background) {
			const colorCode = getColorCode(
				attributes.style.elements.button.color.background
			);
			setAttributes({ subscribeButtonBackgroundColor: colorCode });
		} else {
			setAttributes({
				subscribeButtonBackgroundColor: DEFAULT_BUTTON_BACKGROUND_COLOR,
			});
		}
	}, [attributes.style?.elements?.button?.color?.background, setAttributes]);

	useEffect(() => {
		if (attributes.style?.elements?.button?.color?.text) {
			const colorCode = getColorCode(
				attributes.style.elements.button.color.text
			);
			setAttributes({ subscribeButtonTextColor: colorCode });
		} else {
			setAttributes({
				subscribeButtonTextColor: DEFAULT_BUTTON_TEXT_COLOR,
			});
		}
	}, [attributes.style?.elements?.button?.color?.text, setAttributes]);

	const {
		subdomain,
		autoresponderId,
		emailInputLabel,
		errorMessage,
		errorURL,
		nameInputLabel,
		showNameField,
		subscribeButtonLabel,
		subscribeButtonWidth,
		successMessage,
		successURL,
	} = attributes;

	useEffect(() => {
		(async () => {
			const [ar, config] = await Promise.all([
				apiFetch({ path: '/smaily/v1/autoresponders' }),
				apiFetch({
					path: '/smaily/v1/configuration',
				}),
			]);
			setAutoresponders(ar);
			setAttributes({ subdomain: config.subdomain });
			settingsURL.current = config.settings_url;
		})();
	}, [setAttributes]);

	const handleRedirect = async () => {
		if (settingsURL.current) {
			window.location.href = settingsURL.current;
		}
	};

	if (autoresponders === null) {
		return <Spinner />;
	}

	if (subdomain === '') {
		return (
			<Notice
				status="error"
				isDismissible={false}
				actions={[
					{
						label: __('Go to plugin settings', 'smaily'),
						onClick: handleRedirect,
						variant: 'primary',
					},
				]}
			>
				<h3>{__('Plugin setup is not complete!', 'smaily')}</h3>
				<p>
					{__(
						'Please connect your Smaily account before adding a form!',
						'smaily'
					)}
				</p>
			</Notice>
		);
	}

	return (
		<>
			<Card isBorderless={true} {...blockProps}>
				<CardHeader
					className="smaily-newsletter-block-notice-container"
					style={{ flexDirection: 'column', alignItems: 'inherit' }}
				>
					{successMessage !== '' && (
						<Notice status="success" isDismissible={false}>
							{successMessage}
						</Notice>
					)}
					{errorMessage !== '' && (
						<Notice status="error" isDismissible={false}>
							{errorMessage}
						</Notice>
					)}
				</CardHeader>
				<CardBody>
					<form>
						{showNameField && (
							<TextControl
								type="text"
								name="name"
								label={nameInputLabel !== '' && nameInputLabel}
								value=""
							/>
						)}
						<TextControl
							type="email"
							name="email"
							label={emailInputLabel !== '' && emailInputLabel}
							value=""
							required
						/>
						<Button
							className="smaily-newsletter-block-button-submit"
							variant="primary"
							type="submit"
						>
							{subscribeButtonLabel}
						</Button>
					</form>
				</CardBody>
			</Card>
			<InspectorControls>
				<PanelBody title={__('Visible fields', 'smaily')}>
					<ToggleControl
						label={__('Display name field', 'smaily')}
						checked={showNameField}
						onChange={() =>
							setAttributes({
								showNameField: !showNameField,
							})
						}
						name="show_name"
					/>
					{showNameField && (
						<TextControl
							label={__('Name field label', 'smaily')}
							value={nameInputLabel}
							name="nameInputLabel"
							onChange={(val) =>
								setAttributes({ nameInputLabel: val })
							}
						/>
					)}
					<TextControl
						label={__('Email field label', 'smaily')}
						value={emailInputLabel}
						name="emailInputLabel"
						onChange={(val) =>
							setAttributes({ emailInputLabel: val })
						}
					/>
					<TextControl
						label={__('Subscribe button label', 'smaily')}
						value={subscribeButtonLabel}
						name="subscribeButtonLabel"
						onChange={(val) =>
							setAttributes({ subscribeButtonLabel: val })
						}
					/>
					<ToggleControl
						label={__('Full width subscribe button', 'smaily')}
						checked={subscribeButtonWidth === '100%'}
						onChange={(checked) =>
							setAttributes({
								subscribeButtonWidth: checked
									? '100%'
									: DEFAULT_BUTTON_WIDTH,
							})
						}
						name="show_name"
					/>
					<RangeControl
						label={__('Button border radius', 'smaily')}
						value={attributes.subscribeButtonBorderRadius}
						onChange={(value) => {
							setAttributes({
								subscribeButtonBorderRadius: value,
							});
						}}
						min={0}
					/>
					<TextControl
						label={__('Success message', 'smaily')}
						value={successMessage}
						name="successMessage"
						onChange={(val) =>
							setAttributes({ successMessage: val })
						}
					/>
					<TextControl
						label={__('Error message', 'smaily')}
						value={errorMessage}
						name="errorMessage"
						onChange={(val) => setAttributes({ errorMessage: val })}
					/>
				</PanelBody>
				<PanelBody
					title={__('Hidden fields', 'smaily')}
					initialOpen={false}
				>
					<TextControl
						label={__('Success URL', 'smaily')}
						value={successURL}
						name="successURL"
						onChange={(val) => setAttributes({ successURL: val })}
						help={__('Defaults to current page URL.', 'smaily')}
					/>
					<TextControl
						label={__('Failure URL', 'smaily')}
						value={errorURL}
						name="failure_url"
						onChange={(val) => setAttributes({ errorURL: val })}
						help={__('Defaults to current page URL.', 'smaily')}
					/>
					<SelectControl
						label={__('Autoresponder', 'smaily')}
						name="autoresponderId"
						value={autoresponderId}
						onChange={(val) =>
							setAttributes({ autoresponderId: val })
						}
						options={[
							{
								label: __('No autoresponder', 'smaily'),
								value: '',
							},
							...autoresponders,
						]}
					/>
				</PanelBody>
			</InspectorControls>
		</>
	);
}

function getColorCode(color) {
	if (typeof color !== 'string' || color === '') {
		return null;
	}

	if (color.startsWith('var:preset|')) {
		const colorCode = color
			.replace('var:preset|', '--wp--preset--')
			.replace('|', '--');
		return `var(${colorCode})`;
	}

	// HEX
	return color;
}
