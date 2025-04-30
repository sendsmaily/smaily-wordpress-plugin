import {
	validateLandingPageURL,
	generateLandingPageURL,
} from '../src/urlParser';

test('validating landing page URL', () => {
	const PK = '88e52375-5e6b-4879-b2d6-db1f67765978';

	const { valid, pk } = validateLandingPageURL(
		`https://subdomain.sendsmaily.net/landing-pages/${PK}/html/`,
		'subdomain'
	);
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
	const { valid, pk } = validateLandingPageURL(
		`https://other.sendsmaily.net/landing-pages/${PK}/html/`,
		'testing'
	);
	expect(valid).toBe(false);
	expect(pk).toBe('');
});

test('landing page URL does not use HTTPS', () => {
	const { valid, pk } = validateLandingPageURL(
		'http://testing.sendsmaily.net/landing-pages/12345/html/',
		'testing'
	);
	expect(valid).toBe(false);
	expect(pk).toBe('');
});

test('landing page URL does not originate from sendsmaily.net domain', () => {
	const { valid, pk } = validateLandingPageURL(
		'https://testing.com/landing-pages/12345/html/',
		'testing'
	);
	expect(valid).toBe(false);
	expect(pk).toBe('');
});

test('landing page URL does not contain landing page path', () => {
	const { valid, pk } = validateLandingPageURL(
		'https://testing.sendsmaily.net/some-other-path/',
		'testing'
	);
	expect(valid).toBe(false);
	expect(pk).toBe('');
});

test('landing page URL does not contain valid PK', () => {
	const { valid, pk } = validateLandingPageURL(
		'https://testing.sendsmaily.net/landing-pages/invalid-pk/html/',
		'testing'
	);
	expect(valid).toBe(false);
	expect(pk).toBe('');
});

test('landing page URL is empty', () => {
	const { valid, pk } = validateLandingPageURL('');
	expect(valid).toBe(false);
	expect(pk).toBe('');
});
