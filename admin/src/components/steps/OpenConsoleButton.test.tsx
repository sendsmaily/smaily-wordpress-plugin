import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi, type MockInstance } from 'vitest';

import { ApiError } from '../../api/client';
import { getConsoleEntry } from '../../api/consoleEntry';
import { wizardInitialState } from '../../state/wizard-reducer';
import { OpenConsoleButton } from './OpenConsoleButton';
import { Step4Recommendations } from './Step4Recommendations';

vi.mock('../../api/consoleEntry', () => ({ getConsoleEntry: vi.fn() }));

const entryMock = vi.mocked(getConsoleEntry);
const LINK = 'https://intelligence.smaily.com/console-entry#g=seg_1';
const BUTTON = 'Open Campaign Intelligence';

/** A tab window.open() hands back: a blank page the click opened. */
function fakeTab(): Window {
  return { opener: {}, location: { href: '' }, close: vi.fn() } as unknown as Window;
}

function refused(status: number, body: unknown): ApiError {
  return new ApiError(`POST /rec-engine/console-entry → ${status}`, status, body);
}

describe('OpenConsoleButton (CON-228)', () => {
  let openSpy: MockInstance<typeof window.open>;

  beforeEach(() => {
    vi.clearAllMocks();
    openSpy = vi.spyOn(window, 'open');
  });

  afterEach(() => {
    openSpy.mockRestore();
  });

  it('opens a blank tab in the click itself, cuts it off, then sends it to the link', async () => {
    const tab = fakeTab();
    openSpy.mockReturnValue(tab);
    let resolve: (value: { url: string; expires_at: string }) => void = () => undefined;
    entryMock.mockReturnValue(new Promise((r) => { resolve = r; }));

    render(<OpenConsoleButton />);
    fireEvent.click(screen.getByRole('button', { name: BUTTON }));

    // Synchronously, before the request answers: popup blockers allow it.
    expect(openSpy).toHaveBeenCalledWith('about:blank', '_blank');
    expect(tab.opener).toBeNull();
    expect(entryMock).toHaveBeenCalledTimes(1);

    resolve({ url: LINK, expires_at: '2026-10-27T09:05:00.000Z' });
    await waitFor(() => expect(tab.location.href).toBe(LINK));
    expect(tab.close).not.toHaveBeenCalled();
  });

  it('asks again on every click', async () => {
    openSpy.mockImplementation(() => fakeTab());
    entryMock.mockResolvedValue({ url: LINK, expires_at: '' });

    render(<OpenConsoleButton />);
    fireEvent.click(screen.getByRole('button', { name: BUTTON }));
    await waitFor(() => expect(screen.getByRole('button', { name: BUTTON })).not.toBeDisabled());
    fireEvent.click(screen.getByRole('button', { name: BUTTON }));

    await waitFor(() => expect(entryMock).toHaveBeenCalledTimes(2));
  });

  it('asks nothing when the browser blocks the tab', () => {
    openSpy.mockReturnValue(null);

    render(<OpenConsoleButton />);
    fireEvent.click(screen.getByRole('button', { name: BUTTON }));

    expect(entryMock).not.toHaveBeenCalled();
    expect(screen.getByText(/Your browser blocked the new tab/)).toBeInTheDocument();
  });

  it('on 404 closes the tab, hides the button and shows the console address', async () => {
    const tab = fakeTab();
    openSpy.mockReturnValue(tab);
    entryMock.mockRejectedValue(refused(404, { error: 'not_available', console_url: 'https://intelligence.smaily.com/login' }));

    render(<OpenConsoleButton />);
    fireEvent.click(screen.getByRole('button', { name: BUTTON }));

    const link = await screen.findByRole('link', { name: 'https://intelligence.smaily.com/login' });
    expect(link).toHaveAttribute('href', 'https://intelligence.smaily.com/login');
    expect(tab.close).toHaveBeenCalled();
    expect(screen.queryByRole('button', { name: BUTTON })).not.toBeInTheDocument();
  });

  it('on 404 without a console address says it is not available yet', async () => {
    openSpy.mockReturnValue(fakeTab());
    entryMock.mockRejectedValue(refused(404, { error: 'not_available' }));

    render(<OpenConsoleButton />);
    fireEvent.click(screen.getByRole('button', { name: BUTTON }));

    expect(await screen.findByText('Opening Campaign Intelligence from the plugin is not available yet.')).toBeInTheDocument();
    expect(screen.queryByRole('link')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: BUTTON })).not.toBeInTheDocument();
  });

  it.each([
    [502, { error: 'api_key_rejected' }, /Disconnect here and connect again with a new setup link/],
    [403, { error: 'tenant_inactive' }, /^This store is no longer active in Campaign Intelligence\.$/],
    [429, { error: 'rate_limited', retry_after: 37 }, /^Too many attempts\. Try again in 37 seconds\.$/],
    [429, { error: 'rate_limited', retry_after: null }, /^Too many attempts\. Try again in a minute\.$/],
    [400, { error: 'no_admin_email' }, /has no valid email address/],
    [403, { code: 'smaily_connect_forbidden', message: 'Your session has expired. Reload the page and try again.' }, /^Your session has expired/],
    [502, { error: 'engine_unavailable' }, /^Campaign Intelligence could not be opened\. Try again in a minute\.$/],
  ])('on %i %j closes the tab, keeps the button and shows its own message', async (status, body, text) => {
    const tab = fakeTab();
    openSpy.mockReturnValue(tab);
    entryMock.mockRejectedValue(refused(status, body));

    render(<OpenConsoleButton />);
    fireEvent.click(screen.getByRole('button', { name: BUTTON }));

    expect(await screen.findByText(text)).toBeInTheDocument();
    expect(tab.close).toHaveBeenCalled();
    expect(screen.getByRole('button', { name: BUTTON })).toBeInTheDocument();
  });

  it('a network failure gets the plain message', async () => {
    openSpy.mockReturnValue(fakeTab());
    entryMock.mockRejectedValue(new TypeError('Failed to fetch'));

    render(<OpenConsoleButton />);
    fireEvent.click(screen.getByRole('button', { name: BUTTON }));

    expect(await screen.findByText('Campaign Intelligence could not be opened. Try again in a minute.')).toBeInTheDocument();
  });
});

describe('Step4Recommendations — where the button shows (CON-228)', () => {
  const connected = {
    ...wizardInitialState,
    recEngineConnection: { kind: 'success', message: 'Example store' },
  } as typeof wizardInitialState;

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('shows on the Settings engine connection card while connected', () => {
    render(<Step4Recommendations state={connected} dispatch={vi.fn()} inSettings />);

    expect(screen.getByRole('button', { name: BUTTON })).toBeInTheDocument();
    expect(entryMock).not.toHaveBeenCalled();
  });

  it('does not show while not connected', () => {
    render(<Step4Recommendations state={wizardInitialState} dispatch={vi.fn()} inSettings />);

    expect(screen.queryByRole('button', { name: BUTTON })).not.toBeInTheDocument();
  });

  it('does not show in the setup wizard', () => {
    render(<Step4Recommendations state={connected} dispatch={vi.fn()} />);

    expect(screen.queryByRole('button', { name: BUTTON })).not.toBeInTheDocument();
  });
});
