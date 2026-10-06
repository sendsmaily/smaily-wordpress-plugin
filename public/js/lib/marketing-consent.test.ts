import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { decide, marketingConsentGiven, storedConsent } from './marketing-consent';

/**
 * PRO-3849: marketing consent counts only as an explicit yes — the WP Consent
 * API's consent cookie for the category is `allow` AND `wp_has_consent()` is
 * true. Mirrors the PHP MarketingConsent rule.
 */

function setCookie(name: string, value: string): void {
  document.cookie = `${name}=${value}; path=/`;
}

function clearCookie(name: string): void {
  document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`;
}

describe('marketing-consent: decide', () => {
  it('gives consent only with an allow cookie and a yes', () => {
    expect(decide(true, 'allow')).toBe(true);
  });

  it('gives no consent when wp_has_consent says yes without an allow cookie (no banner, opt-out default)', () => {
    expect(decide(true, null)).toBe(false);
    expect(decide(true, '')).toBe(false);
  });

  it('gives no consent for a deny', () => {
    expect(decide(true, 'deny')).toBe(false);
    expect(decide(false, 'deny')).toBe(false);
  });

  it('gives no consent when wp_has_consent says no', () => {
    expect(decide(false, 'allow')).toBe(false);
    expect(decide(undefined, 'allow')).toBe(false);
  });

  it('gives no consent for an unreadable value', () => {
    expect(decide('true', 'allow')).toBe(false);
    expect(decide(1, 'allow')).toBe(false);
    expect(decide(true, 'ALLOW')).toBe(false);
    expect(decide(true, true)).toBe(false);
  });
});

describe('marketing-consent: storedConsent and marketingConsentGiven', () => {
  beforeEach(() => {
    window.consent_api = { cookie_prefix: 'wp_consent' };
  });

  afterEach(() => {
    delete window.wp_has_consent;
    delete window.consent_api;
    for (const name of ['wp_consent_marketing', 'wp_consent_statistics', 'acme_marketing', 'xwp_consent_marketing']) {
      clearCookie(name);
    }
  });

  it('reads the consent cookie the WP Consent API writes', () => {
    setCookie('wp_consent_marketing', 'allow');
    setCookie('wp_consent_statistics', 'deny');
    expect(storedConsent('marketing')).toBe('allow');
    expect(storedConsent('statistics')).toBe('deny');
    expect(storedConsent('preferences')).toBeNull();
  });

  it('follows the cookie prefix the WP Consent API prints', () => {
    window.consent_api = { cookie_prefix: 'acme' };
    setCookie('acme_marketing', 'allow');
    expect(storedConsent('marketing')).toBe('allow');
  });

  it('does not match a cookie whose name only ends with the consent cookie name', () => {
    setCookie('xwp_consent_marketing', 'allow');
    expect(storedConsent('marketing')).toBeNull();
  });

  it('reads nothing without the WP Consent API settings', () => {
    delete window.consent_api;
    setCookie('wp_consent_marketing', 'allow');
    expect(storedConsent('marketing')).toBeNull();
  });

  it('gives no consent without the WP Consent API', () => {
    setCookie('wp_consent_marketing', 'allow');
    expect(marketingConsentGiven('marketing')).toBe(false);
  });

  it('gives consent when the banner stored allow and the API says yes', () => {
    setCookie('wp_consent_marketing', 'allow');
    window.wp_has_consent = vi.fn(() => true);
    expect(marketingConsentGiven('marketing')).toBe(true);
    expect(window.wp_has_consent).toHaveBeenCalledWith('marketing');
  });

  it('gives no consent when the API says yes but no banner stored a choice', () => {
    window.wp_has_consent = vi.fn(() => true);
    expect(marketingConsentGiven('marketing')).toBe(false);
  });

  it('gives no consent when the banner stored deny', () => {
    setCookie('wp_consent_marketing', 'deny');
    window.wp_has_consent = vi.fn(() => true);
    expect(marketingConsentGiven('marketing')).toBe(false);
  });

  it('checks the cookie of the category asked for', () => {
    setCookie('wp_consent_statistics', 'allow');
    window.wp_has_consent = vi.fn(() => true);
    expect(marketingConsentGiven('statistics')).toBe(true);
    expect(marketingConsentGiven('marketing')).toBe(false);
  });

  it('gives no consent when the API cannot be read', () => {
    setCookie('wp_consent_marketing', 'allow');
    window.wp_has_consent = vi.fn(() => {
      throw new Error('broken banner');
    });
    expect(marketingConsentGiven('marketing')).toBe(false);
  });
});
