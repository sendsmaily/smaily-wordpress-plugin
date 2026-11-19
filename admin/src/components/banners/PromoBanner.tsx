import { useId, type ReactNode } from 'react';

import { __ } from '@admin/lib/i18n';
import { cn } from '../../utils/cn';

/**
 * The invitation banner (CON-350, CON-351; marketing's design, GMS-52): a
 * light lilac card with a logo tile, a title, a short pitch, a dark call to
 * action and, on wide screens, a decorative panel on the right that fades
 * out. Every word is a real string; the panel is hidden from assistive
 * technology because it only illustrates.
 *
 * The Dashboard draws the same look in PHP + admin/css/smaily-connect-banner.css
 * (NotificationManager::render_invite_banner) — change both together.
 */

export type PromoIcon = 'clock' | 'undo' | 'mail' | 'cart' | 'card';

export interface PromoBannerProps {
  /** Which logo tile: Campaign Intelligence or Smaily. */
  logo: 'intelligence' | 'smaily';
  /** The banner's accessible name. */
  label: string;
  title: ReactNode;
  body: string;
  /** The call to action(s), styled with `promoCtaClass` / `promoLinkClass`. */
  actions: ReactNode;
  /** A small grey line under the actions. */
  note?: ReactNode;
  /** A dark pill in the top-right corner. */
  pill?: string;
  /** When given, a Dismiss control closes the banner. */
  onDismiss?: () => void;
  panel: {
    label: string;
    heading?: string;
    rows: Array<{ icon: PromoIcon; text: string }>;
    chip: string;
  };
  className?: string;
}

/** The dark plum call to action — on an <a> or a <button>. */
export const promoCtaClass =
  'inline-flex items-center rounded-lg bg-gradient-to-r from-[#2a1028] to-[#5e3b72] px-[18px] py-[10px] text-[14px] font-semibold text-white no-underline hover:from-[#3a1738] hover:to-[#6e4885] hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#5e3b72] focus-visible:ring-offset-2';

/** A secondary text link beside the call to action. */
export const promoLinkClass = 'text-[14px] font-medium text-[#6b3f8c] underline hover:text-[#2a1028]';

export function PromoBanner({
  logo,
  label,
  title,
  body,
  actions,
  note,
  pill,
  onDismiss,
  panel,
  className,
}: PromoBannerProps): React.JSX.Element {
  return (
    <section
      aria-label={label}
      className={cn(
        'relative flex gap-8 overflow-hidden rounded-[16px] border border-[#ebe3f3] p-7 text-[#2a1028]',
        'bg-gradient-to-r from-[#fcfaff] via-[#f7f1fd] to-[#efe5fb]',
        className,
      )}
    >
      <div className="min-w-0 flex-1 lg:max-w-[440px]">
        <div className="flex items-center gap-3.5">
          <LogoTile logo={logo} />
          <h2 className="m-0 p-0 text-[22px] font-semibold leading-tight text-[#2a1028]">{title}</h2>
        </div>
        <p className="mt-4 text-[15px] leading-[1.45]">{body}</p>
        <div className="mt-5 flex flex-wrap items-center gap-x-[18px] gap-y-3">{actions}</div>
        {note !== undefined && <p className="mt-3 text-[13px] leading-snug text-[#6b6577]">{note}</p>}
      </div>

      <div
        aria-hidden="true"
        className={cn(
          'hidden min-w-0 flex-1 lg:block',
          '-mb-7 -mr-7 mt-2 rounded-tl-[16px] border border-r-0 border-b-0 border-[#ebe3f3] bg-white/60 py-5 pl-5',
          '[mask-image:linear-gradient(to_bottom,#000_45%,transparent)]',
        )}
      >
        <span className="inline-flex items-center gap-2 rounded-lg border border-[#e7e1ee] bg-white px-3 py-1.5 text-[13px] font-medium">
          <LogoGlyph logo={logo} />
          {panel.label}
        </span>
        {panel.heading !== undefined && (
          <p className="mt-5 text-[14px] text-[#8a8494]">{panel.heading}</p>
        )}
        {panel.rows.map((row, index) => (
          <div
            key={row.text}
            className={cn(
              'mt-3 flex items-center gap-3 rounded-l-[10px] border border-r-0 border-[#ece8f1] bg-white px-3.5 py-3 text-[14px] font-medium',
              index === 1 && 'opacity-60',
              index >= 2 && 'opacity-30',
            )}
          >
            <RowIcon icon={row.icon} />
            <span className="min-w-0 flex-1">{row.text}</span>
            <span className="shrink-0 rounded-md bg-[#f1eef5] px-2.5 py-1 text-[12px] text-[#8a8494]">
              {panel.chip}
            </span>
          </div>
        ))}
      </div>

      {(pill !== undefined || onDismiss !== undefined) && (
        <div className="absolute right-4 top-3 flex items-center gap-3">
          {pill !== undefined && (
            <span className="rounded-full bg-[#2a1028] px-4 py-1.5 text-[12px] font-semibold uppercase tracking-wide text-white">
              {pill}
            </span>
          )}
          {onDismiss !== undefined && (
            <button
              type="button"
              onClick={onDismiss}
              className="rounded text-[13px] text-[#6b6577] underline hover:text-[#2a1028] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#5e3b72]"
            >
              {__('Dismiss', 'smaily-connect')}
            </button>
          )}
        </div>
      )}
    </section>
  );
}

