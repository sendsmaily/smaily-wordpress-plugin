import { createContext, useCallback, useContext, useEffect, useId, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

import { __, sprintf } from '@admin/lib/i18n';
import { getDesignLink, getEmailDesigns, type DesignLinkResponse, type EmailDesign } from '../../api/emailDesigns';
import { readBoot } from '../../state/hydrate';
import { Banner, Button } from '../primitives';

/** §23 kinds; an item of any other kind is ignored. */
const KNOWN_KINDS = ['engine', 'plugin', 'transactional'];

/** Milliseconds before the design window points at "Open in a new tab". */
export const HINT_DELAY_MS = 8000;

interface EmailDesignsContextValue {
  /** The last §23 read by email key; null while there is none to show. */
  designs: Map<string, EmailDesign> | null;
  /** Read §23 again the next time the store comes back to this tab. */
  awaitReturn: (waiting: boolean) => void;
  /** Read §23 again now. */
  reload: () => void;
  /** Open the design page in a window over the admin, not a new tab (CON-288). */
  frameable: boolean;
}

const EmailDesignsContext = createContext<EmailDesignsContextValue | null>(null);

/**
 * Reads the engine's email designs (§23) for the email cards inside it —
 * when the cards open, again when the store returns from the design page in
 * a tab (CON-173), and when the design window saves or closes (CON-288). A
 * failed read shows no button anywhere; a disabled provider (Campaign
 * Intelligence not connected) reads nothing.
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
  const [frameable] = useState(() => readBoot()?.designFrameable === true);
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
  const reload = useCallback(() => setReads((n) => n + 1), []);
  const value = useMemo(
    () => ({ designs, awaitReturn, reload, frameable }),
    [designs, awaitReturn, reload, frameable],
  );

  return <EmailDesignsContext.Provider value={value}>{children}</EmailDesignsContext.Provider>;
}

/** A link the admin opens: only a page on the engine's own origin, the origin whose messages it takes. */
function isEngineLink(link: DesignLinkResponse): boolean {
  return typeof link.engine_origin === 'string'
    && link.engine_origin !== ''
    && typeof link.url === 'string'
    && link.url.startsWith(`${link.engine_origin}/`);
}

/** The design window's content: the link is being minted, it arrived, or it failed. */
type DesignFrame =
  | { status: 'loading' }
  | { status: 'ready'; url: string; origin: string }
  | { status: 'failed' };

/**
 * "Edit design" for one email card, with the design state and subject the
 * engine last returned. Renders nothing unless the last §23 read names the
 * email.
 *
 * Where the engine lets this admin frame its design page (`frameable`,
 * decided on the server), the click opens a window over the admin at once
 * and shows the page in it when the one-time link arrives (CON-288).
 * Otherwise it opens a new tab: pop-up blockers allow a new tab only from
 * the click itself, and the link comes from an async request, so the click
 * opens a blank tab at once, cuts it off from this page, and sends it to the
 * link when it arrives.
 */
export function EditDesign({ emailKey }: { emailKey: string }): React.JSX.Element | null {
  const context = useContext(EmailDesignsContext);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [frame, setFrame] = useState<DesignFrame | null>(null);
  // Each open counts up, so the answer to an earlier open is dropped.
  const opening = useRef(0);
  const buttonRef = useRef<HTMLButtonElement>(null);
  const returnFocus = useRef(false);

  // Focus goes back to the button once the window is gone (while it is
  // open, it keeps focus inside itself).
  useEffect(() => {
    if (frame === null && returnFocus.current) {
      returnFocus.current = false;
      buttonRef.current?.focus();
    }
  }, [frame]);

  const design = context?.designs?.get(emailKey);
  if (context === null || design === undefined) {
    return null;
  }

  const failed = __( 'The design page could not be opened. Try again in a minute.', 'smaily-connect' );

  const openInTab = (): void => {
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
      .then((link) => {
        if (!isEngineLink(link)) {
          throw new Error('Not a link on the engine origin.');
        }
        tab.location.href = link.url;
      })
      .catch(() => {
        tab.close();
        context.awaitReturn(false);
        setError(failed);
      })
      .finally(() => setPending(false));
  };

  const openInFrame = (): void => {
    setError(null);
    const ticket = ++opening.current;
    setFrame({ status: 'loading' });
    getDesignLink(emailKey, window.location.href)
      .then((link) => {
        if (ticket === opening.current) {
          setFrame(isEngineLink(link) ? { status: 'ready', url: link.url, origin: link.engine_origin } : { status: 'failed' });
        }
      })
      .catch(() => {
        if (ticket === opening.current) {
          setFrame({ status: 'failed' });
        }
      });
  };

  // Closing ends the frame and reads the list again, unless the design page
  // moves to a tab, whose return reads it.
  const closeFrame = (reread: boolean): void => {
    opening.current++;
    returnFocus.current = true;
    setFrame(null);
    if (reread) {
      context.reload();
    }
  };

  const state = designStateLabel(design.design_state);

  return (
    <div className="mt-4">
      <div className="flex flex-wrap items-center gap-3">
        <Button
          ref={buttonRef}
          variant="secondary"
          size="sm"
          type="button"
          onClick={context.frameable ? openInFrame : openInTab}
          disabled={pending}
        >
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
      {frame !== null && (
        <DesignWindow
          frame={frame}
          error={failed}
          onClose={() => closeFrame(true)}
          onSaved={context.reload}
          onNewTab={() => {
            closeFrame(false);
            openInTab();
          }}
        />
      )}
    </div>
  );
}

/**
 * The window over the darkened admin that shows the design page in a frame
 * (CON-288, contract §22 Embedding). Esc, the close button and a click on
 * the darkened page close it; keyboard focus stays inside it. A frame the
 * browser refuses cannot be told apart from a slow one, so the header
 * always offers "Open in a new tab" (a fresh link: the frame's is used up),
 * and a hint points at it when the page has not loaded after 8 seconds.
 *
 * The design page posts {type: 'smaily_intelligence_design', event:
 * 'saved' | 'closed'} to this page (§22 Embedding rule 4). Only a message
 * from the engine's origin counts, and it is a hint to read §23 again —
 * never a state or an id to take from it.
 */
function DesignWindow({
  frame,
  error,
  onClose,
  onSaved,
  onNewTab,
}: {
  frame: DesignFrame;
  error: string;
  onClose: () => void;
  onSaved: () => void;
  onNewTab: () => void;
}): React.JSX.Element {
  const titleId = useId();
  const dialogRef = useRef<HTMLDivElement>(null);
  const closeRef = useRef<HTMLButtonElement>(null);
  const [loaded, setLoaded] = useState(false);
  const [hint, setHint] = useState(false);
  // The latest handlers, so the listeners below are added once.
  const handlers = useRef({ onClose, onSaved });
  useEffect(() => {
    handlers.current = { onClose, onSaved };
  });

  const origin = frame.status === 'ready' ? frame.origin : null;

  useEffect(() => {
    closeRef.current?.focus();
  }, []);

  useEffect(() => {
    if (origin === null || loaded) {
      return undefined;
    }
    const timer = window.setTimeout(() => setHint(true), HINT_DELAY_MS);
    return () => window.clearTimeout(timer);
  }, [origin, loaded]);

  useEffect(() => {
    if (origin === null) {
      return undefined;
    }
    const onMessage = (event: MessageEvent): void => {
      const data: unknown = event.data;
      if (event.origin !== origin || typeof data !== 'object' || data === null) {
        return;
      }
      const message = data as { type?: unknown; event?: unknown };
      if (message.type !== 'smaily_intelligence_design') {
        return;
      }
      if (message.event === 'closed') {
        handlers.current.onClose();
      } else if (message.event === 'saved') {
        handlers.current.onSaved();
      }
    };
    window.addEventListener('message', onMessage);
    return () => window.removeEventListener('message', onMessage);
  }, [origin]);

  useEffect(() => {
    const focusables = (): HTMLElement[] => Array.from(
      dialogRef.current?.querySelectorAll<HTMLElement>('button, iframe') ?? [],
    );
    const onKeyDown = (event: KeyboardEvent): void => {
      if (event.key === 'Escape') {
        event.preventDefault();
        handlers.current.onClose();
        return;
      }
      if (event.key !== 'Tab') {
        return;
      }
      const items = focusables();
      const first = items[0];
      const last = items[items.length - 1];
      if (first === undefined || last === undefined) {
        return;
      }
      const inside = dialogRef.current?.contains(document.activeElement) === true;
      if (event.shiftKey && (document.activeElement === first || !inside)) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && (document.activeElement === last || !inside)) {
        event.preventDefault();
        first.focus();
      }
    };
    const onFocusIn = (event: FocusEvent): void => {
      if (!(event.target instanceof Node) || dialogRef.current?.contains(event.target) !== true) {
        closeRef.current?.focus();
      }
    };
    document.addEventListener('keydown', onKeyDown);
    document.addEventListener('focusin', onFocusIn);
    return () => {
      document.removeEventListener('keydown', onKeyDown);
      document.removeEventListener('focusin', onFocusIn);
    };
  }, []);

  const title = __( 'Edit design', 'smaily-connect' );

  return createPortal(
    <div className="fixed inset-0 z-[100000] flex items-center justify-center p-4 font-sans">
      {/* The darkened page closes the window on a click; the keyboard closes it by Esc or the close button. */}
      <button
        type="button"
        tabIndex={-1}
        aria-hidden="true"
        className="absolute inset-0 h-full w-full cursor-default bg-text-primary/60"
        onClick={onClose}
      />
      <div
        ref={dialogRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        className="relative z-10 flex h-[90vh] w-full max-w-6xl flex-col overflow-hidden rounded-lg bg-surface shadow-xl"
      >
        <div className="flex items-center justify-between gap-4 border-b border-border px-4 py-3">
          <h2 id={titleId} className="text-lg font-semibold text-text-primary">{title}</h2>
          <div className="flex items-center gap-2">
            <Button variant="secondary" size="sm" type="button" onClick={onNewTab}>
              { __( 'Open in a new tab', 'smaily-connect' ) }
            </Button>
            <Button ref={closeRef} variant="ghost" size="sm" type="button" onClick={onClose}>
              { __( 'Close', 'smaily-connect' ) }
            </Button>
          </div>
        </div>
        {hint && (
          <Banner tone="warning" className="m-4 mb-0">
            { __( 'Is the design page not showing? Your browser may not allow it inside the WordPress admin. Press Open in a new tab.', 'smaily-connect' ) }
          </Banner>
        )}
        {frame.status === 'failed' && (
          <Banner tone="danger" className="m-4 mb-0">{error}</Banner>
        )}
        <div className="relative min-h-0 flex-1">
          {frame.status !== 'failed' && !loaded && (
            <div role="status" className="absolute inset-0 flex items-center justify-center gap-2 text-sm text-text-secondary">
              <span className="scp-spin h-4 w-4 rounded-full border-2 border-current border-t-transparent" aria-hidden />
              { __( 'Opening the design page…', 'smaily-connect' ) }
            </div>
          )}
          {frame.status === 'ready' && (
            <iframe
              title={title}
              src={frame.url}
              className="h-full w-full border-0"
              onLoad={() => {
                setLoaded(true);
                setHint(false);
              }}
            />
          )}
        </div>
      </div>
    </div>,
    document.body,
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
