/**
 * Settings barrel — admin/settings.php imports <Settings /> from here.
 * Wizard tree lives under components/steps; this directory only carries
 * the Settings-context composition (PillTabs router + Save / Discard
 * CTAs + dirty-tab tagging).
 */

export { Settings, type SettingsProps } from './Settings';
