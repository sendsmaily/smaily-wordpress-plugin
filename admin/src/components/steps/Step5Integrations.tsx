import { useRef, useState } from 'react';

import { type WizardState } from '../../state/types';
import { Button, Card, Pill } from '../primitives';
import { RssFeedSection } from './RssFeedSection';
import { __ } from '@admin/lib/i18n';

/** The shortcode a merchant pastes into any post/page/widget. */
const SIGNUP_SHORTCODE = '[smaily_connect_newsletter_form]';

export interface Step5IntegrationsProps {
  state: WizardState;
  inSettings?: boolean;
}

interface IntegrationCard {
  title: string;
  description: string;
  /** Detection key from state.env — true means the integration is installed. */
  installedKey: 'elementorPresent' | 'cf7Present' | null;
  /**
   * Sub-PR 2.H.15 — separate hrefs per installed state. The old single
   * `href` shipped users to `admin.php?page=wpcf7` even when CF7 was
   * absent, which WordPress renders as "Sorry, you are not allowed to
   * access this page" because the slug doesn't resolve. Each card now
   * has an explicit hrefInstalled (the plugin's own settings page) and
   * hrefMissing (plugin-install search filtered to the matching plugin).
   */
  hrefInstalled: string;
  hrefMissing: string;
  /** Button label depending on installed state. */
  ctaInstalled: string;
  ctaMissing: string;
}

const CARDS: IntegrationCard[] = [
  {
    title: __('Elementor', 'smaily-connect'),
    description: __(
      'The Smaily Opt-In Form widget is available in the Elementor editor.',
      'smaily-connect',
    ),
    installedKey: 'elementorPresent',
    hrefInstalled: 'admin.php?page=elementor-app',
    hrefMissing: 'plugin-install.php?s=elementor&tab=search&type=term',
    ctaInstalled: __('Open Elementor', 'smaily-connect'),
    ctaMissing: __('Install Elementor', 'smaily-connect'),
  },
  {
    title: __('Contact Form 7', 'smaily-connect'),
    description: __(
      'Configure individual forms via the "Smaily for Contact Form 7" tab.',
      'smaily-connect',
    ),
    installedKey: 'cf7Present',
    hrefInstalled: 'admin.php?page=wpcf7',
    hrefMissing: 'plugin-install.php?s=contact+form+7&tab=search&type=term',
    ctaInstalled: __('Open CF7', 'smaily-connect'),
    ctaMissing: __('Install Contact Form 7', 'smaily-connect'),
  },
  {
    title: __('Smaily Landing Pages', 'smaily-connect'),
    description: __('Embed Smaily landing pages anywhere via the Gutenberg block.', 'smaily-connect'),
    installedKey: null,
    hrefInstalled: 'post-new.php?post_type=page',
    hrefMissing: 'post-new.php?post_type=page',
    ctaInstalled: __('Add a new page', 'smaily-connect'),
    ctaMissing: __('Add a new page', 'smaily-connect'),
  },
];

/**
 * One "how to add a form" block on the guide grid below. Body-only Card
 * (no headerAccessory / Pill) — these are instructions, not install-status
 * cards like CARDS above.
 */
function SignupFormGuideCard({
  title,
  children,
}: {
  title: string;
  children: React.ReactNode;
}): React.JSX.Element {
  return (
    <Card title={title}>
      <div className="space-y-2 text-sm text-text-secondary">{children}</div>
    </Card>
  );
}

/**
 * Shortcode code block + one-click copy. Mirrors RssFeedSection's
 * handleCopy: navigator.clipboard when available (needs a secure
 * context — a plain-http wp-admin doesn't have one), else fall back to
 * selecting the code text so a manual Ctrl+C still works.
 */
function ShortcodeCopyButton(): React.JSX.Element {
  const [copied, setCopied] = useState(false);
  const codeRef = useRef<HTMLElement>(null);

  const selectCodeText = (): void => {
    const node = codeRef.current;
    if (node === null || typeof window === 'undefined' || window.getSelection === undefined) {
      return;
    }
    const range = document.createRange();
    range.selectNodeContents(node);
    const selection = window.getSelection();
    selection?.removeAllRanges();
    selection?.addRange(range);
  };

  const handleCopy = (): void => {
    if (typeof navigator !== 'undefined' && navigator.clipboard !== undefined) {
      navigator.clipboard.writeText(SIGNUP_SHORTCODE).then(() => {
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
      }, selectCodeText);
    } else {
      selectCodeText();
    }
  };

  return (
    <div className="flex items-center gap-2">
      <code
        ref={codeRef}
        className="flex-1 overflow-x-auto rounded bg-surface-muted px-3 py-2 font-mono text-sm text-text-primary"
      >
        {SIGNUP_SHORTCODE}
      </code>
      <Button type="button" variant="secondary" size="sm" onClick={handleCopy}>
        {copied ? __('Copied ✓', 'smaily-connect') : __('Copy', 'smaily-connect')}
      </Button>
    </div>
  );
}

/**
 * "How to add a Smaily signup form" — one card per surface a merchant can
 * place the form on (PRO-1430). Elementor/CF7 presence reuses the same
 * state.env detection CARDS above already reads; when the host plugin is
 * absent the card just says so, no dead pointer.
 */