/** The Smaily mark — gfx/icon.svg, the admin menu icon. */
const SMAILY_MARK =
  'M24.2563 0H4.8576C2.17536 0 0 2.16606 0 4.83684V24.1632C0 26.8339 2.17536 29 4.8576 29H4.88928C7.13856 28.9895 9.0288 27.4543 9.56736 25.3724C9.98976 25.7614 10.465 26.0874 10.993 26.3503C11.8589 26.7709 12.7565 26.9811 13.6858 26.9811C14.1398 26.9811 14.5939 26.9286 15.0586 26.8129C15.5232 26.7078 15.935 26.5395 16.3046 26.3187C16.6742 26.0979 16.9699 25.8245 17.2022 25.488C17.424 25.1516 17.5402 24.752 17.5402 24.2893C17.5402 23.5112 17.2445 22.9014 16.6637 22.4598C16.0829 22.0076 15.3437 21.6291 14.4672 21.3136C13.5907 20.9982 12.6298 20.6827 11.6054 20.3673C10.5811 20.0519 9.63072 19.6313 8.74368 19.095C7.8672 18.5587 7.128 17.8648 6.5472 16.992C5.9664 16.1193 5.67072 14.9732 5.67072 13.5431C5.67072 12.1657 5.93472 10.9565 6.47328 9.91552C7.01184 8.87455 7.71936 8.00181 8.61696 7.30783C9.51456 6.60334 10.5389 6.07759 11.711 5.7306C12.8726 5.38361 14.0765 5.20486 15.3226 5.20486C16.7482 5.20486 18.1315 5.41516 19.4621 5.82524C20.3914 6.10914 21.2573 6.54025 22.0704 7.09753L22.081 7.10805C22.4189 7.34989 22.7568 7.61276 23.0736 7.89666L19.2826 13.2698C19.2826 13.2698 18.2688 9.78934 13.9603 10.578C13.5274 10.6726 13.1261 10.8303 12.7459 11.0301C12.3658 11.2404 12.0595 11.5033 11.8166 11.8503C11.5738 12.1867 11.4576 12.5968 11.4576 13.0805C11.4576 13.8586 11.7427 14.4474 12.313 14.8575C12.8832 15.2676 13.6013 15.6146 14.4778 15.909C15.3437 16.2034 16.2835 16.4873 17.2867 16.7817C18.2899 17.0761 19.2192 17.4862 20.0957 18.012C20.9616 18.5482 21.6902 19.2527 22.2605 20.157C22.8307 21.0508 23.1158 22.2495 23.1158 23.7531C23.1158 25.1831 22.8518 26.4239 22.3344 27.4859C22.0598 28.0326 21.743 28.5373 21.3734 28.9895H24.2669C26.9491 28.9895 29.1245 26.8234 29.1245 24.1526V4.83684C29.1139 2.16606 26.9386 0 24.2563 0Z';

