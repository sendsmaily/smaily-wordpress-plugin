import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi, type MockInstance } from 'vitest';

import { getOnboardingStatus, startOnboarding, type OnboardingStartResponse } from '../../api/onboarding';
import { setupExchange, type SetupExchangeSuccess } from '../../api/recEngine';
import { _resetCiStartStatus, CampaignIntelligenceBanner } from '.';
import { START_READY_TIMEOUT_MS } from './IntelligenceStart';

/**
 * CON-175 — "Try Intelligence free" (contract §21): the status decides the
 * button, the start opens the engine's window in a frame or a new window,
 * a setup message from the engine's origin runs the existing exchange, and
 * each refusal shows its line in the banner.
 */
vi.mock('../../api/onboarding', () => ({
  getOnboardingStatus: vi.fn(),
  startOnboarding: vi.fn(),
}));
vi.mock('../../api/recEngine', () => ({
  setupExchange: vi.fn(),
}));

const statusMock = vi.mocked(getOnboardingStatus);
const startMock = vi.mocked(startOnboarding);
const exchangeMock = vi.mocked(setupExchange);

const ENGINE = 'https://intelligence.smaily.com';
const START_URL = `${ENGINE}/onboarding/start/f/AQx#t=AQ3f`;
const SETUP_URL = `${ENGINE}/setup/tok_1`;

const CONNECTED: SetupExchangeSuccess = {
  connected: true,
  tenantName: 'Demo shop',
  tenantId: 't_1',
  engineVersion: '1.34.0',
  baseUrl: ENGINE,
  issuedAt: '2026-11-20T10:00:00Z',
  catalogImport: 'started',
  catalogImportDelaySeconds: 180,
};

function link(display: 'frame' | 'window'): OnboardingStartResponse {
  return { ok: true, start_url: START_URL, display, engine_origin: ENGINE };
}

function post(origin: string, data: unknown): void {
  act(() => {
    window.dispatchEvent(new MessageEvent('message', { origin, data }));
  });
}

async function flush(): Promise<void> {
  await act(async () => {
    await Promise.resolve();
    await Promise.resolve();
  });
}

async function renderBanner(
  status: { start_open: boolean; frame: boolean },
  props: Partial<Parameters<typeof CampaignIntelligenceBanner>[0]> = {},
): Promise<void> {
  statusMock.mockResolvedValue(status);
  render(<CampaignIntelligenceBanner variant="invite" smailyLoginUrl="https://demo.sendsmaily.net/" {...props} />);
  await flush();
}

const tryButton = (): HTMLElement => screen.getByRole('button', { name: 'Try Intelligence free' });

/** A window window.open() hands back: a blank page the click opened. */
function fakeTab(): Window {
  return { opener: {}, location: { href: '' }, close: vi.fn() } as unknown as Window;
}

