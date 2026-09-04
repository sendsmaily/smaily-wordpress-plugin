import { forwardRef, type InputHTMLAttributes } from 'react';

import { cn } from '../../utils/cn';

export interface NumberInputProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type'> {
  invalid?: boolean;
  describedById?: string;
  /** Optional unit-label rendered as trailing static text (e.g. "minutes"). */
  unit?: string;
}

/**
 * Numeric input — used for the abandoned-cart cutoff slider and similar
 * integer-only fields in Step 3. Distinct from Input because:
 *
 *   1. type="number" + inputMode="numeric" gives mobile users the
 *      digit keyboard.
 *   2. A right-aligned unit label (e.g. "minutes") often accompanies
 *      the value; the primitive renders it inline rather than
 *      requiring every caller to wrap in a flexbox.
 */
export const NumberInput = forwardRef<HTMLInputElement, NumberInputProps>(function NumberInput(
  { invalid, describedById, unit, className, ...rest },
  ref,
) {
  return (
    <div className="relative inline-flex w-full items-center">
      <input
        ref={ref}
        type="number"
        inputMode="numeric"
        aria-invalid={invalid || undefined}
        aria-describedby={describedById}
        className={cn(
          'w-full rounded border bg-surface text-base text-text-primary',
          'h-10 px-3 placeholder:text-text-tertiary',
          'transition-colors duration-120',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-1',
          invalid
            ? 'border-danger-border focus-visible:ring-danger'
            : 'border-border-strong hover:border-border-cool',
          unit && 'pr-14',
          rest.disabled && 'cursor-not-allowed bg-surface-muted text-text-secondary',
          className,
        )}
        {...rest}
      />

      {unit && (
        <span className="pointer-events-none absolute right-3 text-sm text-text-secondary" aria-hidden>
          {unit}
        </span>
      )}
    </div>
  );
});