/** Two overlapping rounded squares — the Campaign Intelligence mark. */
function IntelligenceShapes({ stroke }: { stroke: string }): React.JSX.Element {
  return (
    <>
      <rect x="3" y="3" width="12" height="12" rx="4" fill="none" stroke={stroke} strokeWidth="2" />
      <rect x="9" y="9" width="12" height="12" rx="4" fill="none" stroke={stroke} strokeWidth="2" />
    </>
  );
}

function LogoTile({ logo }: { logo: PromoBannerProps['logo'] }): React.JSX.Element {
  // useId's colons would sit inside url(#…); keep the id plain.
  const gradientId = `smaily-ci-${useId().replace(/:/g, '')}`;

  if (logo === 'smaily') {
    return (
      <svg aria-hidden="true" viewBox="0 0 30 29" className="h-11 w-11 shrink-0" focusable="false">
        <path d={SMAILY_MARK} fill="#2a1028" />
      </svg>
    );
  }

  return (
    <span aria-hidden="true" className="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-[10px] bg-[#2a1028]">
      <svg viewBox="0 0 24 24" className="h-7 w-7" focusable="false">
        <defs>
          <linearGradient id={gradientId} x1="0" y1="0" x2="1" y2="1">
            <stop offset="0%" stopColor="#ff5c9d" />
            <stop offset="100%" stopColor="#7cc4ff" />
          </linearGradient>
        </defs>
        <IntelligenceShapes stroke={`url(#${gradientId})`} />
      </svg>
    </span>
  );
}

function LogoGlyph({ logo }: { logo: PromoBannerProps['logo'] }): React.JSX.Element {
  if (logo === 'smaily') {
    return (
      <svg viewBox="0 0 30 29" className="h-4 w-4" focusable="false">
        <path d={SMAILY_MARK} fill="#2a1028" />
      </svg>
    );
  }
  return (
    <svg viewBox="0 0 24 24" className="h-4 w-4" focusable="false">
      <IntelligenceShapes stroke="#2a1028" />
    </svg>
  );
}

const ICON_PATHS: Record<PromoIcon, ReactNode> = {
  clock: (
    <>
      <circle cx="12" cy="12" r="8" />
      <path d="M12 8v4l3 2" />
    </>
  ),
  undo: (
    <>
      <path d="M9 6L5 10l4 4" />
      <path d="M5 10h9a5 5 0 0 1 0 10h-3" />
    </>
  ),
  mail: (
    <>
      <rect x="3" y="5" width="18" height="14" rx="2" />
      <path d="M3 7l9 6 9-6" />
    </>
  ),
  cart: (
    <>
      <path d="M3 4h2l2.4 10h10.2L20 7H6.2" />
      <circle cx="9" cy="19" r="1.5" />
      <circle cx="17" cy="19" r="1.5" />
    </>
  ),
  card: (
    <>
      <rect x="3" y="5" width="18" height="14" rx="2" />
      <path d="M3 10h18M7 15h4" />
    </>
  ),
};

const ICON_TILES: Record<PromoIcon, string> = {
  clock: 'bg-[#fde4ef] text-[#e91e63]',
  undo: 'bg-[#fef3c7] text-[#d97706]',
  mail: 'bg-[#fde4ef] text-[#e91e63]',
  cart: 'bg-[#fef3c7] text-[#d97706]',
  card: 'bg-[#ece3fb] text-[#7c4dbc]',
};

function RowIcon({ icon }: { icon: PromoIcon }): React.JSX.Element {
  return (
    <span className={cn('inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg', ICON_TILES[icon])}>
      <svg
        viewBox="0 0 24 24"
        className="h-[18px] w-[18px]"
        fill="none"
        stroke="currentColor"
        strokeWidth="2"
        strokeLinecap="round"
        strokeLinejoin="round"
        focusable="false"
      >
        {ICON_PATHS[icon]}
      </svg>
    </span>
  );
}
