import { type HTMLAttributes, type ReactNode } from 'react';

import { cn } from '../../utils/cn';

export type BannerTone = 'info' | 'success' | 'warning' | 'danger';

export interface BannerProps extends Omit<HTMLAttributes<HTMLDivElement>, 'title'> {
  tone?: BannerTone;
  title?: ReactNode;
  /** Right-aligned slot for actions ("View Event Log →", "Dismiss"). */
  actions?: ReactNode;
}

/**
 * Inline banner — used at the top of the wizard for "Smaily disconnected"
 * / "Backfill complete" / "X failed events" notices, and in Step 4 for
 * the rec-engine consent reminder.
 *
 * Aria: `role="status"` for info/success (non-urgent), `role="alert"`
 * for warning/danger (interrupts AT). Matches WAI-ARIA APG guidance.
 */
export function Banner({
  tone = 'info',
  title,
  actions,
  className,
  children,
  ...rest
}: BannerProps): React.JSX.Element {
  const role = tone === 'warning' || tone === 'danger' ? 'alert' : 'status';

  return (
    <div
      role={role}
      className={cn(
        'flex items-start justify-between gap-4 rounded border px-4 py-3',
        toneClasses[tone],
        className,
      )}
      {...rest}
    >
      <div className="min-w-0 flex-1 text-sm">
        {title && <p className="font-medium leading-tight">{title}</p>}
        {children && <p className={cn(title && 'mt-1', 'leading-snug')}>{children}</p>}
      </div>
      {actions && <div className="shrink-0">{actions}</div>}
    </div>
  );
}

const toneClasses: Record<BannerTone, string> = {
  info: 'border-border bg-surface-soft text-text-primary',
  success: 'border-success-soft-bg bg-success-soft-bg text-success-soft-text',
  warning: 'border-warning-soft-bg bg-warning-soft-bg text-warning-soft-text',
  danger: 'border-danger-border bg-danger-soft-bg text-danger-soft-text',
};
