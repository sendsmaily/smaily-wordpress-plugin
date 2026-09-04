import { useCallback, useEffect, useState } from 'react';

import {
  getEventDetail,
  listEvents,
  retryEvents,
  type EventDetailResponse,
  type EventRow,
  type EventSource,
} from '../../api/events';
import { __, _n, sprintf } from '@admin/lib/i18n';
import { Banner, Button, Card, Pill, Select, type PillTone } from '../primitives';

const PER_PAGE = 50;

function statusTone(status: string): PillTone {
  switch (status) {
    case 'sent':
      return 'success';
    case 'failed':
      return 'danger';
    case 'blocked':
      return 'warning';
    case 'pending':
      return 'brand';
    default:
      return 'neutral';
  }
}

/**
 * Event Log (PLUGIN.md §13) — read-only diagnostic view over both durable
 * queues. The merchant answers "did X sync?" (status column) and the developer
 * "why not?" (last_error + the drill-down payload). Recovery (retry) is 3.10.1.
 */
export function EventLog(): React.JSX.Element {
  const [rows, setRows] = useState<EventRow[]>([]);
  const [total, setTotal] = useState(0);
  const [failed24h, setFailed24h] = useState(0);
  const [page, setPage] = useState(1);
  const [source, setSource] = useState<EventSource | ''>('');
  const [status, setStatus] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [detail, setDetail] = useState<EventDetailResponse | null>(null);
  const [retrying, setRetrying] = useState(false);

  const load = useCallback(
    async (signal?: AbortSignal): Promise<void> => {
      setLoading(true);
      setError(null);
      try {
        const res = await listEvents({ page, perPage: PER_PAGE, source, status }, signal);
        setRows(res.events);
        setTotal(res.total);
        setFailed24h(res.failed_24h);
      } catch (e) {
        if (signal?.aborted) {
          return;
        }
        setError(
          e instanceof Error ? e.message : __('Failed to load the event log.', 'smaily-connect'),
        );
      } finally {
        setLoading(false);
      }
    },
    [page, source, status],
  );

  useEffect(() => {
    const ctrl = new AbortController();
    void load(ctrl.signal);
    return () => ctrl.abort();
  }, [load]);

  const openDetail = useCallback(async (row: EventRow): Promise<void> => {
    try {
      setDetail(await getEventDetail(row.source, row.id));
    } catch {
      // The list row already shows last_error; a failed detail fetch is non-fatal.
    }
  }, []);

  const handleRetry = useCallback(
    async (args: { source?: EventSource; id?: number }): Promise<void> => {
      setRetrying(true);
      setError(null);
      try {
        await retryEvents(args);
        await load();
      } catch (e) {
        setError(e instanceof Error ? e.message : __('Retry failed.', 'smaily-connect'));
      } finally {
        setRetrying(false);
      }
    },
    [load],
  );

  const totalPages = Math.max(1, Math.ceil(total / PER_PAGE));
  // Rows failed outside the 24h window (e.g. months ago, no fresh failures)
  // never trip the banner below, so this is the only bulk-retry path for them.
  const hasFailedRows = rows.some((row) => row.status === 'failed');

  return (
    <div className="space-y-4">
      {failed24h > 0 && (
        <Banner
          tone="danger"
          title={sprintf(
            // translators: %d is the number of failed events in the last 24 hours.
            _n(
              '%d failed event in the last 24 hours',
              '%d failed events in the last 24 hours',
              failed24h,
              'smaily-connect',
            ),
            failed24h,
          )}
          actions={
            <div className="flex gap-2">
              <Button
                variant="secondary"
                type="button"
                onClick={() => {
                  setStatus('failed');
                  setPage(1);
                }}
              >
                {__('View only failed', 'smaily-connect')}
              </Button>
              <Button
                variant="primary"
                type="button"
                loading={retrying}
                onClick={() => void handleRetry({})}
              >
                {__('Retry all failed', 'smaily-connect')}
              </Button>
            </div>
          }
        >
          {__('Some records didn’t reach their destination. Review them below.', 'smaily-connect')}
        </Banner>
      )}

      <Card
        title={__('Event log', 'smaily-connect')}
        description={__(
          'Sync activity across the Smaily and recommendation-engine queues. Read-only — last 7 days of events.',
          'smaily-connect',
        )}
      >
        <div className="flex flex-wrap items-end gap-3">
          <Select
            aria-label={__('Filter by source', 'smaily-connect')}
            value={source}
            onChange={(e) => {
              setSource(e.target.value as EventSource | '');
              setPage(1);
            }}
            options={[
              { value: '', label: __('All sources', 'smaily-connect') },
              { value: 'rec_engine', label: __('Recommendations engine', 'smaily-connect') },
              { value: 'smaily', label: __('Smaily', 'smaily-connect') },
            ]}
          />
          <Select
            aria-label={__('Filter by status', 'smaily-connect')}
            value={status}
            onChange={(e) => {
              setStatus(e.target.value);
              setPage(1);
            }}
            options={[
              { value: '', label: __('All statuses', 'smaily-connect') },
              { value: 'pending', label: __('Pending', 'smaily-connect') },
              { value: 'sent', label: __('Sent', 'smaily-connect') },
              { value: 'failed', label: __('Failed', 'smaily-connect') },
              { value: 'blocked', label: __('Blocked', 'smaily-connect') },
            ]}
          />
          <Button variant="ghost" type="button" onClick={() => void load()}>
            {__('Refresh', 'smaily-connect')}
          </Button>
          {/* Covers failed rows the 24h banner above never sees (aged
              failures with no fresh failures in the last 24h). */}
          {failed24h === 0 && hasFailedRows && (
            <Button
              variant="secondary"
              type="button"
              className="ml-auto"
              loading={retrying}
              onClick={() => void handleRetry({})}
            >
              {__('Retry all failed', 'smaily-connect')}
            </Button>
          )}
        </div>

        {error !== null && (
          <Banner tone="danger" className="mt-4">
            {error}
          </Banner>
        )}

        <div className="mt-4 overflow-x-auto">
          <table className="w-full text-left text-xs">
            <thead className="text-text-tertiary">
              <tr className="border-b border-border">
                <th className="py-2 pr-3 font-medium">{__('Time (UTC)', 'smaily-connect')}</th>
                <th className="py-2 pr-3 font-medium">{__('Source', 'smaily-connect')}</th>
                <th className="py-2 pr-3 font-medium">{__('Event', 'smaily-connect')}</th>
                <th className="py-2 pr-3 font-medium">{__('Entity', 'smaily-connect')}</th>
                <th className="py-2 pr-3 font-medium">{__('Status', 'smaily-connect')}</th>
                <th className="py-2 pr-3 font-medium">{__('Attempts', 'smaily-connect')}</th>
                <th className="py-2 pr-3 font-medium">{__('Last error', 'smaily-connect')}</th>
                {/* Pinned to the right edge so Retry/Details stay reachable
                    when the row overflows horizontally (the failed-row case:
                    the Retry button widens the row and used to push Details
                    off-screen behind the scroll). */}
                <th className="sticky right-0 border-l border-border-subtle bg-surface py-2 pl-3 font-medium" />
              </tr>
            </thead>
            <tbody>
              {rows.length === 0 && !loading && (
                <tr>
                  <td colSpan={8} className="py-6 text-center text-text-tertiary">
                    {__('No events yet.', 'smaily-connect')}
                  </td>
                </tr>
              )}
              {rows.map((row) => (
                <tr key={`${row.source}-${row.id}`} className="border-b border-border">
                  <td className="whitespace-nowrap py-2 pr-3 font-mono text-text-secondary">
                    {row.created_at}
                  </td>
                  <td className="py-2 pr-3">
                    {row.source === 'rec_engine'
                      ? __('Rec', 'smaily-connect')
                      : __('Smaily', 'smaily-connect')}
                  </td>
                  <td className="py-2 pr-3 font-mono">{row.event_type}</td>
                  <td className="py-2 pr-3 font-mono text-text-secondary">{row.entity_id || '—'}</td>
                  <td className="py-2 pr-3">
                    <Pill tone={statusTone(row.status)} dot>
                      {row.status}
                    </Pill>
                  </td>
                  <td className="py-2 pr-3">
                    {row.attempts}
                    {row.max_attempts !== null ? `/${row.max_attempts}` : ''}
                  </td>
                  <td
                    className="max-w-xs truncate py-2 pr-3 text-danger"
                    title={row.last_error}
                  >
                    {row.last_error || '—'}
                  </td>
                  <td className="sticky right-0 border-l border-border-subtle bg-surface py-2 pl-3 text-right">
                    <div className="flex justify-end gap-1 whitespace-nowrap">
                      {row.status === 'failed' && (
                        <Button
                          variant="secondary"
                          type="button"
                          disabled={retrying}
                          onClick={() => void handleRetry({ source: row.source, id: row.id })}
                        >
                          {__('Retry', 'smaily-connect')}
                        </Button>
                      )}
                      <Button variant="ghost" type="button" onClick={() => void openDetail(row)}>
                        {__('Details', 'smaily-connect')}
                      </Button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className="mt-4 flex items-center justify-between text-xs text-text-secondary">
          <span>
            {sprintf(
              // translators: %d is the total number of events.
              _n('%d event', '%d events', total, 'smaily-connect'),
              total,
            )}
            {loading ? __(' · loading…', 'smaily-connect') : ''}
          </span>
          <div className="flex items-center gap-2">
            <Button
              variant="ghost"
              type="button"
              disabled={page <= 1}
              onClick={() => setPage((p) => Math.max(1, p - 1))}
            >
              {__('Previous', 'smaily-connect')}
            </Button>
            <span>
              {sprintf(
                // translators: %1$d is the current page number, %2$d is the total page count.
                __('Page %1$d of %2$d', 'smaily-connect'),
                page,
                totalPages,
              )}
            </span>
            <Button
              variant="ghost"
              type="button"
              disabled={page >= totalPages}
              onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
            >
              {__('Next', 'smaily-connect')}
            </Button>
          </div>
        </div>
      </Card>

      {detail !== null && <EventDetailModal detail={detail} onClose={() => setDetail(null)} />}
    </div>
  );
}

/** Pretty-print a JSON string for display; returns it raw if it isn't JSON, '' if empty. */
function prettyJson(raw: string): string {
  if (raw === '') {
    return '';
  }
  try {
    return JSON.stringify(JSON.parse(raw), null, 2);
  } catch {
    return raw;
  }
}

function EventDetailModal({
  detail,
  onClose,
}: {
  detail: EventDetailResponse;
  onClose: () => void;
}): React.JSX.Element {
  const { event, payload, sent_payload, last_response } = detail;
  const sent = prettyJson(sent_payload);
  const response = prettyJson(last_response);
  const enqueued = prettyJson(payload);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      {/* Backdrop is a real button so closing on outside-click stays a11y-valid. */}
      <button
        type="button"
        aria-label={__('Close details', 'smaily-connect')}
        className="absolute inset-0 h-full w-full cursor-default bg-text-primary/40"
        onClick={onClose}
      />
      <div className="relative z-10 max-h-[80vh] w-full max-w-2xl overflow-auto rounded-lg bg-surface p-6 shadow-xl">
        <div className="flex items-start justify-between gap-4">
          <h3 className="font-mono text-lg font-semibold text-text-primary">{event.event_type}</h3>
          <Button variant="ghost" type="button" onClick={onClose}>
            {__('Close', 'smaily-connect')}
          </Button>
        </div>
        <dl className="mt-3 grid grid-cols-2 gap-2 text-xs">
          <div>
            <dt className="text-text-tertiary">{__('Source', 'smaily-connect')}</dt>
            <dd>{event.source}</dd>
          </div>
          <div>
            <dt className="text-text-tertiary">{__('Status', 'smaily-connect')}</dt>
            <dd>{event.status}</dd>
          </div>
          <div>
            <dt className="text-text-tertiary">{__('Entity', 'smaily-connect')}</dt>
            <dd className="font-mono">{event.entity_id || '—'}</dd>
          </div>
          <div>
            <dt className="text-text-tertiary">{__('Attempts', 'smaily-connect')}</dt>
            <dd>
              {event.attempts}
              {event.max_attempts !== null ? `/${event.max_attempts}` : ''}
            </dd>
          </div>
          <div className="col-span-2">
            <dt className="text-text-tertiary">{__('Created (UTC)', 'smaily-connect')}</dt>
            <dd className="font-mono">{event.created_at}</dd>
          </div>
        </dl>
        {event.last_error !== '' && (
          <div className="mt-3">
            <p className="text-xs font-medium text-text-tertiary">{__('Last error', 'smaily-connect')}</p>
            <pre className="mt-1 whitespace-pre-wrap rounded bg-surface-muted p-2 text-xs text-danger">
              {event.last_error}
            </pre>
          </div>
        )}
        <div className="mt-3">
          <p className="text-xs font-medium text-text-tertiary">{__('Request sent to the engine', 'smaily-connect')}</p>
          <pre className="mt-1 max-h-64 overflow-auto whitespace-pre-wrap rounded bg-surface-muted p-2 font-mono text-xs text-text-secondary">
            {sent !== ''
              ? sent
              : __('— nothing recorded (not sent, or the row predates this version)', 'smaily-connect')}
          </pre>
        </div>
        <div className="mt-3">
          <p className="text-xs font-medium text-text-tertiary">{__('Engine response', 'smaily-connect')}</p>
          <pre className="mt-1 max-h-64 overflow-auto whitespace-pre-wrap rounded bg-surface-muted p-2 font-mono text-xs text-text-secondary">
            {response !== '' ? response : '—'}
          </pre>
        </div>
        <div className="mt-3">
          <p className="text-xs font-medium text-text-tertiary">{__('Enqueued payload', 'smaily-connect')}</p>
          <pre className="mt-1 max-h-64 overflow-auto whitespace-pre-wrap rounded bg-surface-muted p-2 font-mono text-xs text-text-secondary">
            {enqueued}
          </pre>
        </div>
      </div>
    </div>
  );
}
