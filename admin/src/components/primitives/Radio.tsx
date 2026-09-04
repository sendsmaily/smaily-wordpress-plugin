import { forwardRef, type InputHTMLAttributes, type ReactNode } from 'react';

import { cn } from '../../utils/cn';

export interface RadioProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type' | 'children'> {
  label?: ReactNode;
  description?: ReactNode;
}

/**
 * Radio button — one-of-many. Used for the MultilingualModePicker
 * (Mode A / B / C / single) and for the Step 3 "default fallback"
 * marker per language row.
 *
 * Same hidden-native-input pattern as Checkbox; just a circular dot
 * instead of a checkmark for the checked state.
 */
export const Radio = forwardRef<HTMLInputElement, RadioProps>(function Radio(
  { label, description, className, disabled, ...rest },
  ref,
) {
  return (
    <label
      className={cn(
        // items-center keeps the dot vertically centred with the label's
        // first line — items-start (the old value) drifted the dot up a
        // pixel or two on font-semibold labels like the
        // MultilingualModePicker card titles, which Erkki's staging
        // walkthrough surfaced as visibly misaligned.
        // Sub-PR 2.H.12 — block-level `flex` (see Toggle.tsx comment).
        'group flex items-center gap-3',
        disabled ? 'cursor-not-allowed opacity-60' : 'cursor-pointer',
        className,
      )}
    >
      <span className="relative inline-flex h-4 w-4 shrink-0">
        {/* Sub-PR 2.H.13 — same hidden-input pattern as Checkbox + Toggle. */}
        <input
          ref={ref}
          type="radio"
          disabled={disabled}
          className="peer !absolute !inset-0 !m-0 !h-full !w-full !min-w-0 !p-0 !opacity-0 cursor-inherit"
          {...rest}
        />
        {/* Visible outer ring. */}
        <span
          aria-hidden
          className="pointer-events-none absolute inset-0 rounded-full border border-border-strong bg-surface transition-colors duration-120 peer-checked:border-brand peer-focus-visible:ring-2 peer-focus-visible:ring-brand peer-focus-visible:ring-offset-1"
        />
        {/* Inner brand dot — visible only when peer is :checked. */}
        <span
          aria-hidden
          className="pointer-events-none absolute left-1/2 top-1/2 h-2 w-2 -translate-x-1/2 -translate-y-1/2 rounded-full bg-brand opacity-0 peer-checked:opacity-100"
        />
      </span>

      {(label || description) && (
        // sub-PR 2.H.4 alignment fix.
        //
        // The previous attempts (items-center alone in 2.H.1, then
        // items-center + leading-[1rem] in 2.H.3) still drifted because
        // they relied on the text line-box height matching the dot
        // wrapper. Tailwind's arbitrary leading value worked locally
        // but Inter's vertical metrics inside the line-box left the
        // glyphs ~1px above the box centre on Erkki's staging.
        //
        // Concrete fix: give the label row an EXPLICIT 16px height
        // (h-4 = same as the dot wrapper) and centre the text inside
        // it via flex items-center. Pixel-perfect alignment now comes
        // from two identical 16px boxes, not from line-box trickery.
        // The description (when present) flows underneath at its own
        // natural leading.
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
