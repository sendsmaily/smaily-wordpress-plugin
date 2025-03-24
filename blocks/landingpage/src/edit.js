import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { useState, useEffect } from '@wordpress/element';
import { isURL } from '@wordpress/url';

import {
	PanelBody,
	__experimentalNumberControl as NumberControl,
	TextControl,
	Notice,
} from '@wordpress/components';

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

	const parsePKFromURL = (url) => {
		if (url === '') {
			return false;
		}

		if (!isURL(url)) {
			return false;
		}


		const urlObj = new URL(url);
		if (urlObj.protocol !== 'https:') {
			return false;
		}

		if (urlObj.hostname.includes('sendsmaily.net') === false) {
			return false;
		}

		if (urlObj.pathname.includes('/landing-pages/') === false) {
			return false;
		}

		// Smaily landing page URL pattern.
		// https://<subdomain>.sendsmaily.net/landing-pages/<pk>/html/
		const pk = urlObj.pathname
			.split('/landing-pages/')
			.pop()
			.split('/')
			.shift();

		if (!pk) {
			return false;
		}

		return pk;
	};

	const handleChangeURL = (value) => {
		if (value === '' ) {
			setAttributes({ landingpagePK: '' });
			setAttributes({ url: '' });
			setError('');
			return;
		}

		const pk = parsePKFromURL(value);
		if (pk === false) {
			setAttributes({ landingpagePK: '' });
			setError(__('Invalid URL.', 'smaily'));
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
				{attributes.url === '' && <SetupSection />}
				{attributes.landingpagePK !== '' && (
					<iframe
						src={generateLandingPageURL(attributes.subdomain, attributes.landingpagePK)}
					/>
				)}
				{error !== '' && (
					<Notice status="error" isDismissible={false}>
						{error}
					</Notice>
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
						<Notice status="error" isDismissible={false}>
							{error}
						</Notice>
					)}
					<NumberControl
						label={__('Height', 'smaily')}
						value={attributes.height}
						onChange={(value) => {
							setAttributes({
								height: value,
							});
						}}
						min={0}
					/>
					<NumberControl
						label={__('Width', 'smaily')}
						value={attributes.width}
						onChange={(value) => {
							setAttributes({
								width: value,
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

	return (
		<div {...blockProps}>
			<iframe src={attributes.url} />
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
				)}
				{' '}
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

const generateLandingPageURL = (subdomain, pk) => {
	return `https://${subdomain}.sendsmaily.net/landing-pages/${pk}/html/`;
};
