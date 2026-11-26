import {
  useCallback,
  useEffect,
  useMemo,
  useReducer,
  useRef,
  useState,
  type Dispatch,
} from 'react';

import { useSaveSettings } from '../../hooks/useSaveSettings';
import { actionToTab } from '../../state/action-to-tab';
import { buildTabPayload } from '../../state/buildTabPayload';
import { saveEngineAutomations } from '../../state/engine-automations';
import { readBoot } from '../../state/hydrate';
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
import {
  afterConnectionSaved,
  confirmWorkflowRemoval,
  mappingsLosingWorkflow,
  savedAccounts,
} from '../../state/workflow-removal';
import { __, sprintf } from '@admin/lib/i18n';
import { dismissNotice } from '../../api/notices';
import { bannerBoot, CampaignIntelligenceBanner, campaignIntelligenceBannerVariant } from '../banners';
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
 *
 *   6. Every tab stays open while the Smaily connection does not work
 *      (CON-431); a notice at the top says so and leads to the Connection
 *      tab. A Connection save that replaces a Smaily account asks first
 *      when automations would lose their workflows (workflow-removal.ts).
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

  // CON-431: every tab stays open while the Smaily connection does not
  // work — the merchant must reach the other settings to keep the store
  // running — and the notice on top says so. A failed connection test on
  // this page is what tells; the setup wizard keeps its own gate.
  const connectionError = [
    rawState.smailyConnection,
    ...rawState.perLanguageAccounts.map((account) => account.connection),
  ].find((connection) => connection.kind === 'failure');

  // The Smaily accounts the server holds — what a Connection save is
  // compared with before it removes workflows (CON-431).
  const savedRef = useRef(savedAccounts(buildTabPayload(rawState, 'connection')));

  const {
    mutate: save,
    status: saveStatus,
    error: saveError,
    data: saveData,
  } = useSaveSettings({
    onSuccess: (response, request) => {
      rawDispatch({
        type: 'CLEAR_TAB_DIRTY',
        payload: { tab: request.tab as SettingsTabKey },
      });
      if (request.tab === 'connection') {
        savedRef.current = afterConnectionSaved(savedRef.current, request.data, response, rawDispatch);
      }
      // PRO-4091: the confirmation cards show what the save just changed.
      if (response.transactionalStatus !== undefined) {
        rawDispatch({ type: 'SET_TRANSACTIONAL_STATUS', payload: response.transactionalStatus });
      }
    },
  });

  const handleSave = useCallback((): void => {
    if (activeTab === 'integrations' || activeTab === 'events') {
      return;
    }
    const payload = buildTabPayload(rawState, activeTab);
    if (
      activeTab === 'connection' &&
      !confirmWorkflowRemoval(mappingsLosingWorkflow(savedRef.current, payload, rawState.automationMappings))
    ) {
      return;
    }
    void save({ tab: activeTab, data: payload });

    // The engine-run automations section (T2.2) joins the WooCommerce
    // tab's Save: TWO parallel requests — the local POST above plus a
    // PUT through the rec-engine proxy when the engine slice is dirty.
    // The outcomes are independent on purpose: if only the PUT fails,
    // the local half is saved, the engine slice stays dirty and its
    // error renders inside the section — the merchant loses neither
    // half (F3-52).
    if (activeTab === 'woocommerce' && rawState.engineAutomations.dirty) {
      void saveEngineAutomations(rawState.engineAutomations, rawDispatch);
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
  const version = readBoot()?.version ?? '';

  // The Campaign Intelligence banner on top of Settings (CON-350). It can be
  // closed (back in 30 days), and it steps aside on the Campaign Intelligence
  // tab, which carries its own copy of the banner.
  const banners = bannerBoot();
  const [ciBannerClosed, setCiBannerClosed] = useState(banners.ciBannerDismissed);
  const ciBannerVariant = campaignIntelligenceBannerVariant(rawState, banners);
  const handleCloseCiBanner = useCallback((): void => {
    setCiBannerClosed(true);
    // A failed request only means the banner returns on the next load.
    dismissNotice('ci_invitation').catch(() => undefined);
  }, []);

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
            tabs={TABS.map((t) => ({
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
            }))}
            value={activeTab}
            onChange={(next) => setActiveTab(next as AnyTab)}
            ariaLabel={__('Settings tabs', 'smaily-connect')}
          />
        </header>

        {ciBannerVariant !== null && !ciBannerClosed && activeTab !== 'recommendations' && (
          <CampaignIntelligenceBanner
            variant={ciBannerVariant}
            smailyLoginUrl={banners.smailyLoginUrl}
            onDismiss={handleCloseCiBanner}
            // "Try Intelligence free" connected the store (CON-175): the
            // Campaign Intelligence tab shows the connected view.
            onConnected={(response) => {
              rawDispatch({ type: 'TEST_REC_ENGINE_CONNECTION_SUCCESS', payload: { message: response.tenantName } });
              setActiveTab('recommendations');
            }}
            onShowSmailyConnection={() => setActiveTab('connection')}
          />
        )}
        {connectionError?.kind === 'failure' && (
          <Banner
            tone="danger"
            actions={
              activeTab !== 'connection' ? (
                <Button variant="secondary" size="sm" type="button" onClick={() => setActiveTab('connection')}>
                  {__('Fix the connection', 'smaily-connect')}
                </Button>
              ) : undefined
            }
          >
            {sprintf(
              // translators: %s is why the Smaily connection test failed.
              __('The Smaily connection does not work: %s', 'smaily-connect'),
              connectionError.error,
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
            <Step1Connect
              state={rawState}
              dispatch={dispatch}
              inSettings
              fieldErrors={saveStatus === 'error' ? saveData?.errors : undefined}
            />
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
            <Step4Recommendations
              state={rawState}
              dispatch={dispatch}
              inSettings
              onShowSmailyConnection={() => setActiveTab('connection')}
            />
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

        {/* CON-302: the installed version, so support can ask which release a store runs. */}
        {version !== '' && (
          <p className="text-center text-xs text-text-secondary">
            {sprintf(
              // translators: %s is the installed plugin version, e.g. 3.21.1.
              __('Smaily Connect %s', 'smaily-connect'),
              version,
            )}
          </p>
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

