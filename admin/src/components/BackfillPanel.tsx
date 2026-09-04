import type React from 'react';
import { type BackfillJobType } from '../api/backfill';
import { useBackfillProgress } from '../hooks/useBackfillProgress';
import { Button, ProgressBar } from './primitives';
import { __, _n, sprintf } from '@admin/lib/i18n';

interface BackfillPanelProps {
  /** Rec-engine domain to backfill: 'products' | 'customers' | 'orders'. */
  jobType: BackfillJobType;
  /** Human label, e.g. "Products". */
  label: string;
  /** How many records exist (the button is disabled at 0). */
  recordCount: number;
  /**
   * Optional clarification under the record count. Used for the products
   * domain on multilingual stores, where `recordCount` counts one post per
   * language but the import collapses translations into one product each — so
   * the synced total is lower by design and the raw count needs explaining.
   */
  countNote?: string;
  /** Poll interval; Settings uses 30s, the wizard 5s. */
  intervalMs?: number;
}

/**
 * One rec-engine backfill control: an "Import now" button + a progress bar for
 * a single domain. The API + useBackfillProgress hook are already job-type
 * parameterised (3.5.0-.2 wired the products/customers/orders job types), so
 * each panel just instantiates the hook with its jobType.
 *
 * Unlike the contacts backfill (Step2Subscribers), this holds progress purely
 * in the hook and does NOT mirror it into the reducer — only the contacts
 * backfill feeds the Step 6 summary, so rec-engine domains need no reducer slot.
 */
export function BackfillPanel({
  jobType,
  label,
  recordCount,
  countNote,
  intervalMs,
}: BackfillPanelProps): React.JSX.Element {
  const { progress, pollError, start, cancel } = useBackfillProgress({
    jobType,
    intervalMs: intervalMs ?? 30_000,
  });

  const isRunning = progress.status === 'running';
  const isComplete = progress.status === 'completed';
  const hasFailed = progress.status === 'failed';
  const wasCancelled = progress.status === 'cancelled';
  const showProgress = isRunning || isComplete || hasFailed || wasCancelled;
  const noun = label.toLowerCase();

  return (
    <div className="rounded-lg border border-border-subtle p-4">
      <div className="flex items-start justify-between gap-3">
        <div>
          <p className="text-sm font-medium text-text-primary">{label}</p>
          <p className="mt-0.5 text-xs text-text-tertiary">
            {recordCount > 0
              ? sprintf(
                  /* translators: 1: record count, 2: lowercased domain noun (e.g. products). */
                  __('%1$d %2$s to import into the engine.', 'smaily-connect'),
                  recordCount,
                  noun,
                )
              : sprintf(
                  /* translators: %s: lowercased domain noun (e.g. products). */
                  __('No %s to import.', 'smaily-connect'),
                  noun,
                )}
          </p>
          {countNote && recordCount > 0 && (
            <p className="mt-0.5 text-xs text-text-tertiary">{countNote}</p>
          )}
        </div>
        <div className="flex shrink-0 items-center gap-2">
          <Button
            variant="secondary"
            size="sm"
            onClick={() => void start()}
            disabled={isRunning || recordCount === 0}
            loading={isRunning && progress.processed === 0}
            type="button"
          >
            {isRunning ? __('Importing…', 'smaily-connect') : __('Import now', 'smaily-connect')}
          </Button>
          {isRunning && (
            <Button variant="ghost" size="sm" onClick={() => void cancel()} type="button">
              {__('Cancel', 'smaily-connect')}
            </Button>
          )}
        </div>
      </div>

      {showProgress && (
        <div className="mt-3">
          <ProgressBar
            percent={progress.percent}
            tone={hasFailed ? 'danger' : isComplete ? 'success' : 'brand'}
            ariaLabel={sprintf(
              /* translators: %s: domain label (e.g. Products). */
              __('%s backfill progress', 'smaily-connect'),
              label,
            )}
          />
          <p className="mt-2 text-xs text-text-secondary">
            {/* Show the engine-confirmed `sent` count as an absolute number, NOT
                as `sent / total`. For the products domain `total` counts every
                raw post including each language translation (Polylang/WPML), so a
                multilingual catalog collapses many posts into one canonical
                product — `sent` (canonical) over `total` (raw) then reads as a
                misleading fraction (e.g. "1381 of 7794" ≈ 17% when the catalog is
                actually ~complete). The bar already conveys completion via
                `percent` (walked/total, a self-consistent ratio); the count beside
                it is the honest number of products synced. Per-row failures are
                listed in the Event Log. */}
            {isRunning &&
              sprintf(
                /* translators: 1: synced count, 2: lowercased domain noun, 3: percent complete. */
                __('Synced %1$d %2$s · %3$d%% complete', 'smaily-connect'),
                progress.sent,
                noun,
                progress.percent,
              )}
            {isComplete &&
              sprintf(
                /* translators: 1: synced count, 2: lowercased domain noun. */
                __('Done — %1$d %2$s synced.', 'smaily-connect'),
                progress.sent,
                noun,
              )}
            {hasFailed &&
              (progress.error
                ? sprintf(
                    /* translators: %s: error message. */
                    __('Backfill failed: %s', 'smaily-connect'),
                    progress.error,
                  )
                : __('Backfill failed.', 'smaily-connect'))}
            {wasCancelled && __('Backfill cancelled. Re-run when ready.', 'smaily-connect')}
          </p>
          {progress.failed > 0 && (isRunning || isComplete) && (
            <p className="mt-1 text-xs text-danger-fg">
              {sprintf(
                /* translators: %d: number of records that failed to sync. */
                _n(
                  '%d record failed to sync — see the Event Log for details.',
                  '%d records failed to sync — see the Event Log for details.',
                  progress.failed,
                  'smaily-connect',
                ),
                progress.failed,
              )}
            </p>
          )}
          {(isComplete || wasCancelled || hasFailed) && progress.completedAt && (
            <p className="mt-1 text-xs text-text-tertiary">
              {sprintf(
                /* translators: %s: localized date/time of the last run. */
                __('Last run finished %s.', 'smaily-connect'),
                formatLastRun(progress.completedAt),
              )}
            </p>
          )}
        </div>
      )}

      {pollError && (
        <p className="mt-3 text-xs text-danger">
          {sprintf(
            /* translators: %s: status-check error message. */
            __("Couldn't check status: %s", 'smaily-connect'),
            pollError,
          )}
        </p>
      )}
    </div>
  );
}

/**
 * Render a server-emitted UTC datetime ("2026-05-21 10:42:17") in the
 * merchant's local timezone. Mirrors Step2Subscribers' helper.
 */
function formatLastRun(utcDatetime: string): string {
  const iso = utcDatetime.replace(' ', 'T') + 'Z';
  const date = new Date(iso);
  if (isNaN(date.getTime())) {
    return utcDatetime;
  }
  return date.toLocaleString();
}
