import {
  useCallback,
  useEffect,
  useMemo,
  useReducer,
  useState,
  type Dispatch,
} from 'react';

import { useSaveSettings } from '../../hooks/useSaveSettings';
import { actionToTab } from '../../state/action-to-tab';
import { buildTabPayload } from '../../state/buildTabPayload';
import { saveEngineAutomations } from '../../state/engine-automations';
import {
  buildSettingsInitialState,
  type ServerEnv,
} from '../../state/settings-reducer';
import {
  type SettingsTabKey,
  type WizardAction,
  type WizardState,
} from '../../state/types';
import { wizardReducer } from '../../state/wizard-reducer';
import { __, sprintf } from '@admin/lib/i18n';
import { Banner, Button, PillTabs } from '../primitives';
import {
  Step1Connect,
  Step2Subscribers,
  Step3WooCommerce,
  Step4Recommendations,
  Step5Integrations,
} from '../steps';
import { EventLog } from './EventLog';

export interface SettingsProps {
  initialEnv?: ServerEnv;
  /**
   * Pre-hydrated state from admin/src/state/hydrate.ts. When provided,
   * supersedes `initialEnv` — App.tsx hydrates once from the PHP boot
   * payload and hands the same WizardState to both Wizard and Settings.
   * Tests + Storybook still construct Settings with just `initialEnv`.
   */
  initialState?: WizardState;
}

const TABS: Array<{ value: SettingsTabKey | 'integrations' | 'events'; label: string }> = [
  { value: 'connection', label: __('Connection', 'smaily-connect') },
  { value: 'subscribers', label: __('Contacts', 'smaily-connect') },
  { value: 'woocommerce', label: __('WooCommerce', 'smaily-connect') },
  { value: 'recommendations', label: __('Campaign Intelligence', 'smaily-connect') },
  { value: 'integrations', label: __('Integrations', 'smaily-connect') },
  { value: 'events', label: __('Event Log', 'smaily-connect') },
];

type AnyTab = SettingsTabKey | 'integrations' | 'events';

/**
 * Settings root — orchestrates tab routing + per-tab dirty tracking +
 * Save / Discard CTAs.
 *
 * Architectural notes:
 *
 *   1. Same wizardReducer drives state. inSettings=true on each step
 *      component hides the wizard-only chrome (eyebrow, footer). Step 6
 *      doesn't exist as a tab — it's a confirmation-time-only view.
 *
 *   2. Tab routing through location.hash: visiting wp-admin.../settings
 *      #subscribers lands on the Subscribers tab. Browser back/forward
 *      works for free; the user can share a deep link with a colleague.
 *
 *   3. Per-tab dirty tracking via a `taggedDispatch` wrapper. Every
 *      state-mutating action goes through actionToTab() to figure out
 *      which tab's dirty flag to flip. Connection-test lifecycles and
 *      polling events are intentionally non-dirty.
 *
 *   4. Mode A → other-mode swap surfaces a window.confirm() when
 *      perLanguageAccounts has entries that would be discarded. The
 *      reducer's SET_MULTILINGUAL_MODE handler clears the list
 *      automatically; the confirm gates the dispatch.
 *
 *   5. Discard = full page reload — re-fetches server-loaded state.
 *      Phase 4 polish can snapshot pristine state for an in-place revert,
 *      but for the pilot a hard reload is acceptable and bulletproof.
 */
