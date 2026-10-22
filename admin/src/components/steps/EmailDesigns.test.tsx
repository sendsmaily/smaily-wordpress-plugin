import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { ApiError } from '../../api/client';
import { getDesignLink, getEmailDesigns, type EmailDesign } from '../../api/emailDesigns';
import { EditDesign, EmailDesignsProvider } from './EmailDesigns';

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

/** A tab window.open() hands back: a blank page the click opened. */
function fakeTab(): Window {
  return { opener: {}, location: { href: '' }, close: vi.fn() } as unknown as Window;
}

describe('EditDesign', () => {
  let openSpy: ReturnType<typeof vi.spyOn>;

  beforeEach(() => {
    vi.clearAllMocks();
    openSpy = vi.spyOn(window, 'open');
  });

  afterEach(() => {
    openSpy.mockRestore();
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
    let resolveLink: (value: { url: string; expires_at: string }) => void = () => undefined;
    linkMock.mockReturnValue(new Promise((resolve) => { resolveLink = resolve; }));
    const tab = fakeTab();
    openSpy.mockReturnValue(tab);

    renderButton();
    fireEvent.click(await screen.findByRole('button', { name: 'Edit design' }));

    // Opened in the click itself (no pop-up block), before the link exists,
    // and cut off from this page.
    expect(openSpy).toHaveBeenCalledWith('', '_blank');
    expect(tab.opener).toBeNull();
    expect(linkMock).toHaveBeenCalledWith('plugin.welcome', window.location.href);

    await act(async () => {
      resolveLink({ url: 'https://intelligence.smaily.com/design/email/dl_1', expires_at: '2026-10-23T08:05:00.000Z' });
    });
    expect(tab.location.href).toBe('https://intelligence.smaily.com/design/email/dl_1');
  });

  it('reads the list again when the store comes back to this tab, and shows the new state', async () => {
    designsMock
      .mockResolvedValueOnce({ emails: [design()] })
      .mockResolvedValueOnce({ emails: [design({ design_state: 'edited', subject: 'New subject' })] });
    linkMock.mockResolvedValue({ url: 'https://intelligence.smaily.com/design/email/dl_1', expires_at: 'x' });
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
});
