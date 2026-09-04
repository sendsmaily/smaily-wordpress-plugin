import { __ } from '@admin/lib/i18n';
import { cn } from '../../utils/cn';

export interface StepRailItem {
  /** 1-based step number — matches WizardState.currentStep. */
  id: number;
  /** Short label rendered in the rail ("Connect", "Subscribers", …). */
  label: string;
  /** Sub-label rendered under the main label in muted text. */
  description?: string;
  /** Completed = checkmark; otherwise pending. Active is derived from currentStep. */
  completed: boolean;
  /**
   * Locked = unreachable yet. Strict progressive (sub-PR 2.I) marks
   * every step after Step 1 as locked until smailyConnection ===
   * success. Locked items render non-clickable + show a browser
   * tooltip via `title`.
   */
  locked?: boolean;
  /** Optional reason text used as the native browser tooltip on a locked step. */
  lockedReason?: string;
}

export interface StepRailProps {
  /** 1-based step number. Determines which item is rendered as "active". */
  currentStep: number;
  steps: StepRailItem[];
  /**
   * When provided, the rail makes completed + active steps clickable.
   * Pending steps stay disabled — the wizard's canAdvance gating must
   * pass linearly. Omit to render a read-only rail.
   */
  onStepClick?: (step: number) => void;
  className?: string;
}

/**
 * Left sidebar with step indicators. Visual states per item:
 *
 *   completed → check glyph + brand-pink text + clickable (revisit)
 *   active    → numbered circle filled brand + bold label
 *   upcoming  → numbered circle outlined + muted label + non-interactive
 *
 * Keyboard nav: each clickable item is a <button> so Tab + Enter work
 * for free. Pending steps render as <span> so they're skipped by Tab.
 */
export function StepRail({ currentStep, steps, onStepClick, className }: StepRailProps): React.JSX.Element {
  return (
    <nav
      className={cn('w-56 shrink-0 bg-surface-soft px-4 py-6', className)}
      aria-label={__('Wizard steps', 'smaily-connect')}
    >
      <ol className="space-y-1">
        {steps.map((step) => {
          const isActive = step.id === currentStep;
          const isCompleted = step.completed;
          const isLocked = step.locked === true;
          // Locked steps are never clickable. Otherwise the previous rule
          // (completed OR active) still gates back-navigation.
          const isClickable =
            onStepClick !== undefined && !isLocked && (isCompleted || isActive);

          const indicator = (
            <span
              className={cn(
                'inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                isActive && 'bg-brand text-text-white',
                isCompleted && !isActive && 'bg-brand-soft-bg text-brand-soft-text',
                !isCompleted && !isActive && !isLocked && 'border border-border-strong bg-surface text-text-tertiary',
                isLocked && !isActive && 'border border-border-subtle bg-surface text-text-tertiary opacity-60',
              )}
              aria-hidden
            >
              {isCompleted ? (
                <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor">
                  <path d="M13.2 4.4 6.6 11l-3.8-3.8 1.2-1.2L6.6 8.6l5.4-5.4z" />
                </svg>
              ) : (
                step.id
              )}
            </span>
          );

          const labels = (
            <span className="min-w-0 flex-1">
              <span
                className={cn(
                  'block truncate text-sm',
                  isActive && 'font-semibold text-text-primary',
                  !isActive && !isLocked && 'text-text-secondary',
                  isLocked && !isActive && 'text-text-tertiary',
                )}
              >
                {step.label}
              </span>
              {step.description && (
                <span className="block truncate text-xs text-text-tertiary">{step.description}</span>
              )}
            </span>
          );

          const className_ = cn(
            'flex items-center gap-3 rounded px-2 py-2 transition-colors duration-120',
            isActive && 'bg-surface',
            isClickable && !isActive && 'hover:bg-surface',
            isLocked && 'cursor-not-allowed',
          );

          return (
            <li key={step.id}>
              {isClickable ? (
                <button
                  type="button"
                  onClick={() => onStepClick(step.id)}
                  aria-current={isActive ? 'step' : undefined}
                  className={cn(
                    className_,
                    'w-full text-left',
                    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-1',
                  )}
                >
                  {indicator}
                  {labels}
                </button>
              ) : (
                <span
                  className={className_}
                  aria-current={isActive ? 'step' : undefined}
                  aria-disabled={!isActive || undefined}
                  title={isLocked ? step.lockedReason : undefined}
                >
                  {indicator}
                  {labels}
                </span>
              )}
            </li>
          );
        })}
      </ol>
    </nav>
  );
}
