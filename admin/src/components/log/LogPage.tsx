import { __ } from '@admin/lib/i18n';
import { EventLog } from '../settings/EventLog';
import { VersionLine } from '../VersionLine';

/** The statuses the Event Log filters by — the only ones a link may open it on. */
const STATUSES = ['pending', 'sent', 'failed', 'blocked'];

/**
 * The Log page (CON-464): the Event Log on a page of its own, as the Log of
 * Magento and PrestaShop. `?status=failed` in the address opens it on the
 * failed rows — the Dashboard's "Review failures".
 */
export function LogPage(): React.JSX.Element {
  const requested = new URLSearchParams(window.location.search).get('status') ?? '';
  const initialStatus = STATUSES.includes(requested) ? requested : '';

  return (
    <div className="min-h-screen bg-page-bg font-sans text-text-primary">
      <div className="mx-auto max-w-5xl space-y-6 p-6">
        <header>
          <h1 className="text-2xl font-semibold">{__('Smaily Connect — Log', 'smaily-connect')}</h1>
        </header>
        <main>
          <EventLog initialStatus={initialStatus} />
        </main>
        <VersionLine />
      </div>
    </div>
  );
}
