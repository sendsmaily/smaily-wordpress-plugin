import { forwardRef, type InputHTMLAttributes, type ReactNode } from 'react';

import { cn } from '../../utils/cn';

export interface CheckboxProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type' | 'children'> {
  /** Visible label rendered to the right of the box. Omit for icon-only contexts. */
  label?: ReactNode;
  /** Secondary description rendered below the label in smaller text. */
  description?: ReactNode;
}

/**
 * Checkbox — single-state boolean toggle. Used heavily in Step 2 for the
 * sync-field grid and Step 4 for the rec-engine feature toggles.
 *
 * Layout pattern: <label> wraps an <input> + visible label text so the
 * full row is clickable. The native checkbox is hidden visually but kept
 * for accessibility (keyboard tabs, screen readers, form submission); a
 * styled square renders on top via peer-checked Tailwind utilities.
 */
export const Checkbox = forwardRef<HTMLInputElement, CheckboxProps>(function Checkbox(
  { label, description, className, disabled, ...rest },
  ref,
) {
  return (
    <label
      className={cn(
        // items-center keeps the box vertically centred against single-line
        // labels (the dominant case — Step 2 field grid, Step 4 toggles).
        // Same fix as Radio.tsx in sub-PR 2.H.1; checkbox missed the
        // sweep, Erkki's screenshot caught it in the next walkthrough.
        // Multi-line labels (description) still flow underneath because
        // the label is rendered with `block` spans.
        // Sub-PR 2.H.12 — block-level `flex` (see Toggle.tsx comment).
        'group flex items-center gap-3',
        disabled ? 'cursor-not-allowed opacity-60' : 'cursor-pointer',
        className,
      )}
    >
      <span className="relative inline-flex h-4 w-4 shrink-0">
        {/*
         * Sub-PR 2.H.12 — native input is FULLY hidden, the visible glyph
         * is a separate <span aria-hidden>.
         *
         * The 2.H.10 attempt fought wp-admin/css/forms.css by promoting
         * every Tailwind utility to !important. That made the BACKGROUND
         * correct (brand-pink on checked) but the native checkbox itself
         * was still being rendered by Chrome's UA stylesheet behind our
         * border. Erkki's screenshot caught the WP-grey square bleeding
         * through under the brand-pink fill — an opacity-0 + absolute
         * native input is the canonical hidden-checkbox pattern and
         * sidesteps every browser-specific quirk we kept hitting.
         */}
        <input
          ref={ref}
          type="checkbox"
          disabled={disabled}
          className="peer !absolute !inset-0 !m-0 !h-full !w-full !min-w-0 !p-0 !opacity-0 cursor-inherit"
          {...rest}
        />
        {/* Visible box — its appearance is driven by peer-checked. */}
        <span
          aria-hidden
          className="pointer-events-none absolute inset-0 rounded-sm border border-border-strong bg-surface transition-colors duration-120 peer-checked:border-brand peer-checked:bg-brand peer-focus-visible:ring-2 peer-focus-visible:ring-brand peer-focus-visible:ring-offset-1"
        />
        {/* Checkmark — visible only when peer is :checked. */}
        <svg
          className="pointer-events-none absolute inset-0 h-full w-full text-text-white opacity-0 peer-checked:opacity-100"
          viewBox="0 0 16 16"
          fill="currentColor"
          aria-hidden
        >
          <path d="M13.2 4.4 6.6 11l-3.8-3.8 1.2-1.2L6.6 8.6l5.4-5.4z" />
        </svg>
      </span>

      {(label || description) && (
        // Same explicit-box alignment fix as Radio — see comment there.
        description ? (
          <span className="flex flex-col gap-0.5 select-none">
            {label && (
              <span className="flex h-4 items-center text-sm text-text-primary">{label}</span>
            )}
            <span className="text-sm leading-snug text-text-secondary">{description}</span>
          </span>
        ) : (
          <span className="flex h-4 items-center select-none text-sm text-text-primary">
            {label}
          </span>
        )
      )}
    </label>
  );
});
