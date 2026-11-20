import { __ } from '@admin/lib/i18n';
import { SMAILY_SIGNUP_URL } from '@admin/lib/links';
import { PromoBanner, promoCtaClass, promoLinkClass } from './PromoBanner';

/**
 * The invitation to a store with no Smaily account (CON-351), on top of the
 * Connect step. It cannot be closed here — it goes once an account is
 * connected. The Dashboard shows the same banner in PHP
 * (NotificationManager::render_invite_banner), with the same strings.
 */
export function SmailyInvitationBanner({ className }: { className?: string }): React.JSX.Element {
  return (
    <PromoBanner
      logo="smaily"
      label="Smaily"
      title="Smaily"
      body={__('Connect your store to Smaily to sync contacts and set up automated emails.', 'smaily-connect')}
      actions={
        <>
          <button type="button" onClick={focusCredentials} className={promoCtaClass}>
            {__('Connect your Smaily account', 'smaily-connect')}
          </button>
          <a href={SMAILY_SIGNUP_URL} target="_blank" rel="noopener noreferrer" className={promoLinkClass}>
            {__('Create a Smaily account', 'smaily-connect')}
          </a>
        </>
      }
      note={__(
        'Log in to Smaily: gear icon → Integrations → Create a new user (API username and password).',
        'smaily-connect',
      )}
      panel={{
        label: __('Smaily in action', 'smaily-connect'),
        rows: [
          { icon: 'mail', text: __('Welcome email', 'smaily-connect') },
          { icon: 'cart', text: __('Abandoned cart reminder', 'smaily-connect') },
          { icon: 'card', text: __('Payment reminder', 'smaily-connect') },
        ],
        chip: __('Automated', 'smaily-connect'),
      }}
      className={className}
    />
  );
}

/** "Connect your Smaily account" on the Connect step: go to the first credential field. */
function focusCredentials(): void {
  const field = document.querySelector<HTMLInputElement>('input[id^="smaily-subdomain-"]');
  if (field === null) {
    return;
  }
  // jsdom has no scrollIntoView.
  if (typeof field.scrollIntoView === 'function') {
    field.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
  field.focus({ preventScroll: true });
}
