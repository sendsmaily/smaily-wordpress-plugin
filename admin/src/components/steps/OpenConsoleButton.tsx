import { useState } from 'react';

import { __, sprintf } from '@admin/lib/i18n';
import { ApiError, errorMessage } from '../../api/client';
import { getConsoleEntry, type ConsoleEntryErrorBody } from '../../api/consoleEntry';
import { Banner, Button } from '../primitives';

type Notice =
  | { kind: 'error'; text: string }
  | { kind: 'not_available'; consoleUrl: string };

/**
 * "Open Campaign Intelligence" (contract §24, CON-228): opens the store's
 * console signed in, once, through a link the plugin's server asks the engine
 * for with the signed-in admin's own email.
 *
 * Pop-up blockers allow a new tab only from the click itself, and the link
 * comes from an async request: so the click opens a blank tab at once, cuts
 * it off from this page, and sends it to the link when it arrives. The link
 * is never kept, logged or sent anywhere else. Each click is one request.
 *
 * When the engine does not offer console entry yet (404), the button goes
 * away for the rest of the page view and the console address is shown.
 */
export function OpenConsoleButton(): React.JSX.Element {
  const [pending, setPending] = useState(false);
  const [hidden, setHidden] = useState(false);
  const [notice, setNotice] = useState<Notice | null>(null);

  const handleClick = (): void => {
    setNotice(null);
    const tab = window.open('about:blank', '_blank');
    if (tab === null) {
      setNotice({
        kind: 'error',
        text: __( 'Your browser blocked the new tab. Allow pop-ups for this page and try again.', 'smaily-connect' ),
      });
      return;
    }
    tab.opener = null;
    setPending(true);
    getConsoleEntry()
      .then(({ url }) => {
        tab.location.href = url;
      })
      .catch((err: unknown) => {
        tab.close();
        const body = err instanceof ApiError ? (err.body as ConsoleEntryErrorBody | null) : null;
        if (body?.error === 'not_available') {
          setHidden(true);
          setNotice({ kind: 'not_available', consoleUrl: typeof body.console_url === 'string' ? body.console_url : '' });
          return;
        }
        setNotice({ kind: 'error', text: failureText(err, body) });
      })
      .finally(() => setPending(false));
  };

  return (
    <div className="space-y-3">
      {!hidden && (
        <Button variant="primary" type="button" onClick={handleClick} disabled={pending} loading={pending}>
          { __( 'Open Campaign Intelligence', 'smaily-connect' ) }
        </Button>
      )}
      {notice?.kind === 'error' && <Banner tone="danger">{notice.text}</Banner>}
      {notice?.kind === 'not_available' && (
        <Banner tone="info">
          {notice.consoleUrl !== '' ? (
            <>
              { __( 'Opening Campaign Intelligence from the plugin is not available yet. Open the console and sign in there:', 'smaily-connect' ) }{' '}
              <a href={notice.consoleUrl} target="_blank" rel="noopener noreferrer" className="font-medium underline">
                {notice.consoleUrl}
              </a>
            </>
          ) : (
            __( 'Opening Campaign Intelligence from the plugin is not available yet.', 'smaily-connect' )
          )}
        </Banner>
      )}
    </div>
  );
}

function failureText(err: unknown, body: ConsoleEntryErrorBody | null): string {
  switch (body?.error) {
    case 'api_key_rejected':
      return __( 'Campaign Intelligence did not accept this store’s connection. Disconnect here and connect again with a new setup link from Campaign Intelligence.', 'smaily-connect' );
    case 'tenant_inactive':
      return __( 'This store is no longer active in Campaign Intelligence.', 'smaily-connect' );
    case 'rate_limited':
      return typeof body.retry_after === 'number' && body.retry_after > 0
        ? sprintf(
            // translators: %d is a number of seconds.
            __( 'Too many attempts. Try again in %d seconds.', 'smaily-connect' ),
            body.retry_after,
          )
        : __( 'Too many attempts. Try again in a minute.', 'smaily-connect' );
    case 'no_admin_email':
      return __( 'Your WordPress user has no valid email address. Add one to your profile and try again.', 'smaily-connect' );
    default:
      return errorMessage(err, __( 'Campaign Intelligence could not be opened. Try again in a minute.', 'smaily-connect' ));
  }
}
