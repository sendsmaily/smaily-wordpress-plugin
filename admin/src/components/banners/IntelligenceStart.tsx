import { useCallback, useEffect, useId, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

import { __ } from '@admin/lib/i18n';
import { startOnboarding, type OnboardingStartRefusal } from '../../api/onboarding';
import { setupExchange, type SetupExchangeSuccess } from '../../api/recEngine';
import { Button } from '../primitives';

/**
 * Milliseconds the start window may take to say `ready` before the frame is
 * removed and a new window is offered (contract §21 "The modal").
 */
export const START_READY_TIMEOUT_MS = 10000;

/** Where "Try Intelligence free" stands (CON-175). */
export type StartPhase =
  | { kind: 'idle' }
  /** The plugin asks the engine for a start link. */
  | { kind: 'connecting' }
  /** The start window is in the frame over the admin. */
  | { kind: 'frame'; url: string; origin: string }
  /** The start window is in a new window. */
  | { kind: 'window'; origin: string }
  /** The frame did not open: a new window is offered. */
  | { kind: 'fallback' }
  /** The browser refused the new window. */
  | { kind: 'blocked' }
  /** The engine or the plugin refused the start. */
  | { kind: 'refused'; refusal: OnboardingStartRefusal };

export type ExchangeStatus =
  | { status: 'idle' }
  | { status: 'pending' }
  | { status: 'failed'; message: string };

export interface IntelligenceStart {
  phase: StartPhase;
  exchange: ExchangeStatus;
  /** "Try Intelligence free": a frame where this admin allows one, else a new window. */
  start: () => void;
  /** "Open in a new window" — a fresh start link. */
  openWindow: () => void;
  /** The frame's close control, its `closed` message, or the 10-second wait. */
  closeFrame: (fallback: boolean) => void;
}

/**
 * "Try Intelligence free" (contract §21, CON-175). The plugin's server asks
 * the engine for a one-time start link; this opens it in a frame over the
 * admin (`frameable`) or in a new window, and takes the setup token the
 * start window posts — from the engine's origin only — to the setup-exchange
 * route the paste field uses. A frame's connection is reported when the
 * frame closes, so the window's welcome step stays on screen until then.
 */
export function useIntelligenceStart(
  frameable: boolean,
  onConnected: (response: SetupExchangeSuccess) => void,
): IntelligenceStart {
  const [phase, setPhase] = useState<StartPhase>({ kind: 'idle' });
  const [exchange, setExchange] = useState<ExchangeStatus>({ status: 'idle' });
  // Each start counts up, so the answer to an earlier one is dropped.
  const ticket = useRef(0);
  const exchanging = useRef(false);
  // A frame's connection, reported when the frame closes.
  const connected = useRef<SetupExchangeSuccess | null>(null);
  const latest = useRef({ onConnected, phase });
  useEffect(() => {
    latest.current = { onConnected, phase };
  });

  const openWindow = useCallback((): void => {
    // Pop-up blockers allow a new window only from the click itself, and the
    // link comes from an async request: open a blank window now, send it to
    // the link when it arrives. Its opener stays — the start window posts
    // the setup token to it.
    const tab = window.open('', '_blank');
    if (tab === null) {
      setPhase({ kind: 'blocked' });
      return;
    }
    const current = ++ticket.current;
    setPhase({ kind: 'connecting' });
    void startOnboarding('window').then((answer) => {
      if (current !== ticket.current) {
        tab.close();
        return;
      }
      if (answer.ok) {
        tab.location.href = answer.start_url;
        setPhase({ kind: 'window', origin: answer.engine_origin });
      } else {
        tab.close();
        setPhase({ kind: 'refused', refusal: answer });
      }
    });
  }, []);

  const openFrame = useCallback((): void => {
    const current = ++ticket.current;
    setPhase({ kind: 'connecting' });
    void startOnboarding('frame').then((answer) => {
      if (current !== ticket.current) {
        return;
      }
      if (answer.ok && answer.display === 'frame') {
        setPhase({ kind: 'frame', url: answer.start_url, origin: answer.engine_origin });
      } else if (answer.ok || answer.error === 'frame_origin_not_allowed') {
        // No frame from this engine: a new window, from the admin's next click.
        setPhase({ kind: 'fallback' });
      } else {
        setPhase({ kind: 'refused', refusal: answer });
      }
    });
  }, []);

  const closeFrame = useCallback((fallback: boolean): void => {
    ticket.current++;
    const done = connected.current;
    connected.current = null;
    setPhase(fallback && done === null ? { kind: 'fallback' } : { kind: 'idle' });
    if (done !== null) {
      latest.current.onConnected(done);
    }
  }, []);

  const origin = phase.kind === 'frame' || phase.kind === 'window' ? phase.origin : null;

  // `{type: 'smaily_intelligence_setup', setup_token, setup_url}` from the
  // engine's origin: the setup link goes to the existing exchange.
  useEffect(() => {
    if (origin === null) {
      return undefined;
    }
    const onMessage = (event: MessageEvent): void => {
      const data: unknown = event.data;
      if (event.origin !== origin || typeof data !== 'object' || data === null) {
        return;
      }
      const message = data as { type?: unknown; setup_token?: unknown; setup_url?: unknown };
      if (message.type !== 'smaily_intelligence_setup' || exchanging.current) {
        return;
      }
      let setupUrl = '';
      if (typeof message.setup_url === 'string' && message.setup_url.startsWith(`${origin}/setup/`)) {
        setupUrl = message.setup_url;
      } else if (typeof message.setup_token === 'string' && message.setup_token !== '') {
        setupUrl = `${origin}/setup/${message.setup_token}`;
      }
      if (setupUrl === '') {
        return;
      }
      exchanging.current = true;
      setExchange({ status: 'pending' });
      void setupExchange({ setupUrl }).then((response) => {
        exchanging.current = false;
        if (!response.connected) {
          setExchange({ status: 'failed', message: response.message });
          return;
        }
        setExchange({ status: 'idle' });
        if (latest.current.phase.kind === 'frame') {
          connected.current = response;
          return;
        }
        ticket.current++;
        setPhase({ kind: 'idle' });
        latest.current.onConnected(response);
      });
    };
    window.addEventListener('message', onMessage);
    return () => window.removeEventListener('message', onMessage);
  }, [origin]);

  return { phase, exchange, start: frameable ? openFrame : openWindow, openWindow, closeFrame };
}

/**
 * The window over the darkened admin that holds the start window in a frame
 * (contract §21 "The modal"). Esc, the close button and a click on the
 * darkened page close it — before `created` that creates nothing, after it
 * the store exists and the import goes on. The start window's `ready` ends
 * the loading state; without it in 10 seconds the frame is removed and a
 * new window is offered. Its `closed` closes this window. Only messages
 * from the engine's origin count.
 */
export function StartWindow({
  url,
  origin,
  onClose,
}: {
  url: string;
  origin: string;
  /** True when the start window did not say `ready` in time. */
  onClose: (timedOut: boolean) => void;
}): React.JSX.Element {
  const titleId = useId();
  const dialogRef = useRef<HTMLDivElement>(null);
  const closeRef = useRef<HTMLButtonElement>(null);
  const [ready, setReady] = useState(false);
  const handlers = useRef({ onClose });
  useEffect(() => {
    handlers.current = { onClose };
  });

  useEffect(() => {
    closeRef.current?.focus();
  }, []);

  useEffect(() => {
    if (ready) {
      return undefined;
    }
    const timer = window.setTimeout(() => handlers.current.onClose(true), START_READY_TIMEOUT_MS);
    return () => window.clearTimeout(timer);
  }, [ready]);

  useEffect(() => {
    const onMessage = (event: MessageEvent): void => {
      const data: unknown = event.data;
      if (event.origin !== origin || typeof data !== 'object' || data === null) {
        return;
      }
      const message = data as { type?: unknown; event?: unknown };
      if (message.type !== 'smaily_intelligence_onboarding') {
        return;
      }
      if (message.event === 'ready' || message.event === 'created') {
        setReady(true);
      } else if (message.event === 'closed') {
        handlers.current.onClose(false);
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
        handlers.current.onClose(false);
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
    document.addEventListener('keydown', onKeyDown);
    return () => document.removeEventListener('keydown', onKeyDown);
  }, []);

  const title = __('Campaign Intelligence', 'smaily-connect');

  return createPortal(
    <div className="fixed inset-0 z-[100000] flex items-center justify-center p-4 font-sans">
      {/* The darkened page closes the window on a click; the keyboard closes it by Esc or the close button. */}
      <button
        type="button"
        tabIndex={-1}
        aria-hidden="true"
        className="absolute inset-0 h-full w-full cursor-default bg-text-primary/60"
        onClick={() => onClose(false)}
      />
      <div
        ref={dialogRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        className="relative z-10 flex h-full max-h-[760px] w-full max-w-[720px] flex-col overflow-hidden rounded-lg bg-surface shadow-xl"
      >
        <div className="flex items-center justify-between gap-4 border-b border-border px-4 py-3">
          <h2 id={titleId} className="text-lg font-semibold text-text-primary">{title}</h2>
          <Button ref={closeRef} variant="ghost" size="sm" type="button" onClick={() => onClose(false)}>
            {__('Close', 'smaily-connect')}
          </Button>
        </div>
        <div className="relative min-h-0 flex-1 overflow-auto">
          {!ready && (
            <div role="status" className="absolute inset-0 flex items-center justify-center gap-2 text-sm text-text-secondary">
              <span className="scp-spin h-4 w-4 rounded-full border-2 border-current border-t-transparent" aria-hidden />
              {__('Opening the setup window…', 'smaily-connect')}
            </div>
          )}
          {/* §21: at least 560 × 640 (it scrolls inside a smaller frame), no
              referrer, no sandbox. The link is set once and never reused. */}
          <iframe
            title={title}
            src={url}
            referrerPolicy="no-referrer"
            className="h-full min-h-[640px] w-full min-w-[560px] border-0"
          />
        </div>
      </div>
    </div>,
    document.body,
  );
}

