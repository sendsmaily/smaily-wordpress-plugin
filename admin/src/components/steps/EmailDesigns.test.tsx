import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi, type MockInstance } from 'vitest';

import { ApiError } from '../../api/client';
import { getDesignLink, getEmailDesigns, type DesignLinkResponse, type EmailDesign } from '../../api/emailDesigns';
import { EditDesign, EmailDesignsProvider, READY_TIMEOUT_MS } from './EmailDesigns';

vi.mock('../../api/emailDesigns', async (importOriginal) => {
  const original = await importOriginal<Record<string, unknown>>();
  return { ...original, getEmailDesigns: vi.fn(), getDesignLink: vi.fn() };
});

const designsMock = vi.mocked(getEmailDesigns);
const linkMock = vi.mocked(getDesignLink);

function design(fields: Partial<EmailDesign> = {}): EmailDesign {
  return {
    email_key: 'plugin.welcome',
    kind: 'plugin',
    name_et: 'Tervituskiri',
    name_en: 'Welcome email',
    sent_through: 'workflow',
    design_state: 'none',
    subject: null,
    sender: null,
    version: 0,
    smaily_state: 'not_available',
    smaily_template_id: null,
    smaily_workflow_id: null,
    smaily_pushed_at: null,
    updated_at: null,
    ...fields,
  };
}

function renderButton(emailKey = 'plugin.welcome', enabled = true): ReturnType<typeof render> {
  return render(
    <EmailDesignsProvider enabled={enabled}>
      <EditDesign emailKey={emailKey} />
    </EmailDesignsProvider>,
  );
}

const ENGINE = 'https://intelligence.smaily.com';

/** A §22 answer, as the proxy passes it on: the one-time link and the engine's origin. */
function link(fields: Partial<DesignLinkResponse> = {}): DesignLinkResponse {
  return { url: `${ENGINE}/design/email/dl_1`, expires_at: '2026-10-23T08:05:00.000Z', engine_origin: ENGINE, ...fields };
}

/** A tab window.open() hands back: a blank page the click opened. */
function fakeTab(): Window {
  return { opener: {}, location: { href: '' }, close: vi.fn() } as unknown as Window;
}

