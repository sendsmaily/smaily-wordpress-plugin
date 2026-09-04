import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
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
          subtle:  '#F3F4F6',     // Gray 100
          DEFAULT: '#E5E7EB',     // Gray 200
          strong:  '#D1D5DB',     // Gray 300
          cool:    '#9CA3AF',     // Gray 400
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
    forms({ strategy: 'class' }),
  ],
};
