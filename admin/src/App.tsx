import type { JSX } from 'react';

import { configureApiClient } from './api/client';
import { Dashboard } from './components/dashboard';
import { LogPage } from './components/log/LogPage';
import { Settings } from './components/settings';
import { Wizard } from './components/wizard';
import { hydrateState, readBoot } from './state/hydrate';

type View = 'wizard' | 'settings' | 'dashboard' | 'log' | 'unknown';

interface AppProps {
  view: View;
}

/**
 * Top-level switch between the wizard, settings, Dashboard and Log panels.
 *
 * State hydration: both panels share the same wizardReducer, so we
 * hydrate once at the App level and hand the resulting WizardState to
 * either <Wizard> or <Settings>. The `inSettings` flag on the state
 * itself signals to step components whether they should hide
 * wizard-only chrome (the "Step N of 6" eyebrow, etc.).
 *
 * API client wiring (sub-PR 2.H.1 hotfix): seed configureApiClient
 * with the REST URL + nonce BEFORE rendering. Hooks fire during render
 * and call apiRequest — without this seeding the first Test connection
 * click throws "apiRequest called before configureApiClient" with the
 * helpful pointer to this file. Boot may be null in Vite dev / jsdom
 * tests, in which case the seeding is skipped and tests configure the
 * client through their own setup.
 *
 * The `unknown` view shouldn't happen in production — admin/settings.php
 * + admin/wizard.php always set data-view explicitly — but if a future
 * caller forgets, render a small placeholder rather than crashing.
 */
export function App({ view }: AppProps): JSX.Element {
  const boot = readBoot();
  if (boot !== null) {
    configureApiClient({ restUrl: boot.restUrl, nonce: boot.nonce });
  }
  // The Dashboard reads the saved settings the way Settings does.
  const inSettings = view === 'settings' || view === 'dashboard';
  const initialState = hydrateState(boot, inSettings);

  if (view === 'dashboard') {
    return <Dashboard state={initialState} />;
  }

  if (view === 'log') {
    return <LogPage />;
  }

  if (view === 'settings') {
    return <Settings initialState={initialState} />;
  }

  if (view === 'wizard') {
    return <Wizard initialState={initialState} />;
  }

  return (
    <div className="min-h-screen bg-page-bg p-6 font-sans text-text-primary">
      <p>Smaily Connect — unknown view. Check the data-view attribute on the mount node.</p>
    </div>
  );
}
