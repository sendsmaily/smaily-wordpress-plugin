import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { _resetApiClient, configureApiClient } from './client';
import { listWorkflows } from './workflows';

describe('listWorkflows', () => {
  beforeEach(() => {
    _resetApiClient();
    configureApiClient({ restUrl: 'https://example.test/api', nonce: 'n' });
    vi.restoreAllMocks();
  });

  afterEach(() => {
    _resetApiClient();
    vi.restoreAllMocks();
  });

  it('GETs /workflows with the account_key query param', async () => {
    const fetchSpy = vi.spyOn(global, 'fetch').mockResolvedValue(
      new Response(
        JSON.stringify({
          workflows: [
            { id: '42', name: 'Welcome', status: 'ACTIVE' },
            { id: '99', name: 'Cart', status: 'ACTIVE' },
          ],
        }),
        { status: 200, headers: { 'Content-Type': 'application/json' } },
      ),
    );

    const result = await listWorkflows('et');

    expect(result.workflows).toHaveLength(2);
    expect(result.workflows[0]?.id).toBe('42');

    const [url, init] = fetchSpy.mock.calls[0]!;
    expect(url).toBe('https://example.test/api/workflows?account_key=et');
    expect((init as RequestInit).method ?? 'GET').toBe('GET');
  });

  it('defaults account_key to "default" when not supplied', async () => {
    const fetchSpy = vi.spyOn(global, 'fetch').mockResolvedValue(
      new Response(JSON.stringify({ workflows: [] }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    );

    await listWorkflows();

    expect(fetchSpy.mock.calls[0]![0]).toBe('https://example.test/api/workflows?account_key=default');
  });
});
