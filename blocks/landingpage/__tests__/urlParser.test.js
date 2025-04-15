import {
	validateLandingPageURL,
	generateLandingPageURL,
} from '../src/urlParser';

test('validating landing page URL', () => {
	const PK = '88e52375-5e6b-4879-b2d6-db1f67765978';

	const { valid, pk, message } = validateLandingPageURL(
		`https://example.sendsmaily.net/landing-pages/${PK}/html/`
	);
	expect(message).toBeUndefined();
	expect(valid).toBe(true);
	expect(pk).toBe(PK);
});

test('sandbox URL is passed as is', () => {
	const PK = '88e52375-5e6b-4879-b2d6-db1f67765978';
	const { valid, pk, message } = validateLandingPageURL(
		`https://devops.sendsmaily.sandbox/landing-pages/${PK}/html/`
	);
	expect(message).toBeUndefined();
	expect(valid).toBe(true);
	expect(pk).toBe(PK);
});

test('landing page URL does not use HTTPS', () => {
	const { valid, message } = validateLandingPageURL(
		'http://example.sendsmaily.net/landing-pages/12345/html/'
	);
	expect(valid).toBe(false);
	expect(message).toBe('URL must use HTTPS protocol.');
});

test('landing page URL does not originate from sendsmaily.net domain', () => {
	const { valid, message } = validateLandingPageURL(
		'https://example.com/landing-pages/12345/html/'
	);
	expect(valid).toBe(false);
	expect(message).toBe('URL must originate from sendsmaily.net domain.');
});

test('landing page URL does not contain landing page path', () => {
	const { valid, message } = validateLandingPageURL(
		'https://example.sendsmaily.net/some-other-path/'
	);
	expect(valid).toBe(false);
	expect(message).toBe('URL must contain a landing page path.');
});

test('landing page URL does not contain valid PK', () => {
	const { valid, message } = validateLandingPageURL(
		'https://example.sendsmaily.net/landing-pages/invalid-pk/html/'
	);
	expect(valid).toBe(false);
	expect(message).toBe('URL must contain a valid landing page PK.');
});

test('landing page URL is empty', () => {
	const { valid, message } = validateLandingPageURL('');
	expect(valid).toBe(false);
	expect(message).toBe('URL is empty.');
});

test('generating landing page URL', () => {
	const subdomain = 'subdomain';
	const pk = '88e52375-5e6b-4879-b2d6-db1f67765978';

	const url = generateLandingPageURL(subdomain, pk);
	expect(url).toBe(
		`https://${subdomain}.sendsmaily.net/landing-pages/${pk}/html/`
	);
});

test('generating landing page for sandbox', () => {
	const subdomain = 'sandbox';
	const pk = '88e52375-5e6b-4879-b2d6-db1f67765978';
	const url = generateLandingPageURL(subdomain, pk, true);
	expect(url).toBe(
		`https://devops.sendsmaily.sandbox/landing-pages/${pk}/html/`
	);
});
