import { apiRequest } from './client';

/**
 * Plugin-side proxies for the engine's email designs (contract §22–§23,
 * REST\EmailDesignsEndpoint — CON-173). Wire shapes are the contract's
 * snake_case field names.
 */

/** One §23 item. `kind` and `smaily_state` may carry values this plugin does not know yet. */
export interface EmailDesign {
  email_key: string;
  kind: string;
  name_et: string;
  name_en: string;
  sent_through: string;
  design_state: string;
  subject: string | null;
  sender: { from_name: string; from_address: string } | null;
  version: number;
  smaily_state: string;
  smaily_template_id: string | null;
  smaily_workflow_id: string | null;
  smaily_pushed_at: string | null;
  updated_at: string | null;
}

export interface EmailDesignsResponse {
  emails: EmailDesign[];
}

export interface DesignLinkResponse {
  /** One-time link, valid 5 minutes — open it at once, never keep it. */
  url: string;
  expires_at: string;
}

/** GET the store's email designs. Throws ApiError when the engine cannot answer. */
export function getEmailDesigns(signal?: AbortSignal): Promise<EmailDesignsResponse> {
  return apiRequest<EmailDesignsResponse>('/rec-engine/emails/designs', { signal });
}

/**
 * Ask for a one-time link to the design page of one email. `returnUrl` is
 * where the page's back button goes; the server leaves it out when the
 * engine would refuse it.
 */
export function getDesignLink(emailKey: string, returnUrl: string): Promise<DesignLinkResponse> {
  return apiRequest<DesignLinkResponse>('/rec-engine/emails/design-link', {
    method: 'POST',
    body: { email_key: emailKey, return_url: returnUrl },
  });
}