export function Settings({ initialEnv = {}, initialState }: SettingsProps): React.JSX.Element {
  const [rawState, rawDispatch] = useReducer(
    wizardReducer,
    null,
    () => initialState ?? buildSettingsInitialState(initialEnv),
  );

  // Mode-A destructive-change guard + dirty-tab tagging wrap.
  const dispatch = useMemo<Dispatch<WizardAction>>(() => {
    return (action: WizardAction) => {
      if (action.type === 'SET_MULTILINGUAL_MODE') {
        const hasAccountsToLose =
          rawState.multilingualMode === 'A' &&
          rawState.perLanguageAccounts.length > 0 &&
          action.payload !== 'A';
        if (hasAccountsToLose) {
          const confirmed = window.confirm(
            sprintf(
              // translators: %1$s is the target mode letter, %2$d is the number of credential sets.
              __(
                'Switching to Mode %1$s will discard the %2$d per-language credential set(s) you\'ve configured. Continue?',
                'smaily-connect',
              ),
              action.payload,
              rawState.perLanguageAccounts.length,
            ),
          );
          if (!confirmed) {
            return;
          }
        }
      }

      rawDispatch(action);
      const tab = actionToTab(action);
      if (tab !== null) {
        rawDispatch({ type: 'MARK_TAB_DIRTY', payload: { tab } });
      }
    };
  }, [rawDispatch, rawState.multilingualMode, rawState.perLanguageAccounts.length]);

  const [activeTab, setActiveTab] = useTabRouting('connection');

  // Progressive disclosure (sub-PR 2.I). The wizard-first gate at the PHP
  // layer makes sure no one reaches Settings before Step 1 has been
  // completed at least once. But the merchant can still break their
  // connection from inside Settings (rotate credentials, paste garbage,
  // etc.); when that happens we lock the dependent tabs so they can't
  // silently save against an unauthenticated state.
  //
  // Locked tabs: Subscribers, WooCommerce, Recommendations.
  //   Connection is always accessible (that's where you fix the break).
  //   Integrations is info-only — never locked.
  //
  // If the user lands on a locked tab via a #hash deep-link or a
  // post-broken-state reload, bounce them back to Connection so the
  // CTA banner makes sense.
  const isConnected = rawState.smailyConnection.kind === 'success';
  const lockedTabs = useMemo<ReadonlySet<AnyTab>>(
    () =>
      isConnected
        ? new Set<AnyTab>()
        : new Set<AnyTab>(['subscribers', 'woocommerce', 'recommendations']),
    [isConnected],
  );

  useEffect(() => {
    if (lockedTabs.has(activeTab)) {
      setActiveTab('connection');
    }
  }, [lockedTabs, activeTab, setActiveTab]);

  const { mutate: save, status: saveStatus, error: saveError } = useSaveSettings({
    onSuccess: (_response, request) => {
      rawDispatch({
        type: 'CLEAR_TAB_DIRTY',
        payload: { tab: request.tab as SettingsTabKey },
      });
    },
  });

  const handleSave = useCallback((): void => {
    if (activeTab === 'integrations' || activeTab === 'events') {
      return;
    }
    const payload = buildTabPayload(rawState, activeTab);
    void save({ tab: activeTab, data: payload });

    // The engine-run automations section (T2.2) joins the WooCommerce
    // tab's Save: TWO parallel requests — the local POST above plus a
    // PUT through the rec-engine proxy when the engine slice is dirty.
    // The outcomes are independent on purpose: if only the PUT fails,
    // the local half is saved, the engine slice stays dirty and its
    // error renders inside the section — the merchant loses neither
    // half (F3-52).
    if (activeTab === 'woocommerce' && rawState.engineAutomations.dirty) {
      void saveEngineAutomations(rawState.engineAutomations.rows, rawDispatch);
    }
  }, [activeTab, rawState, save]);

  const handleDiscard = useCallback((): void => {
    if (!window.confirm(__('Discard unsaved changes and reload from the server?', 'smaily-connect'))) {
      return;
    }
    window.location.reload();
  }, []);

  // 'finish' is a wizard-only pseudo-tab and never lands in PillTabs;
  // narrow it out alongside 'integrations' so dirtyTabs indexing is
  // safe.
  const tabIsDirty =
    activeTab !== 'integrations' && activeTab !== 'finish' && activeTab !== 'events'
      ? rawState.dirtyTabs[activeTab]
      : false;

  // The engine-automations slice keeps its own dirty bit (a partial
  // save failure must leave only the engine section dirty, F3-52); the
  // WooCommerce tab's footer ORs it in so Save lights up for either half.
  const engineDirty = activeTab === 'woocommerce' && rawState.engineAutomations.dirty;
  const enginePending = rawState.engineAutomations.saveStatus === 'pending';
  const canSaveOrDiscard = tabIsDirty || engineDirty;

  return (
    <div className="min-h-screen bg-page-bg font-sans text-text-primary">
      <div className="mx-auto max-w-5xl space-y-6 p-6">
        <header className="flex items-center justify-between gap-4">
          <div>
            <h1 className="text-2xl font-semibold">{__('Smaily Connect — Settings', 'smaily-connect')}</h1>
            <p className="mt-1 text-sm text-text-secondary">
              {__('Manage your Smaily integration. Each tab saves independently.', 'smaily-connect')}
            </p>
          </div>

          <PillTabs
            tabs={TABS.map((t) => {
              const isLocked = lockedTabs.has(t.value);
              return {
                value: t.value,
                label: t.label,
                badge:
                  t.value !== 'integrations' &&
                  t.value !== 'finish' &&
                  t.value !== 'events' &&
                  (rawState.dirtyTabs[t.value] ||
                    (t.value === 'woocommerce' && rawState.engineAutomations.dirty))
                    ? '•'
                    : undefined,
                disabled: isLocked,
                title: isLocked
                  ? __('Connect to Smaily first to unlock this tab.', 'smaily-connect')
                  : undefined,
              };
            })}
            value={activeTab}
            onChange={(next) => setActiveTab(next as AnyTab)}
            ariaLabel={__('Settings tabs', 'smaily-connect')}
          />
        </header>

        {!isConnected && (
          <Banner tone="warning" title={__('Smaily connection required', 'smaily-connect')}>
            {__(
              'Contacts, WooCommerce, and Campaign Intelligence are locked until your Smaily credentials authenticate. Fix the connection on the Connection tab to unlock them.',
              'smaily-connect',
            )}
          </Banner>
        )}
        {saveStatus === 'error' && saveError !== null && (
          <Banner tone="danger" title={__('Save failed', 'smaily-connect')}>
            {saveError}
          </Banner>
        )}
        {saveStatus === 'success' && (
          <Banner tone="success">{__('Settings saved.', 'smaily-connect')}</Banner>
        )}

        <main>
          <TabPanel active={activeTab === 'connection'}>
            <Step1Connect state={rawState} dispatch={dispatch} inSettings />
          </TabPanel>
          <TabPanel active={activeTab === 'subscribers'}>
            <Step2Subscribers
              state={rawState}
              dispatch={dispatch}
              inSettings
              backfillIntervalMs={30_000}
            />
          </TabPanel>
          <TabPanel active={activeTab === 'woocommerce'}>
            <Step3WooCommerce state={rawState} dispatch={dispatch} inSettings />
          </TabPanel>
          <TabPanel active={activeTab === 'recommendations'}>
            <Step4Recommendations state={rawState} dispatch={dispatch} inSettings />
          </TabPanel>
          <TabPanel active={activeTab === 'integrations'}>
            <Step5Integrations state={rawState} inSettings />
          </TabPanel>
          <TabPanel active={activeTab === 'events'}>
            <EventLog />
          </TabPanel>
        </main>

        {activeTab !== 'integrations' && activeTab !== 'events' && (
          <footer className="sticky bottom-0 -mx-6 flex items-center justify-end gap-3 border-t border-border-subtle bg-surface px-6 py-4 shadow-card">
            <Button
              variant="ghost"
              type="button"
              onClick={handleDiscard}
              disabled={!canSaveOrDiscard}
            >
              {__('Discard changes', 'smaily-connect')}
            </Button>
            <Button
              variant="primary"
              type="button"
              onClick={handleSave}
              disabled={!canSaveOrDiscard}
              loading={saveStatus === 'pending' || enginePending}
            >
              {__('Save changes', 'smaily-connect')}
            </Button>
          </footer>
        )}
      </div>
    </div>
  );
}