describe('EditDesign', () => {
  let openSpy: MockInstance<typeof window.open>;

  beforeEach(() => {
    vi.clearAllMocks();
    openSpy = vi.spyOn(window, 'open');
  });

  afterEach(() => {
    openSpy.mockRestore();
    delete (window as unknown as { smailyConnectBoot?: unknown }).smailyConnectBoot;
  });

  it('shows no button and reads nothing while Campaign Intelligence is not connected', () => {
    renderButton('plugin.welcome', false);

    expect(screen.queryByRole('button', { name: 'Edit design' })).not.toBeInTheDocument();
    expect(designsMock).not.toHaveBeenCalled();
  });

  it('shows no button when the engine does not offer the design list', async () => {
    designsMock.mockRejectedValue(new ApiError('GET /rec-engine/emails/designs → 404', 404, { error: 'not_available' }));

    renderButton();

    await waitFor(() => expect(designsMock).toHaveBeenCalledTimes(1));
    expect(screen.queryByRole('button', { name: 'Edit design' })).not.toBeInTheDocument();
  });

  it('shows no button for an email the list does not name', async () => {
    designsMock.mockResolvedValue({ emails: [design()] });

    renderButton('plugin.first_order');

    await waitFor(() => expect(designsMock).toHaveBeenCalledTimes(1));
    expect(screen.queryByRole('button', { name: 'Edit design' })).not.toBeInTheDocument();
  });

  it('ignores an item of a kind it does not know', async () => {
    designsMock.mockResolvedValue({ emails: [design({ kind: 'something_new' })] });

    renderButton();

    await waitFor(() => expect(designsMock).toHaveBeenCalledTimes(1));
    expect(screen.queryByRole('button', { name: 'Edit design' })).not.toBeInTheDocument();
  });

  it('shows the button with the design state and subject of a listed email', async () => {
    designsMock.mockResolvedValue({ emails: [design({ design_state: 'edited', subject: 'Welcome aboard' })] });

    renderButton();

    expect(await screen.findByRole('button', { name: 'Edit design' })).toBeInTheDocument();
    expect(screen.getByText(/Design edited/)).toBeInTheDocument();
    expect(screen.getByText(/Subject: Welcome aboard/)).toBeInTheDocument();
  });

  it('opens the tab during the click and sends it to the link when it arrives', async () => {
    designsMock.mockResolvedValue({ emails: [design()] });
    let resolveLink: (value: DesignLinkResponse) => void = () => undefined;
    linkMock.mockReturnValue(new Promise((resolve) => { resolveLink = resolve; }));
    const tab = fakeTab();
    openSpy.mockReturnValue(tab);

    renderButton();
    fireEvent.click(await screen.findByRole('button', { name: 'Edit design' }));

    // Opened in the click itself (no pop-up block), before the link exists,
    // and cut off from this page.
    expect(openSpy).toHaveBeenCalledWith('', '_blank');
    expect(tab.opener).toBeNull();
    expect(linkMock).toHaveBeenCalledWith('plugin.welcome', window.location.href, 'tab');

    await act(async () => {
      resolveLink(link());
    });
    expect(tab.location.href).toBe('https://intelligence.smaily.com/design/email/dl_1');
  });

  it('reads the list again when the store comes back to this tab, and shows the new state', async () => {
    designsMock
      .mockResolvedValueOnce({ emails: [design()] })
      .mockResolvedValueOnce({ emails: [design({ design_state: 'edited', subject: 'New subject' })] });
    linkMock.mockResolvedValue(link());
    openSpy.mockReturnValue(fakeTab());

    renderButton();
    fireEvent.click(await screen.findByRole('button', { name: 'Edit design' }));
    await waitFor(() => expect(linkMock).toHaveBeenCalledTimes(1));

    expect(designsMock).toHaveBeenCalledTimes(1);
    act(() => {
      window.dispatchEvent(new Event('focus'));
    });

    expect(await screen.findByText(/Subject: New subject/)).toBeInTheDocument();
    expect(designsMock).toHaveBeenCalledTimes(2);

    // Only the return from the design page reads again.
    act(() => {
      window.dispatchEvent(new Event('focus'));
    });
    expect(designsMock).toHaveBeenCalledTimes(2);
  });

  it('says so when the browser blocks the new tab, and asks for no link', async () => {
    designsMock.mockResolvedValue({ emails: [design()] });
    openSpy.mockReturnValue(null);

    renderButton();
    fireEvent.click(await screen.findByRole('button', { name: 'Edit design' }));

    expect(await screen.findByText(/blocked the new tab/)).toBeInTheDocument();
    expect(linkMock).not.toHaveBeenCalled();
  });

  it('closes the tab and says so when the link cannot be made', async () => {
    designsMock.mockResolvedValue({ emails: [design()] });
    linkMock.mockRejectedValue(new ApiError('POST /rec-engine/emails/design-link → 502', 502, { error: 'rate_limit_exceeded' }));
    const tab = fakeTab();
    openSpy.mockReturnValue(tab);

    renderButton();
    fireEvent.click(await screen.findByRole('button', { name: 'Edit design' }));

    expect(await screen.findByText(/could not be opened/)).toBeInTheDocument();
    expect(tab.close).toHaveBeenCalled();

    act(() => {
      window.dispatchEvent(new Event('focus'));
    });
    expect(designsMock).toHaveBeenCalledTimes(1);
  });

  it('opens no page that is not on the engine origin', async () => {
    designsMock.mockResolvedValue({ emails: [design()] });
    linkMock.mockResolvedValue(link({ url: 'https://elsewhere.example/design/email/dl_1' }));
    const tab = fakeTab();
    openSpy.mockReturnValue(tab);

    renderButton();
    fireEvent.click(await screen.findByRole('button', { name: 'Edit design' }));

    expect(await screen.findByText(/could not be opened/)).toBeInTheDocument();
    expect(tab.close).toHaveBeenCalled();
    expect(tab.location.href).toBe('');
  });

  it('opens a new tab, not a window, when the server says the page cannot be framed', async () => {
    setBoot(false);
    designsMock.mockResolvedValue({ emails: [design()] });
    linkMock.mockResolvedValue(link());
    openSpy.mockReturnValue(fakeTab());

    renderButton();
    fireEvent.click(await screen.findByRole('button', { name: 'Edit design' }));

    expect(openSpy).toHaveBeenCalledWith('', '_blank');
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  });
});

/** The boot blob the server writes; only the frame decision matters here. */
function setBoot(designFrameable: boolean): void {
  (window as unknown as { smailyConnectBoot?: unknown }).smailyConnectBoot = { designFrameable };
}

