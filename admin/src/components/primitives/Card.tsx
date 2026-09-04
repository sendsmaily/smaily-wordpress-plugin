import { type HTMLAttributes, type ReactNode } from 'react';

import { cn } from '../../utils/cn';

export interface CardProps extends Omit<HTMLAttributes<HTMLDivElement>, 'title'> {
  /** Larger title rendered as the card header. Omit when the card wraps standalone content. */
  title?: ReactNode;
  /** Sub-heading rendered under the title in muted text. */
  description?: ReactNode;
  /** Right-aligned slot for buttons / pills in the header row. */
  headerAccessory?: ReactNode;
}

/**
 * Card — every grouped block of fields in the wizard sits inside one.
 *
 * Two layouts:
 *   - Header + body: pass title / description / headerAccessory →
 *     produces the standard "Smaily section card" look.
 *   - Body-only: no title, just children → a plain bordered container.
 *
 * Padding lives on the inner sections rather than the outer wrapper so
 * the divider line between header and body lands flush with the edges
 * (cleaner than `p-6` + negative margin tricks).
 */
export function Card({
  title,
  description,
  headerAccessory,
  className,
  children,
  ...rest
}: CardProps): React.JSX.Element {
  const hasHeader = title !== undefined || description !== undefined || headerAccessory !== undefined;

  return (
    <div
      className={cn(
        'overflow-hidden rounded-lg border border-border bg-surface shadow-card',
        className,
      )}
      {...rest}
    >
      {hasHeader && (
        <div className="flex items-start justify-between gap-4 border-b border-border-subtle px-6 py-4">
          <div className="min-w-0">
            {title && <h3 className="text-lg font-semibold text-text-primary">{title}</h3>}
            {description && <p className="mt-1 text-sm text-text-secondary">{description}</p>}
          </div>
          {headerAccessory && <div className="shrink-0">{headerAccessory}</div>}
        </div>
      )}
      <div className="px-6 py-5">{children}</div>
    </div>
  );
}
