import { useReducer, useState } from 'react';

import { saveSettings } from '../../api/saveSettings';
import { buildTabPayload } from '../../state/buildTabPayload';
import { saveEngineAutomations } from '../../state/engine-automations';
import { wizardReducer } from '../../state/wizard-reducer';
import { type SettingsTabKey, type WizardState } from '../../state/types';
import { __, sprintf } from '@admin/lib/i18n';
import { Banner } from '../primitives';
import {
  Step1Connect,
  Step2Subscribers,
  Step3WooCommerce,
  Step4Recommendations,
  Step5Integrations,
  Step6Done,
} from '../steps';
import { StepRail, type StepRailItem } from './StepRail';
import { WizardFooter } from './WizardFooter';

export interface WizardProps {
  initialState: WizardState;
}

const STEP_LABELS: Array<{ label: string; description: string }> = [
  {
    label: __('Create a connection', 'smaily-connect'),
    description: __('Set up Smaily account connections', 'smaily-connect'),
  },
  {
    label: __('Contacts', 'smaily-connect'),
    description: __('Synchronization settings', 'smaily-connect'),
  },
  {
    label: __('Automated letters', 'smaily-connect'),
    description: __('Setting up triggers for automations', 'smaily-connect'),
  },
  {
    label: __('Campaign Intelligence', 'smaily-connect'),
    description: __('Create a connection with Campaign Intelligence (optional)', 'smaily-connect'),
  },
  {
    label: __('Subscription forms and RSS', 'smaily-connect'),
    description: __('Setting up subscription forms', 'smaily-connect'),
  },
  { label: __('Overview', 'smaily-connect'), description: __('Summary and last check', 'smaily-connect') },
];

/**
 * Wizard root — orchestrates the six steps via a single useReducer
 * driven by wizardReducer. State is hydrated from the PHP boot payload
 * (admin/wizard.php → wp_localize_script → window.smailyConnectBoot)
 * inside admin/src/index.tsx before this component receives it.
 *
 * Per-step layout: <StepRail | step body | WizardFooter>.
 *
 * canAdvance gating (Phase 2):
 *   Step 1 — requires smailyConnection.kind === 'success'
 *   Step 2-5 — always pass (toggles + info)
 *   Step 6 — Finish enabled when Step 1 connection passed
 *
 * The Finish handler is a stub in Phase 2 — it just closes the wizard
 * by reloading to the Settings page. Phase 3 wires it to a bulk save
 * across the four /settings tabs.
 */
