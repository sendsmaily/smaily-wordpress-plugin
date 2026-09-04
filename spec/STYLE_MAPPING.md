# Style Mapping — Smaily disainisüsteem → Tailwind config

**Versioon**: 1.0 (hinnangulisi väärtusi Variant 3 valikuga)
**Avaldatud**: 2026-05-19
**Adressaat**: Claude Code, Faas 2 sub-PR 1 (Tailwind setup)
**Aluseks**:
- Smaily logo SVG (brand primary: `#E91E63` Material Pink 500)
- Smaily UI screenshot (campaign editor)
- Mu prototüübi `t` token-objekt (lähte-token-id, mida vahetada)

---

## 1. Kuidas seda dokumenti kasutada

See dokument annab **Tailwind config valmis-koodi**, mis sisaldab Smaily disainisüsteemi tokenid prototüübi `t`-objektist tuletatud. **Vaikimisi väärtused on hinnangulised** (vt Variant 3 valik) ja Erkki pilootkliendi review-faasis vahetab täpsete hex'idega.

**Kasutus Code-agendile:**

1. Kopeeri `tailwind.config.js` täielikult (vt §3) — see on production-ready baas
2. Kopeeri `assets/fonts/` instructions (vt §5) — Inter font bundle
3. Kasuta `class-mapping-table` (vt §4) primitives-komponentides — iga prototüübi `style={{...}}` → Tailwind utility'd
4. **NB**: kõik viited prototüübi `t.c.brand`-ile (sinine `#0F3DDD`) → `bg-brand` Tailwindis (= Smaily pink `#E91E63`)

**Kasutus Erkki review-faasis** (Faas 2 lõpus, plugin-deploy enne piloodile):
1. Ava plugin'i WP-admin-UI Smaily-UI kõrval
2. Browser DevTools → võrdle värvid
3. Igale kohale, kus need ei ühildu, paranda `tailwind.config.js` vastav hex
4. Build uuesti (`npm run build`), refresh

---

## 2. Disain-süsteemi alusotsused

### 2.1. Brand colors

**Primary brand**: `#E91E63` (Material Pink 500, kinnitatud Smaily logo SVG-st)

Tooni ümber hindangud (vt §6 review-checklist):

| Token | Hex | Kontekst |
|-------|-----|----------|
| `brand.DEFAULT` | `#E91E63` | Primary buttons, links, active states |
| `brand.hover` | `#C2185B` | Hover state (Pink 700) |
| `brand.disabled` | `#F8BBD0` | Disabled primary buttons (Pink 100) |
| `brand.soft.bg` | `#FCE4EC` | Soft pill backgrounds (näit "Newsletter") (Pink 50) |
| `brand.soft.text` | `#AD1457` | Soft pill text (Pink 800) |

### 2.2. Terminal-action color (success-banneritele, MITTE primary nuppudele)

**Korrektsioon 2026-05-20 (sub-PR 2.D)**: varasem versioon kirjutas Step 6 Finish nupp rohelisena (Stripe-style "brand = identity, action = green"). Erkki täpsustas: **kõik primary action-nupud sh Step 6 Finish on brand-pink** — terminal-color rohelist kasutame **ainult success-banneritel** ("Backfill complete", "Settings saved", jne).

Põhjus: ühe-värvi-primary-action muster on järjekindlam ja Smaily-UI ei kasuta primary nuppude jaoks rohelist.

| Token | Hex (hinnatud) | Kontekst |
|-------|----------------|----------|
| `success.DEFAULT` | `#10B981` | ~~Step 6 Finish nupp~~ (eemaldatud), success banners (Emerald 500) |
| `success.hover` | `#059669` | (Emerald 600) |
| `success.soft.bg` | `#D1FAE5` | Success banners background (Emerald 100) |
| `success.soft.text` | `#065F46` | Success banners text (Emerald 800) |

