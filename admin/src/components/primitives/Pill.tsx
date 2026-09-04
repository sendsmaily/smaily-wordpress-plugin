import { type HTMLAttributes } from 'react';

import { cn } from '../../utils/cn';

export type PillTone = 'brand' | 'success' | 'warning' | 'danger' | 'neutral';
export type PillSize = 'sm' | 'md';

export interface PillProps extends HTMLAttributes<HTMLSpanElement> {
  tone?: PillTone;
  size?: PillSize;
  /** Optional small dot rendered before the label. */
  dot?: boolean;
}

/**
 * Pill — status label rendered inline next to headings or table rows.
 * "Connected", "Newsletter", "Requires consent", "Failed (3)" patterns
 * from the prototype.
 *
 * Tone palette mirrors the four semantic banner tones plus a neutral
 * grey for non-semantic tags ("Beta", "Optional").
 */
export function Pill({
  tone = 'brand',
  size = 'sm',
  dot = false,
  className,
  children,
  ...rest
}: PillProps): React.JSX.Element {
  return (
    <span
      className={cn(
        'inline-flex items-center gap-1.5 rounded-full font-medium',
        size === 'sm' ? 'h-5 px-2 text-xs' : 'h-6 px-2.5 text-sm',
        toneClasses[tone],
        className,
      )}
      {...rest}
    >
      {dot && <span className={cn('inline-block h-1.5 w-1.5 rounded-full', dotClasses[tone])} aria-hidden />}
      {children}
    </span>
  );
}

const toneClasses: Record<PillTone, string> = {
  brand: 'bg-brand-soft-bg text-brand-soft-text',
  success: 'bg-success-soft-bg text-success-soft-text',
  warning: 'bg-warning-soft-bg text-warning-soft-text',
  danger: 'bg-danger-soft-bg text-danger-soft-text',
  neutral: 'bg-surface-muted text-text-secondary',
};

const dotClasses: Record<PillTone, string> = {
  brand: 'bg-brand',
  success: 'bg-success',
  warning: 'bg-warning',
  danger: 'bg-danger',
  neutral: 'bg-border-cool',
};
