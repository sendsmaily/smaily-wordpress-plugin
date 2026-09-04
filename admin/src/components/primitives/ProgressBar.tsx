import { type HTMLAttributes } from 'react';

import { cn } from '../../utils/cn';

export type ProgressBarTone = 'brand' | 'success' | 'warning' | 'danger';

export interface ProgressBarProps extends HTMLAttributes<HTMLDivElement> {
  /** Integer 0–100. Values outside the range get clamped. */
  percent: number;
  tone?: ProgressBarTone;
  /** Required accessible name for the progress indicator. */
  ariaLabel: string;
  /** Indeterminate visualisation when total is unknown. */
  indeterminate?: boolean;
}

/**
 * Progress bar — backfill status, file uploads, anywhere we surface a
 * "X / Y done" indicator.
 *
 * indeterminate mode renders a slow-pulse track + sliding highlight.
 * Used in the moment between BACKFILL_START dispatch and the first
 * BACKFILL_PROGRESS event landing from polling.
 */
export function ProgressBar({
  percent,
  tone = 'brand',
  ariaLabel,
  indeterminate,
  className,
  ...rest
}: ProgressBarProps): React.JSX.Element {
  const clamped = Math.max(0, Math.min(100, Math.round(percent)));

  return (
    <div
      role="progressbar"
      aria-label={ariaLabel}
      aria-valuenow={indeterminate ? undefined : clamped}
      aria-valuemin={0}
      aria-valuemax={100}
      className={cn(
        'relative h-1.5 w-full overflow-hidden rounded-full bg-surface-muted',
        className,
      )}
      {...rest}
    >
      <div
        className={cn(
          'h-full rounded-full transition-[width] duration-200',
          toneClasses[tone],
          indeterminate && 'w-1/3 animate-pulse',
        )}
        style={indeterminate ? undefined : { width: `${clamped}%` }}
      />
    </div>
  );
}

const toneClasses: Record<ProgressBarTone, string> = {
  brand: 'bg-brand',
  success: 'bg-success',
  warning: 'bg-warning',
  danger: 'bg-danger',
};