describe('EditDesign in a window over the admin (CON-288)', () => {
  let openSpy: MockInstance<typeof window.open>;

  beforeEach(() => {
    vi.clearAllMocks();
    setBoot(true);
    openSpy = vi.spyOn(window, 'open');
    designsMock.mockResolvedValue({ emails: [design()] });
    linkMock.mockResolvedValue(link());
  });

  afterEach(() => {
    openSpy.mockRestore();
    vi.useRealTimers();
    delete (window as unknown as { smailyConnectBoot?: unknown }).smailyConnectBoot;
  });

  /**
   * Click "Edit design" and wait for the design page's frame. With
   * `fakeClock`, the click and everything after it run on a fake clock.
   */
  async function openWindow(fakeClock = false): Promise<HTMLIFrameElement> {
    renderButton();
    const button = await screen.findByRole('button', { name: 'Edit design' });
    if (fakeClock) {
      vi.useFakeTimers();
    }
    fireEvent.click(button);
    // The link arrives on the microtask queue, not on a timer.
    await act(async () => {
      await Promise.resolve();
    });
    const frame = screen.getByRole('dialog', { name: 'Edit design' }).querySelector('iframe');
    expect(frame).not.toBeNull();
    return frame as HTMLIFrameElement;
  }

  function post(origin: string, data: unknown): void {
    act(() => {
      window.dispatchEvent(new MessageEvent('message', { origin, data }));
    });
  }

  it('opens the window at once with a loading state, then shows the link in a frame', async () => {
    let resolveLink: (value: DesignLinkResponse) => void = () => undefined;
    linkMock.mockReturnValue(new Promise((resolve) => { resolveLink = resolve; }));

    renderButton();
    fireEvent.click(await screen.findByRole('button', { name: 'Edit design' }));

    const dialog = screen.getByRole('dialog', { name: 'Edit design' });
    expect(dialog).toHaveAttribute('aria-modal', 'true');
    expect(screen.getByText('Opening the design page…')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Close' })).toHaveFocus();
    expect(openSpy).not.toHaveBeenCalled();
    // A frame link: its session lives in the page, not in a cookie (CON-311).
    expect(linkMock).toHaveBeenCalledWith('plugin.welcome', window.location.href, 'frame');

    await act(async () => {
      resolveLink(link());
    });
    const frame = dialog.querySelector('iframe');
    expect(frame).toHaveAttribute('src', `${ENGINE}/design/email/dl_1`);
    // §22 Embedding rule 6.
    expect(frame).toHaveAttribute('allow', 'clipboard-read; clipboard-write; fullscreen');
    expect(frame).toHaveAttribute('referrerpolicy', 'no-referrer');
    expect(frame).not.toHaveAttribute('sandbox');
  });

  it('mints a new frame link for every opening', async () => {
    linkMock
      .mockResolvedValueOnce(link())
      .mockResolvedValueOnce(link({ url: `${ENGINE}/design/email/dl_2` }));
    await openWindow();
    fireEvent.click(screen.getByRole('button', { name: 'Close' }));

    fireEvent.click(screen.getByRole('button', { name: 'Edit design' }));
    await act(async () => {
      await Promise.resolve();
    });

    expect(linkMock).toHaveBeenCalledTimes(2);
    expect(linkMock).toHaveBeenLastCalledWith('plugin.welcome', window.location.href, 'frame');
    expect(screen.getByRole('dialog').querySelector('iframe')).toHaveAttribute('src', `${ENGINE}/design/email/dl_2`);
  });

  it.each([
    ['Esc', (): void => { fireEvent.keyDown(document, { key: 'Escape' }); }],
    ['the close button', (): void => { fireEvent.click(screen.getByRole('button', { name: 'Close' })); }],
    ['a click on the darkened page', (): void => { fireEvent.click(screen.getByRole('dialog').previousElementSibling as HTMLElement); }],
  ])('closes on %s, gives focus back to the button and reads the list again', async (_name, close) => {
    await openWindow();
    expect(designsMock).toHaveBeenCalledTimes(1);

    act(close);

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Edit design' })).toHaveFocus();
    await waitFor(() => expect(designsMock).toHaveBeenCalledTimes(2));
  });

  it('keeps keyboard focus inside the window', async () => {
    const frame = await openWindow();
    const newTab = screen.getByRole('button', { name: 'Open in a new tab' });
    const close = screen.getByRole('button', { name: 'Close' });

    // Tab past the last item (the frame) wraps to the first, Shift+Tab back.
    frame.focus();
    fireEvent.keyDown(document, { key: 'Tab' });
    expect(newTab).toHaveFocus();
    fireEvent.keyDown(document, { key: 'Tab', shiftKey: true });
    expect(frame).toHaveFocus();

    // Focus that lands outside the window comes back into it.
    const outside = document.createElement('button');
    document.body.appendChild(outside);
    outside.focus();
    expect(close).toHaveFocus();
    outside.remove();
  });

  it('reads the list again on a saved message from the engine origin and keeps the window open', async () => {
    designsMock
      .mockResolvedValueOnce({ emails: [design()] })
      .mockResolvedValueOnce({ emails: [design({ design_state: 'edited', subject: 'New subject' })] });
    await openWindow();

    post(ENGINE, { type: 'smaily_intelligence_design', event: 'saved', email_key: 'plugin.welcome', version: 2 });

    expect(await screen.findByText(/Subject: New subject/)).toBeInTheDocument();
    expect(screen.getByRole('dialog')).toBeInTheDocument();
  });

  it('closes and reads the list again on a closed message from the engine origin', async () => {
    await openWindow();

    post(ENGINE, { type: 'smaily_intelligence_design', event: 'closed', email_key: 'plugin.welcome' });

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    await waitFor(() => expect(designsMock).toHaveBeenCalledTimes(2));
  });

  it('ignores a message from another origin, of another type or with an unknown event', async () => {
    await openWindow();

    post('https://evil.example', { type: 'smaily_intelligence_design', event: 'closed' });
    post(`${ENGINE}.evil.example`, { type: 'smaily_intelligence_design', event: 'saved' });
    post(ENGINE, { type: 'something_else', event: 'closed' });
    post(ENGINE, 'smaily_intelligence_design');
    post(ENGINE, { type: 'smaily_intelligence_design', event: 'deleted' });
    post('https://evil.example', { type: 'smaily_intelligence_design', event: 'ready' });
    post('https://evil.example', { type: 'smaily_intelligence_design', event: 'changes', unsaved: true });

    expect(screen.getByRole('dialog')).toBeInTheDocument();
    // No `ready` from the engine: still loading. No `changes` from it: closes without asking.
    expect(screen.getByText('Opening the design page…')).toBeInTheDocument();
    const confirmSpy = vi.spyOn(window, 'confirm');
    fireEvent.click(screen.getByRole('button', { name: 'Close' }));
    expect(confirmSpy).not.toHaveBeenCalled();
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    confirmSpy.mockRestore();
    // Closing reads the list once; none of the messages above did.
    await waitFor(() => expect(designsMock).toHaveBeenCalledTimes(2));
  });

  it('offers a new tab when the page has not said it is ready 20 seconds after the frame got its link', async () => {
    const frame = await openWindow(true);
    const notOpened = 'The design page did not open.';

    // The iframe's load event says nothing: it fires for an expired link too.
    fireEvent.load(frame);
    act(() => {
      vi.advanceTimersByTime(READY_TIMEOUT_MS - 1);
    });
    expect(screen.queryByText(notOpened)).not.toBeInTheDocument();
    expect(screen.getByText('Opening the design page…')).toBeInTheDocument();

    act(() => {
      vi.advanceTimersByTime(1);
    });
    expect(screen.getByText(notOpened)).toBeInTheDocument();
    expect(screen.queryByText('Opening the design page…')).not.toBeInTheDocument();

    // The offer opens a fresh tab link.
    const tab = fakeTab();
    openSpy.mockReturnValue(tab);
    linkMock.mockResolvedValueOnce(link({ url: `${ENGINE}/design/email/dl_2` }));
    const offers = screen.getAllByRole('button', { name: 'Open in a new tab' });
    fireEvent.click(offers[offers.length - 1] as HTMLElement);
    await act(async () => {
      await Promise.resolve();
    });
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(linkMock).toHaveBeenLastCalledWith('plugin.welcome', window.location.href, 'tab');
    expect(tab.location.href).toBe(`${ENGINE}/design/email/dl_2`);
  });

  it('ends the loading state on ready and offers no new tab later', async () => {
    await openWindow(true);

    post(ENGINE, { type: 'smaily_intelligence_design', event: 'ready', email_key: 'plugin.welcome' });
    act(() => {
      vi.advanceTimersByTime(READY_TIMEOUT_MS);
    });

    expect(screen.queryByText('Opening the design page…')).not.toBeInTheDocument();
    expect(screen.queryByText('The design page did not open.')).not.toBeInTheDocument();
    // A second ready changes nothing.
    post(ENGINE, { type: 'smaily_intelligence_design', event: 'ready', email_key: 'plugin.welcome' });
    expect(screen.getByRole('dialog')).toBeInTheDocument();
  });

  it.each([
    ['Esc', (): void => { fireEvent.keyDown(document, { key: 'Escape' }); }],
    ['the close button', (): void => { fireEvent.click(screen.getByRole('button', { name: 'Close' })); }],
    ['a click on the darkened page', (): void => { fireEvent.click(screen.getByRole('dialog').previousElementSibling as HTMLElement); }],
  ])('asks before closing on %s while the page has unsaved changes', async (_name, close) => {
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);
    await openWindow();
    post(ENGINE, { type: 'smaily_intelligence_design', event: 'ready', email_key: 'plugin.welcome' });
    post(ENGINE, { type: 'smaily_intelligence_design', event: 'changes', email_key: 'plugin.welcome', unsaved: true });

    // "Cancel" keeps the window and the frame.
    act(close);
    expect(confirmSpy).toHaveBeenCalledWith('Close without saving?');
    expect(screen.getByRole('dialog')).toBeInTheDocument();

    // "OK" closes it.
    confirmSpy.mockReturnValue(true);
    act(close);
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    confirmSpy.mockRestore();
  });

  it('closes without asking once the changes are saved, and on the page\'s own Close', async () => {
    const confirmSpy = vi.spyOn(window, 'confirm');
    await openWindow();
    post(ENGINE, { type: 'smaily_intelligence_design', event: 'changes', email_key: 'plugin.welcome', unsaved: true });
    post(ENGINE, { type: 'smaily_intelligence_design', event: 'saved', email_key: 'plugin.welcome', version: 2 });
    post(ENGINE, { type: 'smaily_intelligence_design', event: 'changes', email_key: 'plugin.welcome', unsaved: false });

    fireEvent.click(screen.getByRole('button', { name: 'Close' }));
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();

    // The page asks its own "Leave without saving?" before it posts closed.
    fireEvent.click(screen.getByRole('button', { name: 'Edit design' }));
    await act(async () => {
      await Promise.resolve();
    });
    post(ENGINE, { type: 'smaily_intelligence_design', event: 'changes', email_key: 'plugin.welcome', unsaved: true });
    post(ENGINE, { type: 'smaily_intelligence_design', event: 'closed', email_key: 'plugin.welcome' });
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();

    expect(confirmSpy).not.toHaveBeenCalled();
    confirmSpy.mockRestore();
  });

  it('starts every opening without unsaved changes', async () => {
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true);
    await openWindow();
    post(ENGINE, { type: 'smaily_intelligence_design', event: 'changes', email_key: 'plugin.welcome', unsaved: true });
    fireEvent.click(screen.getByRole('button', { name: 'Close' }));
    expect(confirmSpy).toHaveBeenCalledTimes(1);

    fireEvent.click(screen.getByRole('button', { name: 'Edit design' }));
    await act(async () => {
      await Promise.resolve();
    });
    fireEvent.click(screen.getByRole('button', { name: 'Close' }));

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(confirmSpy).toHaveBeenCalledTimes(1);
    confirmSpy.mockRestore();
  });

  it('opens a fresh link in a new tab from the window', async () => {
    const tab = fakeTab();
    openSpy.mockReturnValue(tab);
    linkMock
      .mockResolvedValueOnce(link())
      .mockResolvedValueOnce(link({ url: `${ENGINE}/design/email/dl_2` }));
    await openWindow();

    fireEvent.click(screen.getByRole('button', { name: 'Open in a new tab' }));

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(openSpy).toHaveBeenCalledWith('', '_blank');
    expect(tab.opener).toBeNull();
    await waitFor(() => expect(tab.location.href).toBe(`${ENGINE}/design/email/dl_2`));
    expect(linkMock).toHaveBeenCalledTimes(2);
  });

  it('shows the error in the window and no frame when the link cannot be made or is not on the engine origin', async () => {
    for (const answer of [
      (): void => { linkMock.mockRejectedValueOnce(new ApiError('POST /rec-engine/emails/design-link → 502', 502, { error: 'rate_limit_exceeded' })); },
      (): void => { linkMock.mockResolvedValueOnce(link({ url: 'https://elsewhere.example/design/email/dl_1' })); },
    ]) {
      answer();
      const { unmount } = renderButton();
      fireEvent.click(await screen.findByRole('button', { name: 'Edit design' }));

      expect(await screen.findByText(/could not be opened/)).toBeInTheDocument();
      expect(screen.getByRole('dialog').querySelector('iframe')).toBeNull();
      expect(screen.queryByText('Opening the design page…')).not.toBeInTheDocument();
      unmount();
    }
  });
});
