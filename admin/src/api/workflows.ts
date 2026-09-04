import { apiRequest } from './client';

export interface Workflow {
  id: string;
  name: string;
  /** "ACTIVE" / "INACTIVE" from Smaily; empty when the API didn't surface one. */
  status: string;
}

export interface WorkflowsResponse {
  workflows: Workflow[];
  /** Optional error string when credentials are missing or Smaily rejects the request. */
  error?: string;
}

/**
 * GET /smaily-connect/v1/workflows?account_key=...
 *
 * Returns the autoresponder list for a given Smaily credential set.
 * Used by Step 3 to populate workflow dropdowns per (trigger, language)
 * row. Mode A passes the language-keyed account_key; Mode B/C use the
 * default account.
 */
export function listWorkflows(
  accountKey: string = 'default',
  signal?: AbortSignal,
): Promise<WorkflowsResponse> {
  return apiRequest<WorkflowsResponse>(
    `/workflows?account_key=${encodeURIComponent(accountKey)}`,
    { signal },
  );
}
