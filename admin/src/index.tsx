import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';

import { App } from './App';
import './index.css';

/**
 * Mount detection — admin/settings.php and admin/wizard.php emit a
 * <div id="smaily-connect-app" data-view="settings|wizard"> placeholder.
 * The bundle finds it on DOMContentLoaded and mounts <App view=...>.
 *
 * If the mount node is absent (Vite dev / unrelated admin page) we no-op
 * silently — loading the bundle on the wrong screen shouldn't throw.
 */

type View = 'wizard' | 'settings' | 'unknown';

function resolveView(node: HTMLElement): View {
  const raw = node.getAttribute('data-view');
  if (raw === 'wizard' || raw === 'settings') {
    return raw;
  }
  return 'unknown';
}

function mount(): void {
  const node = document.getElementById('smaily-connect-app');
  if (!(node instanceof HTMLElement)) {
    return;
  }

  createRoot(node).render(
    <StrictMode>
      <App view={resolveView(node)} />
    </StrictMode>,
  );
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', mount);
} else {
  mount();
}
