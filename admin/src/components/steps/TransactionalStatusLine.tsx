import { formatUtc } from '@admin/lib/format-utc';
import { __, sprintf } from '@admin/lib/i18n';
import { transactionalReasonMessage } from '@admin/lib/transactional-reasons';
import { useWorkflows } from '../../hooks/useWorkflows';
import { type TransactionalTrigger, type WizardState } from '../../state/types';

export interface TransactionalStatusLineProps {
  state: WizardState;
  trigger: TransactionalTrigger;
  /** The Smaily account the trigger's workflows live on. */
  accountKey: string;
}

/**
 * The status line on an order/shipping confirmation card (PRO-4091): the
 * first reason it does not send, else when it last sent — plus, for the
 * shipping confirmation, a warning when recent orders never reach a ticked
 * status. The reason comes from the server's send gate
 * (state.transactionalStatus); only "the workflow isn't active in Smaily"
 * is decided here, from the workflow list the card's menu already loaded.
 *
 * Settings only: the line describes what is saved, and only a Settings
 * save refreshes it.
 */
export function TransactionalStatusLine({
  state,
  trigger,
  accountKey,
}: TransactionalStatusLineProps): React.JSX.Element {
  const { workflows, status: workflowsStatus } = useWorkflows(accountKey);
  const status = state.inSettings ? state.transactionalStatus[trigger] : undefined;

  let reason = status?.reason ?? '';
  if (status !== undefined && status.workflowResolved && workflowsStatus === 'success') {
    const active = new Set(workflows.filter((w) => w.status !== 'INACTIVE').map((w) => w.id));
    const inactive = state.automationMappings.some(
      (m) => m.triggerType === trigger && m.accountKey === accountKey && !active.has(m.workflowId),
    );
    if (inactive) {
      reason = 'workflow_inactive';
    }
  }

  const statusNames = new Map((state.env.orderStatuses ?? []).map((s) => [s.slug, s.name]));
  const unticked = status?.untickedRecentStatuses ?? [];

  return (
    <div className="mt-4 space-y-2 text-sm">
      {status !== undefined && (
        <p className={reason !== '' ? 'font-medium text-warning-soft-text' : 'text-text-secondary'}>
          {reason !== ''
            ? sprintf(
                // translators: %s is why the email is not sent, e.g. "No workflow is chosen for this email."
                __('Not sending: %s', 'smaily-connect'),
                transactionalReasonMessage(reason),
              )
            : status.lastSentAt !== ''
              ? sprintf(
                  // translators: %s is the date and time of the last send.
                  __('Last sent %s.', 'smaily-connect'),
                  formatUtc(status.lastSentAt),
                )
              : __('Nothing sent in the last 30 days.', 'smaily-connect')}
        </p>
      )}
      {reason === '' && unticked.length > 0 && (
        <p className="font-medium text-warning-soft-text">
          {sprintf(
            // translators: %s is a comma-separated list of order statuses, e.g. "Processing, Label printed".
            __(
              'No order in the last 30 days reached a status ticked under “Counts as shipped”. Recent orders are at: %s. Nothing is sent until orders reach a ticked status.',
              'smaily-connect',
            ),
            unticked.map((slug) => statusNames.get(slug) ?? slug).join(', '),
          )}
        </p>
      )}
      <p className="text-xs text-text-tertiary">
        {__(
          'This email goes out through Smaily’s transactional sending: Smaily sends the chosen workflow’s email straight away, so delays set in the workflow do not apply and the workflow shows no runs. Each send, and each confirmation that could not be sent, is listed in the Event Log.',
          'smaily-connect',
        )}
      </p>
    </div>
  );
}