/**
 * URL-hash routing — keeps `location.hash` in lockstep with the active tab.
 * Falls back to 'connection' when the hash is missing or unrecognised.
 * Returns the [activeTab, setActiveTab] pair the caller binds to PillTabs.
 */
function useTabRouting(defaultTab: AnyTab): [AnyTab, (next: AnyTab) => void] {
  const validTabs = useMemo(() => TABS.map((t) => t.value), []);

  const readHash = useCallback((): AnyTab => {
    if (typeof window === 'undefined') {
      return defaultTab;
    }
    const raw = window.location.hash.replace(/^#/, '');
    return (validTabs as readonly string[]).includes(raw) ? (raw as AnyTab) : defaultTab;
  }, [defaultTab, validTabs]);

  const [activeTab, setTab] = useState<AnyTab>(readHash);

  useEffect(() => {
    const onHashChange = (): void => setTab(readHash());
    window.addEventListener('hashchange', onHashChange);
    return () => window.removeEventListener('hashchange', onHashChange);
  }, [readHash]);

  const setActiveTab = useCallback((next: AnyTab) => {
    if (typeof window !== 'undefined') {
      window.location.hash = next;
    }
    setTab(next);
  }, []);

  return [activeTab, setActiveTab];
}

function TabPanel({
  active,
  children,
}: {
  active: boolean;
  children: React.ReactNode;
}): React.JSX.Element | null {
  if (!active) {
    return null;
  }
  return <div>{children}</div>;
}

