import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { useState, useEffect } from '@wordpress/element';
import { isURL } from '@wordpress/url';

import { PanelBody, TextControl, Notice } from '@wordpress/components';

export const Edit = ({ attributes, setAttributes }) => {
	const [error, setError] = useState('');

	const blockProps = useBlockProps({
		className: 'smaily-wp-connect-landingpage-block-edit-wrapper',
		style: {
			height: attributes.height,
			width: attributes.width,
		},
	});

	useEffect(() => {
		(async () => {
			const config = await apiFetch({
				path: '/smaily/v1/configuration',
			});
			setAttributes({ subdomain: config.subdomain });
		})();
	}, [setAttributes]);

	const handleChangeURL = async (value) => {
		if (value === '') {
			setAttributes({
				landingpagePK: '',
				url: '',
				height: 450,
				width: 500,
			});
			setError('');
			return;
		}

		const { valid, pk, message } = parsePKFromURL(value);
		if (!valid) {
			setAttributes({ landingpagePK: '' });
			setError(message);
		} else {
			setError('');
			setAttributes({
				landingpagePK: pk,
			});
		}

		setAttributes({
			url: value,
		});
	};

	if (attributes.subdomain === '') {
		return (
			<div {...blockProps}>
				<Notice status="info" isDismissible={false}>
					{__('Please configure the plugin first.', 'smaily')}
				</Notice>
			</div>
		);
	}

	return (
		<>
			<div {...blockProps}>
				{error === '' && !isURL(attributes.url) && <SetupSection />}
				{error !== '' && <ErrorSection message={error} />}
				{attributes.url !== '' && attributes.landingpagePK !== '' && (
					<iframe
						loading="lazy"
						title={__('Smaily Landing Page', 'smaily')}
						src={generateLandingPageURL(
							attributes.subdomain,
							attributes.landingpagePK
						)}
					/>
				)}
			</div>
			<InspectorControls>
				<PanelBody title={__('Settings', 'smaily')}>
					<TextControl
						value={attributes.url}
						label={__('URL', 'smaily')}
						onChange={handleChangeURL}
						help={__(
							'Enter the URL of the Smaily landing page.',
							'smaily'
						)}
						placeholder={__('Landing page URL', 'smaily')}
					/>
					{error !== '' && (
						<p className="smaily-wp-connect-landingpage-block-error">
							<em>{__('Invalid landing page URL!', 'smaily')}</em>
						</p>
					)}
					<TextControl
						className="components-base-control"
						label={__('Height', 'smaily')}
						type="number"
						value={attributes.height}
						onChange={(value) => {
							setAttributes({
								height: Number(value),
							});
						}}
						min={0}
					/>
					<TextControl
						className="components-base-control"
						label={__('Width', 'smaily')}
						type="number"
						value={attributes.width}
						onChange={(value) => {
							setAttributes({
								width: Number(value),
							});
						}}
						min={0}
					/>
				</PanelBody>
			</InspectorControls>
		</>
	);
};

export const Save = ({ attributes }) => {
	const blockProps = useBlockProps.save({
		className: 'smaily-wp-connect-landingpage-block-front-wrapper',
		style: {
			height: attributes.height,
			width: attributes.width,
		},
	});

	if (attributes.landingpagePK === '') {
		return <SetupSection />;
	}

	return (
		<div {...blockProps}>
			<iframe
				src={attributes.url}
				title={__('Smaily Landing Page', 'smaily')}
			/>
		</div>
	);
};

const SetupSection = () => {
	return (
		<div className="smaily-wp-connect-landingpage-block-edit-setup">
			<h3>{__('Smaily WP Connect Landing Page', 'smaily')}</h3>
			<p>
				{__(
					'Copy the URL of the landing page you want to display in this block and paste it in the Block settings.',
					'smaily'
				)}
			</p>
			<p>
				{__(
					'If you need any help setting up the landing page, follow our awesome guide:',
					'smaily'
				)}{' '}
				<a
					href="https://smaily.com/help/user-manual/landing-pages/creating-landing-pages/"
					target="_blank"
					rel="noreferrer"
				>
					{__('creating a landing page', 'smaily')}
				</a>
				.
			</p>
		</div>
	);
};

const ErrorSection = (props) => {
	return (
		<div className="smaily-wp-connect-landingpage-block-edit-error">
			<h3>{__('Invalid Landing Page URL!', 'smaily')}</h3>
			<p className="smaily-wp-connect-landingpage-block-error">
				{props.message}
			</p>
		</div>
	);
};

const generateLandingPageURL = (subdomain, pk) => {
	return `https://${subdomain}.sendsmaily.net/landing-pages/${pk}/html/`;
};

const parsePKFromURL = (url) => {
	if (typeof url !== 'string' || !url.trim()) {
		return { valid: false, message: __('URL is empty.', 'smaily') };
	}

	try {
		const urlObj = new URL(url);
		if (urlObj.protocol !== 'https:') {
			return {
				valid: false,
				message: __('URL must use HTTPS protocol.', 'smaily'),
			};
		}

		if (!urlObj.hostname.endsWith('sendsmaily.net')) {
			return {
				valid: false,
				message: __(
					'URL must originate from sendsmaily.net domain.',
					'smaily'
				),
			};
		}

		if (urlObj.pathname.includes('/landing-pages/') === false) {
			return {
				valid: false,
				message: __('URL must contain a landing page path.', 'smaily'),
			};
		}

		// Smaily landing page URL pattern.
		// https://<subdomain>.sendsmaily.net/landing-pages/<pk>/html/
		const pk = urlObj.pathname.split('/landing-pages/')[1]?.split('/')[0];

		if (!pk || !/^[a-zA-Z0-9_-]+$/.test(pk)) {
			return {
				valid: false,
				message: __(
					'Could not extract landing page ID from URL.',
					'smaily'
				),
			};
		}

		return { valid: true, pk };
	} catch (error) {
		return {
			valid: false,
			message: __('Please enter a valid URL!', 'smaily'),
		};
	}
};
