import { type ReactNode } from 'react';

import { cn } from '../../utils/cn';

export interface PillTab<TValue extends string> {
  value: TValue;
  label: ReactNode;
  /** Optional trailing count or chip ("Failed (3)"). */
  badge?: ReactNode;
  disabled?: boolean;
  /** Native browser tooltip — used to explain why a disabled tab is locked. */
  title?: string;
}

export interface PillTabsProps<TValue extends string> {
  tabs: PillTab<TValue>[];
  value: TValue;
  onChange: (next: TValue) => void;
  /** Accessible label for the tablist. Required for screen readers. */
  ariaLabel: string;
  className?: string;
}

/**
 * Horizontal tab strip with pill-styled tabs — the Settings nav and the
 * Step-3 trigger picker use this pattern.
 *
 * Uses WAI-ARIA tabs pattern: role="tablist" + role="tab" + aria-selected.
 * Arrow-key navigation lives in the consumer for now; Phase 4 polish
 * may extract keyboard handling into a hook if multiple tablists pile
 * up.
 */
export function PillTabs<TValue extends string>({
  tabs,
  value,
  onChange,
  ariaLabel,
  className,
}: PillTabsProps<TValue>): React.JSX.Element {
  return (
    <div
      role="tablist"
      aria-label={ariaLabel}
      className={cn(
        'inline-flex items-center gap-1 rounded-full bg-surface-muted p-1',
        className,
      )}
    >
      {tabs.map((tab) => {
        const isActive = tab.value === value;
        return (
          <button
            key={tab.value}
            type="button"
            role="tab"
            aria-selected={isActive}
            disabled={tab.disabled}
            title={tab.title}
            onClick={() => onChange(tab.value)}
            className={cn(
              'inline-flex h-8 items-center gap-1.5 rounded-full px-3 text-sm font-medium',
              'transition-colors duration-120',
              'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-1',
              isActive
                ? 'bg-surface text-text-primary shadow-sm'
                : 'text-text-secondary hover:text-text-primary',
              tab.disabled && 'cursor-not-allowed opacity-50',
            )}
          >
            {tab.label}
            {tab.badge !== undefined && <span className="ml-0.5">{tab.badge}</span>}
          </button>
        );
      })}
    </div>
  );
}
