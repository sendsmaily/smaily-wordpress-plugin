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
 * Landing Page URL object.
 *
 * @typedef  {Object}  LandingPageURL
 * @property {boolean} valid Whether the URL is valid or not.
 * @property {string}  pk    The PK of the landing page.
 */

/**
 * Validates the landing page URL and extracts the PK.
 *
 * @param {string} url       URL to validate
 * @param {string} subdomain Subdomain of the Smaily account.
 *
 * @return {LandingPageURL} Object containing the validation result and the PK.
 */
export const validateLandingPageURL = (url, subdomain) => {
	if (typeof url !== 'string' || !url.trim()) {
		return { valid: false, pk: '' };
	}

	try {
		const urlObj = new URL(url);

		if (urlObj.protocol !== 'https:') {
			return {
				valid: false,
				pk: '',
			};
		}

		if (!urlObj.hostname.endsWith('sendsmaily.net')) {
			return {
				valid: false,
				pk: '',
			};
		}

		if (urlObj.hostname !== `${subdomain}.sendsmaily.net`) {
			return {
				valid: false,
				pk: '',
			};
		}

		if (urlObj.pathname.includes('/landing-pages/') === false) {
			return {
				valid: false,
				pk: '',
			};
		}

		const pk = findPKFromURL(urlObj.pathname);
		if (!pk) {
			return {
				valid: false,
				pk: '',
			};
		}

		return { valid: true, pk };
	} catch (error) {
		return {
			valid: false,
			pk: '',
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
