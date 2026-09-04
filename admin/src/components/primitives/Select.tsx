import { forwardRef, type SelectHTMLAttributes } from 'react';

import { cn } from '../../utils/cn';

export interface SelectOption {
  value: string;
  label: string;
  /** Renders the option with reduced opacity but still selectable. */
  hint?: string;
  disabled?: boolean;
}

export interface SelectProps extends Omit<SelectHTMLAttributes<HTMLSelectElement>, 'children'> {
  options: SelectOption[];
  /** When true, renders a "— pick —" placeholder as the first <option>. */
  placeholder?: string;
  invalid?: boolean;
  describedById?: string;
}

/**
 * Native <select> styled to match the Input field. Used in Step 3 for
 * workflow selection and Step 1 for the Mode-A account-key picker.
 *
 * We deliberately don't ship a combobox / typeahead in Phase 2 —
 * Smaily's autoresponder lists hit tens of entries at most, and the
 * native control gives us free keyboard nav + screen-reader support.
 * Phase 4 polish can swap to a custom combobox if pilot feedback
 * demands.
 */
export const Select = forwardRef<HTMLSelectElement, SelectProps>(function Select(
  { options, placeholder, invalid, describedById, className, ...rest },
  ref,
) {
  return (
    <div className="relative inline-flex w-full items-center">
      <select
        ref={ref}
        aria-invalid={invalid || undefined}
        aria-describedby={describedById}
        className={cn(
          'h-10 w-full appearance-none rounded border bg-surface pl-3 pr-10 text-base',
          'text-text-primary transition-colors duration-120',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-1',
          invalid
            ? 'border-danger-border focus-visible:ring-danger'
            : 'border-border-strong hover:border-border-cool',
          rest.disabled && 'cursor-not-allowed bg-surface-muted text-text-secondary',
          className,
        )}
        {...rest}
      >
        {placeholder !== undefined && (
          <option value="" disabled>
            {placeholder}
          </option>
        )}
        {options.map((opt) => (
          <option key={opt.value} value={opt.value} disabled={opt.disabled}>
            {opt.hint ? `${opt.label} — ${opt.hint}` : opt.label}
          </option>
        ))}
      </select>

      {/* Chevron — pointer-events-none so the native click-area extends across it. */}
      <span
        className="pointer-events-none absolute right-3 inline-flex text-text-tertiary"
        aria-hidden
      >
        <svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor">
          <path
            fillRule="evenodd"
            d="M5.23 7.21a.75.75 0 011.06.02L10 11.06l3.71-3.83a.75.75 0 111.08 1.04l-4.25 4.39a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z"
            clipRule="evenodd"
          />
        </svg>
      </span>
    </div>
  );
});