function SignupFormGuide({ state }: { state: WizardState }): React.JSX.Element {
  const docsUrl = state.env.docsUrl ?? '';

  return (
    <div>
      <h3 className="text-lg font-semibold text-text-primary">
        {__('How to add a Smaily signup form', 'smaily-connect')}
      </h3>
      <p className="mt-1 text-sm text-text-secondary">
        {__('Add a newsletter signup form to your page using any of these methods.', 'smaily-connect')}
      </p>

      <div className="mt-4 grid gap-4 md:grid-cols-2">
        <SignupFormGuideCard title={__('Shortcode', 'smaily-connect')}>
          <p>
            {__(
              'To add a Smaily form, place this code where you want the form to appear:',
              'smaily-connect',
            )}
          </p>
          <ShortcodeCopyButton />
          <p>
            {__(
              'Attributes let you customize the redirect URLs, add a name field, and enrol subscribers into a workflow.',
              'smaily-connect',
            )}{' '}
            {docsUrl !== '' && (
              <a
                href={`${docsUrl}#set-integrations`}
                target="_blank"
                rel="noopener noreferrer"
                className="underline"
              >
                {__('See all attributes', 'smaily-connect')}
              </a>
            )}
          </p>
        </SignupFormGuideCard>

        <SignupFormGuideCard title={__('Gutenberg block', 'smaily-connect')}>
          <p>
            {__(
              'In the WordPress page or post editor, open the block inserter and search "Smaily" — add the Smaily Sign-Up Form block anywhere in your content.',
              'smaily-connect',
            )}
          </p>
        </SignupFormGuideCard>

        <SignupFormGuideCard title={__('Elementor widget', 'smaily-connect')}>
          {state.env.elementorPresent ? (
            <p>
              {__(
                'In the Elementor editor, open the widget panel and look under the Smaily category for the Smaily Opt-In Form widget.',
                'smaily-connect',
              )}
            </p>
          ) : (
            <p>{__('Elementor is not installed on this site.', 'smaily-connect')}</p>
          )}
        </SignupFormGuideCard>

        <SignupFormGuideCard title={__('Classic Widget', 'smaily-connect')}>
          <p>
            {__(
              'Go to Appearance → Widgets and add the "Smaily Classic Subscription Widget" to any widget area.',
              'smaily-connect',
            )}
          </p>
        </SignupFormGuideCard>

        <SignupFormGuideCard title={__('Contact Form 7', 'smaily-connect')}>
          {state.env.cf7Present ? (
            <p>
              {__(
                'Open a form under Contact → Contact Forms and switch to the "Smaily for Contact Form 7" tab to enable signup for that form.',
                'smaily-connect',
              )}
            </p>
          ) : (
            <p>{__('Contact Form 7 is not installed on this site.', 'smaily-connect')}</p>
          )}
        </SignupFormGuideCard>
      </div>
    </div>
  );
}

/**
 * Step 5 — Integrations.
 *
 * Informative-only step: three cards link out to the WP admin pages for
 * each integration. Each card surfaces whether the underlying plugin is
 * installed (detected via state.env on PHP-mount) so admins know where
 * to start.
 *
 * Below the cards, a "How to add a Smaily signup form" guide (PRO-1430)
 * covers every surface a merchant can place the form on: Shortcode,
 * Gutenberg block, Elementor widget, Classic Widget, Contact Form 7.
 *
 * WooCommerce stores additionally get the product RSS-feed URL builder
 * (RssFeedSection) — client-side only, so the step stays save-free.
 * Gated on env.rss, which the server emits only when WC is active.
 *
 * Links use admin_url() output (server-side) so the relative href stays
 * in-window — `target="_blank"` would dump users into a new tab which
 * is jarring in the wizard flow. The signup-form guide's docs link is
 * the exception — it points off-site, so it opens in a new tab like
 * every other external docs link in the app.
 */
export function Step5Integrations({
  state,
  inSettings = false,
}: Step5IntegrationsProps): React.JSX.Element {
  return (
    <div className="space-y-6">
      {!inSettings && (
        <div>
          <p className="text-sm font-medium uppercase tracking-wide text-text-tertiary">
            {__('Step 5 of 6', 'smaily-connect')}
          </p>
          <h2 className="mt-1 text-2xl font-semibold text-text-primary">
            {__('Forms and RSS feed', 'smaily-connect')}
          </h2>
          <p className="mt-2 text-sm text-text-secondary">
            {__(
              'Add a newsletter subscription form to any page, to collect new subscribers directly to your Smaily account. Choose the suitable form option for your page and follow the instructions to add the form.',
              'smaily-connect',
            )}
          </p>
        </div>
      )}

      <div className="grid gap-4 md:grid-cols-3">
        {CARDS.map((card) => {
          const installed =
            card.installedKey === null
              ? true
              : Boolean(state.env[card.installedKey]);
          return (
            <Card
              key={card.title}
              title={card.title}
              headerAccessory={
                installed ? (
                  <Pill tone="success">{__('Installed', 'smaily-connect')}</Pill>
                ) : (
                  <Pill tone="neutral">{__('Not installed', 'smaily-connect')}</Pill>
                )
              }
            >
              <p className="text-sm text-text-secondary">{card.description}</p>
              <a
                href={installed ? card.hrefInstalled : card.hrefMissing}
                className="mt-4 inline-flex h-8 items-center justify-center rounded bg-brand-soft-bg px-3 text-sm font-medium text-brand-soft-text hover:bg-brand-soft-bg/80"
              >
                {installed ? card.ctaInstalled : card.ctaMissing} →
              </a>
            </Card>
          );
        })}
      </div>

      <SignupFormGuide state={state} />

      {state.env.rss != null && <RssFeedSection rss={state.env.rss} />}
    </div>
  );
}
