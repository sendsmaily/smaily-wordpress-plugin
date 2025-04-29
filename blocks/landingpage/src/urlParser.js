import { __ } from '@wordpress/i18n';

/**
 * Generates the landing page URL based on the subdomain and PK.
 *
 * @param {string} subdomain Subdomain of the Smaily account.
 * @param {string} pk        PK of the landing page.
 *
 * @return {string} The generated landing page URL.
 */
export const generateLandingPageURL = (subdomain, pk) => {
	if (!pk) {
		return '';
	}

	return `https://${subdomain}.sendsmaily.net/landing-pages/${pk}/html/`;
};

/**
 * Valid Landing Page URL object.
 *
 * @typedef  {Object}  LandingPageURL
 * @property {boolean} valid Whether the URL is valid or not.
 * @property {string}  pk    The PK of the landing page.
 */

/**
 * Invalid Landing Page URL object.
 *
 * @typedef  {Object}  InvalidLandingPageURL
 * @property {boolean} valid   Whether the URL is valid or not.
 * @property {string}  message The error message.
 */

/**
 * Validates the landing page URL and extracts the PK.
 *
 * @param {string} url       URL to validate
 * @param {string} subdomain Subdomain of the Smaily account.
 *
 * @return {LandingPageURL|InvalidLandingPageURL} Object containing the validation result and the PK.
 */
export const validateLandingPageURL = (url, subdomain) => {
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

		if (urlObj.hostname !== `${subdomain}.sendsmaily.net`) {
			return {
				valid: false,
				message: __(
					"Landing page doesn't originate from your account.",
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

		const pk = findPKFromURL(urlObj.pathname);
		if (!pk) {
			return {
				valid: false,
				message: __(
					'URL must contain a valid landing page PK.',
					'smaily'
				),
			};
		}

		return { valid: true, pk };
	} catch (error) {
		return {
			valid: false,
			message: __('Please enter a valid URL.', 'smaily'),
		};
	}
};

const findPKFromURL = (url) => {
	// Smaily landing page URL pattern.
	// https://<subdomain>.sendsmaily.net/landing-pages/<pk>/html/
	const pk = url.split('/landing-pages/')[1]?.split('/')[0];
	if (!pk) {
		return null;
	}

	// Check if the PK is a valid UUID (version 4).
	const isUUID =
		/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/;

	if (!isUUID.test(pk)) {
		return null;
	}

	return pk;
};
