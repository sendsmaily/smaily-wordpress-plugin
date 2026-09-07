import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { ApiError } from '../../api/client';
import * as eventsApi from '../../api/events';
import { EventLog } from './EventLog';

const ROW = {
  id: 1,
  source: 'rec_engine' as const,
  event_type: 'order.upsert',
  entity_id: '42',
  status: 'failed',
  attempts: 5,
  max_attempts: 5,
  last_error: 'http_503 service unavailable',
  created_at: '2026-06-09 10:00:00',
  retry_refusal: '',
  retry_refusal_message: '',
  can_send_again: false,
  cancelled: false,
};

/**
 * PRO-1733: a failed order confirmation whose fail-open already re-fired the
 * native WooCommerce email. Retrying it would send the shopper a second
 * confirmation, so the server marks it refused.
 */
const TRANSACTIONAL_REFUSED_ROW = {
  ...ROW,
  id: 9,
  source: 'smaily' as const,
  event_type: 'transactional.order_confirmation',
  last_error: 'retry_ceiling_exceeded',
  retry_refusal: 'wc_email_sent',
  retry_refusal_message:
    'This confirmation was already sent to the shopper as the standard WooCommerce email; it cannot be re-sent.',
};

/**
 * PRO-2324: a shipping confirmation Smaily itself sent. The merchant may
 * deliberately follow it with a second one (a corrected tracking number).
 */
const SENT_CONFIRMATION_ROW = {
  ...ROW,
  id: 21,
  source: 'smaily' as const,
  event_type: 'transactional.shipping_confirmation',
  status: 'sent',
  last_error: '',
  can_send_again: true,
};

