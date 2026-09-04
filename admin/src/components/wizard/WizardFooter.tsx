import { __ } from '@admin/lib/i18n';
import { Button } from '../primitives';
import { cn } from '../../utils/cn';

export interface WizardFooterProps {
  /** 1-based current step (1 to totalSteps). */
  currentStep: number;
  /** Final step number — 6 for the BETA. Finish button replaces Continue here. */
  totalSteps: number;
  /** Wired to dispatch({ type: 'WIZARD_PREVIOUS_STEP' }). Hidden on step 1. */
  onBack?: () => void;
  /** Wired to dispatch({ type: 'WIZARD_NEXT_STEP' }) for steps 1..N-1. */
  onContinue?: () => void;
  /** Fired on the last step instead of onContinue. */
  onFinish?: () => void;
  /**
   * False when the current step's required fields aren't yet valid. The
   * Continue / Finish button disables and the optional `advanceHint`
   * surfaces under it.
   */
  canAdvance: boolean;
  /** Help text rendered beside / below Continue when canAdvance is false. */
  advanceHint?: string;
  /** Loading state for async-deferred Continue/Finish (e.g. last-second save). */
  loading?: boolean;
  className?: string;
}

/**
 * Footer rail rendered under every wizard step. Three buttons in two
 * configurations:
 *
 *   Step 1     → [          ]  [Continue]
 *   Step 2..5  → [Back]        [Continue]
 *   Step 6     → [Back]        [Finish]
 *
 * STYLE_MAPPING.md §2.2 correction (sub-PR 2.D): Finish stays brand-pink
 * to match Continue, not the earlier green proposal. Single primary
 * action colour is the canonical Smaily-UI pattern.
 */
export function WizardFooter({
  currentStep,
  totalSteps,
  onBack,
  onContinue,
  onFinish,
  canAdvance,
  advanceHint,
  loading,
  className,
}: WizardFooterProps): React.JSX.Element {
  const isFirstStep = currentStep <= 1;
  const isLastStep = currentStep >= totalSteps;

  return (
    <div
      className={cn(
        'flex items-center justify-between gap-4 border-t border-border-subtle bg-surface px-6 py-4',
        className,
      )}
    >
      <div className="flex items-center">
        {!isFirstStep && onBack && (
          <Button variant="ghost" onClick={onBack} type="button">
            {__('← Back', 'smaily-connect')}
          </Button>
        )}
      </div>

      <div className="flex items-center gap-3">
        {advanceHint && !canAdvance && (
          <p className="text-sm text-text-tertiary" role="note">
            {advanceHint}
          </p>
        )}

        {isLastStep ? (
          <Button
            variant="primary"
            onClick={onFinish}
            disabled={!canAdvance}
            loading={loading}
            type="button"
          >
            {__('Finish', 'smaily-connect')}
          </Button>
        ) : (
          <Button
            variant="primary"
            onClick={onContinue}
            disabled={!canAdvance}
            loading={loading}
            type="button"
          >
            {__('Continue →', 'smaily-connect')}
          </Button>
        )}
      </div>
    </div>
  );
}
