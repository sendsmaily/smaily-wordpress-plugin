import { afterEach, describe, expect, it, vi } from 'vitest';
import { decide, marketingConsentGiven } from './marketing-consent';

/**
 * PRO-3849: marketing consent counts only as an explicit yes — a consent
 * banner set a WP Consent API consent type AND the visitor said yes to the
 * category. Mirrors the PHP MarketingConsent rule (PRO-3845).
 */

describe('marketing-consent: decide', () => {
  it('gives consent only with a consent type and a yes', () => {
    expect(decide('optin', true)).toBe(true);
    expect(decide('optout', true)).toBe(true);
  });

  it('gives no consent without a consent type', () => {
    expect(decide('', true)).toBe(false);
    expect(decide(undefined, true)).toBe(false);
    expect(decide(null, true)).toBe(false);
    expect(decide(false, true)).toBe(false);
  });

  it('gives no consent without a yes', () => {
    expect(decide('optin', false)).toBe(false);
    expect(decide('optin', undefined)).toBe(false);
    expect(decide('optin', 'true')).toBe(false);
    expect(decide('optin', 1)).toBe(false);
  });

  it('gives no consent for a consent type of the wrong type', () => {
    expect(decide(1, true)).toBe(false);
    expect(decide({ type: 'optin' }, true)).toBe(false);
  });
});

describe('marketing-consent: marketingConsentGiven', () => {
  afterEach(() => {
    delete window.wp_has_consent;
    delete window.wp_consent_type;
    delete window.wp_fallback_consent_type;
  });

  it('gives no consent without the WP Consent API', () => {
    window.wp_consent_type = 'optin';
    expect(marketingConsentGiven('marketing')).toBe(false);
  });

  it('gives no consent when no banner set a consent type, although the API says yes', () => {
    window.wp_fallback_consent_type = '';
    window.wp_has_consent = vi.fn(() => true);
    expect(marketingConsentGiven('marketing')).toBe(false);
  });

  it('gives consent when the banner set a consent type and the visitor said yes', () => {
    window.wp_consent_type = 'optin';
    window.wp_has_consent = vi.fn((category: string) => category === 'marketing');
    expect(marketingConsentGiven('marketing')).toBe(true);
    expect(window.wp_has_consent).toHaveBeenCalledWith('marketing');
  });

  it('gives no consent when the visitor did not say yes to the category', () => {
    window.wp_consent_type = 'optin';
    window.wp_has_consent = vi.fn((category: string) => category === 'statistics');
    expect(marketingConsentGiven('marketing')).toBe(false);
  });

  it('reads the server-side consent type when the banner set none in the browser', () => {
    window.wp_fallback_consent_type = 'optin';
    window.wp_has_consent = vi.fn(() => true);
    expect(marketingConsentGiven('marketing')).toBe(true);
  });

  it('prefers the banner consent type over the server-side one', () => {
    window.wp_consent_type = '';
    window.wp_fallback_consent_type = 'optin';
    window.wp_has_consent = vi.fn(() => true);
    expect(marketingConsentGiven('marketing')).toBe(false);
  });

  it('gives no consent when the API cannot be read', () => {
    window.wp_consent_type = 'optin';
    window.wp_has_consent = vi.fn(() => {
      throw new Error('broken banner');
    });
    expect(marketingConsentGiven('marketing')).toBe(false);
  });
});
