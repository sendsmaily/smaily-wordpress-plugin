import { __, sprintf } from '@admin/lib/i18n';
import { readBoot } from '../state/hydrate';

/**
 * The installed version at the bottom of the Dashboard, Settings and the Log
 * (CON-302, CON-464), so support can ask which release a store runs. Nothing
 * when the boot payload carries no version.
 */
export function VersionLine(): React.JSX.Element | null {
  const version = readBoot()?.version ?? '';
  if (version === '') {
    return null;
  }
  return (
    <p className="text-center text-xs text-text-secondary">
      {sprintf(
        // translators: %s is the installed plugin version, e.g. 3.21.1.
        __('Smaily Connect %s', 'smaily-connect'),
        version,
      )}
    </p>
  );
}