**Code-le juhis**: `Button` primitive säilitab `variant='success'` (banner-action'iks, näit. "Got it" banneri sulgemiseks), aga **`WizardFooter` Finish-nupp kasutab `variant='primary'`** (pink, sama mis Continue Step 1-5-l).

### 2.3. Neutrals (Smaily on "puhas" cool-grey)

Mu prototüübis on cream-warm neutrals (`#F4F4F2`, `#FAFAF8`). Smaily UI on **puhtam cool-grey** — vahetus on suur visuaalne nihe, aga see on autentne Smaily-stiil.

| Token | Hex (hinnatud) | Kontekst |
|-------|----------------|----------|
| `surface.DEFAULT` | `#FFFFFF` | Cards, modals |
| `surface.soft` | `#F9FAFB` | Page sub-sections (Gray 50) |
| `surface.muted` | `#F3F4F6` | Disabled inputs, dimmed (Gray 100) |
| `page.bg` | `#F9FAFB` | Main page background |
| `border.subtle` | `#F3F4F6` | Hairline dividers |
| `border.DEFAULT` | `#E5E7EB` | Card borders (Gray 200) |
| `border.strong` | `#D1D5DB` | Input borders (Gray 300) |
| `border.cool` | `#9CA3AF` | Hover borders, focus rings (Gray 400) |
| `text.primary` | `#111827` | Headings, body (Gray 900) |
| `text.secondary` | `#6B7280` | Descriptions (Gray 500) |
| `text.tertiary` | `#9CA3AF` | Eyebrows, labels (Gray 400) |
| `text.white` | `#FFFFFF` | On dark backgrounds |

### 2.4. Status colors

Standardne Tailwind-valik (vt §6 review-checklist):

| Token | Hex | Kontekst |
|-------|-----|----------|
| `warning.DEFAULT` | `#F59E0B` | Warning banners, icons (Amber 500) |
| `warning.soft.bg` | `#FEF3C7` | Warning bg (Amber 100) |
| `warning.soft.text` | `#92400E` | Warning text (Amber 800) |
| `danger.DEFAULT` | `#EF4444` | Error banners, destructive buttons (Red 500) |
| `danger.hover` | `#DC2626` | (Red 600) |
| `danger.soft.bg` | `#FEE2E2` | Error bg (Red 100) |
| `danger.soft.text` | `#991B1B` | Error text (Red 800) |
| `danger.border` | `#FCA5A5` | Error input border (Red 300) |

### 2.5. Typography

**Font perekond**: Inter (tõenäolisim Smaily valik; mu prototüübis on Geist, mis on lähedane visuaalselt aga ei ole identne).

**Hinnangu põhjendus**: Inter on de-facto standard moderne SaaS-rakenduses (Stripe, Vercel, GitHub, Linear kõik kasutavad). Ekraanipildi font tundub samasugune — sans-serif, kerge, hea numbri-rendering. Kui Smaily kasutab tegelikult muud (Aeonik, GT Walsheim, Manrope, sarnaseid), Erkki vahetab review-faasis ühe rea.

**Weights kasutusel**:
- 400 — body text
- 500 — labels, secondary headings
- 600 — primary headings, button text
- 700 — emphasis (rare)

**Mono font**: ui-monospace stack (SF Mono / Cascadia / Menlo / Consolas) — kasutusel ainult API-keys, code-snippets, tehnilised väärtused.

### 2.6. Radii

| Token | Pixels | Kontekst |
|-------|--------|----------|
| `rounded-sm` | 4px | Small buttons (sm-size), tag-pills |
| `rounded` | 6px | Standard buttons, inputs |
| `rounded-lg` | 8px | Cards |
| `rounded-xl` | 12px | Modals, large cards |
| `rounded-full` | 9999px | Round pills, avatars |

### 2.7. Shadows

Smaily UI on **flat** — minimaalne shadow-kasutus. Mu prototüübi shadow'd on sobivad:

| Token | CSS | Kontekst |
|-------|-----|----------|
| `shadow-sm` | `0 1px 2px rgba(15,15,15,0.06)` | Buttons hover, subtle elevation |
| `shadow.card` | `0 1px 2px rgba(15,15,15,0.04)` | Cards (peamine) |
| `shadow-md` | `0 4px 8px rgba(15,15,15,0.06), 0 1px 3px rgba(15,15,15,0.04)` | Dropdowns, popovers |
| `shadow.pop` | `0 8px 24px rgba(15,15,15,0.08), 0 2px 6px rgba(15,15,15,0.04)` | Modals |

### 2.8. Spacing & sizing

Kasuta Tailwindi standardset spacing-skaalat (`p-1`, `p-2`, `p-3`, ...). Mu prototüübi väärtused (`padding: 16` → `p-4`, `padding: 24` → `p-6`) lähevad puhtaks Tailwindi mappinguks.

---

## 3. Tailwind config valmis-kood

**`tailwind.config.js`:**

```javascript
/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './admin/src/**/*.{js,jsx,ts,tsx}',
    './admin/src/**/*.html',
  ],
  theme: {
    extend: {
      colors: {
        brand: {
          DEFAULT: '#E91E63',     // Smaily primary pink (Material Pink 500)
          hover:   '#C2185B',     // Pink 700
          disabled:'#F8BBD0',     // Pink 100
          soft: {
            bg:   '#FCE4EC',      // Pink 50
            text: '#AD1457',      // Pink 800
          },
        },
        success: {
          DEFAULT: '#10B981',     // Emerald 500 — terminal action (Step 6 Finish)
          hover:   '#059669',     // Emerald 600
          soft: {
            bg:   '#D1FAE5',      // Emerald 100
            text: '#065F46',      // Emerald 800
          },
        },
        warning: {
          DEFAULT: '#F59E0B',     // Amber 500
          soft: {
            bg:   '#FEF3C7',      // Amber 100
            text: '#92400E',      // Amber 800
          },
        },
        danger: {
          DEFAULT: '#EF4444',     // Red 500
          hover:   '#DC2626',     // Red 600
          soft: {
            bg:   '#FEE2E2',      // Red 100
            text: '#991B1B',      // Red 800
          },
          border:  '#FCA5A5',     // Red 300
        },
        surface: {
          DEFAULT: '#FFFFFF',
          soft:    '#F9FAFB',     // Gray 50
          muted:   '#F3F4F6',     // Gray 100
        },
        page: {
          bg: '#F9FAFB',
        },
        border: {
          subtle: '#F3F4F6',      // Gray 100
          DEFAULT:'#E5E7EB',      // Gray 200
          strong: '#D1D5DB',      // Gray 300
          cool:   '#9CA3AF',      // Gray 400
        },
        text: {
          primary:   '#111827',   // Gray 900
          secondary: '#6B7280',   // Gray 500
          tertiary:  '#9CA3AF',   // Gray 400
          white:     '#FFFFFF',
        },
      },
      fontFamily: {
        sans: [
          'Inter',
          'ui-sans-serif',
          'system-ui',
          '-apple-system',
          'BlinkMacSystemFont',
          '"Segoe UI"',
          'sans-serif',
        ],
        mono: [
          'ui-monospace',
          'SFMono-Regular',
          'Menlo',
          'Monaco',
          'Consolas',
          '"Courier New"',
          'monospace',
        ],
      },
      fontSize: {
        // Prototüübi-skaala: 11.5, 12, 12.5, 13, 13.5, 14, 14.5, 16, 22
        'xs':    ['11.5px', { lineHeight: '1.4' }],
        'sm':    ['12.5px', { lineHeight: '1.5' }],
        'base':  ['13.5px', { lineHeight: '1.5' }],
        'lg':    ['14.5px', { lineHeight: '1.5' }],
        'xl':    ['16px',   { lineHeight: '1.4' }],
        '2xl':   ['22px',   { lineHeight: '1.2' }],
      },
      borderRadius: {
        'sm':   '4px',
        DEFAULT:'6px',
        'lg':   '8px',
        'xl':   '12px',
        'full': '9999px',
      },
      boxShadow: {
        'card': '0 1px 2px rgba(15,15,15,0.04)',
        'sm':   '0 1px 2px rgba(15,15,15,0.06)',
        'md':   '0 4px 8px rgba(15,15,15,0.06), 0 1px 3px rgba(15,15,15,0.04)',
        'pop':  '0 8px 24px rgba(15,15,15,0.08), 0 2px 6px rgba(15,15,15,0.04)',
      },
      transitionDuration: {
        '120': '120ms',
      },
    },
  },
  plugins: [
    require('@tailwindcss/forms')({ strategy: 'class' }),  // optional, opt-in via .form-input class
  ],
};
```

**`postcss.config.js`:**

```javascript
module.exports = {
  plugins: {
    tailwindcss: {},
    autoprefixer: {},
  },
};
```

**`admin/src/index.css`** (Tailwind directives + font-face):

```css
@font-face {
  font-family: 'Inter';
  font-style: normal;
  font-weight: 400;
  font-display: swap;
  src: url('/wp-content/plugins/smaily-connect/assets/fonts/Inter-Regular.woff2') format('woff2');
}
@font-face {
  font-family: 'Inter';
  font-style: normal;
  font-weight: 500;
  font-display: swap;
  src: url('/wp-content/plugins/smaily-connect/assets/fonts/Inter-Medium.woff2') format('woff2');
}
@font-face {
  font-family: 'Inter';
  font-style: normal;
  font-weight: 600;
  font-display: swap;
  src: url('/wp-content/plugins/smaily-connect/assets/fonts/Inter-SemiBold.woff2') format('woff2');
}
@font-face {
  font-family: 'Inter';
  font-style: normal;
  font-weight: 700;
  font-display: swap;
  src: url('/wp-content/plugins/smaily-connect/assets/fonts/Inter-Bold.woff2') format('woff2');
}

@tailwind base;
@tailwind components;
@tailwind utilities;

/* Custom utilities */
@layer utilities {
  .scp-spin {
    animation: scp-spin 0.8s linear infinite;
  }
  @keyframes scp-spin {
    from { transform: rotate(0deg); }
    to   { transform: rotate(360deg); }
  }
}
```

---

## 4. Prototüübi `t`-token → Tailwind utility mapping-tabel

See tabel aitab Faas 2 inline-stiilide migratsioonil. Iga prototüübi viide → Tailwind klass.

### 4.1. Colors

| Prototüüp `t.c.*` | Tailwind utility |
|-------------------|------------------|
| `t.c.brand` | `bg-brand` / `text-brand` / `border-brand` |
| `t.c.brandHover` | `hover:bg-brand-hover` |
| `t.c.brandSoftBg` | `bg-brand-soft-bg` |
| `t.c.brandSoftText` | `text-brand-soft-text` |
| `t.c.brandDisabled` | `bg-brand-disabled` |
| `t.c.pageBg` | `bg-page-bg` |
| `t.c.surface` | `bg-surface` (= white) |
| `t.c.surfaceSoft` | `bg-surface-soft` |
| `t.c.surfaceMuted` | `bg-surface-muted` |
| `t.c.borderDefault` | `border-border` |
| `t.c.borderSubtle` | `border-border-subtle` |
| `t.c.borderStrong` | `border-border-strong` |
| `t.c.borderCool` | `border-border-cool` |
| `t.c.textPrimary` | `text-text-primary` |
| `t.c.textSecondary` | `text-text-secondary` |
| `t.c.textTertiary` | `text-text-tertiary` |
| `t.c.textWhite` | `text-text-white` |
| `t.c.successFg` | `text-success-soft-text` |
| `t.c.successBg` | `bg-success-soft-bg` |
| `t.c.warningFg` | `text-warning-soft-text` |
| `t.c.warningBg` | `bg-warning-soft-bg` |
| `t.c.dangerFg` | `text-danger-soft-text` |
| `t.c.dangerBg` | `bg-danger-soft-bg` |
| `t.c.dangerBorder` | `border-danger-border` |

### 4.2. Radii

| Prototüüp `t.r.*` | Tailwind utility |
|-------------------|------------------|
| `t.r.sm` (4) | `rounded-sm` |
| `t.r.md` (6) | `rounded` |
| `t.r.lg` (8) | `rounded-lg` |
| `t.r.xl` (12) | `rounded-xl` |
| `t.r.pill` (999) | `rounded-full` |

### 4.3. Shadows

| Prototüüp `t.shadow.*` | Tailwind utility |
|------------------------|------------------|
| `t.shadow.card` | `shadow-card` |
| `t.shadow.sm` | `shadow-sm` |
| `t.shadow.pop` | `shadow-pop` |

### 4.4. Common inline-style → Tailwind

| Prototüüp inline | Tailwind |
|------------------|----------|
| `display: 'flex'` | `flex` |
| `display: 'inline-flex'` | `inline-flex` |
| `flexDirection: 'column'` | `flex-col` |
| `alignItems: 'center'` | `items-center` |
| `justifyContent: 'space-between'` | `justify-between` |
| `gap: 8` | `gap-2` |
| `gap: 12` | `gap-3` |
| `gap: 16` | `gap-4` |
| `padding: 8` | `p-2` |
| `padding: 12` | `p-3` |
| `padding: 16` | `p-4` |
| `padding: 24` | `p-6` |
| `padding: 32` | `p-8` |
| `padding: '14px 40px'` | `py-3.5 px-10` (või custom `p-[14px_40px]`) |
| `marginTop: 6` | `mt-1.5` |
| `marginTop: 24` | `mt-6` |
| `width: '100%'` | `w-full` |
| `fontSize: 12.5` | `text-sm` (= 12.5px config'is) |
| `fontSize: 13.5` | `text-base` (= 13.5px config'is) |
| `fontWeight: 500` | `font-medium` |
| `fontWeight: 600` | `font-semibold` |
| `lineHeight: 1.5` | `leading-normal` |
| `cursor: 'pointer'` | `cursor-pointer` |
| `transition: 'background 120ms ease'` | `transition-colors duration-120 ease-in-out` |

### 4.5. Conditional styling (uus `cn()` helper)

Prototüüp:
```jsx
<div style={{
  background: selected ? '#F4F7FF' : t.c.surface,
  border: `1px solid ${selected ? t.c.brand : t.c.borderDefault}`,
}}>
```

Tailwindis:
```jsx
import { cn } from '~/utils/cn';

<div className={cn(
  'border',
  selected ? 'bg-brand-soft-bg border-brand' : 'bg-surface border-border',
)}>
```

`cn()` helper:
```typescript
import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]): string {
  return twMerge(clsx(inputs));
}
```

**Dependencies**: `npm install clsx tailwind-merge`.

---

## 5. Inter font bundle

WordPress.org Plugin Repository **ei luba** Google Fonts API otsest URL-i (GDPR-piirang). Inter tuleb bundle'ida.

**Faili-paigutus:**

```
assets/
└── fonts/
    ├── Inter-Regular.woff2     (~ 22 KB)
    ├── Inter-Medium.woff2      (~ 22 KB)
    ├── Inter-SemiBold.woff2    (~ 22 KB)
    └── Inter-Bold.woff2        (~ 22 KB)
```

Total: ~88 KB lisaks plugin-zip'ile. Aktsepteeritav.

**Allikas:**

- Lae alla `rsms/inter` GitHub-i releaseist: https://github.com/rsms/inter/releases
- Vali "Inter Variable" → ekstreaktige üks variant per weight (400, 500, 600, 700)
- Või kasuta Google Fonts CSS API-d **lokaalse-fail'i genereerimiseks** (lokaalne lae alla, mitte hostingu link)

**Alternatiivne**: Inter Variable Font (üks `.woff2`-fail kõikide weight'idega, ~150 KB). Lihtsam paigutus, aga vajab `@font-face`-i `font-weight: 100 900` (range). Code-agent võib valida kumb on selle keskkonna jaoks mõistlikum.

**CSS-references**: vt §3 `admin/src/index.css` `@font-face` deklaratsioonid.

---

## 6. Review-checklist Erkkile (Faas 2 lõpus)

Kui Faas 2 PR on review'l, ava plugin WP-admin'is **Smaily-UI kõrval browseri-tabis** ja kontrolli:

### Värvid

- [ ] Primary pink ühildub Smaily-UI primary-pink-ga (otsene võrdlus DevTools'is)
- [ ] Pink hover-state tundub sama tonalitsioonis
- [ ] "Newsletter" pill (või sarnane status) on **identse roosa** Smaily-UI status-pill'iga
- [ ] Page background — kas `#F9FAFB` on liiga hall? Kas tegelikult on **puhas valge** `#FFFFFF`? (Smaily-UI vaade)
- [ ] Card-border — kas `#E5E7EB` on liiga tume/hele?

### Typography

- [ ] Font tundub samasugune kui Smaily-UI-s
- [ ] Headings (Step pealkirjad) — sama-suurused, sama-weight
- [ ] Body text — sama-tonaalsus (mitte liiga must, mitte liiga hall)

### Spacing & layout

- [ ] Cards' padding tundub sama (Smaily-UI campaign-cards-iga võrdluses)
- [ ] Button-suurused — kas Smaily-UI nupud on suuremad/väiksemad?
- [ ] Vertical rhythm — kas mu vahed paragrahvide vahel on liiga tihedad/lõdvad?

### Action colors

- [ ] Step 6 Finish nupp — kas Smaily-UI "Send campaign" rohelisega ühildub?
- [ ] Success banner — sama-tonaalsus?

### Custom-tellimused

- [ ] Kas on Smaily-spetsiifilised komponendid (näit. unique chip-stiil, distinctive-input-look), mida prototüüp ei kata?
- [ ] Kui jah, lisa `STYLE_MAPPING.md` v1.1-sse.

**Pärast review-paranduste sisestamist**: vaikimisi hex'id `tailwind.config.js`-s asendatakse täpsetega, `npm run build` ja kontrolli.

---

## 7. Edasised laiendused (v1.x)

- **Dark mode**: praegu **ei** ole MVP-s. Tailwindi `dark:` variant on saadaval kui hilisem add-on. Code-agent ei pea seda nüüd implementeerima — token-id kõik defineeritud light-mode-le.
- **Custom shadcn/ui komponendid**: prototüüp ei kasuta shadcn-i, aga kui pilootkliendi review näitab, et standard-komponendid ei sobi (näit drop-down on liiga lihtne), võime hiljem integreerida. v1.x backlog.
- **Animatsioonid**: minimaalne MVP-s — ainult loading-spinner (`scp-spin`), backfill progress-bar fill, modal-fade-in (kui modaalid implementeeritakse hiljem). Mitte mingit hover-microinteraction'e — Smaily-stiil on rahulik.

---

## 8. Avatud küsimused

1. **Inter vs Smaily oma font** — kui Smaily kasutab mõnda muud fonti (Aeonik, GT Walsheim, sarnaseid), asendamine on triviaalne — vahetage `@font-face` failid `assets/fonts/`-i + uuendage `tailwind.config.js` `fontFamily.sans` esimene element.
2. **Brand-pink hex'i täpne väärtus** — `#E91E63` Material Pink 500 on logo-värv, aga Smaily võib brand-süsteemis kasutada veidi erinevat (näit `#EC407A`). Review-faasis kontrolli.
3. **Smaily-spetsiifilised komponendid** — kui pilootkliendi review näitab, et standard-token-id ei kata kõike, lisa STYLE_MAPPING.md v1.1.

---

**Lõpp**

See dokument annab Code-agendile **valmis Tailwind-config + font-bundle setup'i**. Hilisemas review-faasis Erkki vahetab täpsete hex'idega ühe-rea-edit'iga.
