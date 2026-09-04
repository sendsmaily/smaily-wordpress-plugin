import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { _resetApiClient, configureApiClient } from './client';
import { testSmailyConnection } from './testConnection';

describe('testSmailyConnection', () => {
  beforeEach(() => {
    _resetApiClient();
    configureApiClient({ restUrl: 'https://example.test/api', nonce: 'n' });
    vi.restoreAllMocks();
  });

  afterEach(() => {
    _resetApiClient();
    vi.restoreAllMocks();
  });

  it('POSTs the credentials to /test-smaily and returns the parsed body', async () => {
    const fetchSpy = vi.spyOn(global, 'fetch').mockResolvedValue(
      new Response(JSON.stringify({ connected: true, accountName: 'My Pet Shop' }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    );

    const result = await testSmailyConnection({
      subdomain: 'demo',
      username: 'alice',
      password: 's3cret',
    });

    expect(result).toEqual({ connected: true, accountName: 'My Pet Shop' });

    const [url, init] = fetchSpy.mock.calls[0]!;
    expect(url).toBe('https://example.test/api/test-smaily');
    expect((init as RequestInit).method).toBe('POST');
    expect(JSON.parse((init as RequestInit).body as string)).toEqual({
      subdomain: 'demo',
      username: 'alice',
      password: 's3cret',
    });
  });
});