describe('Campaign Intelligence banner — Try Intelligence free (CON-175)', () => {
  let openSpy: MockInstance<typeof window.open>;

  beforeEach(() => {
    vi.clearAllMocks();
    _resetCiStartStatus();
    openSpy = vi.spyOn(window, 'open');
  });

  afterEach(() => {
    openSpy.mockRestore();
    vi.useRealTimers();
  });

  it('shows Contact Smaily while the start is closed', async () => {
    await renderBanner({ start_open: false, frame: true });

    expect(screen.getByRole('link', { name: 'Contact Smaily' })).toHaveAttribute('href', 'https://smaily.com/consultation/');
    expect(screen.queryByRole('button', { name: 'Try Intelligence free' })).not.toBeInTheDocument();
  });

  it('asks the status once for every banner on the page', async () => {
    await renderBanner({ start_open: true, frame: true });
    render(<CampaignIntelligenceBanner variant="invite" smailyLoginUrl="" />);
    await flush();

    expect(screen.getAllByRole('button', { name: 'Try Intelligence free' })).toHaveLength(2);
    expect(statusMock).toHaveBeenCalledTimes(1);
  });

  it('opens the start window in a frame and connects on its setup message', async () => {
    const onConnected = vi.fn();
    startMock.mockResolvedValue(link('frame'));
    exchangeMock.mockResolvedValue(CONNECTED);
    await renderBanner({ start_open: true, frame: true }, { onConnected });

    fireEvent.click(tryButton());
    expect(screen.getByText('Connecting your shop · Sending your shop address · Checking your Smaily account')).toBeInTheDocument();
    await flush();

    expect(startMock).toHaveBeenCalledWith('frame');
    expect(screen.getByRole('dialog')).toBeInTheDocument();
    expect(screen.getByTitle('Campaign Intelligence')).toHaveAttribute('src', START_URL);
    expect(screen.getByTitle('Campaign Intelligence')).toHaveAttribute('referrerpolicy', 'no-referrer');
    expect(screen.getByTitle('Campaign Intelligence')).not.toHaveAttribute('sandbox');

    post(ENGINE, { type: 'smaily_intelligence_onboarding', event: 'ready' });
    post(ENGINE, { type: 'smaily_intelligence_setup', setup_token: 'tok_1', setup_url: SETUP_URL });
    await flush();

    expect(exchangeMock).toHaveBeenCalledWith({ setupUrl: SETUP_URL });
    // The welcome step stays on screen: the connection is reported when the window closes.
    expect(onConnected).not.toHaveBeenCalled();
    expect(screen.getByRole('dialog')).toBeInTheDocument();

    post(ENGINE, { type: 'smaily_intelligence_onboarding', event: 'closed' });

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(onConnected).toHaveBeenCalledWith(CONNECTED);
  });

  it('ignores messages from any other origin', async () => {
    startMock.mockResolvedValue(link('frame'));
    await renderBanner({ start_open: true, frame: true });

    fireEvent.click(tryButton());
    await flush();

    post('https://evil.example', { type: 'smaily_intelligence_setup', setup_token: 'tok_x', setup_url: SETUP_URL });
    post('https://evil.example', { type: 'smaily_intelligence_onboarding', event: 'closed' });
    await flush();

    expect(exchangeMock).not.toHaveBeenCalled();
    expect(screen.getByRole('dialog')).toBeInTheDocument();
  });

  it('offers a new window when the frame does not say ready in 10 seconds', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    startMock.mockResolvedValueOnce(link('frame')).mockResolvedValueOnce(link('window'));
    await renderBanner({ start_open: true, frame: true });

    fireEvent.click(tryButton());
    await flush();
    act(() => {
      vi.advanceTimersByTime(START_READY_TIMEOUT_MS - 1);
    });
    expect(screen.getByRole('dialog')).toBeInTheDocument();
    act(() => {
      vi.advanceTimersByTime(1);
    });

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(screen.getByText('The setup window did not open here.')).toBeInTheDocument();

    const tab = fakeTab();
    openSpy.mockReturnValue(tab);
    fireEvent.click(screen.getByRole('button', { name: 'Open in a new window' }));
    await flush();

    expect(openSpy).toHaveBeenCalledWith('', '_blank');
    expect(startMock).toHaveBeenLastCalledWith('window');
    expect(tab.location.href).toBe(START_URL);
  });

  it('offers a new window when the engine answers without the frame mode or refuses the frame origin', async () => {
    for (const answer of [link('window'), { ok: false as const, error: 'frame_origin_not_allowed' }]) {
      _resetCiStartStatus();
      startMock.mockResolvedValue(answer);
      statusMock.mockResolvedValue({ start_open: true, frame: true });
      const { unmount } = render(<CampaignIntelligenceBanner variant="invite" smailyLoginUrl="" />);
      await flush();

      fireEvent.click(tryButton());
      await flush();

      expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
      expect(screen.getByRole('button', { name: 'Open in a new window' })).toBeInTheDocument();
      unmount();
    }
  });

  it('opens a new window where no frame is allowed and connects on its message', async () => {
    const onConnected = vi.fn();
    const tab = fakeTab();
    openSpy.mockReturnValue(tab);
    startMock.mockResolvedValue(link('window'));
    exchangeMock.mockResolvedValue(CONNECTED);
    await renderBanner({ start_open: true, frame: false }, { onConnected });

    fireEvent.click(tryButton());
    await flush();

    expect(startMock).toHaveBeenCalledWith('window');
    expect(tab.location.href).toBe(START_URL);
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();

    post(ENGINE, { type: 'smaily_intelligence_setup', setup_token: 'tok_1' });
    await flush();

    expect(exchangeMock).toHaveBeenCalledWith({ setupUrl: SETUP_URL });
    expect(onConnected).toHaveBeenCalledWith(CONNECTED);
  });

  it('says so when the browser blocks the new window', async () => {
    openSpy.mockReturnValue(null);
    await renderBanner({ start_open: true, frame: false });

    fireEvent.click(tryButton());

    expect(screen.getByText('Your browser blocked the new tab. Allow pop-ups for this page and try again.')).toBeInTheDocument();
    expect(startMock).not.toHaveBeenCalled();
  });

  it('shows a failed exchange and keeps the button to try again', async () => {
    startMock.mockResolvedValue(link('window'));
    openSpy.mockReturnValue(fakeTab());
    exchangeMock.mockResolvedValue({ connected: false, error: 'token_expired_or_used', message: 'This setup link has already been used.' });
    await renderBanner({ start_open: true, frame: false });

    fireEvent.click(tryButton());
    await flush();
    post(ENGINE, { type: 'smaily_intelligence_setup', setup_url: SETUP_URL });
    await flush();

    expect(screen.getByText('This setup link has already been used.')).toBeInTheDocument();
    expect(tryButton()).toBeEnabled();
  });

  describe('the refusals', () => {
    async function refuse(error: string, extra: Record<string, string> = {}, props = {}): Promise<void> {
      startMock.mockResolvedValue({ ok: false, error, ...extra });
      await renderBanner({ start_open: true, frame: true }, props);
      fireEvent.click(tryButton());
      await flush();
    }

    it('401 says Smaily refused the login and links the connection settings', async () => {
      const onShowSmailyConnection = vi.fn();
      await refuse('smaily_login_refused', {}, { onShowSmailyConnection });

      expect(
        screen.getByText(/Smaily refused the API login stored in this plugin\. Check it in the plugin settings and try again\. No store was created\./),
      ).toBeInTheDocument();
      fireEvent.click(screen.getByRole('button', { name: 'Open the Smaily connection settings' }));
      expect(onShowSmailyConnection).toHaveBeenCalled();
      expect(tryButton()).toBeEnabled();
    });

    it('403 switches to the upsell', async () => {
      await refuse('smaily_paid_package_required');

      expect(screen.getByRole('link', { name: 'Log in to Smaily' })).toHaveAttribute('href', 'https://demo.sendsmaily.net/');
      expect(screen.getByText('Requires a paid Smaily plan')).toBeInTheDocument();
      expect(screen.getByText(/^Intelligence needs a paid Smaily package\./)).toBeInTheDocument();
      expect(screen.queryByRole('button', { name: 'Try Intelligence free' })).not.toBeInTheDocument();
    });

    it('404 hides the button and offers Contact Smaily', async () => {
      await refuse('not_found');

      expect(screen.getByRole('link', { name: 'Contact Smaily' })).toBeInTheDocument();
      expect(screen.queryByRole('button', { name: 'Try Intelligence free' })).not.toBeInTheDocument();
    });

    it('409 offers the console', async () => {
      await refuse('store_exists', { console_url: `${ENGINE}/console/t_1/home` });

      expect(screen.getByText(/This Smaily account already has a store in Intelligence\./)).toBeInTheDocument();
      expect(screen.getByRole('link', { name: 'Open console' })).toHaveAttribute('href', `${ENGINE}/console/t_1/home`);
    });

    it('502, 429 and no answer say to try again shortly with the same button', async () => {
      for (const error of ['unavailable', 'rate_limited', 'network']) {
        _resetCiStartStatus();
        startMock.mockResolvedValue({ ok: false, error });
        statusMock.mockResolvedValue({ start_open: true, frame: true });
        const { unmount } = render(<CampaignIntelligenceBanner variant="invite" smailyLoginUrl="" />);
        await flush();
        fireEvent.click(tryButton());
        await flush();

        expect(screen.getByText('Smaily did not answer. Try again shortly.')).toBeInTheDocument();
        fireEvent.click(tryButton());
        expect(startMock).toHaveBeenCalledTimes(2);
        startMock.mockClear();
        unmount();
      }
    });

    it('anything else is a generic failure', async () => {
      await refuse('start_failed');

      expect(screen.getByText('Campaign Intelligence could not be started. Try again later, or contact Smaily.')).toBeInTheDocument();
    });
  });

  it('has Estonian for every new line', () => {
    const catalog = readFileSync(resolve(__dirname, '../../../../languages/smaily-connect-et.po'), 'utf8');
    for (const source of [
      'Connecting your shop · Sending your shop address · Checking your Smaily account',
      'Smaily refused the API login stored in this plugin. Check it in the plugin settings and try again. No store was created.',
      'This Smaily account already has a store in Intelligence.',
      'Open console',
      'Smaily did not answer. Try again shortly.',
      'Campaign Intelligence could not be started. Try again later, or contact Smaily.',
      'Open in a new window',
      'The setup window did not open here.',
      'Open the Smaily connection settings',
    ]) {
      const escaped = source.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
      expect(catalog, source).toMatch(new RegExp(`^msgid "${escaped}"\\nmsgstr "(.+)"$`, 'm'));
    }
  });
});
