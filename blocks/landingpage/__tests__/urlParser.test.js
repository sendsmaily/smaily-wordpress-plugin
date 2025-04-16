import {
	validateLandingPageURL,
	generateLandingPageURL,
} from '../src/urlParser';

test('validating landing page URL', () => {
	const PK = '88e52375-5e6b-4879-b2d6-db1f67765978';

	const { valid, pk, message } = validateLandingPageURL(
		`https://subdomain.sendsmaily.net/landing-pages/${PK}/html/`,
		'subdomain'
	);
	expect(message).toBeUndefined();
	expect(valid).toBe(true);
	expect(pk).toBe(PK);
});

test('generating landing page URL', () => {
	const subdomain = 'subdomain';
	const pk = '88e52375-5e6b-4879-b2d6-db1f67765978';

	const url = generateLandingPageURL(subdomain, pk);
	expect(url).toBe(
		`https://${subdomain}.sendsmaily.net/landing-pages/${pk}/html/`
	);
});

// Invariants

test('URL subdomain is not matching the user account subdomain', () => {
	const PK = '88e52375-5e6b-4879-b2d6-db1f67765978';
	const { valid, message } = validateLandingPageURL(
		`https://other.sendsmaily.net/landing-pages/${PK}/html/`,
		'testing'
	);
	expect(valid).toBe(false);
	expect(message).toBe(`Landing page doesn't originate from your account.`);
});

test('landing page URL does not use HTTPS', () => {
	const { valid, message } = validateLandingPageURL(
		'http://testing.sendsmaily.net/landing-pages/12345/html/',
		'testing'
	);
	expect(valid).toBe(false);
	expect(message).toBe('URL must use HTTPS protocol.');
});

test('landing page URL does not originate from sendsmaily.net domain', () => {
	const { valid, message } = validateLandingPageURL(
		'https://testing.com/landing-pages/12345/html/',
		'testing'
	);
	expect(valid).toBe(false);
	expect(message).toBe('URL must originate from sendsmaily.net domain.');
});

test('landing page URL does not contain landing page path', () => {
	const { valid, message } = validateLandingPageURL(
		'https://testing.sendsmaily.net/some-other-path/',
		'testing'
	);
	expect(valid).toBe(false);
	expect(message).toBe('URL must contain a landing page path.');
});

test('landing page URL does not contain valid PK', () => {
	const { valid, message } = validateLandingPageURL(
		'https://testing.sendsmaily.net/landing-pages/invalid-pk/html/',
		'testing'
	);
	expect(valid).toBe(false);
	expect(message).toBe('URL must contain a valid landing page PK.');
});

test('landing page URL is empty', () => {
	const { valid, message } = validateLandingPageURL('');
	expect(valid).toBe(false);
	expect(message).toBe('URL is empty.');
});