export function Wizard({ initialState }: WizardProps): React.JSX.Element {
  const [state, dispatch] = useReducer(wizardReducer, initialState);
  const [navStatus, setNavStatus] = useState<'idle' | 'saving' | 'error'>('idle');
  const [navError, setNavError] = useState<string | null>(null);

  // Strict progressive (sub-PR 2.I): Steps 2+ require a verified
  // Smaily connection. Locked steps render non-clickable in the rail +
  // show a tooltip explaining why. The StepRail still treats completed
  // steps as clickable so back-navigation works once the gate clears.
  const isConnected = state.smailyConnection.kind === 'success';
  const railItems: StepRailItem[] = STEP_LABELS.map((entry, idx) => {
    const stepId = idx + 1;
    const locked = !isConnected && stepId > 1;
    return {
      id: stepId,
      label: entry.label,
      description: entry.description,
      completed: state.currentStep > stepId,
      locked,
      lockedReason: locked ? __('Complete Step 1 (Connect) first.', 'smaily-connect') : undefined,
    };
  });

  const canAdvance = computeCanAdvance(state);
  const advanceHint = computeAdvanceHint(state);

  const saveAndAdvance = async (tab: SettingsTabKey | null): Promise<boolean> => {
    if (tab === null) {
      return true;
    }
    setNavStatus('saving');
    setNavError(null);
    const payload = buildTabPayload(state, tab);
    try {
      const response = await saveSettings({ tab, data: payload });
      if (!response.saved) {
        const message =
          response.errors.map((e) => `${e.field}: ${e.message}`).join('; ') ||
          __('Save failed.', 'smaily-connect');
        setNavStatus('error');
        setNavError(
          // translators: %1$s is the settings tab key, %2$s is the error detail.
          sprintf(__('Couldn’t save %1$s: %2$s', 'smaily-connect'), tab, message),
        );
        return false;
      }
    } catch (err) {
      const message = err instanceof Error ? err.message : __('Network error', 'smaily-connect');
      setNavStatus('error');
      setNavError(
        // translators: %1$s is the settings tab key, %2$s is the error detail.
        sprintf(__('Couldn’t save %1$s: %2$s', 'smaily-connect'), tab, message),
      );
      return false;
    }
    setNavStatus('idle');
    return true;
  };

  // Sub-PR 2.H.18 — save-on-Continue.
  //
  // Each Continue persists the step's payload before navigating.
  // Step 5 (Integrations) is info-only — Continue navigates without
  // a save. Step 6 is the Finish action, not Continue.
  //
  // Step 3 additionally saves the engine-run automations draft (T2.2)
  // through the rec-engine proxy, in parallel with the local POST —
  // the same two-request split the Settings sticky footer does
  // (F3-52). Either failure keeps the merchant on the step; an engine
  // failure renders inside the section (all-or-nothing §13, the slice
  // stays dirty) so nothing is silently lost.
  const handleContinue = async (): Promise<void> => {
    const tab = stepToTab(state.currentStep);
    const needsEngineSave = state.currentStep === 3 && state.engineAutomations.dirty;
    const [localOk, engineOk] = await Promise.all([
      saveAndAdvance(tab),
      needsEngineSave
        ? saveEngineAutomations(state.engineAutomations.rows, dispatch)
        : Promise.resolve(true),
    ]);
    if (!localOk) {
      // Stay on the current step so the merchant can correct or retry.
      return;
    }
    if (!engineOk) {
      setNavStatus('error');
      setNavError(
        __(
          "Couldn't save the engine-run automations — see that section below for details.",
          'smaily-connect',
        ),
      );
      return;
    }
    dispatch({ type: 'WIZARD_NEXT_STEP' });
  };

  const handleBack = (): void => dispatch({ type: 'WIZARD_PREVIOUS_STEP' });

  // Finish is now lightweight — every step has already persisted its
  // slice on Continue, so this just flips the setup-completed flag
  // and redirects to Settings.
  const handleFinish = async (): Promise<void> => {
    const ok = await saveAndAdvance('finish');
    if (!ok) {
      return;
    }
    if (typeof window !== 'undefined') {
      window.location.href = 'admin.php?page=smaily-connect-settings';
    }
  };

  const handleStepClick = (step: number): void => {
    // Defensive: StepRail already gates this on isClickable, but if a
    // future call-site bypasses the rail (deep-link, dev console) we
    // still refuse to land on a step that's not reachable yet.
    if (!isConnected && step > 1) {
      return;
    }
    dispatch({ type: 'WIZARD_GO_TO_STEP', payload: { step } });
  };

  return (
    <div className="min-h-screen bg-page-bg font-sans text-text-primary">
      <div className="mx-auto flex max-w-6xl gap-6 px-6 py-8">
        <StepRail
          currentStep={state.currentStep}
          steps={railItems}
          onStepClick={handleStepClick}
        />

        <div className="min-w-0 flex-1">
          <div className="rounded-lg bg-surface shadow-card">
            <div className="px-6 py-6">
              {renderStep(state, dispatch)}
              {navStatus === 'error' && navError !== null && (
                <Banner tone="danger" className="mt-4">
                  {navError}
                </Banner>
              )}
            </div>

            <WizardFooter
              currentStep={state.currentStep}
              totalSteps={6}
              onBack={handleBack}
              onContinue={() => void handleContinue()}
              onFinish={() => void handleFinish()}
              canAdvance={canAdvance}
              advanceHint={advanceHint}
              loading={navStatus === 'saving'}
            />
          </div>
        </div>
      </div>
    </div>
  );
}

function renderStep(
  state: WizardState,
  dispatch: React.Dispatch<Parameters<typeof wizardReducer>[1]>,
): React.JSX.Element {
  switch (state.currentStep) {
    case 1:
      return <Step1Connect state={state} dispatch={dispatch} />;
    case 2:
      return <Step2Subscribers state={state} dispatch={dispatch} />;
    case 3:
      return <Step3WooCommerce state={state} dispatch={dispatch} />;
    case 4:
      return <Step4Recommendations state={state} dispatch={dispatch} />;
    case 5:
      return <Step5Integrations state={state} />;
    case 6:
      return <Step6Done state={state} />;
    default:
      return <Step1Connect state={state} dispatch={dispatch} />;
  }
}

/**
 * Map a 1-based wizard step to the settings tab whose payload it owns.
 * Step 5 (Integrations) is info-only — no tab. Step 6 is the Finish
 * action, which uses the 'finish' pseudo-tab on its own.
 */
function stepToTab(step: number): SettingsTabKey | null {
  switch (step) {
    case 1:
      return 'connection';
    case 2:
      return 'subscribers';
    case 3:
      return 'woocommerce';
    case 4:
      return 'recommendations';
    case 5:
    case 6:
    default:
      return null;
  }
}

function computeCanAdvance(state: WizardState): boolean {
  if (state.currentStep === 1) {
    return state.smailyConnection.kind === 'success';
  }
  if (state.currentStep === 6) {
    return state.smailyConnection.kind === 'success';
  }
  return true;
}

function computeAdvanceHint(state: WizardState): string | undefined {
  if (state.currentStep === 1 && state.smailyConnection.kind !== 'success') {
    return __('Test your Smaily connection to continue.', 'smaily-connect');
  }
  return undefined;
}
