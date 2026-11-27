import { apiRequest } from './client';

/**
 * The Dashboard's state (REST\DashboardEndpoint, CON-464): counts of the
 * plugin's own queues and the health check's stored state. Snake_case, the
 * route's own field names.
 */
export interface DashboardState {
  failed_24h: number;
  contact_syncs_30d: number;
  catalog_items_30d: number;
  queued_today: number;
  /** Why the health check found Smaily refusing the saved account; '' when it does not. */
  smaily_refusal: '' | 'plan_blocked' | 'credentials_rejected';
  engine_down: boolean;
  /** The engine's latest answer to this connection refused its stored key (401). */
  engine_key_rejected: boolean;
  /** Nights with no product list sent to Campaign Intelligence; 0 below the warning's three. */
  unsent_product_list_nights: number;
  background: {
    running: boolean;
    /** UTC "Y-m-d H:i:s", '' when none of our actions ever ran. */
    last_run: string;
    wp_cron_disabled: boolean;
  };
}

export function getDashboardState(signal?: AbortSignal): Promise<DashboardState> {
  return apiRequest<DashboardState>('/dashboard', { signal });
}
