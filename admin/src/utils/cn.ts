import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

/**
 * Class-name composer used by every primitive in admin/src/components/.
 *
 * Two layers:
 *
 *   1. clsx handles the conditional logic — accepts strings, arrays,
 *      objects with truthy values, and skips falsy entries silently.
 *      Example: cn('p-4', isActive && 'bg-brand', { 'opacity-50': isDisabled })
 *
 *   2. tailwind-merge resolves the utility-precedence problem. When a
 *      caller composes `cn('p-4', isDense && 'p-2')`, naive concatenation
 *      yields `"p-4 p-2"` and Tailwind picks whichever rule appears
 *      later in the stylesheet — non-deterministic, surprising. twMerge
 *      keeps only the *last* utility within each functional group, so
 *      `cn('p-4', 'p-2')` reliably produces `"p-2"`.
 *
 * STYLE_MAPPING.md §4.5 documents the same pattern Erkki specified for
 * sub-PR 2.C. Keep the dep set tight: clsx (~600 B) + tailwind-merge
 * (~3 KB) is the canonical industry combo. A bespoke implementation
 * would miss the variant-group bookkeeping twMerge handles.
 */
export function cn(...inputs: ClassValue[]): string {
  return twMerge(clsx(inputs));
}
