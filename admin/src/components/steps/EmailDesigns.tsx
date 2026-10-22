import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';

import { __, sprintf } from '@admin/lib/i18n';
import { getDesignLink, getEmailDesigns, type EmailDesign } from '../../api/emailDesigns';
import { Banner, Button } from '../primitives';

/** §23 kinds; an item of any other kind is ignored. */
const KNOWN_KINDS = ['engine', 'plugin', 'transactional'];

interface EmailDesignsContextValue {
  /** The last §23 read by email key; null while there is none to show. */
  designs: Map<string, EmailDesign> | null;
  /** Read §23 again the next time the store comes back to this tab. */
  awaitReturn: (waiting: boolean) => void;
}

const EmailDesignsContext = createContext<EmailDesignsContextValue | null>(null);

/**
 * Reads the engine's email designs (§23) for the email cards inside it —
 * when the cards open and again when the store returns from the design
 * page (CON-173). A failed read shows no button anywhere; a disabled
 * provider (Campaign Intelligence not connected) reads nothing.
 */
export function EmailDesignsProvider({
  enabled,
  children,
}: {
  enabled: boolean;
  children: React.ReactNode;
}): React.JSX.Element {
  const [designs, setDesigns] = useState<Map<string, EmailDesign> | null>(null);
  const [reads, setReads] = useState(0);
  const waiting = useRef(false);

  useEffect(() => {
    if (!enabled) {
      setDesigns(null);
      return undefined;
    }
    const controller = new AbortController();
    getEmailDesigns(controller.signal)
      .then((body) => {
        const items = Array.isArray(body?.emails) ? body.emails : [];
        setDesigns(new Map(items.filter((item) => KNOWN_KINDS.includes(item.kind)).map((item) => [item.email_key, item])));
      })
      .catch(() => {
        if (!controller.signal.aborted) {
          setDesigns(null);
        }
      });
    return () => controller.abort();
  }, [enabled, reads]);

  useEffect(() => {
    const onReturn = (): void => {
      if (!waiting.current || document.visibilityState === 'hidden') {
        return;
      }
      waiting.current = false;
      setReads((n) => n + 1);
    };
    window.addEventListener('focus', onReturn);
    document.addEventListener('visibilitychange', onReturn);
    return () => {
      window.removeEventListener('focus', onReturn);
      document.removeEventListener('visibilitychange', onReturn);
    };
  }, []);

  const awaitReturn = useCallback((value: boolean) => {
    waiting.current = value;
  }, []);
  const value = useMemo(() => ({ designs, awaitReturn }), [designs, awaitReturn]);

  return <EmailDesignsContext.Provider value={value}>{children}</EmailDesignsContext.Provider>;
}

/**
 * "Edit design" for one email card, with the design state and subject the
 * engine last returned. Renders nothing unless the last §23 read names the
 * email.
 *
 * Pop-up blockers allow a new tab only from the click itself, and the link
 * comes from an async request: so the click opens a blank tab at once,
 * cuts it off from this page, and sends it to the link when it arrives.
 */
export function EditDesign({ emailKey }: { emailKey: string }): React.JSX.Element | null {
  const context = useContext(EmailDesignsContext);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const design = context?.designs?.get(emailKey);
  if (context === null || design === undefined) {
    return null;
  }

  const handleClick = (): void => {
    setError(null);
    const tab = window.open('', '_blank');
    if (tab === null) {
      setError(__( 'Your browser blocked the new tab. Allow pop-ups for this page and try again.', 'smaily-connect' ));
      return;
    }
    tab.opener = null;
    context.awaitReturn(true);
    setPending(true);
    getDesignLink(emailKey, window.location.href)
      .then(({ url }) => {
        tab.location.href = url;
      })
      .catch(() => {
        tab.close();
        context.awaitReturn(false);
        setError(__( 'The design page could not be opened. Try again in a minute.', 'smaily-connect' ));
      })
      .finally(() => setPending(false));
  };

  const state = designStateLabel(design.design_state);

  return (
    <div className="mt-4">
      <div className="flex flex-wrap items-center gap-3">
        <Button variant="secondary" size="sm" type="button" onClick={handleClick} disabled={pending}>
          { __( 'Edit design', 'smaily-connect' ) }
        </Button>
        <span className="text-xs text-text-tertiary">
          {state}
          {state !== '' && design.subject !== null && design.subject !== '' && ' · '}
          {design.subject !== null && design.subject !== '' && sprintf(
            // translators: %s is the email's subject line.
            __( 'Subject: %s', 'smaily-connect' ),
            design.subject,
          )}
        </span>
      </div>
      {error !== null && (
        <div className="mt-2">
          <Banner tone="danger">{error}</Banner>
        </div>
      )}
    </div>
  );
}

function designStateLabel(state: string): string {
  switch (state) {
    case 'none':
      return __( 'No design saved yet', 'smaily-connect' );
    case 'starter':
      return __( 'Starter design', 'smaily-connect' );
    case 'edited':
      return __( 'Design edited', 'smaily-connect' );
    case 'check_failed':
      return __( 'Design check failed — open the design to fix it', 'smaily-connect' );
    default:
      return '';
  }
}
