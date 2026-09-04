import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { _resetApiClient, ApiError, configureApiClient } from './client';
import { saveSettings } from './saveSettings';

describe('saveSettings', () => {
  beforeEach(() => {
    _resetApiClient();
    configureApiClient({ restUrl: 'https://example.test/api', nonce: 'n' });
    vi.restoreAllMocks();
  });

  afterEach(() => {
    _resetApiClient();
    vi.restoreAllMocks();
  });

  it('POSTs the tab slice to /settings and returns the parsed body', async () => {
    const fetchSpy = vi.spyOn(global, 'fetch').mockResolvedValue(
      new Response(JSON.stringify({ saved: true, errors: [] }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    );

    const result = await saveSettings({
      tab: 'connection',
      data: { subdomain: 'demo' },
    });

    expect(result).toEqual({ saved: true, errors: [] });

    const [url, init] = fetchSpy.mock.calls[0]!;
    expect(url).toBe('https://example.test/api/settings');
    expect((init as RequestInit).method).toBe('POST');
    expect(JSON.parse((init as RequestInit).body as string)).toEqual({
      tab: 'connection',
      data: { subdomain: 'demo' },
    });
  });

  it('throws an ApiError carrying the validation body when the endpoint rejects the slice', async () => {
    vi.spyOn(global, 'fetch').mockResolvedValue(
      new Response(
        JSON.stringify({
          saved: false,
          errors: [{ field: 'subdomain', message: 'Subdomain is required.' }],
        }),
        { status: 400, headers: { 'Content-Type': 'application/json' } },
      ),
    );

    const error = await saveSettings({ tab: 'connection', data: {} }).catch(
      (err: unknown) => err,
    );

    expect(error).toBeInstanceOf(ApiError);
    expect((error as ApiError).status).toBe(400);
    expect((error as ApiError).body).toEqual({
      saved: false,
      errors: [{ field: 'subdomain', message: 'Subdomain is required.' }],
    });
  });

  it('propagates a transport failure to the caller', async () => {
    vi.spyOn(global, 'fetch').mockRejectedValue(new Error('Network down'));

    await expect(
      saveSettings({ tab: 'connection', data: { subdomain: 'demo' } }),
    ).rejects.toThrow('Network down');
  });
});
