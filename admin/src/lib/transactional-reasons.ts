import { __ } from '@admin/lib/i18n';

/**
 * Why a transactional email does not go out, worded for the merchant
 * (PRO-4091). The codes come from the server — the send gate's
 * TransactionalGate::REASON_*, TransactionalFlusher::REASON_NO_BILLING_EMAIL
 * and the follow-up email's due-time reasons (PRO-4095) — plus `workflow_inactive`, which only this screen can tell (it holds the
 * Smaily workflow list). One wording for both places that show a reason:
 * the card's status line and the Event Log's skipped row.
 */
export function transactionalReasonMessage(reason: string): string {
  switch (reason) {
    case 'switched_off':
      return __(
        'Transactional emails are switched off. Switch on “Send transactional emails” on the Connection tab.',
        'smaily-connect',
      );
    case 'trigger_off':
      return __('This email is switched off.', 'smaily-connect');
    case 'no_workflow':
      return __('No workflow is chosen for this email.', 'smaily-connect');
    case 'workflow_inactive':
      return __(
        'The chosen workflow is not active in Smaily. Activate it in Smaily or choose another workflow.',
        'smaily-connect',
      );
    case 'connection_incomplete':
      return __(
        'The transactional Smaily account is not fully connected. Check its subdomain, username and password on the Connection tab.',
        'smaily-connect',
      );
    case 'password_unreadable':
      return __(
        'The saved password of the transactional Smaily account can no longer be read. Re-enter the password on the Connection tab.',
        'smaily-connect',
      );
    case 'no_billing_email':
      return __('The order has no billing email address.', 'smaily-connect');
    case 'order_missing':
      return __('The order no longer exists.', 'smaily-connect');
    case 'order_closed':
      return __('The order was cancelled, refunded or failed before the follow-up email was due.', 'smaily-connect');
    case 'status_not_counting':
      return __(
        'When the wait ended, the order was no longer at a status ticked for the follow-up email.',
        'smaily-connect',
      );
    default:
      return reason;
  }
}
