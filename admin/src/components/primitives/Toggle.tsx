import { forwardRef, type InputHTMLAttributes, type ReactNode } from 'react';

import { cn } from '../../utils/cn';

export interface ToggleProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type' | 'children'> {
  label?: ReactNode;
  description?: ReactNode;
  /** When true, the toggle and label go below 50 % opacity. */
  disabled?: boolean;
}

/**
 * Toggle switch — semantically a checkbox, visually a slider. Used for
 * the "enable feature" rows in Step 4 (Recommendations) and the
 * subscription-checkbox row in Step 2.
 *
 * `role="switch"` is implicit on a checkbox with the visual treatment,
 * but we add it explicitly so VoiceOver/NVDA announce "switch, on/off"
 * instead of "checkbox, checked".
 */
export const Toggle = forwardRef<HTMLInputElement, ToggleProps>(function Toggle(
  { label, description, className, disabled, ...rest },
  ref,
) {
  return (
    <label
      className={cn(
        // Sub-PR 2.H.12 — `flex` (block-level) instead of `inline-flex`
        // so vertical containers (space-y-* / flex-col) actually stack
        // these block-by-block. Erkki's "two toggles on the same row"
        // bug came from <label inline-flex> children sitting in a
        // <div space-y-4>: inline-tasandi flow rule packed them
        // side-by-side until one ran off the right edge.
        'group flex items-center gap-3',
        disabled ? 'cursor-not-allowed opacity-60' : 'cursor-pointer',
        className,
      )}
    >
      <span className="relative inline-flex h-5 w-9 shrink-0">
        {/*
         * Sub-PR 2.H.13 — native input fully hidden, same pattern as
         * Checkbox in 2.H.12. WP-core + Chromium UA together leaked a
         * dark checkmark glyph on top of the brand-pink pill when the
         * input was on but the visible thumb sat below it. Three
         * layers — invisible input, track <span>, thumb <span>.
         */}
        <input
          ref={ref}
          type="checkbox"
          role="switch"
          disabled={disabled}
          className="peer !absolute !inset-0 !m-0 !h-full !w-full !min-w-0 !p-0 !opacity-0 cursor-inherit"
          {...rest}
        />
        {/* Track — colour swap on peer-checked. */}
        <span
          aria-hidden
          className="pointer-events-none absolute inset-0 rounded-full bg-border-strong transition-colors duration-120 peer-checked:bg-brand peer-focus-visible:ring-2 peer-focus-visible:ring-brand peer-focus-visible:ring-offset-1"
        />
        {/* Thumb — slide right on peer-checked. */}
        <span
          aria-hidden
          className="pointer-events-none absolute left-0.5 top-0.5 h-4 w-4 rounded-full bg-text-white transition-transform duration-120 peer-checked:translate-x-4"
        />
      </span>

      {(label || description) && (
        description ? (
          <span className="flex flex-col gap-0.5 select-none">
            {label && (
              <span className="flex h-5 items-center text-sm text-text-primary">{label}</span>
            )}
            <span className="text-sm leading-snug text-text-secondary">{description}</span>
          </span>
        ) : (
          <span className="flex h-5 items-center select-none text-sm text-text-primary">
            {label}
          </span>
        )
      )}
    </label>
  );
});
