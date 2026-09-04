import { forwardRef, type InputHTMLAttributes, type ReactNode } from 'react';

import { cn } from '../../utils/cn';

export interface InputProps extends InputHTMLAttributes<HTMLInputElement> {
  /** Optional left-side icon — typically lucide-react svg. */
  leadingIcon?: ReactNode;
  /** Optional right-side icon or status indicator. */
  trailingIcon?: ReactNode;
  /** Renders the field in error state (red border + danger-text aria). */
  invalid?: boolean;
  /** Lent for unique error wiring with FormField label. */
  describedById?: string;
}

/**
 * Single-line text input. Used through FormField in step components — the
 * primitive on its own omits the visible <label> so wrappers can choose
 * layout (stacked, inline, with-suffix).
 *
 * forwardRef enables react-hook-form / focus-management callers.
 *
 * aria-invalid is set so screen readers announce the error state; the
 * actual error message lives in the parent FormField + the optional
 * describedById prop points the input at the message via aria-describedby.
 */
export const Input = forwardRef<HTMLInputElement, InputProps>(function Input(
  { leadingIcon, trailingIcon, invalid, describedById, className, type = 'text', ...rest },
  ref,
) {
  return (
    <div className="relative inline-flex w-full items-center">
      {leadingIcon && (
        <span className="pointer-events-none absolute left-3 inline-flex text-text-tertiary" aria-hidden>
          {leadingIcon}
        </span>
      )}

      <input
        ref={ref}
        type={type}
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
          leadingIcon && 'pl-9',
          trailingIcon && 'pr-9',
          rest.disabled && 'cursor-not-allowed bg-surface-muted text-text-secondary',
          className,
        )}
        {...rest}
      />

      {trailingIcon && (
        <span className="pointer-events-none absolute right-3 inline-flex text-text-tertiary" aria-hidden>
          {trailingIcon}
        </span>
      )}
    </div>
  );
});
