import { apiRequest } from './client';

/**
 * Close a banner (REST\NoticesEndpoint, CON-350). The server stamps the
 * dismissal in the same map the admin notices' Dismiss links use; the key's
 * own cooldown decides when it returns.
 */
export function dismissNotice(key: 'ci_invitation'): Promise<{ dismissed: boolean }> {
  return apiRequest<{ dismissed: boolean }>('/notices/dismiss', { method: 'POST', body: { key } });
}