describe('EventLog', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('renders rows from the events API', async () => {
    vi.spyOn(eventsApi, 'listEvents').mockResolvedValue({
      events: [ROW],
      total: 1,
      page: 1,
      per_page: 50,
      failed_24h: 0,
    });

    render(<EventLog />);

    expect(await screen.findByText('order.upsert')).toBeInTheDocument();
    expect(screen.getByText('http_503 service unavailable')).toBeInTheDocument();
  });

  it('shows the sticky failed-24h banner when failures exist', async () => {
    vi.spyOn(eventsApi, 'listEvents').mockResolvedValue({
      events: [ROW],
      total: 1,
      page: 1,
      per_page: 50,
      failed_24h: 3,
    });

    render(<EventLog />);

    expect(await screen.findByText(/3 failed events in the last 24 hours/i)).toBeInTheDocument();
  });

  it('renders the empty state when there are no events', async () => {
    vi.spyOn(eventsApi, 'listEvents').mockResolvedValue({
      events: [],
      total: 0,
      page: 1,
      per_page: 50,
      failed_24h: 0,
    });

    render(<EventLog />);

    await waitFor(() => {
      expect(screen.getByText('No events yet.')).toBeInTheDocument();
    });
  });

  it('retries a failed row via the API and reloads', async () => {
    vi.spyOn(eventsApi, 'listEvents').mockResolvedValue({
      events: [ROW],
      total: 1,
      page: 1,
      per_page: 50,
      failed_24h: 1,
    });
    const retrySpy = vi.spyOn(eventsApi, 'retryEvents').mockResolvedValue({ reset: 1 });

    render(<EventLog />);

    // The per-row Retry button only renders for failed rows.
    const retryButton = await screen.findByRole('button', { name: 'Retry' });
    fireEvent.click(retryButton);

    await waitFor(() => {
      expect(retrySpy).toHaveBeenCalledWith({ source: 'rec_engine', id: 1 });
    });
  });

  it('retry-all-failed posts an empty retry', async () => {
    vi.spyOn(eventsApi, 'listEvents').mockResolvedValue({
      events: [ROW],
      total: 1,
      page: 1,
      per_page: 50,
      failed_24h: 2,
    });
    const retrySpy = vi.spyOn(eventsApi, 'retryEvents').mockResolvedValue({ reset: 2 });

    render(<EventLog />);

    const retryAll = await screen.findByRole('button', { name: 'Retry all failed' });
    fireEvent.click(retryAll);

    await waitFor(() => {
      expect(retrySpy).toHaveBeenCalledWith({});
    });

    // The 24h banner (PRO-1539) stays the only surfaced bulk-retry control
    // when it's showing — no duplicate button alongside it.
    expect(screen.getAllByRole('button', { name: 'Retry all failed' })).toHaveLength(1);
  });

  it('offers no Retry for a transactional row the WooCommerce email already covered (PRO-1733)', async () => {
    vi.spyOn(eventsApi, 'listEvents').mockResolvedValue({
      events: [TRANSACTIONAL_REFUSED_ROW],
      total: 1,
      page: 1,
      per_page: 50,
      failed_24h: 1,
    });

    render(<EventLog />);

    await screen.findByText('transactional.order_confirmation');
    expect(screen.queryByRole('button', { name: 'Retry' })).not.toBeInTheDocument();
    // Details stays reachable — the merchant still needs to see why.
    expect(screen.getByRole('button', { name: 'Details' })).toBeInTheDocument();
  });

  it('explains in the details panel that the WooCommerce email was sent instead (PRO-1733)', async () => {
    vi.spyOn(eventsApi, 'listEvents').mockResolvedValue({
      events: [TRANSACTIONAL_REFUSED_ROW],
      total: 1,
      page: 1,
      per_page: 50,
      failed_24h: 1,
    });
    vi.spyOn(eventsApi, 'getEventDetail').mockResolvedValue({
      event: TRANSACTIONAL_REFUSED_ROW,
      payload: '{"to_status":""}',
      sent_payload: '',
      last_response: '',
    });

    render(<EventLog />);

    fireEvent.click(await screen.findByRole('button', { name: 'Details' }));

    expect(
      await screen.findByText(
        'This confirmation was already sent to the shopper as the standard WooCommerce email; it cannot be re-sent.',
      ),
    ).toBeInTheDocument();
  });

  it('keeps Retry on a transactional row the shopper never got (PRO-1733)', async () => {
    // A shipping confirmation on a merchant-defined shipped status: WooCommerce
    // has no native email for it, so nothing was sent and a retry is the only
    // way the shopper gets one. The server leaves retry_refusal empty.
    vi.spyOn(eventsApi, 'listEvents').mockResolvedValue({
      events: [
        {
          ...TRANSACTIONAL_REFUSED_ROW,
          event_type: 'transactional.shipping_confirmation',
          retry_refusal: '',
          retry_refusal_message: '',
        },
      ],
      total: 1,
      page: 1,
      per_page: 50,
      failed_24h: 1,
    });
    const retrySpy = vi.spyOn(eventsApi, 'retryEvents').mockResolvedValue({ reset: 1 });

    render(<EventLog />);

    fireEvent.click(await screen.findByRole('button', { name: 'Retry' }));

    await waitFor(() => {
      expect(retrySpy).toHaveBeenCalledWith({ source: 'smaily', id: 9 });
    });

    // PRO-2323: the retry only re-queues the row — the send happens on the
    // flusher's next scheduled pass, and the notice must say so rather than
    // imply the confirmation just went out.
    expect(
      await screen.findByText(
        '1 record is back in the queue — it will be sent at the next scheduled pass, within about a minute.',
      ),
    ).toBeInTheDocument();
  });

  it('offers Send again on a confirmation Smaily sent, and only after a confirm (PRO-2324)', async () => {
    vi.spyOn(eventsApi, 'listEvents').mockResolvedValue({
      events: [SENT_CONFIRMATION_ROW],
      total: 1,
      page: 1,
      per_page: 50,
      failed_24h: 0,
    });
    const resendSpy = vi
      .spyOn(eventsApi, 'resendEvent')
      .mockResolvedValue({ queued: 1, id: 77 });
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);

    render(<EventLog />);

    const sendAgain = await screen.findByRole('button', { name: 'Send again' });

    // Declined at the confirm step: nothing is sent.
    fireEvent.click(sendAgain);
    expect(confirmSpy).toHaveBeenCalledWith('This sends the customer a second confirmation.');
    expect(resendSpy).not.toHaveBeenCalled();

    confirmSpy.mockReturnValue(true);
    fireEvent.click(sendAgain);

    await waitFor(() => {
      expect(resendSpy).toHaveBeenCalledWith(21);
    });

    expect(
      await screen.findByText(
        'A second confirmation is queued — it will be sent at the next scheduled pass, within about a minute.',
      ),
    ).toBeInTheDocument();
  });

  it('offers no Send again on rows the server did not mark resendable (PRO-2324)', async () => {
    // A failed confirmation (Retry is its action), an ingest row and a
    // contact-sync row — none of them is a confirmation Smaily sent.
    vi.spyOn(eventsApi, 'listEvents').mockResolvedValue({
      events: [
        TRANSACTIONAL_REFUSED_ROW,
        { ...ROW, id: 31, status: 'sent' },
        { ...ROW, id: 32, source: 'smaily' as const, event_type: 'contact.sync', status: 'sent' },
      ],
      total: 3,
      page: 1,
      per_page: 50,
      failed_24h: 1,
    });

    render(<EventLog />);

    await screen.findByText('contact.sync');
    expect(screen.queryByRole('button', { name: 'Send again' })).not.toBeInTheDocument();
  });

  it('reads a withdrawn abandoned-cart reminder as cancelled, not sent (PRO-2372)', async () => {
    // The row is stored `sent` — terminal, never retried — but nothing went
    // out, and the merchant must see that without opening Details.
    vi.spyOn(eventsApi, 'listEvents').mockResolvedValue({
      events: [
        {
          ...ROW,
          id: 51,
          source: 'smaily' as const,
          event_type: 'automation.abandoned_cart',
          status: 'sent',
          last_error: '',
          cancelled: true,
        },
      ],
      total: 1,
      page: 1,
      per_page: 50,
      failed_24h: 0,
    });

    render(<EventLog />);

    expect(await screen.findByText('cancelled')).toBeInTheDocument();
    expect(screen.queryByText('sent')).not.toBeInTheDocument();
  });

  /**
   * PRO-2369: both row actions answer a refusal with a sentence the SERVER
   * worded. The stub sentence below is deliberately NOT the PHP wording —
   * that is pinned server-side (TransactionalEmailsPipelineTest); what this
   * asserts is that the banner shows whatever came back, and never the
   * request line or the status number.
   */
  const REFUSAL_SENTENCE = 'The server explains, in plain words, why this was refused.';

  it.each([
    {
      action: 'Retry',
      row: { ...TRANSACTIONAL_REFUSED_ROW, retry_refusal: '', retry_refusal_message: '' },
      path: '/events/retry',
      refuse: (err: ApiError) => vi.spyOn(eventsApi, 'retryEvents').mockRejectedValue(err),
    },
    {
      action: 'Send again',
      row: SENT_CONFIRMATION_ROW,
      path: '/events/resend',
      refuse: (err: ApiError) => vi.spyOn(eventsApi, 'resendEvent').mockRejectedValue(err),
    },
  ])('explains a refused $action in plain words instead of the request line (PRO-2369)', async ({
    action,
    row,
    path,
    refuse,
  }) => {
    vi.spyOn(eventsApi, 'listEvents').mockResolvedValue({
      events: [row],
      total: 1,
      page: 1,
      per_page: 50,
      failed_24h: 1,
    });
    // The 409 the refusal routes really answer with.
    refuse(
      new ApiError(`POST ${path} → 409`, 409, {
        error: 'refused',
        message: REFUSAL_SENTENCE,
      }),
    );
    vi.spyOn(window, 'confirm').mockReturnValue(true);

    render(<EventLog />);

    fireEvent.click(await screen.findByRole('button', { name: action }));

    expect(await screen.findByText(REFUSAL_SENTENCE)).toBeInTheDocument();
    expect(screen.queryByText(new RegExp(path))).not.toBeInTheDocument();
    expect(screen.queryByText(/409/)).not.toBeInTheDocument();
  });

  it('falls back to its own sentence when a failure carries no reason (PRO-2369)', async () => {
    vi.spyOn(eventsApi, 'listEvents').mockResolvedValue({
      events: [ROW],
      total: 1,
      page: 1,
      per_page: 50,
      failed_24h: 1,
    });
    vi.spyOn(eventsApi, 'retryEvents').mockRejectedValue(
      new ApiError('POST /events/retry → 500', 500, null),
    );

    render(<EventLog />);

    fireEvent.click(await screen.findByRole('button', { name: 'Retry' }));

    expect(await screen.findByText('Retry failed.')).toBeInTheDocument();
  });

  it('shows a reachable Retry all failed control for aged failures with no 24h banner (PRO-1539)', async () => {
    const AGED_ROW = { ...ROW, created_at: '2026-01-01 00:00:00' };
    vi.spyOn(eventsApi, 'listEvents').mockResolvedValue({
      events: [AGED_ROW],
      total: 1,
      page: 1,
      per_page: 50,
      failed_24h: 0,
    });
    const retrySpy = vi.spyOn(eventsApi, 'retryEvents').mockResolvedValue({ reset: 1 });

    render(<EventLog />);

    await screen.findByText('order.upsert');

    // No fresh failures in the last 24h, so the banner is absent...
    expect(screen.queryByText(/failed events in the last 24 hours/i)).not.toBeInTheDocument();

    // ...but the aged failed row still has a reachable bulk-retry control.
    const retryAll = await screen.findByRole('button', { name: 'Retry all failed' });
    fireEvent.click(retryAll);

    await waitFor(() => {
      expect(retrySpy).toHaveBeenCalledWith({});
    });
  });
});
