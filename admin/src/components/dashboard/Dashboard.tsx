import { __ } from '@admin/lib/i18n';
import { LOG_URL, SETTINGS_URL, SETUP_URL } from '@admin/lib/links';
import { type WizardState } from '../../state/types';
import { Card } from '../primitives';
import { VersionLine } from '../VersionLine';

export interface DashboardProps {
  /** The saved settings, hydrated from the boot payload as Settings reads them. */
  state: WizardState;
}

/**
 * The Dashboard (CON-464): the plugin's state at a glance, in the order and
 * words of Magento's and PrestaShop's Dashboard.
 */
export function Dashboard({ state }: DashboardProps): React.JSX.Element {
  return (
    <div className="min-h-screen bg-page-bg font-sans text-text-primary">
      <div className="mx-auto max-w-5xl space-y-6 p-6">
        <header>
          <h1 className="text-2xl font-semibold">{__('Smaily Connect — Dashboard', 'smaily-connect')}</h1>
        </header>
        <QuickLinks docsUrl={state.env.docsUrl ?? ''} />
        <VersionLine />
      </div>
    </div>
  );
}

/** Settings, Log, Initial setup and the merchant guide (CON-463's link moved here). */
function QuickLinks({ docsUrl }: { docsUrl: string }): React.JSX.Element {
  const links = [
    {
      href: SETTINGS_URL,
      label: __('Settings', 'smaily-connect'),
      desc: __('connection, contacts, automations, Intelligence and RSS in one tabbed page', 'smaily-connect'),
    },
    {
      href: LOG_URL,
      label: __('Log', 'smaily-connect'),
      desc: __('every delivery with retry for failed rows', 'smaily-connect'),
    },
    {
      href: SETUP_URL,
      label: __('Initial setup', 'smaily-connect'),
      desc: __('re-run the guided setup at any time; your settings are kept', 'smaily-connect'),
    },
  ];

  return (
    <Card title={__('Quick links', 'smaily-connect')}>
      <ul className="space-y-2 text-sm">
        {links.map((link) => (
          <li key={link.href}>
            <a href={link.href} className="font-medium text-brand underline">
              {link.label}
            </a>{' '}
            <span className="text-text-secondary">— {link.desc}</span>
          </li>
        ))}
        {docsUrl !== '' && (
          <li>
            <a href={docsUrl} target="_blank" rel="noopener noreferrer" className="font-medium text-brand underline">
              {__('User Guide', 'smaily-connect')}
            </a>{' '}
            <span className="text-text-secondary">
              — {__('the full merchant documentation: every feature, setting and troubleshooting steps', 'smaily-connect')}
            </span>
          </li>
        )}
      </ul>
    </Card>
  );
}
