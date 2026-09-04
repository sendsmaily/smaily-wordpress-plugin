/**
 * Smaily Connect Plus — WordPress Plugin Wizard Prototype
 * ========================================================
 * Iteration 4 — inline-styled rewrite.
 *
 * Single-file React/JSX UX prototype for the plugin spec in PLUGIN.md v0.2.
 *
 * Why inline styles?
 *   This prototype is meant to be rendered in many host environments — chat
 *   previews, plain CRA, online sandboxes, eventual Claude Code handoff. Any
 *   one of those can have a broken Tailwind setup. Inline styles eliminate
 *   the dependency entirely. The handoff to Code (see SUGGESTION.md at the
 *   end of this conversation) will convert these styles into Tailwind utility
 *   classes for production.
 *
 * Structure:
 *   1. Design tokens (colors, fonts, radii) — the only place to edit visuals
 *   2. Style helpers (sx) — reusable style objects
 *   3. Mock data (inline — replaced with real sources during Claude Code phase)
 *   4. UI primitives (Button, Input, Select, Checkbox, Toggle, Card, Banner...)
 *   5. Wizard shell (StepRail, WizardFooter)
 *   6. Step 1 — Connect (multilingual mode + Smaily + rec-engine)
 *   7. Step 2 — Subscribers (field sync + opt-in + animated backfill)
 *   8. Steps 3–6 placeholders
 *   9. Settings view (placeholder)
 *   10. Dev panel
 *   11. Wizard state reducer
 *   12. Root component
 *
 * NOT production code. UX prototype for Claude Code handoff.
 */

import React, { useState, useEffect, useMemo, useReducer } from 'react';
import {
  Check, X, AlertTriangle, AlertCircle, Info, ChevronRight, ChevronLeft, ChevronDown,
  Loader2, RefreshCw, Eye, EyeOff, Upload,
  Users, ShoppingCart, Sparkles, Puzzle, CheckCircle2,
  Wand2, Cog, Sliders, Link2, Languages, Power, UserPlus,
  Mail, Clock, ShoppingBag,
  Package, Heart, Gift, Layers, Undo2, ArrowRight, Lock, Shield,
  LayoutPanelLeft, ClipboardList, FilePlus, ExternalLink, Download,
  Search, RotateCw, Activity, FileSearch,
} from 'lucide-react';

/* ============================================================================
 * 1. DESIGN TOKENS
 * ========================================================================== */

const t = {
  font: {
    sans: "'Geist', ui-sans-serif, system-ui, -apple-system, sans-serif",
    mono: "'Geist Mono', ui-monospace, SFMono-Regular, Menlo, monospace",
  },
  c: {
    pageBg:        '#F4F4F2',
    surface:       '#FFFFFF',
    surfaceSoft:   '#FAFAF8',
    surfaceMuted:  '#F0F0EC',

    borderDefault: '#E7E7E4',
    borderSubtle:  '#EFEFEC',
    borderStrong:  '#D7D7D2',
    borderCool:    '#C7C7C2',

    textPrimary:   '#0A0A0A',
    textSecondary: '#5A5A55',
    textTertiary:  '#94948E',
    textWhite:     '#FFFFFF',

    brand:         '#0F3DDD',
    brandHover:    '#0B2FB0',
    brandSoftBg:   '#ECF0FF',
    brandSoftText: '#0E2F99',
    brandDisabled: '#A8B6F0',

    successFg: '#067A4A',
    successBg: '#E5F4EC',
    warningFg: '#A45D0B',
    warningBg: '#FBF0DE',
    dangerFg:  '#B62E47',
    dangerBg:  '#F8E5E9',
    dangerBorder: '#F0C7CF',
  },
  r: { sm: 4, md: 6, lg: 8, xl: 12, pill: 999 },
  shadow: {
    card: '0 1px 2px rgba(15,15,15,0.04)',
    pop:  '0 8px 24px rgba(15,15,15,0.08), 0 2px 6px rgba(15,15,15,0.04)',
    sm:   '0 1px 2px rgba(15,15,15,0.06)',
  },
};

/* ============================================================================
 * 2. MOCK DATA
 * ========================================================================== */

const MOCK = {
  defaultEnv: {
    upstreamPluginActive: false,
    detectedLanguages: ['et', 'en'],
    siteUrl: 'shop.example.com',
    elementorPresent: true,
    cf7Present: true,
  },
  languageNames: {
    et: 'Estonian', en: 'English', ru: 'Russian',
    fi: 'Finnish', lv: 'Latvian', lt: 'Lithuanian',
  },
  smailyAccountSuccessInfo: {
    accountName: 'shop-example', plan: 'Pro',
    contactCount: 4172, workflowCount: 14,
  },
  recEngineTenant: {
    tenant_id: 'tnt_8f3a09b2',
    tenant_name: 'Example Pet Shop',
    industry: 'pet',
  },
  storeTotals: { customers: 2347, orders: 8912, products: 412 },
  backfillSplit: { et: 1500, en: 750, _default: 97 },

  // Mock Smaily workflows for Step 3 automation mapping.
  // In production this is fetched via `GET /api/autoresponder/` per account.
  // account_key tells which credential block a workflow belongs to:
  //   - 'primary' for single-lang and Mode B/C (one account holds all workflows)
  //   - language code ('et', 'en', ...) for Mode A (each account has its own)
  // language is metadata to help the user pick — Smaily doesn't enforce it.
  workflows: [
    { id: 'wf_001', name: 'Welcome series — General',          account_key: 'primary', language: null },
    { id: 'wf_002', name: 'Welcome — Estonian',                account_key: 'primary', language: 'et' },
    { id: 'wf_003', name: 'Welcome — English',                 account_key: 'primary', language: 'en' },
    { id: 'wf_004', name: 'First order — 10% discount (ET)',   account_key: 'primary', language: 'et' },
    { id: 'wf_005', name: 'First order — 10% discount (EN)',   account_key: 'primary', language: 'en' },
    { id: 'wf_006', name: 'Abandoned cart 24h reminder (ET)',  account_key: 'primary', language: 'et' },
    { id: 'wf_007', name: 'Abandoned cart 24h reminder (EN)',  account_key: 'primary', language: 'en' },
    { id: 'wf_008', name: 'VIP welcome',                       account_key: 'primary', language: null },
    // Mode A: same workflow names, but in per-language accounts
    { id: 'wf_101', name: 'Welcome series',     account_key: 'et', language: 'et' },
    { id: 'wf_102', name: 'First order series', account_key: 'et', language: 'et' },
    { id: 'wf_103', name: 'Abandoned cart',     account_key: 'et', language: 'et' },
    { id: 'wf_201', name: 'Welcome series',     account_key: 'en', language: 'en' },
    { id: 'wf_202', name: 'First order series', account_key: 'en', language: 'en' },
    { id: 'wf_203', name: 'Abandoned cart',     account_key: 'en', language: 'en' },
  ],
};

const SUBSCRIBER_FIELDS = [
  { key: 'first_name',       label: 'First name',        defaultOn: true  },
  { key: 'last_name',        label: 'Last name',         defaultOn: true  },
  { key: 'phone',            label: 'Phone number',      defaultOn: true  },
  { key: 'birthday',         label: 'Birthday',          defaultOn: false },
  { key: 'gender',           label: 'Gender',            defaultOn: false },
  { key: 'customer_group',   label: 'Customer group',    defaultOn: true,  hint: 'WooCommerce role' },
  { key: 'customer_id',      label: 'Customer ID',       defaultOn: true,  hint: 'WordPress user ID' },
  { key: 'first_registered', label: 'Registration date', defaultOn: true  },
  { key: 'nickname',         label: 'Nickname',          defaultOn: false },
  { key: 'site_title',       label: 'Site title',        defaultOn: true,  hint: 'Useful when one Smaily account serves multiple sites' },
];

const defaultFieldSelections = () =>
  Object.fromEntries(SUBSCRIBER_FIELDS.map((f) => [f.key, f.defaultOn]));

// Mock event log data (placeholder for Event Log view).
// Production: this comes from PHP REST endpoint `/wp-json/smaily-plus/v1/events`
// which reads from `smly_plus_event_queue` + `smly_rec_event_queue` tables.
// Each entry has: id, timestamp, event_type, entity_id, source, status,
// attempts, max_attempts, last_error, payload (preview).
const nowIso = () => new Date().toISOString();
const minsAgo = (n) => new Date(Date.now() - n * 60 * 1000).toISOString();
const hoursAgo = (n) => new Date(Date.now() - n * 60 * 60 * 1000).toISOString();
const daysAgo = (n) => new Date(Date.now() - n * 24 * 60 * 60 * 1000).toISOString();

const MOCK_EVENT_LOG = [
  // Recent successful events
  { id: 1041, ts: minsAgo(2),  event_type: 'browse.batch',      entity_id: 'batch_72',     source: 'rec_engine', status: 'success', attempts: 1, max_attempts: 3, last_error: null, payload: { events_count: 47, batch_window: '30s' } },
  { id: 1040, ts: minsAgo(3),  event_type: 'contact.sync',      entity_id: 'usr_3829',     source: 'smaily',     status: 'success', attempts: 1, max_attempts: 5, last_error: null, payload: { email: 'mari@example.com', language: 'et' } },
  { id: 1039, ts: minsAgo(5),  event_type: 'order.created',     entity_id: 'wc_12892',     source: 'rec_engine', status: 'success', attempts: 1, max_attempts: 3, last_error: null, payload: { customer_email: 'jaak@example.com', total: 47.50 } },
  { id: 1038, ts: minsAgo(7),  event_type: 'browse.batch',      entity_id: 'batch_71',     source: 'rec_engine', status: 'success', attempts: 1, max_attempts: 3, last_error: null, payload: { events_count: 23, batch_window: '30s' } },
  // Failure — rate-limited
  { id: 1037, ts: minsAgo(12), event_type: 'catalog.update',    entity_id: 'sku_ACA-12',   source: 'rec_engine', status: 'retrying', attempts: 2, max_attempts: 5, last_error: 'HTTP 429: Too Many Requests. Retry-After: 5s', payload: { sku: 'ACA-DOG-3KG', price: 22.99 } },
  // Pending
  { id: 1036, ts: minsAgo(14), event_type: 'product.update',    entity_id: 'sku_ACA-22',   source: 'rec_engine', status: 'pending',  attempts: 0, max_attempts: 5, last_error: null, payload: { sku: 'ACA-CAT-1KG' } },
  // More success
  { id: 1035, ts: minsAgo(16), event_type: 'automation.trigger',entity_id: 'wf_002',       source: 'smaily',     status: 'success', attempts: 1, max_attempts: 3, last_error: null, payload: { trigger: 'welcome', email: 'uus@example.com', workflow_id: 'wf_002', language: 'et' } },
  { id: 1034, ts: minsAgo(19), event_type: 'contact.sync',      entity_id: 'usr_3830',     source: 'smaily',     status: 'success', attempts: 1, max_attempts: 5, last_error: null, payload: { email: 'kati@example.com', language: 'en' } },
  // Persistent failure
  { id: 1033, ts: minsAgo(22), event_type: 'identity.merge',    entity_id: 'vid_8f3a09',   source: 'rec_engine', status: 'failed', attempts: 5, max_attempts: 5, last_error: 'HTTP 401: Unauthorized. API key may be revoked.', payload: { visitor_id: '8f3a09b2-...', email: 'old@example.com', source: 'email_link' } },
  { id: 1032, ts: minsAgo(28), event_type: 'order.created',     entity_id: 'wc_12891',     source: 'rec_engine', status: 'success', attempts: 1, max_attempts: 3, last_error: null, payload: { customer_email: 'kalle@example.com', total: 89.00 } },
  { id: 1031, ts: minsAgo(33), event_type: 'browse.batch',      entity_id: 'batch_70',     source: 'rec_engine', status: 'success', attempts: 1, max_attempts: 3, last_error: null, payload: { events_count: 18, batch_window: '30s' } },
  // Transient 5xx — retrying
  { id: 1030, ts: minsAgo(38), event_type: 'customer.synced',   entity_id: 'cust_3829',    source: 'rec_engine', status: 'retrying', attempts: 1, max_attempts: 5, last_error: 'HTTP 503: Service Unavailable. Engine temporarily down.', payload: { email: 'tiit@example.com' } },
  { id: 1029, ts: minsAgo(45), event_type: 'contact.backfill',  entity_id: 'job_12',       source: 'smaily',     status: 'success', attempts: 1, max_attempts: 3, last_error: null, payload: { batch_size: 100, processed: 100 } },
  { id: 1028, ts: minsAgo(52), event_type: 'automation.trigger',entity_id: 'wf_004',       source: 'smaily',     status: 'success', attempts: 1, max_attempts: 3, last_error: null, payload: { trigger: 'first_order', email: 'jaak@example.com', workflow_id: 'wf_004' } },
  { id: 1027, ts: hoursAgo(1), event_type: 'browse.batch',      entity_id: 'batch_69',     source: 'rec_engine', status: 'success', attempts: 1, max_attempts: 3, last_error: null, payload: { events_count: 35, batch_window: '30s' } },
  // Validation error (4xx — won't retry)
  { id: 1026, ts: hoursAgo(2), event_type: 'catalog.update',    entity_id: 'sku_EMPTY',    source: 'rec_engine', status: 'failed', attempts: 1, max_attempts: 1, last_error: 'HTTP 400: Missing required field "name". Product was skipped (no SKU mapping).', payload: { sku: '', name: null } },
  { id: 1025, ts: hoursAgo(3), event_type: 'contact.sync',      entity_id: 'usr_3825',     source: 'smaily',     status: 'success', attempts: 2, max_attempts: 5, last_error: null, payload: { email: 'liis@example.com', language: 'et' } },
  { id: 1024, ts: hoursAgo(5), event_type: 'order.created',     entity_id: 'wc_12888',     source: 'rec_engine', status: 'success', attempts: 1, max_attempts: 3, last_error: null, payload: { customer_email: 'mart@example.com', total: 15.99 } },
  { id: 1023, ts: hoursAgo(8), event_type: 'browse.batch',      entity_id: 'batch_68',     source: 'rec_engine', status: 'success', attempts: 1, max_attempts: 3, last_error: null, payload: { events_count: 12, batch_window: '30s' } },
  { id: 1022, ts: hoursAgo(12),event_type: 'contact.backfill',  entity_id: 'job_11',       source: 'smaily',     status: 'success', attempts: 1, max_attempts: 3, last_error: null, payload: { batch_size: 100, processed: 100 } },
  { id: 1021, ts: daysAgo(1),  event_type: 'automation.trigger',entity_id: 'wf_003',       source: 'smaily',     status: 'success', attempts: 1, max_attempts: 3, last_error: null, payload: { trigger: 'abandoned_cart', email: 'piret@example.com', workflow_id: 'wf_003' } },
  { id: 1020, ts: daysAgo(2),  event_type: 'identity.merge',    entity_id: 'vid_a1b2c3',   source: 'rec_engine', status: 'success', attempts: 1, max_attempts: 3, last_error: null, payload: { visitor_id: 'a1b2c3...', email: 'silver@example.com', source: 'checkout' } },
];

/* ============================================================================
 * 3. UTILITIES
 * ========================================================================== */

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const formatNumber = (n) => new Intl.NumberFormat('en-US').format(n);
const countTrue = (obj) => Object.values(obj).filter(Boolean).length;

// Relative time formatter for the Event Log. Shows compact relative times
// for recent events ("2 min ago") and absolute date for older ones.
const formatRelativeTime = (iso) => {
  const then = new Date(iso).getTime();
  const diffSec = Math.floor((Date.now() - then) / 1000);
  if (diffSec < 60) return `${diffSec}s ago`;
  if (diffSec < 3600) return `${Math.floor(diffSec / 60)} min ago`;
  if (diffSec < 86400) return `${Math.floor(diffSec / 3600)} h ago`;
  if (diffSec < 604800) return `${Math.floor(diffSec / 86400)} d ago`;
  const d = new Date(iso);
  return `${d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}`;
};

const formatExactTime = (iso) => {
  const d = new Date(iso);
  return d.toLocaleString('en-GB', {
    year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', second: '2-digit',
  });
};

// Merge style objects, with `undefined`/`false`/`null` ignored. Used to compose
// base + variant + state styles without sprinkling ternaries everywhere.
const sx = (...args) => Object.assign({}, ...args.filter(Boolean));

/* ============================================================================
 * 4. GLOBAL STYLES (injected once on mount)
 * Needed for keyframes, pseudo-classes (hover/focus), and resets that can't
 * be done with inline styles alone.
 * ========================================================================== */

const GLOBAL_CSS = `
@keyframes scp-spin { to { transform: rotate(360deg); } }
@keyframes scp-pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
@keyframes scp-fadein {
  from { opacity: 0; transform: translateY(-4px); }
  to   { opacity: 1; transform: translateY(0); }
}

.scp-root * { box-sizing: border-box; }
.scp-root button { cursor: pointer; font-family: inherit; }
.scp-root button:disabled { cursor: not-allowed; }
.scp-root input, .scp-root select, .scp-root button { font-family: inherit; }
.scp-root input:focus, .scp-root select:focus { outline: none; }
.scp-root code { font-family: ${t.font.mono}; }

.scp-spin { animation: scp-spin 0.8s linear infinite; }
.scp-pulse { animation: scp-pulse 1.6s ease-in-out infinite; }
.scp-fadein { animation: scp-fadein 280ms ease-out; }

/* Hover/active states — these are the few cases we can't express inline */
.scp-btn-primary:hover:not(:disabled)   { background: ${t.c.brandHover} !important; }
.scp-btn-secondary:hover:not(:disabled) { background: ${t.c.surfaceSoft} !important; }
.scp-btn-ghost:hover:not(:disabled)     { background: ${t.c.surfaceMuted} !important; }
.scp-btn-danger:hover:not(:disabled)    { background: #FBF0F3 !important; }
.scp-rail-item:hover:not(:disabled)     { background: rgba(255,255,255,0.6); }
.scp-mode-card:hover                    { border-color: ${t.c.borderCool} !important; }
.scp-tab:hover                          { color: ${t.c.textPrimary} !important; }
.scp-input-wrap:focus-within            { border-color: ${t.c.brand} !important; box-shadow: 0 0 0 1px ${t.c.brand}; }
.scp-link:hover                         { color: ${t.c.textPrimary}; }

/* Scrollbar styling (subtle, doesn't intrude) */
.scp-scroll::-webkit-scrollbar { width: 10px; height: 10px; }
.scp-scroll::-webkit-scrollbar-thumb { background: #D7D7D2; border-radius: 6px; border: 2px solid transparent; background-clip: padding-box; }
.scp-scroll::-webkit-scrollbar-thumb:hover { background: #94948E; background-clip: padding-box; border: 2px solid transparent; }
.scp-scroll::-webkit-scrollbar-track { background: transparent; }
`;

const GlobalStyles = () => {
  useEffect(() => {
    if (typeof document === 'undefined') return;
    if (document.getElementById('scp-global-styles')) return;
    const style = document.createElement('style');
    style.id = 'scp-global-styles';
    style.textContent = GLOBAL_CSS;
    document.head.appendChild(style);

    // Geist fonts
    if (!document.getElementById('scp-fonts')) {
      const link = document.createElement('link');
      link.id = 'scp-fonts';
      link.rel = 'stylesheet';
      link.href = 'https://fonts.googleapis.com/css2?family=Geist:wght@300;400;500;600;700&family=Geist+Mono:wght@400;500&display=swap';
      document.head.appendChild(link);
    }
  }, []);
  return null;
};

/* ============================================================================
 * 5. UI PRIMITIVES
 * ========================================================================== */

// --- Button -----------------------------------------------------------------
const buttonSizes = {
  sm: { height: 32, padding: '0 12px', fontSize: 13,   gap: 6,  borderRadius: t.r.sm },
  md: { height: 36, padding: '0 16px', fontSize: 13.5, gap: 8,  borderRadius: t.r.md },
  lg: { height: 44, padding: '0 20px', fontSize: 14.5, gap: 8,  borderRadius: t.r.md },
};

const buttonVariants = {
  primary: {
    background: t.c.brand,
    color: t.c.textWhite,
    border: 'none',
    disabledBg: t.c.brandDisabled,
  },
  secondary: {
    background: t.c.surface,
    color: t.c.textPrimary,
    border: `1px solid ${t.c.borderStrong}`,
    disabledBg: t.c.surfaceMuted,
    disabledColor: t.c.textTertiary,
  },
  ghost: {
    background: 'transparent',
    color: t.c.textPrimary,
    border: 'none',
    disabledColor: t.c.textTertiary,
  },
  danger: {
    background: t.c.surface,
    color: t.c.dangerFg,
    border: `1px solid ${t.c.dangerBorder}`,
    disabledColor: t.c.textTertiary,
  },
};

const Button = ({
  children, variant = 'primary', size = 'md', loading = false, disabled = false,
  icon: Icon = null, iconPosition = 'left', onClick, type = 'button',
  fullWidth = false, style,
}) => {
  const s = buttonSizes[size];
  const v = buttonVariants[variant];
  const isDisabled = disabled || loading;
  const baseStyle = {
    display: 'inline-flex',
    alignItems: 'center',
    justifyContent: 'center',
    fontWeight: 500,
    transition: 'background-color 120ms ease, color 120ms ease',
    userSelect: 'none',
    whiteSpace: 'nowrap',
    height: s.height,
    padding: s.padding,
    fontSize: s.fontSize,
    gap: s.gap,
    borderRadius: s.borderRadius,
    background: isDisabled && v.disabledBg ? v.disabledBg : v.background,
    color: isDisabled && v.disabledColor ? v.disabledColor : v.color,
    border: v.border,
    width: fullWidth ? '100%' : undefined,
    ...style,
  };
  return (
    <button
      type={type}
      onClick={onClick}
      disabled={isDisabled}
      className={`scp-btn-${variant}`}
      style={baseStyle}
    >
      {loading && <Loader2 size={14} className="scp-spin" />}
      {!loading && Icon && iconPosition === 'left' && <Icon size={14} />}
      {children}
      {!loading && Icon && iconPosition === 'right' && <Icon size={14} />}
    </button>
  );
};

// --- Input ------------------------------------------------------------------
const Input = ({
  label, hint, error, prefix, suffix, mono, type = 'text', value, onChange,
  placeholder, style,
}) => {
  const [shown, setShown] = useState(false);
  const isPassword = type === 'password';
  const inputType = isPassword ? (shown ? 'text' : 'password') : type;
  return (
    <label style={sx({ display: 'block' }, style)}>
      {label && (
        <div style={{ fontSize: 12.5, fontWeight: 500, color: t.c.textPrimary, marginBottom: 6 }}>
          {label}
        </div>
      )}
      <div
        className="scp-input-wrap"
        style={{
          display: 'flex',
          alignItems: 'center',
          background: t.c.surface,
          border: `1px solid ${error ? t.c.dangerFg : t.c.borderStrong}`,
          borderRadius: t.r.md,
          transition: 'border-color 120ms ease, box-shadow 120ms ease',
        }}
      >
        {prefix && (
          <span style={{
            paddingLeft: 12, paddingRight: 4, fontSize: 13, color: t.c.textTertiary,
            userSelect: 'none', fontFamily: mono ? t.font.mono : undefined,
          }}>
            {prefix}
          </span>
        )}
        <input
          type={inputType}
          value={value || ''}
          onChange={onChange}
          placeholder={placeholder}
          style={{
            flex: 1, minWidth: 0, background: 'transparent', border: 'none',
            padding: prefix ? '8px 12px 8px 4px' : '8px 12px',
            fontSize: 13.5, color: t.c.textPrimary,
            fontFamily: mono ? t.font.mono : undefined,
          }}
        />
        {suffix && !isPassword && (
          <span style={{
            paddingLeft: 4, paddingRight: 12, fontSize: 13, color: t.c.textTertiary,
            userSelect: 'none', whiteSpace: 'nowrap',
            fontFamily: mono ? t.font.mono : undefined,
          }}>
            {suffix}
          </span>
        )}
        {isPassword && (
          <button
            type="button"
            onClick={() => setShown(!shown)}
            tabIndex={-1}
            style={{
              padding: '0 12px', background: 'transparent', border: 'none',
              color: t.c.textTertiary, display: 'flex', alignItems: 'center',
            }}
          >
            {shown ? <EyeOff size={14} /> : <Eye size={14} />}
          </button>
        )}
      </div>
      {(hint || error) && (
        <div style={{ fontSize: 12, marginTop: 6, color: error ? t.c.dangerFg : t.c.textSecondary }}>
          {error || hint}
        </div>
      )}
    </label>
  );
};

// --- NumberInput ------------------------------------------------------------
// Compact number input with +/- stepper buttons. Min/max are enforced; step
// defaults to 1. Used for Step 3's abandoned-cart cutoff time.
const NumberInput = ({ value, onChange, min = 0, max = 9999, step = 1, suffix, disabled = false, width = 140 }) => {
  const clamp = (n) => Math.max(min, Math.min(max, n));
  const handleChange = (e) => {
    const raw = e.target.value;
    if (raw === '') return onChange('');
    const n = parseInt(raw, 10);
    if (!isNaN(n)) onChange(clamp(n));
  };
  const dec = () => onChange(clamp((typeof value === 'number' ? value : min) - step));
  const inc = () => onChange(clamp((typeof value === 'number' ? value : min) + step));
  const stepperBtn = {
    width: 28, height: 28, padding: 0, display: 'flex', alignItems: 'center',
    justifyContent: 'center', background: t.c.surface,
    border: `1px solid ${t.c.borderStrong}`, fontSize: 14, fontWeight: 500,
    color: t.c.textPrimary, borderRadius: t.r.sm,
    opacity: disabled ? 0.5 : 1,
  };
  return (
    <div style={{ display: 'inline-flex', alignItems: 'center', gap: 4, width }}>
      <button type="button" onClick={dec} disabled={disabled} style={stepperBtn}>−</button>
      <div style={{
        flex: 1, display: 'flex', alignItems: 'center',
        background: t.c.surface, border: `1px solid ${t.c.borderStrong}`,
        borderRadius: t.r.sm, opacity: disabled ? 0.5 : 1,
      }}>
        <input
          type="text"
          inputMode="numeric"
          value={value}
          onChange={handleChange}
          disabled={disabled}
          style={{
            flex: 1, minWidth: 0, background: 'transparent', border: 'none',
            padding: '5px 8px', fontSize: 13.5, color: t.c.textPrimary,
            textAlign: 'center',
            fontFamily: t.font.mono,
          }}
        />
        {suffix && (
          <span style={{
            paddingRight: 8, fontSize: 12, color: t.c.textTertiary,
            userSelect: 'none', whiteSpace: 'nowrap',
          }}>
            {suffix}
          </span>
        )}
      </div>
      <button type="button" onClick={inc} disabled={disabled} style={stepperBtn}>+</button>
    </div>
  );
};

// --- Select -----------------------------------------------------------------
const Select = ({ label, hint, value, onChange, options, placeholder, disabled, style }) => (
  <label style={sx({ display: 'block' }, style)}>
    {label && (
      <div style={{ fontSize: 12.5, fontWeight: 500, color: t.c.textPrimary, marginBottom: 6 }}>
        {label}
      </div>
    )}
    <div style={{ position: 'relative' }}>
      <select
        value={value || ''}
        onChange={(e) => onChange(e.target.value)}
        disabled={disabled}
        style={{
          width: '100%', appearance: 'none', background: disabled ? t.c.surfaceMuted : t.c.surface,
          border: `1px solid ${t.c.borderStrong}`, borderRadius: t.r.md,
          padding: '8px 36px 8px 12px', fontSize: 13.5,
          color: disabled ? t.c.textTertiary : t.c.textPrimary,
          cursor: disabled ? 'not-allowed' : 'pointer',
        }}
      >
        {placeholder && <option value="" disabled>{placeholder}</option>}
        {options.map((opt) => (
          <option key={opt.value} value={opt.value}>{opt.label}</option>
        ))}
      </select>
      <ChevronDown
        size={16}
        color={t.c.textTertiary}
        style={{
          position: 'absolute', right: 10, top: '50%', transform: 'translateY(-50%)',
          pointerEvents: 'none',
        }}
      />
    </div>
    {hint && <div style={{ fontSize: 12, marginTop: 6, color: t.c.textSecondary }}>{hint}</div>}
  </label>
);

// --- Checkbox ---------------------------------------------------------------
const Checkbox = ({ checked, onChange, label, hint, disabled = false }) => (
  <label style={{
    display: 'flex', alignItems: 'flex-start', gap: 10,
    cursor: disabled ? 'not-allowed' : 'pointer', opacity: disabled ? 0.5 : 1,
  }}>
    <button
      type="button"
      onClick={(e) => { e.preventDefault(); if (!disabled) onChange(!checked); }}
      tabIndex={-1}
      style={{
        marginTop: 2, width: 16, height: 16, flexShrink: 0, borderRadius: 3,
        background: checked ? t.c.brand : t.c.surface,
        border: `1px solid ${checked ? t.c.brand : t.c.borderCool}`,
        display: 'flex', alignItems: 'center', justifyContent: 'center',
        transition: 'background 120ms ease, border-color 120ms ease',
        padding: 0,
      }}
    >
      {checked && <Check size={12} color={t.c.textWhite} strokeWidth={3} />}
    </button>
    <div style={{ flex: 1, minWidth: 0 }}>
      <div style={{ fontSize: 13.5, color: t.c.textPrimary, lineHeight: 1.4 }}>{label}</div>
      {hint && (
        <div style={{ fontSize: 12, color: t.c.textSecondary, marginTop: 2, lineHeight: 1.4 }}>
          {hint}
        </div>
      )}
    </div>
  </label>
);

// --- Toggle -----------------------------------------------------------------
const Toggle = ({ checked, onChange, disabled = false }) => (
  <button
    type="button"
    onClick={() => !disabled && onChange(!checked)}
    disabled={disabled}
    style={{
      position: 'relative', display: 'inline-flex', alignItems: 'center',
      height: 20, width: 34, flexShrink: 0, borderRadius: t.r.pill, border: 'none',
      background: checked ? t.c.brand : t.c.borderStrong,
      transition: 'background 120ms ease',
      opacity: disabled ? 0.5 : 1, padding: 0,
    }}
  >
    <span style={{
      pointerEvents: 'none', display: 'inline-block', height: 16, width: 16,
      borderRadius: '50%', background: t.c.surface, boxShadow: t.shadow.sm,
      transform: `translateX(${checked ? 16 : 2}px)`,
      transition: 'transform 160ms ease',
    }} />
  </button>
);

// --- Radio ------------------------------------------------------------------
const Radio = ({ checked, onChange, label, hint, disabled = false }) => (
  <label style={{
    display: 'flex', alignItems: 'flex-start', gap: 10,
    cursor: disabled ? 'not-allowed' : 'pointer', opacity: disabled ? 0.5 : 1,
  }}>
    <button
      type="button"
      onClick={(e) => { e.preventDefault(); if (!disabled) onChange(); }}
      tabIndex={-1}
      style={{
        marginTop: 2, width: 16, height: 16, flexShrink: 0, borderRadius: '50%',
        background: t.c.surface, padding: 0,
        border: checked
          ? `5px solid ${t.c.brand}`
          : `1px solid ${t.c.borderCool}`,
        transition: 'border 120ms ease',
      }}
    />
    {(label || hint) && (
      <div style={{ flex: 1, minWidth: 0 }}>
        {label && <div style={{ fontSize: 13.5, color: t.c.textPrimary, lineHeight: 1.4 }}>{label}</div>}
        {hint && (
          <div style={{ fontSize: 12, color: t.c.textSecondary, marginTop: 2, lineHeight: 1.4 }}>
            {hint}
          </div>
        )}
      </div>
    )}
  </label>
);

// --- Card -------------------------------------------------------------------
const cardPad = { sm: 16, md: 20, lg: 24, xl: 28, none: 0 };
const Card = ({ children, padding = 'lg', style }) => (
  <div style={sx({
    background: t.c.surface,
    border: `1px solid ${t.c.borderDefault}`,
    borderRadius: t.r.lg,
    padding: cardPad[padding],
  }, style)}>
    {children}
  </div>
);

// --- SectionHeader ----------------------------------------------------------
const SectionHeader = ({ eyebrow, title, description, action }) => (
  <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 24, marginBottom: 20 }}>
    <div style={{ flex: 1, minWidth: 0 }}>
      {eyebrow && (
        <div style={{
          fontSize: 11.5, fontWeight: 500, textTransform: 'uppercase',
          letterSpacing: '0.08em', color: t.c.textTertiary, marginBottom: 6,
        }}>
          {eyebrow}
        </div>
      )}
      <h2 style={{
        fontSize: 20, fontWeight: 600, color: t.c.textPrimary, lineHeight: 1.2,
        margin: 0,
      }}>
        {title}
      </h2>
      {description && (
        <p style={{
          fontSize: 13.5, color: t.c.textSecondary, marginTop: 8,
          lineHeight: 1.6, maxWidth: 640,
        }}>
          {description}
        </p>
      )}
    </div>
    {action && <div style={{ flexShrink: 0 }}>{action}</div>}
  </div>
);

// --- Banner -----------------------------------------------------------------
const bannerTones = {
  info:    { bg: t.c.brandSoftBg, text: t.c.brandSoftText, icon: Info },
  success: { bg: t.c.successBg,   text: t.c.successFg,     icon: CheckCircle2 },
  warning: { bg: t.c.warningBg,   text: t.c.warningFg,     icon: AlertTriangle },
  danger:  { bg: t.c.dangerBg,    text: t.c.dangerFg,      icon: AlertCircle },
};

const Banner = ({ tone = 'info', title, children, action }) => {
  const ts = bannerTones[tone];
  const Icon = ts.icon;
  return (
    <div style={{
      borderRadius: t.r.md, padding: '12px 16px',
      display: 'flex', alignItems: 'flex-start', gap: 12,
      background: ts.bg, color: ts.text,
    }}>
      <Icon size={16} style={{ marginTop: 2, flexShrink: 0 }} />
      <div style={{ flex: 1, minWidth: 0 }}>
        {title && (
          <div style={{ fontSize: 13.5, fontWeight: 500, lineHeight: 1.4 }}>
            {title}
          </div>
        )}
        {children && (
          <div style={{ fontSize: 13, marginTop: 4, lineHeight: 1.6 }}>
            {children}
          </div>
        )}
      </div>
      {action && <div style={{ flexShrink: 0 }}>{action}</div>}
    </div>
  );
};

// --- Pill -------------------------------------------------------------------
const pillTones = {
  neutral: { bg: t.c.surfaceMuted, text: t.c.textSecondary },
  success: { bg: t.c.successBg,    text: t.c.successFg },
  warning: { bg: t.c.warningBg,    text: t.c.warningFg },
  danger:  { bg: t.c.dangerBg,     text: t.c.dangerFg },
  info:    { bg: t.c.brandSoftBg,  text: t.c.brandSoftText },
  brand:   { bg: t.c.brand,        text: t.c.textWhite },
};

const Pill = ({ tone = 'neutral', children, icon: Icon = null }) => {
  const ts = pillTones[tone];
  return (
    <span style={{
      display: 'inline-flex', alignItems: 'center', gap: 4,
      padding: '2px 8px', borderRadius: t.r.pill, fontSize: 11.5, fontWeight: 500,
      background: ts.bg, color: ts.text,
    }}>
      {Icon && <Icon size={12} />}
      {children}
    </span>
  );
};

// --- ProgressBar ------------------------------------------------------------
const progressTones = { brand: t.c.brand, success: t.c.successFg, danger: t.c.dangerFg };

const ProgressBar = ({ value, tone = 'brand' }) => {
  const pct = Math.min(100, Math.max(0, value));
  return (
    <div style={{ height: 6, background: t.c.surfaceMuted, borderRadius: t.r.pill, overflow: 'hidden' }}>
      <div style={{
        height: '100%', width: `${pct}%`, background: progressTones[tone],
        transition: 'width 200ms ease-out',
      }} />
    </div>
  );
};

// --- PillTabs ---------------------------------------------------------------
const PillTabs = ({ items, value, onChange }) => (
  <div style={{
    display: 'inline-flex', alignItems: 'center',
    background: t.c.surfaceMuted, padding: 4, borderRadius: t.r.md, gap: 4,
  }}>
    {items.map((item) => {
      const isActive = item.key === value;
      return (
        <button
          key={item.key}
          type="button"
          onClick={() => onChange(item.key)}
          style={{
            display: 'inline-flex', alignItems: 'center', gap: 6,
            padding: '0 12px', height: 28,
            fontSize: 13, fontWeight: 500, borderRadius: t.r.sm, border: 'none',
            background: isActive ? t.c.surface : 'transparent',
            color: isActive ? t.c.textPrimary : t.c.textSecondary,
            boxShadow: isActive ? t.shadow.sm : 'none',
            transition: 'background 120ms ease, color 120ms ease',
          }}
        >
          <span>{item.label}</span>
          {item.status === 'success' && (
            <Check size={14} color={t.c.successFg} strokeWidth={3} />
          )}
          {item.status === 'testing' && (
            <Loader2 size={12} className="scp-spin" color={t.c.brand} />
          )}
          {item.status === 'error' && (
            <X size={14} color={t.c.dangerFg} strokeWidth={3} />
          )}
        </button>
      );
    })}
  </div>
);

/* ============================================================================
 * 6. WIZARD SHELL
 * ========================================================================== */

const WIZARD_STEPS = [
  { num: 1, key: 'connect',         label: 'Connect',         description: 'Smaily and recommendations engine credentials' },
  { num: 2, key: 'subscribers',     label: 'Subscribers',     description: 'Contact sync and subscription forms' },
  { num: 3, key: 'woocommerce',     label: 'WooCommerce',     description: 'Welcome, first order, abandoned cart' },
  { num: 4, key: 'recommendations', label: 'Recommendations', description: 'Orders, customers, products, browsing' },
  { num: 5, key: 'integrations',    label: 'Integrations',    description: 'Elementor, CF7, landing pages' },
  { num: 6, key: 'done',            label: 'Done',            description: 'Review and finish' },
];

const STEP_ICONS = {
  connect: Link2, subscribers: Users, woocommerce: ShoppingCart,
  recommendations: Sparkles, integrations: Puzzle, done: CheckCircle2,
};

const StepRail = ({ currentStep, completed, onStepClick }) => (
  <div
    className="scp-scroll"
    style={{
      width: 280, flexShrink: 0, padding: 32,
      borderRight: `1px solid ${t.c.borderDefault}`,
      background: t.c.surfaceSoft, overflowY: 'auto',
    }}
  >
    <div style={{ marginBottom: 28 }}>
      <div style={{
        fontSize: 11.5, fontWeight: 500, textTransform: 'uppercase',
        letterSpacing: '0.08em', color: t.c.textTertiary, marginBottom: 8,
      }}>
        Setup
      </div>
      <div style={{ fontSize: 16, fontWeight: 600, color: t.c.textPrimary, lineHeight: 1.3 }}>
        Smaily Connect Plus
      </div>
      <div style={{ fontSize: 12.5, color: t.c.textSecondary, marginTop: 2 }}>
        Plugin configuration wizard
      </div>
    </div>
    <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
      {WIZARD_STEPS.map((step) => {
        const isActive = step.num === currentStep;
        const isDone = completed.includes(step.num);
        const isClickable = isDone || step.num <= currentStep;
        return (
          <button
            key={step.key}
            onClick={() => isClickable && onStepClick(step.num)}
            disabled={!isClickable}
            className="scp-rail-item"
            style={{
              width: '100%', display: 'flex', alignItems: 'flex-start', gap: 12,
              padding: '10px 12px', borderRadius: t.r.md, textAlign: 'left',
              transition: 'background 120ms ease',
              background: isActive ? t.c.surface : 'transparent',
              border: isActive ? `1px solid ${t.c.borderDefault}` : '1px solid transparent',
              boxShadow: isActive ? t.shadow.card : 'none',
              opacity: isClickable ? 1 : 0.6,
              cursor: isClickable ? 'pointer' : 'not-allowed',
            }}
          >
            <div style={{
              marginTop: 2, width: 22, height: 22, borderRadius: '50%',
              display: 'flex', alignItems: 'center', justifyContent: 'center',
              flexShrink: 0, fontSize: 11, fontWeight: 600,
              background: isDone ? t.c.successFg : isActive ? t.c.brand : t.c.borderDefault,
              color: (isDone || isActive) ? t.c.textWhite : t.c.textSecondary,
              transition: 'background 120ms ease',
            }}>
              {isDone ? <Check size={12} strokeWidth={3} /> : step.num}
            </div>
            <div style={{ flex: 1, minWidth: 0 }}>
              <div style={{ fontSize: 13.5, fontWeight: 500, lineHeight: 1.2, color: t.c.textPrimary }}>
                {step.label}
              </div>
              <div style={{ fontSize: 12, color: t.c.textSecondary, marginTop: 4, lineHeight: 1.4 }}>
                {step.description}
              </div>
            </div>
          </button>
        );
      })}
    </div>
  </div>
);

const WizardFooter = ({ currentStep, canAdvance, onBack, onNext, hint }) => (
  <div style={{
    borderTop: `1px solid ${t.c.borderDefault}`, background: t.c.surface,
    padding: '16px 40px',
    display: 'flex', alignItems: 'center', justifyContent: 'space-between',
    flexShrink: 0,
  }}>
    <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
      {currentStep > 1 && (
        <Button variant="ghost" icon={ChevronLeft} onClick={onBack}>Back</Button>
      )}
      {hint && <div style={{ fontSize: 12.5, color: t.c.textTertiary }}>{hint}</div>}
    </div>
    <Button
      variant="primary"
      icon={currentStep === WIZARD_STEPS.length ? Check : ChevronRight}
      iconPosition="right"
      disabled={!canAdvance}
      onClick={onNext}
    >
      {currentStep === WIZARD_STEPS.length ? 'Finish' : 'Continue'}
    </Button>
  </div>
);

/* ============================================================================
 * 7. STEP 1 — CONNECT
 * ========================================================================== */

const MultilingualModePicker = ({ value, onChange }) => {
  const opts = [
    {
      key: 'A',
      title: 'Separate Smaily accounts per language',
      description: 'One Smaily account per language. Maximum flexibility — recommended when you have separate brand teams.',
    },
    {
      key: 'B',
      title: 'One account, separate automations per language',
      description: 'Single Smaily account, but each automation (welcome, first order, abandoned cart) is mapped per language. Most common setup.',
      recommended: true,
    },
    {
      key: 'C',
      title: 'One account, one automation with language branching',
      description: 'Single account, single automation that branches inside Smaily based on the contact language field.',
    },
  ];
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
      {opts.map((opt) => {
        const selected = value === opt.key;
        return (
          <button
            type="button"
            key={opt.key}
            onClick={() => onChange(opt.key)}
            className="scp-mode-card"
            style={{
              width: '100%', textAlign: 'left', padding: 16,
              borderRadius: t.r.lg, display: 'flex', gap: 12,
              transition: 'border-color 120ms ease, background 120ms ease',
              background: selected ? '#F4F7FF' : t.c.surface,
              border: `1px solid ${selected ? t.c.brand : t.c.borderDefault}`,
              boxShadow: selected ? `0 0 0 1px ${t.c.brand}` : 'none',
            }}
          >
            <div style={{
              marginTop: 2, width: 16, height: 16, borderRadius: '50%', flexShrink: 0,
              border: selected ? `5px solid ${t.c.brand}` : `1px solid ${t.c.borderCool}`,
              background: t.c.surface,
              transition: 'border 120ms ease',
            }} />
            <div style={{ flex: 1, minWidth: 0 }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                <div style={{ fontSize: 13.5, fontWeight: 500, color: t.c.textPrimary, lineHeight: 1.4 }}>
                  Mode {opt.key} · {opt.title}
                </div>
                {opt.recommended && <Pill tone="brand">Recommended</Pill>}
              </div>
              <div style={{ fontSize: 12.5, color: t.c.textSecondary, marginTop: 4, lineHeight: 1.6 }}>
                {opt.description}
              </div>
            </div>
          </button>
        );
      })}
    </div>
  );
};

const SmailyCredentialBlock = ({ values, onChange, onTest, onReset, testStatus, accountInfo }) => {
  const canTest = values.subdomain && values.user && values.password;
  return (
    <Card padding="md">
      <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
        <Input
          label="Subdomain"
          prefix="https://"
          suffix=".sendsmaily.net"
          value={values.subdomain}
          onChange={(e) => onChange({ ...values, subdomain: e.target.value })}
          placeholder="your-account"
          mono
        />
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
          <Input
            label="API user"
            value={values.user}
            onChange={(e) => onChange({ ...values, user: e.target.value })}
            placeholder="user@example.com"
          />
          <Input
            label="API password"
            type="password"
            value={values.password}
            onChange={(e) => onChange({ ...values, password: e.target.value })}
            placeholder="••••••••••••"
          />
        </div>
        {testStatus === 'success' && accountInfo && (
          <Banner tone="success" title={`Connected to ${accountInfo.accountName}.sendsmaily.net`}>
            Plan: {accountInfo.plan} · {formatNumber(accountInfo.contactCount)} contacts · {accountInfo.workflowCount} workflows
          </Banner>
        )}
        {testStatus === 'error' && (
          <Banner tone="danger" title="Connection failed">
            The API returned 401 (unauthorized). Check your credentials and try again.
          </Banner>
        )}
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'flex-end', gap: 8 }}>
          {testStatus === 'success' ? (
            <Button variant="ghost" size="sm" onClick={onReset}>Test again</Button>
          ) : (
            <Button
              variant="secondary"
              size="sm"
              icon={Link2}
              loading={testStatus === 'testing'}
              disabled={!canTest || testStatus === 'testing'}
              onClick={onTest}
            >
              Test connection
            </Button>
          )}
        </div>
      </div>
    </Card>
  );
};

const RecEngineBlock = ({ values, onChange, onTest, onReset, testStatus, tenantInfo }) => {
  const canTest = values.endpoint && values.token;
  return (
    <Card padding="md">
      <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
        <Input
          label="Endpoint URL"
          prefix="https://"
          value={values.endpoint}
          onChange={(e) => onChange({ ...values, endpoint: e.target.value })}
          placeholder="recommendations.smaily.com"
          mono
        />
        <Input
          label="Access token"
          type="password"
          value={values.token}
          onChange={(e) => onChange({ ...values, token: e.target.value })}
          placeholder="Paste your tenant token"
          hint="Issued from the recommendations engine dashboard. The token contains your tenant identifier."
        />
        {testStatus === 'success' && tenantInfo && (
          <Banner tone="success" title={`Connected to tenant: ${tenantInfo.tenant_name}`}>
            <span style={{ fontFamily: t.font.mono }}>{tenantInfo.tenant_id}</span>
            <span style={{ margin: '0 8px' }}>·</span>
            <span style={{ textTransform: 'capitalize' }}>Industry: {tenantInfo.industry}</span>
          </Banner>
        )}
        {testStatus === 'error' && (
          <Banner tone="danger" title="Connection failed">
            The endpoint did not respond or the token is invalid. Check the values and try again.
          </Banner>
        )}
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'flex-end', gap: 8 }}>
          {testStatus === 'success' ? (
            <Button variant="ghost" size="sm" onClick={onReset}>Test again</Button>
          ) : (
            <Button
              variant="secondary"
              size="sm"
              icon={Link2}
              loading={testStatus === 'testing'}
              disabled={!canTest || testStatus === 'testing'}
              onClick={onTest}
            >
              Test connection
            </Button>
          )}
        </div>
      </div>
    </Card>
  );
};

const DefaultAccountRow = ({ languages, accounts, value, onChange }) => {
  const tested = languages.filter((l) => accounts[l]?.testStatus === 'success');
  if (tested.length === 0) return null;
  return (
    <div style={{
      display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 16,
      padding: '12px 16px', background: t.c.surfaceSoft,
      border: `1px solid ${t.c.borderDefault}`, borderRadius: t.r.md,
    }}>
      <div style={{ minWidth: 0 }}>
        <div style={{ fontSize: 13, fontWeight: 500, color: t.c.textPrimary }}>Default account</div>
        <div style={{ fontSize: 12, color: t.c.textSecondary, marginTop: 2, lineHeight: 1.4 }}>
          Used for contacts without a detected language
        </div>
      </div>
      <div style={{ minWidth: 200, flexShrink: 0 }}>
        <Select
          value={value || ''}
          onChange={onChange}
          options={tested.map((l) => ({ value: l, label: `${MOCK.languageNames[l]} account` }))}
          placeholder="Select default"
        />
      </div>
    </div>
  );
};

const Step1Connect = ({ state, dispatch, env, inSettings = false }) => {
  const isMultilingual = env.detectedLanguages.length > 1;
  const mode = state.multilingualMode;
  const usePerLangAccounts = isMultilingual && mode === 'A';
  const [activeLang, setActiveLang] = useState(env.detectedLanguages[0]);

  useEffect(() => {
    if (!env.detectedLanguages.includes(activeLang)) {
      setActiveLang(env.detectedLanguages[0]);
    }
  }, [env.detectedLanguages, activeLang]);

  const handleSmailyTest = async (key) => {
    dispatch({ type: 'SMAILY_TEST', key, status: 'testing' });
    await sleep(900);
    dispatch({ type: 'SMAILY_TEST', key, status: 'success', accountInfo: MOCK.smailyAccountSuccessInfo });
  };
  const handleSmailyReset = (key) => dispatch({ type: 'SMAILY_TEST', key, status: 'idle' });

  const handleRecTest = async () => {
    dispatch({ type: 'REC_TEST', status: 'testing' });
    await sleep(1100);
    dispatch({ type: 'REC_TEST', status: 'success', tenantInfo: MOCK.recEngineTenant });
  };
  const handleRecReset = () => dispatch({ type: 'REC_TEST', status: 'idle' });

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 32 }}>
      {!inSettings && (
        <SectionHeader
          eyebrow="Step 1 of 6"
          title="Connect your accounts"
          description="Connect Smaily so the plugin can sync contacts and trigger automations. Optionally connect a recommendations engine for personalized product blocks in your emails."
        />
      )}

      {env.upstreamPluginActive && (
        <Banner
          tone="warning"
          title="Smaily Connect (older version) is active"
          action={<Button size="sm" variant="secondary" icon={Power}>Deactivate</Button>}
        >
          Running both plugins will send duplicate events. Deactivate the older plugin before continuing —
          your existing credentials and workflow settings will be migrated automatically.
        </Banner>
      )}

      {isMultilingual && (
        <section>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 6 }}>
            <Languages size={16} color={t.c.brand} />
            <h3 style={{ fontSize: 14, fontWeight: 600, color: t.c.textPrimary, margin: 0 }}>
              Multilingual setup
            </h3>
          </div>
          <p style={{ fontSize: 13, color: t.c.textSecondary, marginBottom: 16, maxWidth: 640, lineHeight: 1.6 }}>
            We detected {env.detectedLanguages.length} languages on this site
            ({env.detectedLanguages.map((l) => MOCK.languageNames[l]).join(', ')}).
            How is your Smaily setup organized for languages?
          </p>
          <MultilingualModePicker
            value={mode}
            onChange={(m) => dispatch({ type: 'SET_MODE', mode: m })}
          />
        </section>
      )}

      <section>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 12 }}>
          <h3 style={{ fontSize: 14, fontWeight: 600, color: t.c.textPrimary, margin: 0 }}>
            {usePerLangAccounts ? 'Smaily accounts' : 'Smaily account'}
          </h3>
          <Pill tone="danger">Required</Pill>
        </div>

        {usePerLangAccounts ? (
          <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
            <PillTabs
              items={env.detectedLanguages.map((lang) => ({
                key: lang,
                label: MOCK.languageNames[lang],
                status: state.smailyAccounts[lang]?.testStatus,
              }))}
              value={activeLang}
              onChange={setActiveLang}
            />
            <SmailyCredentialBlock
              values={state.smailyAccounts[activeLang] || { subdomain: '', user: '', password: '' }}
              onChange={(v) => dispatch({ type: 'SMAILY_CRED', key: activeLang, values: v })}
              onTest={() => handleSmailyTest(activeLang)}
              onReset={() => handleSmailyReset(activeLang)}
              testStatus={state.smailyAccounts[activeLang]?.testStatus || 'idle'}
              accountInfo={state.smailyAccounts[activeLang]?.accountInfo}
            />
            <DefaultAccountRow
              languages={env.detectedLanguages}
              accounts={state.smailyAccounts}
              value={state.defaultAccount}
              onChange={(lang) => dispatch({ type: 'SET_DEFAULT', lang })}
            />
          </div>
        ) : (
          <SmailyCredentialBlock
            values={state.smailyAccounts.primary || { subdomain: '', user: '', password: '' }}
            onChange={(v) => dispatch({ type: 'SMAILY_CRED', key: 'primary', values: v })}
            onTest={() => handleSmailyTest('primary')}
            onReset={() => handleSmailyReset('primary')}
            testStatus={state.smailyAccounts.primary?.testStatus || 'idle'}
            accountInfo={state.smailyAccounts.primary?.accountInfo}
          />
        )}
      </section>

      <section>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 6 }}>
          <Sparkles size={16} color={t.c.brand} />
          <h3 style={{ fontSize: 14, fontWeight: 600, color: t.c.textPrimary, margin: 0 }}>
            Recommendations engine
          </h3>
          <Pill tone="neutral">Optional</Pill>
        </div>
        <p style={{ fontSize: 13, color: t.c.textSecondary, marginBottom: 16, maxWidth: 640, lineHeight: 1.6 }}>
          Personalized product blocks in your campaigns. You can skip this and connect later from Settings.
        </p>
        <RecEngineBlock
          values={state.recEngine}
          onChange={(v) => dispatch({ type: 'REC_CRED', values: v })}
          onTest={handleRecTest}
          onReset={handleRecReset}
          testStatus={state.recEngine.testStatus}
          tenantInfo={state.recEngine.tenantInfo}
        />
      </section>
    </div>
  );
};

/* ============================================================================
 * 8. STEP 2 — SUBSCRIBERS
 * ========================================================================== */

const FieldSelectionCard = ({ fields, onChange }) => {
  const selectedCount = countTrue(fields);
  const allSelected = selectedCount === SUBSCRIBER_FIELDS.length;
  const handleToggleAll = () => {
    const next = Object.fromEntries(SUBSCRIBER_FIELDS.map((f) => [f.key, !allSelected]));
    onChange(next);
  };
  return (
    <Card padding="md">
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16 }}>
        <div style={{ fontSize: 13, fontWeight: 500, color: t.c.textPrimary }}>
          Fields to sync
          <span style={{ marginLeft: 8, fontSize: 12, fontWeight: 400, color: t.c.textTertiary }}>
            {selectedCount} of {SUBSCRIBER_FIELDS.length} selected
          </span>
        </div>
        <Button variant="ghost" size="sm" onClick={handleToggleAll}>
          {allSelected ? 'Deselect all' : 'Select all'}
        </Button>
      </div>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', columnGap: 24, rowGap: 12 }}>
        {SUBSCRIBER_FIELDS.map((field) => (
          <Checkbox
            key={field.key}
            checked={!!fields[field.key]}
            onChange={(v) => onChange({ ...fields, [field.key]: v })}
            label={field.label}
            hint={field.hint}
          />
        ))}
      </div>
      <div style={{
        fontSize: 11.5, color: t.c.textTertiary, marginTop: 16, paddingTop: 12,
        borderTop: `1px solid ${t.c.borderSubtle}`,
      }}>
        Email address is always synced and isn't shown here. Selected fields are sent on every contact create or update.
      </div>
    </Card>
  );
};

const BackfillIdle = ({ total, isModeA, totalByLang, defaultAccount, languages, onStart }) => (
  <Card padding="md">
    <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 16 }}>
      <div style={{ minWidth: 0 }}>
        <div style={{ fontSize: 13.5, fontWeight: 500, color: t.c.textPrimary }}>
          Import {formatNumber(total)} existing users to Smaily
        </div>
        <div style={{ fontSize: 12.5, color: t.c.textSecondary, marginTop: 4, lineHeight: 1.6, maxWidth: 440 }}>
          {isModeA
            ? 'Users will be split across your language accounts based on their site language. Users without a language go to the default account.'
            : 'Every existing WordPress user becomes a Smaily contact. You can re-run this later from Settings.'}
        </div>
      </div>
      <Button variant="primary" icon={Upload} onClick={onStart}>Start backfill</Button>
    </div>
    {isModeA && (
      <div style={{
        marginTop: 16, paddingTop: 16, borderTop: `1px solid ${t.c.borderSubtle}`,
        display: 'flex', flexDirection: 'column', gap: 6,
      }}>
        {languages.map((lang) => (
          <div key={lang} style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', fontSize: 12 }}>
            <span style={{ color: t.c.textPrimary }}>{MOCK.languageNames[lang]} users</span>
            <span style={{ fontFamily: t.font.mono, color: t.c.textSecondary }}>
              {formatNumber(totalByLang[lang] || 0)} → {MOCK.languageNames[lang]} account
            </span>
          </div>
        ))}
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', fontSize: 12 }}>
          <span style={{ color: t.c.textPrimary }}>Users without language</span>
          <span style={{ fontFamily: t.font.mono, color: t.c.textSecondary }}>
            {formatNumber(totalByLang._default || 0)} →{' '}
            {defaultAccount ? `${MOCK.languageNames[defaultAccount]} (default)` : 'default account'}
          </span>
        </div>
      </div>
    )}
  </Card>
);

const BackfillRow = ({ name, destination, processed, total }) => {
  const isDone = total > 0 && processed >= total;
  const pct = total > 0 ? (processed / total) * 100 : 0;
  return (
    <div>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', fontSize: 12.5, marginBottom: 6 }}>
        <div style={{ fontWeight: 500, color: t.c.textPrimary, display: 'flex', alignItems: 'center', gap: 6 }}>
          {name}
          {isDone && <Check size={12} color={t.c.successFg} strokeWidth={3} />}
        </div>
        <div style={{ fontFamily: t.font.mono, fontSize: 12, color: t.c.textSecondary }}>
          {formatNumber(processed)} / {formatNumber(total)}
        </div>
      </div>
      <ProgressBar value={pct} tone={isDone ? 'success' : 'brand'} />
      <div style={{ fontSize: 11.5, color: t.c.textTertiary, marginTop: 4 }}>→ {destination}</div>
    </div>
  );
};

const BackfillProgress = ({ bf, defaultAccount, onReset }) => {
  const isCompleted = bf.status === 'completed';
  const totalProcessed = bf.isModeA
    ? Object.values(bf.processedByLang).reduce((a, b) => a + b, 0)
    : bf.processed;
  const totalAll = bf.isModeA
    ? Object.values(bf.totalByLang).reduce((a, b) => a + b, 0)
    : bf.total;

  return (
    <Card padding="md">
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 16, marginBottom: 16 }}>
        <div style={{ minWidth: 0 }}>
          <div style={{
            fontSize: 13.5, fontWeight: 500, color: t.c.textPrimary,
            display: 'flex', alignItems: 'center', gap: 8,
          }}>
            {!isCompleted && <Loader2 size={14} className="scp-spin" color={t.c.brand} />}
            {isCompleted && <CheckCircle2 size={16} color={t.c.successFg} />}
            {isCompleted ? 'Backfill completed' : 'Syncing contacts to Smaily'}
          </div>
          <div style={{ fontSize: 12.5, color: t.c.textSecondary, marginTop: 4 }}>
            {isCompleted
              ? `${formatNumber(totalAll)} users synced just now`
              : `${formatNumber(totalProcessed)} of ${formatNumber(totalAll)} synced`}
          </div>
        </div>
        {isCompleted && (
          <Button variant="ghost" size="sm" icon={RefreshCw} onClick={onReset}>Re-run</Button>
        )}
      </div>

      {bf.isModeA ? (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
          {bf.languages.map((lang) => (
            <BackfillRow
              key={lang}
              name={`${MOCK.languageNames[lang]} users`}
              destination={`${MOCK.languageNames[lang]} account`}
              processed={bf.processedByLang[lang] || 0}
              total={bf.totalByLang[lang] || 0}
            />
          ))}
          <BackfillRow
            name="Users without language"
            destination={
              defaultAccount
                ? `${MOCK.languageNames[defaultAccount]} account (default)`
                : 'default account'
            }
            processed={bf.processedByLang._default || 0}
            total={bf.totalByLang._default || 0}
          />
        </div>
      ) : (
        <ProgressBar
          value={totalAll > 0 ? (totalProcessed / totalAll) * 100 : 0}
          tone={isCompleted ? 'success' : 'brand'}
        />
      )}
    </Card>
  );
};

const Step2Subscribers = ({ state, dispatch, env, inSettings = false }) => {
  const isMultilingual = env.detectedLanguages.length > 1;
  const isModeA = isMultilingual && state.multilingualMode === 'A';
  const subs = state.subscribers;
  const bf = subs.backfill;

  useEffect(() => {
    if (bf.status !== 'running') return;
    const id = setInterval(() => dispatch({ type: 'BACKFILL_TICK' }), 80);
    return () => clearInterval(id);
  }, [bf.status, dispatch]);

  const handleStartBackfill = () => {
    dispatch({ type: 'BACKFILL_START', isModeA, languages: env.detectedLanguages });
  };

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 32 }}>
      {!inSettings && (
        <SectionHeader
          eyebrow="Step 2 of 6"
          title="Sync subscribers to Smaily"
          description="Choose what contact data is synced, let visitors opt in from your existing forms, and import your current users."
        />
      )}

      {/* === CONTACT SYNC === */}
      <section>
        <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 16, marginBottom: 12 }}>
          <div style={{ minWidth: 0 }}>
            <h3 style={{ fontSize: 14, fontWeight: 600, color: t.c.textPrimary, margin: 0 }}>
              Sync contacts to Smaily
            </h3>
            <p style={{ fontSize: 13, color: t.c.textSecondary, marginTop: 2, maxWidth: 540, lineHeight: 1.6 }}>
              Existing and new WordPress users are kept in sync with Smaily as contacts.
            </p>
          </div>
          <Toggle
            checked={subs.syncEnabled}
            onChange={(v) => dispatch({ type: 'SUBSCRIBERS_TOGGLE_SYNC', value: v })}
          />
        </div>

        {subs.syncEnabled && (
          <FieldSelectionCard
            fields={subs.fields}
            onChange={(next) => dispatch({ type: 'SUBSCRIBERS_SET_FIELDS', fields: next })}
          />
        )}
      </section>

      {/* === SUBSCRIPTION OPT-IN === */}
      <section>
        <div style={{ marginBottom: 12 }}>
          <h3 style={{ fontSize: 14, fontWeight: 600, color: t.c.textPrimary, margin: 0 }}>
            Subscription opt-in
          </h3>
          <p style={{ fontSize: 13, color: t.c.textSecondary, marginTop: 2, maxWidth: 540, lineHeight: 1.6 }}>
            Let people subscribe to your newsletter from existing WordPress and WooCommerce forms.
          </p>
        </div>
        <Card padding="md">
          <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
            <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 16 }}>
              <div style={{ minWidth: 0 }}>
                <div style={{ fontSize: 13.5, fontWeight: 500, color: t.c.textPrimary }}>
                  WordPress registration form
                </div>
                <div style={{ fontSize: 12.5, color: t.c.textSecondary, marginTop: 2, lineHeight: 1.4 }}>
                  Add a "Subscribe to newsletter" checkbox to the WP registration form.
                </div>
              </div>
              <Toggle
                checked={subs.showOnWPRegistration}
                onChange={(v) => dispatch({ type: 'SUBSCRIBERS_TOGGLE_OPTIN', key: 'showOnWPRegistration', value: v })}
              />
            </div>
            <div style={{ borderTop: `1px solid ${t.c.borderSubtle}` }} />
            <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 16 }}>
              <div style={{ minWidth: 0 }}>
                <div style={{ fontSize: 13.5, fontWeight: 500, color: t.c.textPrimary }}>
                  WooCommerce checkout
                </div>
                <div style={{ fontSize: 12.5, color: t.c.textSecondary, marginTop: 2, lineHeight: 1.4 }}>
                  Add a "Subscribe to newsletter" checkbox to the WC checkout page.
                </div>
              </div>
              <Toggle
                checked={subs.showOnWCCheckout}
                onChange={(v) => dispatch({ type: 'SUBSCRIBERS_TOGGLE_OPTIN', key: 'showOnWCCheckout', value: v })}
              />
            </div>
          </div>
        </Card>

        <div style={{ marginTop: 12 }}>
          <Banner tone="info" title="Subscription form on any page or post">
            Add a sign-up form anywhere with the Gutenberg block <strong>Smaily subscription form</strong> or the shortcode{' '}
            <code style={{
              padding: '2px 6px', borderRadius: 4, background: 'rgba(255,255,255,0.6)',
              fontSize: 12, fontFamily: t.font.mono,
            }}>
              [smaily_subscription_form]
            </code>.
          </Banner>
        </div>
      </section>

      {/* === INITIAL BACKFILL === */}
      {subs.syncEnabled && (
        <section>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 12 }}>
            <UserPlus size={16} color={t.c.brand} />
            <h3 style={{ fontSize: 14, fontWeight: 600, color: t.c.textPrimary, margin: 0 }}>
              Initial backfill
            </h3>
            {bf.status === 'completed' && <Pill tone="success" icon={CheckCircle2}>Completed</Pill>}
            {bf.status === 'running' && <Pill tone="info">Running</Pill>}
          </div>

          {bf.status === 'idle' ? (
            <BackfillIdle
              total={bf.total}
              isModeA={isModeA}
              totalByLang={isModeA ? MOCK.backfillSplit : {}}
              defaultAccount={state.defaultAccount}
              languages={env.detectedLanguages}
              onStart={handleStartBackfill}
            />
          ) : (
            <BackfillProgress
              bf={bf}
              defaultAccount={state.defaultAccount}
              onReset={() => dispatch({ type: 'BACKFILL_RESET' })}
            />
          )}
        </section>
      )}
    </div>
  );
};

/* ============================================================================
 * 9. STEP 3 — WOOCOMMERCE AUTOMATIONS
 * Three stacked sections (Welcome, First Order, Abandoned Cart), each with:
 *   - Toggle to enable/disable (dimmed when off, but still visible)
 *   - Workflow picker that adapts to the multilingual mode:
 *       single-lang / Mode C → one dropdown
 *       Mode B               → per-language rows with workflow dropdown + radio
 *       Mode A               → same, plus per-language account context
 *   - Abandoned Cart only: cutoff time number input
 *
 * The default-language radio (Variant 1 from the spec discussion) indicates
 * which language row's workflow is the fallback for contacts whose language
 * cannot be detected. No separate "Default" row.
 * ========================================================================== */

// Filter the mock workflow list to those belonging to a given account.
// Single-lang / Mode B / Mode C: account_key = 'primary'
// Mode A:                       account_key = language code
const workflowsForAccount = (accountKey) =>
  MOCK.workflows.filter((wf) => wf.account_key === accountKey);

const AUTOMATION_DEFS = [
  {
    kind: 'welcome',
    icon: Mail,
    title: 'Welcome email',
    description: 'Sent to new subscribers when they sign up.',
    trigger: 'New contact created',
  },
  {
    kind: 'firstOrder',
    icon: ShoppingBag,
    title: 'First order email',
    description: 'Sent to customers after their first completed purchase.',
    trigger: 'Order completed (first-time buyer)',
  },
  {
    kind: 'abandonedCart',
    icon: Clock,
    title: 'Abandoned cart',
    description: 'Sent when a customer leaves items in their cart without checking out.',
    trigger: 'Cart abandoned',
  },
];

// LanguageWorkflowRow — one row per language inside an AutomationSection,
// used in Mode A and Mode B. Shows: radio (default fallback marker),
// language name, optional account context (Mode A), workflow dropdown.
const LanguageWorkflowRow = ({
  lang, accountKey, accountSubdomain, workflowId, onWorkflowChange,
  isDefault, onSetDefault, showAccountContext, disabled,
}) => {
  const workflows = workflowsForAccount(accountKey);
  return (
    <div style={{
      display: 'flex', alignItems: 'flex-start', gap: 12,
      padding: '12px 0',
      borderTop: `1px solid ${t.c.borderSubtle}`,
    }}>
      <div style={{ paddingTop: 8 }}>
        <Radio
          checked={isDefault}
          onChange={onSetDefault}
          disabled={disabled}
        />
      </div>
      <div style={{ flex: 1, minWidth: 0 }}>
        <div style={{
          display: 'flex', alignItems: 'baseline', gap: 8, marginBottom: 6,
          flexWrap: 'wrap',
        }}>
          <div style={{
            fontSize: 13.5, fontWeight: 500,
            color: disabled ? t.c.textTertiary : t.c.textPrimary,
          }}>
            {MOCK.languageNames[lang]}
          </div>
          {showAccountContext && accountSubdomain && (
            <div style={{
              fontSize: 12, fontFamily: t.font.mono,
              color: disabled ? t.c.textTertiary : t.c.textSecondary,
            }}>
              · {accountSubdomain}.sendsmaily.net
            </div>
          )}
          {isDefault && (
            <Pill tone="info">Default fallback</Pill>
          )}
        </div>
        <Select
          value={workflowId || ''}
          onChange={onWorkflowChange}
          disabled={disabled}
          options={workflows.map((wf) => ({ value: wf.id, label: wf.name }))}
          placeholder="Select a workflow"
        />
      </div>
    </div>
  );
};

const AutomationSection = ({ def, automation, mode, env, state, dispatch }) => {
  const { kind, icon: Icon, title, description, trigger } = def;
  const isMultilingual = env.detectedLanguages.length > 1;
  const isModeA = isMultilingual && mode === 'A';
  const isModeB = isMultilingual && mode === 'B';
  const usesPerLang = isModeA || isModeB;
  const dimmed = !automation.enabled;

  const setWorkflow = (lang, workflowId) =>
    dispatch({ type: 'AUTOMATION_SET_WORKFLOW', kind, lang, workflowId });
  const setSingleWorkflow = (workflowId) =>
    dispatch({ type: 'AUTOMATION_SET_WORKFLOW', kind, workflowId });
  const setDefaultLang = (lang) =>
    dispatch({ type: 'AUTOMATION_SET_DEFAULT_LANG', kind, lang });
  const setEnabled = (value) =>
    dispatch({ type: 'AUTOMATION_TOGGLE', kind, value });
  const setCutoff = (value) =>
    dispatch({ type: 'AUTOMATION_SET_CUTOFF', value });

  // For Mode A: each language row uses its language-keyed account.
  // For Mode B: every row uses the single primary account.
  const accountKeyForLang = (lang) => (isModeA ? lang : 'primary');
  const accountSubdomainFor = (lang) => {
    const key = accountKeyForLang(lang);
    return state.smailyAccounts[key]?.subdomain || (isModeA ? 'unconfigured' : 'shop-example');
  };

  const singleWorkflowOptions = workflowsForAccount('primary');

  return (
    <Card padding="md" style={{ opacity: dimmed ? 0.55 : 1, transition: 'opacity 160ms ease' }}>
      {/* Section header */}
      <div style={{
        display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between',
        gap: 16, marginBottom: dimmed ? 0 : 16,
      }}>
        <div style={{ display: 'flex', alignItems: 'flex-start', gap: 12, minWidth: 0 }}>
          <div style={{
            width: 36, height: 36, borderRadius: t.r.md, flexShrink: 0,
            background: t.c.brandSoftBg,
            display: 'flex', alignItems: 'center', justifyContent: 'center',
          }}>
            <Icon size={18} color={t.c.brand} />
          </div>
          <div style={{ minWidth: 0 }}>
            <div style={{ fontSize: 14, fontWeight: 600, color: t.c.textPrimary }}>
              {title}
            </div>
            <div style={{ fontSize: 12.5, color: t.c.textSecondary, marginTop: 2, lineHeight: 1.5 }}>
              {description}
            </div>
            <div style={{
              fontSize: 11.5, color: t.c.textTertiary, marginTop: 4,
              display: 'inline-flex', alignItems: 'center', gap: 4,
            }}>
              <span>Trigger:</span>
              <span style={{ fontFamily: t.font.mono }}>{trigger}</span>
            </div>
          </div>
        </div>
        <Toggle checked={automation.enabled} onChange={setEnabled} />
      </div>

      {/* Body — workflow picker */}
      {usesPerLang ? (
        <div>
          {env.detectedLanguages.map((lang) => (
            <LanguageWorkflowRow
              key={lang}
              lang={lang}
              accountKey={accountKeyForLang(lang)}
              accountSubdomain={accountSubdomainFor(lang)}
              workflowId={automation.workflowsByLang[lang]}
              onWorkflowChange={(wfId) => setWorkflow(lang, wfId)}
              isDefault={automation.defaultLang === lang}
              onSetDefault={() => setDefaultLang(lang)}
              showAccountContext={isModeA}
              disabled={dimmed}
            />
          ))}
          {/* Inline help below the rows */}
          <div style={{
            marginTop: 12, paddingTop: 12,
            borderTop: `1px solid ${t.c.borderSubtle}`,
            fontSize: 11.5, color: t.c.textTertiary, lineHeight: 1.6,
          }}>
            Select one language as the <strong style={{ color: t.c.textSecondary }}>default fallback</strong> — its
            workflow is used for contacts whose language cannot be detected.
          </div>
        </div>
      ) : (
        // Single-lang / Mode C: one dropdown.
        <div>
          <Select
            value={automation.workflow || ''}
            onChange={setSingleWorkflow}
            disabled={dimmed}
            options={singleWorkflowOptions.map((wf) => ({ value: wf.id, label: wf.name }))}
            placeholder="Select a workflow"
          />
          {mode === 'C' && (
            <div style={{
              fontSize: 11.5, color: t.c.textTertiary, marginTop: 8, lineHeight: 1.6,
            }}>
              The plugin will forward the contact's language to Smaily. Your workflow handles
              the per-language branching internally.
            </div>
          )}
        </div>
      )}

      {/* Abandoned cart — cutoff time */}
      {kind === 'abandonedCart' && (
        <div style={{
          marginTop: 16, paddingTop: 16,
          borderTop: `1px solid ${t.c.borderSubtle}`,
          display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 16,
        }}>
          <div style={{ minWidth: 0 }}>
            <div style={{ fontSize: 13, fontWeight: 500, color: t.c.textPrimary }}>
              Trigger cart as abandoned after
            </div>
            <div style={{ fontSize: 12, color: t.c.textSecondary, marginTop: 2, lineHeight: 1.4 }}>
              Minimum 10 minutes. We recommend 30–60 minutes for most stores.
            </div>
          </div>
          <NumberInput
            value={automation.cutoffMinutes}
            onChange={(v) => setCutoff(typeof v === 'number' ? Math.max(10, v) : 10)}
            min={10}
            max={1440}
            step={5}
            suffix="min"
            disabled={dimmed}
          />
        </div>
      )}
    </Card>
  );
};

const Step3WooCommerce = ({ state, dispatch, env, inSettings = false }) => {
  const mode = state.multilingualMode;

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 32 }}>
      {!inSettings && (
        <SectionHeader
          eyebrow="Step 3 of 6"
          title="WooCommerce automations"
          description="Map shop events to Smaily automation workflows. Each automation can be turned off if you don't want to use it."
        />
      )}

      <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
        {AUTOMATION_DEFS.map((def) => (
          <AutomationSection
            key={def.kind}
            def={def}
            automation={state.automations[def.kind]}
            mode={mode}
            env={env}
            state={state}
            dispatch={dispatch}
          />
        ))}
      </div>
    </div>
  );
};

/* ============================================================================
 * 10. STEP 4 — RECOMMENDATIONS
 * Two variants depending on whether rec-engine is connected in Step 1:
 *   4a (connected):  Feature toggles + combined backfill panel + browsing card
 *   4b (not connected): Marketing pitch with SVG hero, 6 context cards, CTA
 * ========================================================================== */

// --- Marketing illustration (Variant 4b hero) -------------------------------
// Stylized email mockup with a "Recommended for you" product strip. SVG only,
// no external assets needed.
const RecEmailHeroSVG = () => (
  <svg
    viewBox="0 0 640 380"
    xmlns="http://www.w3.org/2000/svg"
    style={{ width: '100%', height: 'auto', display: 'block' }}
    role="img"
    aria-label="Email mockup with personalized product recommendations"
  >
    {/* Background backdrop with subtle gradient */}
    <defs>
      <linearGradient id="bg-grad" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0%" stopColor="#ECF0FF" />
        <stop offset="100%" stopColor="#F4F4F2" />
      </linearGradient>
      <linearGradient id="card-grad" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0%" stopColor="#FAFAFA" />
        <stop offset="100%" stopColor="#F0F0EC" />
      </linearGradient>
      <filter id="card-shadow" x="-20%" y="-20%" width="140%" height="140%">
        <feDropShadow dx="0" dy="4" stdDeviation="8" floodOpacity="0.08" />
      </filter>
    </defs>

    <rect x="0" y="0" width="640" height="380" fill="url(#bg-grad)" />

    {/* Email window */}
    <g filter="url(#card-shadow)">
      <rect x="60" y="32" width="520" height="316" rx="10" fill="#FFFFFF" stroke="#E7E7E4" />

      {/* Window controls */}
      <circle cx="80"  cy="52" r="4" fill="#EBEBE7" />
      <circle cx="94"  cy="52" r="4" fill="#EBEBE7" />
      <circle cx="108" cy="52" r="4" fill="#EBEBE7" />

      {/* Sender bar */}
      <rect x="76" y="76" width="100" height="8" rx="2" fill="#0A0A0A" />
      <rect x="76" y="92" width="180" height="6" rx="2" fill="#C7C7C2" />

      {/* Subject */}
      <rect x="76" y="118" width="320" height="10" rx="2" fill="#0A0A0A" />

      {/* Body text lines */}
      <rect x="76" y="144" width="468" height="5" rx="2" fill="#E7E7E4" />
      <rect x="76" y="156" width="420" height="5" rx="2" fill="#E7E7E4" />
      <rect x="76" y="168" width="360" height="5" rx="2" fill="#E7E7E4" />

      {/* "Recommended for you" label */}
      <rect x="76" y="200" width="170" height="14" rx="3" fill="#0F3DDD" />
      <text
        x="84" y="211"
        fontSize="9" fontFamily="Geist, sans-serif" fontWeight="600"
        fill="#FFFFFF"
        letterSpacing="0.5"
      >
        RECOMMENDED FOR YOU
      </text>

      {/* Product cards */}
      {[
        { x: 76,  highlight: false },
        { x: 232, highlight: true  },
        { x: 388, highlight: false },
      ].map((card, i) => (
        <g key={i}>
          <rect
            x={card.x} y={228} width={140} height={104}
            rx={6}
            fill="#FFFFFF"
            stroke={card.highlight ? '#0F3DDD' : '#E7E7E4'}
            strokeWidth={card.highlight ? 1.5 : 1}
          />
          {/* Product image placeholder */}
          <rect x={card.x + 10} y={238} width={120} height={56} rx={4} fill="url(#card-grad)" />
          {/* Image icon-ish detail */}
          <circle cx={card.x + 35}  cy={262} r={5} fill="#D7D7D2" />
          <path
            d={`M ${card.x + 14} 290 L ${card.x + 55} 268 L ${card.x + 90} 282 L ${card.x + 126} 270 L ${card.x + 126} 294 L ${card.x + 14} 294 Z`}
            fill="#D7D7D2"
          />
          {/* Product name + price */}
          <rect x={card.x + 10} y={302} width={94} height={5} rx={2} fill="#0A0A0A" />
          <rect x={card.x + 10} y={314} width={48} height={5} rx={2} fill="#5A5A55" />
        </g>
      ))}
    </g>
  </svg>
);

// --- Context cards (Variant 4b context list) --------------------------------
const REC_CONTEXTS = [
  {
    key: 'welcome',
    icon: UserPlus,
    name: 'Welcome',
    desc: 'Greet new subscribers with bestsellers and category staples that fit their declared interests.',
  },
  {
    key: 'cart_abandoned',
    icon: ShoppingCart,
    name: 'Cart abandoned',
    desc: 'Remind shoppers of what they left behind, paired with complementary items they\'re likely to add.',
  },
  {
    key: 'cross_sell',
    icon: Layers,
    name: 'Cross-sell',
    desc: 'Suggest accessories and add-ons after a purchase, based on what similar customers bought.',
  },
  {
    key: 'win_back',
    icon: Undo2,
    name: 'Win-back',
    desc: 'Re-engage lapsed customers with products tailored to their previous browsing and order history.',
  },
  {
    key: 'newsletter',
    icon: Mail,
    name: 'Newsletter',
    desc: 'Personalize every newsletter so each contact sees the products most relevant to them.',
  },
  {
    key: 'anniversary',
    icon: Gift,
    name: 'Anniversary',
    desc: 'Celebrate milestones (first order, birthday, customer-since date) with a curated gift selection.',
  },
];

const ContextCard = ({ ctx }) => {
  const Icon = ctx.icon;
  return (
    <div style={{
      display: 'flex', alignItems: 'flex-start', gap: 12,
      padding: 16, background: t.c.surface,
      border: `1px solid ${t.c.borderDefault}`, borderRadius: t.r.md,
    }}>
      <div style={{
        width: 32, height: 32, flexShrink: 0, borderRadius: t.r.md,
        background: t.c.brandSoftBg,
        display: 'flex', alignItems: 'center', justifyContent: 'center',
      }}>
        <Icon size={16} color={t.c.brand} />
      </div>
      <div style={{ minWidth: 0 }}>
        <div style={{ fontSize: 13.5, fontWeight: 600, color: t.c.textPrimary }}>
          {ctx.name}
        </div>
        <div style={{ fontSize: 12.5, color: t.c.textSecondary, marginTop: 4, lineHeight: 1.6 }}>
          {ctx.desc}
        </div>
      </div>
    </div>
  );
};

// --- 4b variant: marketing pitch --------------------------------------------
const Step4Marketing = ({ onBackToConnect, inSettings = false }) => (
  <div style={{ display: 'flex', flexDirection: 'column', gap: 32 }}>
    {!inSettings && (
      <SectionHeader
        eyebrow="Step 4 of 6"
        title="Personalized product recommendations"
        description="Connect a recommendations engine to add personalized product blocks to your campaigns — no manual curation required."
      />
    )}

    {/* Hero illustration */}
    <div style={{
      borderRadius: t.r.xl, overflow: 'hidden',
      border: `1px solid ${t.c.borderDefault}`,
    }}>
      <RecEmailHeroSVG />
    </div>

    {/* Context cards */}
    <section>
      <h3 style={{
        fontSize: 14, fontWeight: 600, color: t.c.textPrimary, margin: 0, marginBottom: 12,
      }}>
        Six contexts, personalized per contact
      </h3>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
        {REC_CONTEXTS.map((ctx) => (
          <ContextCard key={ctx.key} ctx={ctx} />
        ))}
      </div>
    </section>

    {/* CTA */}
    <Card padding="lg" style={{
      background: t.c.brand, border: 'none',
      color: t.c.textWhite,
    }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 24 }}>
        <div style={{ minWidth: 0 }}>
          <div style={{ fontSize: 16, fontWeight: 600 }}>
            Get started with the recommendations engine
          </div>
          <div style={{ fontSize: 13, marginTop: 4, opacity: 0.85, lineHeight: 1.6, maxWidth: 480 }}>
            Contact Smaily to provision a tenant for your store, then paste the endpoint and access token in Step 1.
          </div>
        </div>
        {/* TODO(claude-code): replace href with the final marketing/sales page URL before release. */}
        <a
          href="https://smaily.com/recommendations/"
          target="_blank"
          rel="noopener noreferrer"
          style={{
            display: 'inline-flex', alignItems: 'center', gap: 8,
            background: t.c.surface, color: t.c.brand,
            padding: '0 18px', height: 40, borderRadius: t.r.md,
            fontSize: 13.5, fontWeight: 500, whiteSpace: 'nowrap',
            textDecoration: 'none',
          }}
        >
          Activate recommendations engine
          <ArrowRight size={14} />
        </a>
      </div>
    </Card>

    {/* Back-to-connect link */}
    <div style={{ textAlign: 'center', fontSize: 13, color: t.c.textSecondary }}>
      Already have an endpoint?{' '}
      <button
        type="button"
        onClick={onBackToConnect}
        className="scp-link"
        style={{
          background: 'transparent', border: 'none', padding: 0,
          color: t.c.brand, fontWeight: 500,
          fontSize: 13, textDecoration: 'underline',
        }}
      >
        Add it in Step 1
      </button>
    </div>
  </div>
);

// --- 4a variant components --------------------------------------------------

// A row inside the sync-toggles card.
const SyncToggleRow = ({ icon: Icon, title, description, checked, onChange }) => (
  <div style={{
    display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 16,
  }}>
    <div style={{ display: 'flex', alignItems: 'flex-start', gap: 12, minWidth: 0 }}>
      <div style={{
        width: 32, height: 32, flexShrink: 0, borderRadius: t.r.md,
        background: t.c.surfaceMuted,
        display: 'flex', alignItems: 'center', justifyContent: 'center',
      }}>
        <Icon size={16} color={t.c.textSecondary} />
      </div>
      <div style={{ minWidth: 0 }}>
        <div style={{ fontSize: 13.5, fontWeight: 500, color: t.c.textPrimary }}>
          {title}
        </div>
        <div style={{ fontSize: 12.5, color: t.c.textSecondary, marginTop: 2, lineHeight: 1.5 }}>
          {description}
        </div>
      </div>
    </div>
    <Toggle checked={checked} onChange={onChange} />
  </div>
);

// Per-data-type row inside the combined backfill progress.
const RecBackfillRow = ({ name, processed, total, icon: Icon }) => {
  const isDone = total > 0 && processed >= total;
  const pct = total > 0 ? (processed / total) * 100 : 0;
  if (total === 0) return null; // type was disabled, skip
  return (
    <div>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', fontSize: 12.5, marginBottom: 6 }}>
        <div style={{ fontWeight: 500, color: t.c.textPrimary, display: 'flex', alignItems: 'center', gap: 8 }}>
          <Icon size={13} color={t.c.textSecondary} />
          {name}
          {isDone && <Check size={12} color={t.c.successFg} strokeWidth={3} />}
        </div>
        <div style={{ fontFamily: t.font.mono, fontSize: 12, color: t.c.textSecondary }}>
          {formatNumber(processed)} / {formatNumber(total)}
        </div>
      </div>
      <ProgressBar value={pct} tone={isDone ? 'success' : 'brand'} />
    </div>
  );
};

// Backfill panel — idle (with Start All button) or running/completed (3 progress bars).
const RecBackfillPanel = ({ bf, recState, onStart, onReset }) => {
  // Compute idle preview totals based on enabled toggles.
  const enabledTotals = {
    orders:    recState.syncOrders    ? MOCK.storeTotals.orders    : 0,
    customers: recState.syncCustomers ? MOCK.storeTotals.customers : 0,
    products:  recState.syncProducts  ? MOCK.storeTotals.products  : 0,
  };
  const totalEnabled = Object.values(enabledTotals).reduce((a, b) => a + b, 0);
  const noneEnabled = totalEnabled === 0;

  if (bf.status === 'idle') {
    return (
      <Card padding="md">
        <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 16 }}>
          <div style={{ minWidth: 0 }}>
            <div style={{ fontSize: 13.5, fontWeight: 500, color: t.c.textPrimary }}>
              Import your existing data
            </div>
            <div style={{ fontSize: 12.5, color: t.c.textSecondary, marginTop: 4, lineHeight: 1.6, maxWidth: 440 }}>
              Orders, customers, and products import together — the engine learns from the joined dataset.
              You can re-run this anytime from Settings.
            </div>
          </div>
          <Button
            variant="primary"
            icon={Upload}
            onClick={onStart}
            disabled={noneEnabled}
          >
            Start backfill
          </Button>
        </div>
        <div style={{
          marginTop: 16, paddingTop: 16, borderTop: `1px solid ${t.c.borderSubtle}`,
          display: 'flex', flexDirection: 'column', gap: 6,
        }}>
          {[
            { key: 'orders',    label: 'Orders',    enabled: recState.syncOrders,    n: MOCK.storeTotals.orders },
            { key: 'customers', label: 'Customers', enabled: recState.syncCustomers, n: MOCK.storeTotals.customers },
            { key: 'products',  label: 'Products',  enabled: recState.syncProducts,  n: MOCK.storeTotals.products },
          ].map((row) => (
            <div key={row.key} style={{
              display: 'flex', alignItems: 'center', justifyContent: 'space-between',
              fontSize: 12, opacity: row.enabled ? 1 : 0.45,
            }}>
              <span style={{ color: t.c.textPrimary }}>{row.label}</span>
              <span style={{ fontFamily: t.font.mono, color: t.c.textSecondary }}>
                {row.enabled ? formatNumber(row.n) : 'skipped (sync off)'}
              </span>
            </div>
          ))}
        </div>
      </Card>
    );
  }

  const isCompleted = bf.status === 'completed';
  const totalProcessed = Object.values(bf.processed).reduce((a, b) => a + b, 0);
  const totalAll = Object.values(bf.total).reduce((a, b) => a + b, 0);

  return (
    <Card padding="md">
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 16, marginBottom: 16 }}>
        <div style={{ minWidth: 0 }}>
          <div style={{
            fontSize: 13.5, fontWeight: 500, color: t.c.textPrimary,
            display: 'flex', alignItems: 'center', gap: 8,
          }}>
            {!isCompleted && <Loader2 size={14} className="scp-spin" color={t.c.brand} />}
            {isCompleted && <CheckCircle2 size={16} color={t.c.successFg} />}
            {isCompleted ? 'Backfill completed' : 'Importing data to the recommendations engine'}
          </div>
          <div style={{ fontSize: 12.5, color: t.c.textSecondary, marginTop: 4 }}>
            {isCompleted
              ? `${formatNumber(totalAll)} records imported just now`
              : `${formatNumber(totalProcessed)} of ${formatNumber(totalAll)} records imported`}
          </div>
        </div>
        {isCompleted && (
          <Button variant="ghost" size="sm" icon={RefreshCw} onClick={onReset}>Re-run</Button>
        )}
      </div>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
        <RecBackfillRow icon={ShoppingCart} name="Orders"    processed={bf.processed.orders}    total={bf.total.orders} />
        <RecBackfillRow icon={Users}        name="Customers" processed={bf.processed.customers} total={bf.total.customers} />
        <RecBackfillRow icon={Package}      name="Products"  processed={bf.processed.products}  total={bf.total.products} />
      </div>
    </Card>
  );
};

// --- Browsing privacy card --------------------------------------------------
const BrowsingPrivacyCard = ({ enabled, onChange }) => (
  <Card padding="md">
    <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 16, marginBottom: 12 }}>
      <div style={{ display: 'flex', alignItems: 'flex-start', gap: 12, minWidth: 0 }}>
        <div style={{
          width: 32, height: 32, flexShrink: 0, borderRadius: t.r.md,
          background: t.c.brandSoftBg,
          display: 'flex', alignItems: 'center', justifyContent: 'center',
        }}>
          <Eye size={16} color={t.c.brand} />
        </div>
        <div style={{ minWidth: 0 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
            <div style={{ fontSize: 13.5, fontWeight: 500, color: t.c.textPrimary }}>
              Track browsing behavior
            </div>
            <Pill tone="warning" icon={Shield}>Requires consent</Pill>
          </div>
          <div style={{ fontSize: 12.5, color: t.c.textSecondary, marginTop: 4, lineHeight: 1.6 }}>
            Tracking which products visitors look at lets the engine suggest items they considered but didn't buy.
            Significantly improves recommendation quality for return visitors.
          </div>
        </div>
      </div>
      <Toggle checked={enabled} onChange={onChange} />
    </div>

    <div style={{
      marginTop: 4, padding: 12,
      background: t.c.surfaceSoft, borderRadius: t.r.md,
      border: `1px solid ${t.c.borderSubtle}`,
      display: 'flex', flexDirection: 'column', gap: 10,
    }}>
      <div style={{ display: 'flex', alignItems: 'flex-start', gap: 10 }}>
        <Lock size={13} color={t.c.textTertiary} style={{ marginTop: 2, flexShrink: 0 }} />
        <div style={{ fontSize: 12, color: t.c.textSecondary, lineHeight: 1.6 }}>
          The plugin respects your cookie consent setup. Supported: WP Consent API, Cookiebot, Complianz, CookieYes —
          detected automatically. Browsing data is only collected from visitors who have opted in to marketing cookies.
        </div>
      </div>
      <div style={{ display: 'flex', alignItems: 'flex-start', gap: 10 }}>
        <Info size={13} color={t.c.textTertiary} style={{ marginTop: 2, flexShrink: 0 }} />
        <div style={{ fontSize: 12, color: t.c.textSecondary, lineHeight: 1.6 }}>
          No historical backfill — tracking starts when you enable it. Events are sent in real time with their original
          timestamps, and linked to a known customer once they log in, check out, or click an email link.
        </div>
      </div>
    </div>
  </Card>
);

// --- 4a variant: active configuration ---------------------------------------
const Step4Active = ({ state, dispatch, env, inSettings = false }) => {
  const r = state.recommendations;
  const bf = r.backfill;

  useEffect(() => {
    if (bf.status !== 'running') return;
    const id = setInterval(() => dispatch({ type: 'REC_BACKFILL_TICK' }), 80);
    return () => clearInterval(id);
  }, [bf.status, dispatch]);

  const set = (key, value) => dispatch({ type: 'REC_TOGGLE', key, value });

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 32 }}>
      {!inSettings && (
        <SectionHeader
          eyebrow="Step 4 of 6"
          title="Recommendations engine"
          description="Choose what to sync. The more the engine knows about your store, the better its product suggestions become."
          action={
            <Pill tone="success" icon={CheckCircle2}>
              Connected to {state.recEngine.tenantInfo?.tenant_name || 'tenant'}
            </Pill>
          }
        />
      )}
      {inSettings && (
        <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: -16 }}>
          <Pill tone="success" icon={CheckCircle2}>
            Connected to {state.recEngine.tenantInfo?.tenant_name || 'tenant'}
          </Pill>
        </div>
      )}

      {/* === SYNC TOGGLES === */}
      <section>
        <div style={{ marginBottom: 12 }}>
          <h3 style={{ fontSize: 14, fontWeight: 600, color: t.c.textPrimary, margin: 0 }}>
            What to sync
          </h3>
          <p style={{ fontSize: 13, color: t.c.textSecondary, marginTop: 2, lineHeight: 1.6, maxWidth: 540 }}>
            Each sync feeds a different signal to the engine. They work best together.
          </p>
        </div>
        <Card padding="md">
          <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
            <SyncToggleRow
              icon={ShoppingCart}
              title="Orders"
              description="Send every order in real time, including line items, totals, and status changes."
              checked={r.syncOrders}
              onChange={(v) => set('syncOrders', v)}
            />
            <div style={{ borderTop: `1px solid ${t.c.borderSubtle}` }} />
            <SyncToggleRow
              icon={Users}
              title="Customers"
              description="Sync customer profiles including registration date, group, and contact attributes."
              checked={r.syncCustomers}
              onChange={(v) => set('syncCustomers', v)}
            />
            <div style={{ borderTop: `1px solid ${t.c.borderSubtle}` }} />
            <SyncToggleRow
              icon={Package}
              title="Products"
              description="Send catalog updates: new products, edits, deletes, stock and price changes."
              checked={r.syncProducts}
              onChange={(v) => set('syncProducts', v)}
            />
            <div style={{ borderTop: `1px solid ${t.c.borderSubtle}` }} />
            <SyncToggleRow
              icon={Heart}
              title="Cart events"
              description="Track add-to-cart, remove-from-cart, and cart-viewed events in real time. No historical backfill — events stream as they happen."
              checked={r.trackCartEvents}
              onChange={(v) => set('trackCartEvents', v)}
            />
          </div>
        </Card>
      </section>

      {/* === COMBINED BACKFILL === */}
      <section>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 12 }}>
          <Upload size={16} color={t.c.brand} />
          <h3 style={{ fontSize: 14, fontWeight: 600, color: t.c.textPrimary, margin: 0 }}>
            Initial backfill
          </h3>
          {bf.status === 'completed' && <Pill tone="success" icon={CheckCircle2}>Completed</Pill>}
          {bf.status === 'running' && <Pill tone="info">Running</Pill>}
        </div>
        <RecBackfillPanel
          bf={bf}
          recState={r}
          onStart={() => dispatch({ type: 'REC_BACKFILL_START' })}
          onReset={() => dispatch({ type: 'REC_BACKFILL_RESET' })}
        />
      </section>

      {/* === BROWSING (separate, privacy-sensitive) === */}
      <section>
        <div style={{ marginBottom: 12 }}>
          <h3 style={{ fontSize: 14, fontWeight: 600, color: t.c.textPrimary, margin: 0 }}>
            Optional: browsing behavior
          </h3>
          <p style={{ fontSize: 13, color: t.c.textSecondary, marginTop: 2, lineHeight: 1.6, maxWidth: 540 }}>
            An extra signal — what visitors look at but don't buy. Powerful, but opt-in by default for privacy compliance.
          </p>
        </div>
        <BrowsingPrivacyCard
          enabled={r.trackBrowsing}
          onChange={(v) => set('trackBrowsing', v)}
        />
      </section>
    </div>
  );
};

// --- Step 4 dispatcher ------------------------------------------------------
const Step4Recommendations = ({ state, dispatch, env, onBackToStep1, inSettings = false }) => {
  const isConnected = state.recEngine.testStatus === 'success';
  return isConnected
    ? <Step4Active state={state} dispatch={dispatch} env={env} inSettings={inSettings} />
    : <Step4Marketing onBackToConnect={onBackToStep1} inSettings={inSettings} />;
};

/* ============================================================================
 * 11. STEP 5 — INTEGRATIONS
 * Three informational cards showing how the plugin integrates with other
 * WordPress plugins. Each card adapts to whether the integration is installed.
 * ========================================================================== */

// IntegrationCard — a single integration entry. Action link adapts to the
// installed state: "Open in WordPress" when installed, "Install" when missing.
const IntegrationCard = ({
  icon: Icon, name, description, installed,
  openLabel, openHref, installLabel, installHref,
}) => (
  <Card padding="md">
    <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 16 }}>
      <div style={{ display: 'flex', alignItems: 'flex-start', gap: 14, minWidth: 0 }}>
        <div style={{
          width: 40, height: 40, flexShrink: 0, borderRadius: t.r.md,
          background: t.c.brandSoftBg,
          display: 'flex', alignItems: 'center', justifyContent: 'center',
        }}>
          <Icon size={18} color={t.c.brand} />
        </div>
        <div style={{ minWidth: 0 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
            <div style={{ fontSize: 14, fontWeight: 600, color: t.c.textPrimary }}>
              {name}
            </div>
            {installed === true && <Pill tone="success" icon={CheckCircle2}>Installed</Pill>}
            {installed === false && <Pill tone="neutral">Not installed</Pill>}
          </div>
          <div style={{ fontSize: 12.5, color: t.c.textSecondary, marginTop: 4, lineHeight: 1.6, maxWidth: 480 }}>
            {description}
          </div>
        </div>
      </div>
      <div style={{ flexShrink: 0 }}>
        {/* TODO(claude-code): in production, openHref should use admin_url() and stay in-window;
            installHref points to wp.org plugin page. */}
        {installed !== false ? (
          <a
            href={openHref}
            style={{
              display: 'inline-flex', alignItems: 'center', gap: 6,
              padding: '0 14px', height: 36, borderRadius: t.r.md,
              background: t.c.surface, color: t.c.textPrimary,
              border: `1px solid ${t.c.borderStrong}`,
              fontSize: 13, fontWeight: 500, whiteSpace: 'nowrap',
              textDecoration: 'none',
            }}
          >
            {openLabel}
            <ExternalLink size={13} />
          </a>
        ) : (
          <a
            href={installHref}
            target="_blank"
            rel="noopener noreferrer"
            style={{
              display: 'inline-flex', alignItems: 'center', gap: 6,
              padding: '0 14px', height: 36, borderRadius: t.r.md,
              background: t.c.brand, color: t.c.textWhite,
              border: 'none',
              fontSize: 13, fontWeight: 500, whiteSpace: 'nowrap',
              textDecoration: 'none',
            }}
          >
            <Download size={13} />
            {installLabel}
          </a>
        )}
      </div>
    </div>
  </Card>
);

const Step5Integrations = ({ env, inSettings = false }) => (
  <div style={{ display: 'flex', flexDirection: 'column', gap: 32 }}>
    {!inSettings && (
      <SectionHeader
        eyebrow="Step 5 of 6"
        title="Integrations"
        description="The plugin integrates with the tools you already use. No configuration needed here — each integration is set up in its own place."
      />
    )}

    <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
      <IntegrationCard
        icon={LayoutPanelLeft}
        name="Elementor"
        description="The Smaily subscription form is available as a widget inside the Elementor editor. Drop it onto any page or landing template you build."
        installed={env.elementorPresent}
        openLabel="Open Elementor"
        openHref="/wp-admin/admin.php?page=elementor"
        installLabel="Install Elementor"
        installHref="https://wordpress.org/plugins/elementor/"
      />
      <IntegrationCard
        icon={ClipboardList}
        name="Contact Form 7"
        description="Each CF7 form has its own Smaily tab, where you can map form fields to contact attributes and select a subscriber list."
        installed={env.cf7Present}
        openLabel="Open Forms"
        openHref="/wp-admin/edit.php?post_type=wpcf7_contact_form"
        installLabel="Install Contact Form 7"
        installHref="https://wordpress.org/plugins/contact-form-7/"
      />
      <IntegrationCard
        icon={FilePlus}
        name="Smaily Landing Pages"
        description="Embed a Smaily-hosted landing page on any post or page using the dedicated Gutenberg block. Useful for campaign-specific signup forms."
        installed
        openLabel="Add new page"
        openHref="/wp-admin/post-new.php?post_type=page"
      />
    </div>

    <Banner tone="info" title="More on the way">
      Future versions will add deeper integrations — direct CF7 events to the recommendations engine,
      Elementor popup subscription forms, and more. Suggestions welcome at the plugin's GitHub repository.
    </Banner>
  </div>
);

/* ============================================================================
 * 12. STEP 6 — DONE
 * Summary screen reflecting what the user actually configured. Pulls live
 * values from wizard state — toggles a feature off in earlier steps and the
 * summary updates accordingly.
 * ========================================================================== */

// SummaryLine — one row inside a SummaryCard. Renders ✓ for active features,
// ○ for disabled-but-known features (so the user sees what's possible to
// re-enable later from Settings).
const SummaryLine = ({ active, label, detail }) => (
  <div style={{ display: 'flex', alignItems: 'flex-start', gap: 10, padding: '8px 0' }}>
    <div style={{
      marginTop: 2, width: 16, height: 16, borderRadius: '50%', flexShrink: 0,
      background: active ? t.c.successFg : t.c.surfaceMuted,
      border: active ? 'none' : `1px solid ${t.c.borderStrong}`,
      display: 'flex', alignItems: 'center', justifyContent: 'center',
    }}>
      {active && <Check size={11} color={t.c.textWhite} strokeWidth={3} />}
    </div>
    <div style={{ flex: 1, minWidth: 0 }}>
      <div style={{
        fontSize: 13.5, color: active ? t.c.textPrimary : t.c.textSecondary,
      }}>
        {label}
      </div>
      {detail && (
        <div style={{
          fontSize: 12, color: t.c.textTertiary, marginTop: 2,
          lineHeight: 1.5,
        }}>
          {detail}
        </div>
      )}
    </div>
  </div>
);

const SummaryCard = ({ title, icon: Icon, children }) => (
  <Card padding="md">
    <div style={{
      display: 'flex', alignItems: 'center', gap: 10, marginBottom: 8,
      paddingBottom: 12, borderBottom: `1px solid ${t.c.borderSubtle}`,
    }}>
      <Icon size={16} color={t.c.brand} />
      <div style={{ fontSize: 13, fontWeight: 600, color: t.c.textPrimary }}>
        {title}
      </div>
    </div>
    <div>{children}</div>
  </Card>
);

const Step6Done = ({ state, env, onViewSettings, onViewEventLog }) => {
  const isMultilingual = env.detectedLanguages.length > 1;
  const mode = state.multilingualMode;

  // === Connection summary ===
  const connectedAccounts = (() => {
    if (mode === 'A' && isMultilingual) {
      return env.detectedLanguages
        .filter((l) => state.smailyAccounts[l]?.testStatus === 'success')
        .map((l) => `${MOCK.languageNames[l]} (${state.smailyAccounts[l].subdomain || '—'}.sendsmaily.net)`);
    }
    const primary = state.smailyAccounts.primary;
    if (primary?.testStatus === 'success') {
      return [`${primary.subdomain || 'shop-example'}.sendsmaily.net`];
    }
    return [];
  })();
  const recConnected = state.recEngine.testStatus === 'success';

  // === Subscribers summary ===
  const subs = state.subscribers;
  const fieldCount = countTrue(subs.fields);
  const optIns = [
    subs.showOnWPRegistration && 'WP registration',
    subs.showOnWCCheckout && 'WC checkout',
  ].filter(Boolean);
  const bfDone = subs.backfill.status === 'completed';

  // === Automations summary ===
  const autos = state.automations;
  const autoCount = ['welcome', 'firstOrder', 'abandonedCart'].filter((k) => autos[k].enabled).length;

  // === Recommendations summary ===
  const r = state.recommendations;
  const recSyncList = recConnected ? [
    r.syncOrders     && 'orders',
    r.syncCustomers  && 'customers',
    r.syncProducts   && 'products',
    r.trackCartEvents && 'cart events',
    r.trackBrowsing  && 'browsing',
  ].filter(Boolean) : [];

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 32 }}>
      {/* === HERO === */}
      <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'flex-start', gap: 12 }}>
        <div style={{
          width: 48, height: 48, borderRadius: '50%',
          background: t.c.successBg,
          display: 'flex', alignItems: 'center', justifyContent: 'center',
        }}>
          <Check size={22} color={t.c.successFg} strokeWidth={3} />
        </div>
        <div>
          <div style={{
            fontSize: 11.5, fontWeight: 500, textTransform: 'uppercase',
            letterSpacing: '0.08em', color: t.c.textTertiary, marginBottom: 6,
          }}>
            Setup complete
          </div>
          <h2 style={{ fontSize: 24, fontWeight: 600, color: t.c.textPrimary, margin: 0, lineHeight: 1.2 }}>
            You're all set
          </h2>
          <p style={{
            fontSize: 14, color: t.c.textSecondary, marginTop: 8,
            lineHeight: 1.6, maxWidth: 560,
          }}>
            Smaily Connect Plus is now configured. Below is a snapshot of what's active.
            You can change any of these settings later from the Settings tabs.
          </p>
        </div>
      </div>

      {/* === SUMMARY CARDS === */}
      <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
        {/* Connection */}
        <SummaryCard title="Connection" icon={Link2}>
          {connectedAccounts.length > 0 ? (
            connectedAccounts.map((acct, i) => (
              <SummaryLine
                key={i}
                active
                label={connectedAccounts.length === 1 ? 'Smaily account connected' : `Smaily account: ${acct.split(' (')[0]}`}
                detail={connectedAccounts.length === 1 ? acct : acct.split(' (')[1]?.replace(')', '')}
              />
            ))
          ) : (
            <SummaryLine active={false} label="No Smaily account connected" />
          )}
          <SummaryLine
            active={recConnected}
            label="Recommendations engine"
            detail={recConnected
              ? `Connected to ${state.recEngine.tenantInfo?.tenant_name}`
              : 'Not connected — set up later from Settings → Connection'}
          />
        </SummaryCard>

        {/* Subscribers */}
        <SummaryCard title="Subscribers" icon={Users}>
          <SummaryLine
            active={subs.syncEnabled}
            label="Contact sync"
            detail={subs.syncEnabled
              ? `${fieldCount} of ${SUBSCRIBER_FIELDS.length} fields synced`
              : 'Disabled — turn on in Settings → Subscribers'}
          />
          <SummaryLine
            active={optIns.length > 0}
            label="Subscription opt-in"
            detail={optIns.length > 0
              ? `Shown on: ${optIns.join(', ')}`
              : 'No opt-in forms enabled'}
          />
          <SummaryLine
            active={bfDone}
            label="Initial backfill"
            detail={bfDone
              ? 'Existing users imported'
              : 'Not run — start anytime from Settings → Subscribers'}
          />
        </SummaryCard>

        {/* Automations */}
        <SummaryCard title="Automations" icon={Mail}>
          <SummaryLine
            active={autos.welcome.enabled}
            label="Welcome email"
            detail={autos.welcome.enabled
              ? 'New subscribers receive a welcome message'
              : 'Disabled'}
          />
          <SummaryLine
            active={autos.firstOrder.enabled}
            label="First order email"
            detail={autos.firstOrder.enabled
              ? 'First-time buyers receive a follow-up'
              : 'Disabled'}
          />
          <SummaryLine
            active={autos.abandonedCart.enabled}
            label="Abandoned cart"
            detail={autos.abandonedCart.enabled
              ? `Reminders sent after ${autos.abandonedCart.cutoffMinutes} minutes of inactivity`
              : 'Disabled'}
          />
        </SummaryCard>

        {/* Recommendations — only if rec-engine connected */}
        {recConnected && (
          <SummaryCard title="Recommendations" icon={Sparkles}>
            <SummaryLine
              active={recSyncList.length > 0}
              label="Data streaming to engine"
              detail={recSyncList.length > 0
                ? `Tracking: ${recSyncList.join(', ')}`
                : 'All syncs disabled'}
            />
            <SummaryLine
              active={r.trackBrowsing}
              label="Browsing behavior"
              detail={r.trackBrowsing
                ? 'Privacy-compliant tracking active (subject to visitor consent)'
                : 'Disabled — enable in Settings → Recommendations to improve suggestion quality'}
            />
            <SummaryLine
              active={state.recommendations.backfill.status === 'completed'}
              label="Initial data import"
              detail={state.recommendations.backfill.status === 'completed'
                ? 'Orders, customers, and products imported'
                : 'Not run yet'}
            />
          </SummaryCard>
        )}
      </div>

      {/* === MONITORING NOTE === */}
      <Banner
        tone="info"
        title="Monitor sync activity in the Event Log"
        action={<Button size="sm" variant="secondary" icon={Activity} onClick={onViewEventLog}>Open Event Log</Button>}
      >
        Errors, retries, and API responses are recorded under{' '}
        <strong>Smaily → Event Log</strong> in the sidebar. The log shows the last 7 days; anything
        older is archived. Set up email alerts for critical failures from Settings → Connection.
      </Banner>

      {/* === ACTIONS === */}
      <div style={{
        display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap',
        paddingTop: 8, borderTop: `1px solid ${t.c.borderSubtle}`,
      }}>
        <Button variant="primary" icon={Cog} onClick={onViewSettings}>
          View Settings
        </Button>
        {/* TODO(claude-code): hrefs should be built from the actual Smaily/rec-engine URLs at runtime. */}
        <a
          href={connectedAccounts[0] ? `https://${(state.smailyAccounts.primary?.subdomain || env.detectedLanguages.includes('et') ? state.smailyAccounts.et?.subdomain : null) || 'app'}.sendsmaily.net` : 'https://app.sendsmaily.net'}
          target="_blank"
          rel="noopener noreferrer"
          style={{
            display: 'inline-flex', alignItems: 'center', gap: 6,
            padding: '0 16px', height: 36, borderRadius: t.r.md,
            background: t.c.surface, color: t.c.textPrimary,
            border: `1px solid ${t.c.borderStrong}`,
            fontSize: 13.5, fontWeight: 500, textDecoration: 'none',
          }}
        >
          Open Smaily dashboard
          <ExternalLink size={13} />
        </a>
        {recConnected && (
          <a
            href="https://recommendations.smaily.com"
            target="_blank"
            rel="noopener noreferrer"
            style={{
              display: 'inline-flex', alignItems: 'center', gap: 6,
              padding: '0 16px', height: 36, borderRadius: t.r.md,
              background: t.c.surface, color: t.c.textPrimary,
              border: `1px solid ${t.c.borderStrong}`,
              fontSize: 13.5, fontWeight: 500, textDecoration: 'none',
            }}
          >
            Open Recommendations dashboard
            <ExternalLink size={13} />
          </a>
        )}
      </div>
    </div>
  );
};

/* ============================================================================
 * 13. PLACEHOLDER (unused now that all steps are built)
 * Kept for graceful fallback if WIZARD_STEPS is extended in the future.
 * ========================================================================== */

const PlaceholderStep = ({ step }) => {
  const Icon = STEP_ICONS[step.key];
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 32 }}>
      <SectionHeader
        eyebrow={`Step ${step.num} of 6`}
        title={`${step.label} — coming next`}
        description={step.description}
      />
      <Card>
        <div style={{ textAlign: 'center', padding: '48px 0' }}>
          <div style={{
            display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
            width: 48, height: 48, borderRadius: '50%', background: t.c.brandSoftBg,
            marginBottom: 16,
          }}>
            <Icon size={20} color={t.c.brand} />
          </div>
          <div style={{ fontSize: 14, fontWeight: 500, color: t.c.textPrimary, marginBottom: 4 }}>
            This step will be built in the next iteration
          </div>
          <div style={{ fontSize: 13, color: t.c.textSecondary, maxWidth: 420, margin: '0 auto', lineHeight: 1.6 }}>
            The wizard navigation, state plumbing, and design system are already wired up — only
            the per-step content remains.
          </div>
        </div>
      </Card>
    </div>
  );
};

/* ============================================================================
 * 10. EVENT LOG VIEW
 * Placeholder view showing what the Event Log will look like in production.
 * Sidebar item: Smaily → Event Log. Pulls from a PHP REST endpoint that
 * unions smly_plus_event_queue + smly_rec_event_queue tables, filtered by
 * the last 7 days. This prototype renders mock data with the full UI shape.
 * ========================================================================== */

// Stat card — used in the stats grid at the top.
const EventStat = ({ label, value, tone = 'neutral' }) => {
  const tones = {
    neutral: { color: t.c.textPrimary },
    success: { color: t.c.successFg },
    warning: { color: t.c.warningFg },
    danger:  { color: t.c.dangerFg },
    info:    { color: t.c.brand },
  };
  return (
    <Card padding="md" style={{ minWidth: 0 }}>
      <div style={{ fontSize: 11.5, fontWeight: 500, textTransform: 'uppercase', letterSpacing: '0.06em', color: t.c.textTertiary, marginBottom: 8 }}>
        {label}
      </div>
      <div style={{ fontSize: 24, fontWeight: 600, color: tones[tone].color, fontFamily: t.font.mono, lineHeight: 1 }}>
        {value}
      </div>
    </Card>
  );
};

// Status-specific badge for table rows.
const EventStatusBadge = ({ status, attempts, maxAttempts }) => {
  const variants = {
    success:  { tone: 'success', label: 'Success' },
    failed:   { tone: 'danger',  label: 'Failed' },
    pending:  { tone: 'neutral', label: 'Pending' },
    retrying: { tone: 'warning', label: `Retry ${attempts}/${maxAttempts}` },
  };
  const v = variants[status] || { tone: 'neutral', label: status };
  return <Pill tone={v.tone}>{v.label}</Pill>;
};

// Type-specific color hint for event_type text (visual category cue).
const eventTypeColor = (type) => {
  if (type.startsWith('contact.') || type.startsWith('automation.')) return t.c.brand;
  if (type.startsWith('browse.') || type.startsWith('identity.')) return t.c.warningFg;
  if (type.startsWith('order.') || type.startsWith('customer.')) return t.c.successFg;
  if (type.startsWith('catalog.') || type.startsWith('product.')) return t.c.dangerFg;
  return t.c.textSecondary;
};

// Source pill — small textual indicator of which API the event went to.
const SourcePill = ({ source }) => {
  const label = source === 'smaily' ? 'Smaily' : 'Rec engine';
  return (
    <span style={{
      display: 'inline-block', padding: '2px 7px', borderRadius: 3,
      fontSize: 11, fontWeight: 500, fontFamily: t.font.mono,
      background: source === 'smaily' ? '#F0F0EC' : '#ECF0FF',
      color: source === 'smaily' ? t.c.textSecondary : t.c.brandSoftText,
      whiteSpace: 'nowrap',
    }}>
      {label}
    </span>
  );
};

const EventRow = ({ event, expanded, onToggle }) => {
  const cellBase = { padding: '10px 12px', fontSize: 13, color: t.c.textPrimary, verticalAlign: 'middle' };
  return (
    <>
      <tr
        onClick={onToggle}
        style={{
          borderTop: `1px solid ${t.c.borderSubtle}`,
          background: expanded ? t.c.surfaceSoft : t.c.surface,
          cursor: 'pointer',
        }}
      >
        <td style={{ ...cellBase, color: t.c.textSecondary, whiteSpace: 'nowrap', fontSize: 12.5 }}>
          {formatRelativeTime(event.ts)}
        </td>
        <td style={cellBase}>
          <span style={{ fontFamily: t.font.mono, fontSize: 12.5, color: eventTypeColor(event.event_type) }}>
            {event.event_type}
          </span>
        </td>
        <td style={{ ...cellBase, fontFamily: t.font.mono, fontSize: 12, color: t.c.textSecondary }}>
          {event.entity_id}
        </td>
        <td style={cellBase}>
          <SourcePill source={event.source} />
        </td>
        <td style={cellBase}>
          <EventStatusBadge status={event.status} attempts={event.attempts} maxAttempts={event.max_attempts} />
        </td>
        <td style={{
          ...cellBase, color: event.last_error ? t.c.dangerFg : t.c.textTertiary,
          fontSize: 12, maxWidth: 320,
          overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap',
        }}>
          {event.last_error || '—'}
        </td>
        <td style={{ ...cellBase, width: 30, textAlign: 'right' }}>
          <ChevronDown
            size={14}
            color={t.c.textTertiary}
            style={{ transform: expanded ? 'rotate(180deg)' : 'none', transition: 'transform 160ms ease' }}
          />
        </td>
      </tr>
      {expanded && (
        <tr style={{ background: t.c.surfaceSoft, borderTop: `1px solid ${t.c.borderSubtle}` }}>
          <td colSpan={7} style={{ padding: '16px 20px' }}>
            <div style={{ display: 'flex', gap: 24, flexWrap: 'wrap' }}>
              <div style={{ flex: 1, minWidth: 280 }}>
                <div style={{ fontSize: 11.5, fontWeight: 500, textTransform: 'uppercase', letterSpacing: '0.06em', color: t.c.textTertiary, marginBottom: 6 }}>
                  Event details
                </div>
                <div style={{ fontSize: 12.5, color: t.c.textSecondary, lineHeight: 1.8 }}>
                  <div><strong style={{ color: t.c.textPrimary, fontWeight: 500 }}>ID:</strong> <span style={{ fontFamily: t.font.mono }}>{event.id}</span></div>
                  <div><strong style={{ color: t.c.textPrimary, fontWeight: 500 }}>Timestamp:</strong> <span style={{ fontFamily: t.font.mono }}>{formatExactTime(event.ts)}</span></div>
                  <div><strong style={{ color: t.c.textPrimary, fontWeight: 500 }}>Attempts:</strong> <span style={{ fontFamily: t.font.mono }}>{event.attempts} / {event.max_attempts}</span></div>
                </div>
                {event.last_error && (
                  <div style={{ marginTop: 12 }}>
                    <div style={{ fontSize: 11.5, fontWeight: 500, textTransform: 'uppercase', letterSpacing: '0.06em', color: t.c.textTertiary, marginBottom: 6 }}>
                      Last error
                    </div>
                    <div style={{
                      fontSize: 12, fontFamily: t.font.mono, color: t.c.dangerFg,
                      background: t.c.dangerBg, padding: '10px 12px', borderRadius: t.r.sm,
                      lineHeight: 1.5,
                    }}>
                      {event.last_error}
                    </div>
                  </div>
                )}
              </div>
              <div style={{ flex: 1, minWidth: 280 }}>
                <div style={{ fontSize: 11.5, fontWeight: 500, textTransform: 'uppercase', letterSpacing: '0.06em', color: t.c.textTertiary, marginBottom: 6 }}>
                  Payload
                </div>
                <pre style={{
                  fontSize: 11.5, fontFamily: t.font.mono, color: t.c.textSecondary,
                  background: t.c.surface, padding: '10px 12px',
                  border: `1px solid ${t.c.borderSubtle}`, borderRadius: t.r.sm,
                  margin: 0, overflowX: 'auto', lineHeight: 1.5,
                  maxHeight: 200, overflowY: 'auto',
                }}>
{JSON.stringify(event.payload, null, 2)}
                </pre>
              </div>
            </div>
            <div style={{ marginTop: 16, paddingTop: 12, borderTop: `1px solid ${t.c.borderSubtle}`, display: 'flex', gap: 8 }}>
              {(event.status === 'failed' || event.status === 'retrying') && (
                <Button variant="secondary" size="sm" icon={RotateCw}>Retry now</Button>
              )}
              <Button variant="ghost" size="sm" icon={FileSearch}>View full payload</Button>
              {event.status === 'failed' && (
                <Button variant="ghost" size="sm" icon={X}>Mark as resolved</Button>
              )}
            </div>
          </td>
        </tr>
      )}
    </>
  );
};

const EventLogView = ({ onBackToWizard, onViewSettings }) => {
  const [expandedId, setExpandedId] = useState(null);
  const [filters, setFilters] = useState({
    type: 'all', status: 'all', source: 'all', search: '',
  });

  // === Filtering ===
  const filtered = useMemo(() => {
    return MOCK_EVENT_LOG.filter((e) => {
      if (filters.type !== 'all' && !e.event_type.startsWith(filters.type)) return false;
      if (filters.status !== 'all' && e.status !== filters.status) return false;
      if (filters.source !== 'all' && e.source !== filters.source) return false;
      if (filters.search) {
        const q = filters.search.toLowerCase();
        const haystack = [e.event_type, e.entity_id, e.last_error || ''].join(' ').toLowerCase();
        if (!haystack.includes(q)) return false;
      }
      return true;
    });
  }, [filters]);

  // === Stats ===
  const stats = useMemo(() => {
    const all = MOCK_EVENT_LOG;
    return {
      total:    all.length,
      success:  all.filter((e) => e.status === 'success').length,
      failed:   all.filter((e) => e.status === 'failed').length,
      pending:  all.filter((e) => e.status === 'pending' || e.status === 'retrying').length,
    };
  }, []);

  return (
    <div style={{
      flex: 1, display: 'flex', flexDirection: 'column',
      background: t.c.pageBg, minWidth: 0, minHeight: 0,
    }}>
      {/* Header */}
      <div style={{
        padding: '36px 40px 24px', background: t.c.surface,
        borderBottom: `1px solid ${t.c.borderDefault}`, flexShrink: 0,
      }}>
        <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 24 }}>
          <div>
            <div style={{
              fontSize: 11.5, fontWeight: 500, textTransform: 'uppercase',
              letterSpacing: '0.08em', color: t.c.textTertiary, marginBottom: 6,
              display: 'flex', alignItems: 'center', gap: 8,
            }}>
              Smaily Connect Plus
              <span style={{ color: t.c.borderStrong }}>·</span>
              Monitoring
            </div>
            <h1 style={{ fontSize: 22, fontWeight: 600, color: t.c.textPrimary, margin: 0, lineHeight: 1.2, display: 'flex', alignItems: 'center', gap: 10 }}>
              Event Log
              <Pill tone="neutral">Last 7 days</Pill>
            </h1>
            <p style={{ fontSize: 13, color: t.c.textSecondary, marginTop: 6, lineHeight: 1.6, maxWidth: 640 }}>
              Every API call, queue job, and retry attempt is logged here. Older events are archived after 7 days.
            </p>
          </div>
          <div style={{ display: 'flex', gap: 8, flexShrink: 0 }}>
            <Button variant="ghost" size="md" icon={RefreshCw}>Refresh</Button>
            <Button variant="secondary" size="md" icon={Download}>Export failed</Button>
          </div>
        </div>
      </div>

      {/* Scroll container */}
      <div
        className="scp-scroll"
        style={{ flex: 1, padding: '24px 40px 40px', overflowY: 'auto', minHeight: 0 }}
      >
        {/* Placeholder notice */}
        <Banner tone="info" title="Prototype preview" >
          This view is a placeholder. Production reads from the PHP REST endpoint{' '}
          <code style={{ fontFamily: t.font.mono, fontSize: 12 }}>/wp-json/smaily-plus/v1/events</code>,
          which unions <code style={{ fontFamily: t.font.mono, fontSize: 12 }}>smly_plus_event_queue</code> and{' '}
          <code style={{ fontFamily: t.font.mono, fontSize: 12 }}>smly_rec_event_queue</code> tables.
          Filter, sort, paginate, and retry actions hit the same endpoint with query parameters.
        </Banner>

        {/* Stats grid */}
        <div style={{
          display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 12,
          marginTop: 20,
        }}>
          <EventStat label="Total events" value={formatNumber(stats.total)} tone="neutral" />
          <EventStat label="Success" value={formatNumber(stats.success)} tone="success" />
          <EventStat label="Failed" value={formatNumber(stats.failed)} tone="danger" />
          <EventStat label="Pending / retrying" value={formatNumber(stats.pending)} tone="warning" />
        </div>

        {/* Filter bar */}
        <div style={{
          marginTop: 20, padding: 16, background: t.c.surface,
          border: `1px solid ${t.c.borderDefault}`, borderRadius: t.r.lg,
          display: 'grid', gridTemplateColumns: '1fr 180px 180px 160px',
          gap: 12, alignItems: 'flex-end',
        }}>
          <div>
            <div style={{ fontSize: 12.5, fontWeight: 500, color: t.c.textPrimary, marginBottom: 6 }}>Search</div>
            <div style={{
              display: 'flex', alignItems: 'center', background: t.c.surface,
              border: `1px solid ${t.c.borderStrong}`, borderRadius: t.r.md,
            }}>
              <Search size={14} color={t.c.textTertiary} style={{ marginLeft: 10 }} />
              <input
                type="text"
                placeholder="Event type, entity ID, error message…"
                value={filters.search}
                onChange={(e) => setFilters({ ...filters, search: e.target.value })}
                style={{
                  flex: 1, minWidth: 0, background: 'transparent', border: 'none',
                  padding: '8px 12px', fontSize: 13, color: t.c.textPrimary,
                }}
              />
            </div>
          </div>
          <Select
            label="Event type"
            value={filters.type}
            onChange={(v) => setFilters({ ...filters, type: v })}
            options={[
              { value: 'all',         label: 'All types' },
              { value: 'contact.',    label: 'contact.*' },
              { value: 'automation.', label: 'automation.*' },
              { value: 'order.',      label: 'order.*' },
              { value: 'customer.',   label: 'customer.*' },
              { value: 'catalog.',    label: 'catalog.*' },
              { value: 'product.',    label: 'product.*' },
              { value: 'browse.',     label: 'browse.*' },
              { value: 'identity.',   label: 'identity.*' },
            ]}
          />
          <Select
            label="Status"
            value={filters.status}
            onChange={(v) => setFilters({ ...filters, status: v })}
            options={[
              { value: 'all',      label: 'All statuses' },
              { value: 'success',  label: 'Success' },
              { value: 'failed',   label: 'Failed' },
              { value: 'retrying', label: 'Retrying' },
              { value: 'pending',  label: 'Pending' },
            ]}
          />
          <Select
            label="Source"
            value={filters.source}
            onChange={(v) => setFilters({ ...filters, source: v })}
            options={[
              { value: 'all',        label: 'All sources' },
              { value: 'smaily',     label: 'Smaily' },
              { value: 'rec_engine', label: 'Rec engine' },
            ]}
          />
        </div>

        {/* Table */}
        <Card padding="none" style={{ marginTop: 20, overflow: 'hidden' }}>
          {/* Result count */}
          <div style={{
            padding: '10px 16px', background: t.c.surfaceSoft,
            borderBottom: `1px solid ${t.c.borderSubtle}`,
            fontSize: 12, color: t.c.textSecondary,
            display: 'flex', justifyContent: 'space-between', alignItems: 'center',
          }}>
            <span>{formatNumber(filtered.length)} of {formatNumber(MOCK_EVENT_LOG.length)} events shown</span>
            {filtered.length < MOCK_EVENT_LOG.length && (
              <button
                onClick={() => setFilters({ type: 'all', status: 'all', source: 'all', search: '' })}
                style={{
                  background: 'transparent', border: 'none', padding: 0,
                  color: t.c.brand, fontSize: 12, fontWeight: 500,
                }}
              >
                Clear filters
              </button>
            )}
          </div>

          {filtered.length === 0 ? (
            <div style={{ textAlign: 'center', padding: '48px 24px' }}>
              <FileSearch size={28} color={t.c.textTertiary} style={{ marginBottom: 12 }} />
              <div style={{ fontSize: 14, fontWeight: 500, color: t.c.textPrimary, marginBottom: 4 }}>
                No events match your filters
              </div>
              <div style={{ fontSize: 13, color: t.c.textSecondary }}>
                Try widening the filter selection above.
              </div>
            </div>
          ) : (
            <div style={{ overflowX: 'auto' }}>
              <table style={{ width: '100%', borderCollapse: 'collapse', tableLayout: 'auto' }}>
                <thead>
                  <tr style={{ background: t.c.surface }}>
                    {['Time', 'Event type', 'Entity', 'Source', 'Status', 'Last error', ''].map((h) => (
                      <th
                        key={h}
                        style={{
                          padding: '10px 12px', fontSize: 11.5, fontWeight: 500,
                          textTransform: 'uppercase', letterSpacing: '0.06em',
                          color: t.c.textTertiary, textAlign: 'left',
                        }}
                      >
                        {h}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {filtered.map((event) => (
                    <EventRow
                      key={event.id}
                      event={event}
                      expanded={expandedId === event.id}
                      onToggle={() => setExpandedId(expandedId === event.id ? null : event.id)}
                    />
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>

        {/* Cross-nav */}
        <div style={{
          marginTop: 24, paddingTop: 16,
          borderTop: `1px solid ${t.c.borderSubtle}`,
          display: 'flex', gap: 12, alignItems: 'center', justifyContent: 'space-between',
          fontSize: 13, color: t.c.textSecondary,
        }}>
          <span>Looking for setup or configuration?</span>
          <div style={{ display: 'flex', gap: 8 }}>
            <Button variant="ghost" size="sm" onClick={onViewSettings}>Open Settings</Button>
            <Button variant="ghost" size="sm" onClick={onBackToWizard}>Open Setup Wizard</Button>
          </div>
        </div>
      </div>
    </div>
  );
};

/* ============================================================================
 * 11. SETTINGS VIEW
 * ========================================================================== */

const SettingsView = ({ state, dispatch, env, onRunWizard, onViewEventLog }) => {
  const [activeTab, setActiveTab] = useState('connection');
  const tabs = [
    { key: 'connection',      label: 'Connection',      icon: Link2 },
    { key: 'subscribers',     label: 'Subscribers',     icon: Users },
    { key: 'woocommerce',     label: 'WooCommerce',     icon: ShoppingCart },
    { key: 'recommendations', label: 'Recommendations', icon: Sparkles },
    { key: 'integrations',    label: 'Integrations',    icon: Puzzle },
  ];

  const tabDescriptions = {
    connection:      'Smaily and recommendations engine credentials. Re-test connections or change multilingual mode.',
    subscribers:     'Contact sync fields, subscription form opt-ins, and backfill control.',
    woocommerce:     'Welcome, first order, and abandoned cart automation workflow mappings.',
    recommendations: 'Sync controls for orders, customers, products, cart events, and browsing tracking.',
    integrations:    'Elementor, Contact Form 7, and Smaily Landing Pages integration status.',
  };

  const renderTab = () => {
    switch (activeTab) {
      case 'connection':
        return <Step1Connect state={state} dispatch={dispatch} env={env} inSettings />;
      case 'subscribers':
        return <Step2Subscribers state={state} dispatch={dispatch} env={env} inSettings />;
      case 'woocommerce':
        return <Step3WooCommerce state={state} dispatch={dispatch} env={env} inSettings />;
      case 'recommendations':
        return (
          <Step4Recommendations
            state={state}
            dispatch={dispatch}
            env={env}
            onBackToStep1={() => setActiveTab('connection')}
            inSettings
          />
        );
      case 'integrations':
        return <Step5Integrations env={env} inSettings />;
      default:
        return null;
    }
  };

  return (
    <div style={{
      flex: 1, display: 'flex', flexDirection: 'column',
      background: t.c.pageBg, minWidth: 0,
    }}>
      <div style={{
        padding: '36px 40px 0', background: t.c.surface,
        borderBottom: `1px solid ${t.c.borderDefault}`, flexShrink: 0,
      }}>
        <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 24 }}>
          <div>
            <div style={{
              fontSize: 11.5, fontWeight: 500, textTransform: 'uppercase',
              letterSpacing: '0.08em', color: t.c.textTertiary, marginBottom: 6,
            }}>
              Smaily Connect Plus
            </div>
            <h1 style={{ fontSize: 22, fontWeight: 600, color: t.c.textPrimary, margin: 0, lineHeight: 1.2 }}>
              Settings
            </h1>
          </div>
          <div style={{ display: 'flex', gap: 8 }}>
            <Button variant="ghost" icon={Activity} onClick={onViewEventLog}>
              Event Log
            </Button>
            <Button variant="secondary" icon={Wand2} onClick={onRunWizard}>
              Re-run setup wizard
            </Button>
          </div>
        </div>
        <div style={{ display: 'flex', gap: 4, marginTop: 24, marginBottom: -1 }}>
          {tabs.map((tab) => {
            const Icon = tab.icon;
            const isActive = tab.key === activeTab;
            return (
              <button
                key={tab.key}
                onClick={() => setActiveTab(tab.key)}
                className="scp-tab"
                style={{
                  display: 'inline-flex', alignItems: 'center', gap: 8,
                  padding: '10px 16px', fontSize: 13.5, fontWeight: 500,
                  borderTop: 'none', borderLeft: 'none', borderRight: 'none',
                  borderBottom: `2px solid ${isActive ? t.c.brand : 'transparent'}`,
                  background: 'transparent',
                  color: isActive ? t.c.textPrimary : t.c.textSecondary,
                  transition: 'color 120ms ease, border-color 120ms ease',
                }}
              >
                <Icon size={14} />
                {tab.label}
              </button>
            );
          })}
        </div>
      </div>
      <div
        className="scp-scroll"
        style={{ flex: 1, overflowY: 'auto', minHeight: 0 }}
      >
        {/* Tab description bar */}
        <div style={{
          padding: '14px 40px', background: t.c.surfaceSoft,
          borderBottom: `1px solid ${t.c.borderSubtle}`,
          fontSize: 12.5, color: t.c.textSecondary,
        }}>
          {tabDescriptions[activeTab]}
        </div>
        {/* Tab content */}
        <div style={{ padding: '32px 40px 48px' }}>
          <div style={{ maxWidth: 820 }}>
            {renderTab()}
          </div>
        </div>
      </div>
    </div>
  );
};

/* ============================================================================
 * 11. DEV PANEL
 * ========================================================================== */

const DevPanel = ({ env, setEnv, view, setView, resetState }) => {
  const [open, setOpen] = useState(false);
  return (
    <div style={{ position: 'fixed', bottom: 16, left: 16, zIndex: 50 }}>
      {open && (
        <div style={{
          marginBottom: 8, width: 280, background: t.c.surface,
          border: `1px solid ${t.c.borderStrong}`, borderRadius: t.r.lg,
          boxShadow: t.shadow.pop, padding: 16,
        }}>
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 12 }}>
            <div style={{
              fontSize: 11.5, fontWeight: 600, textTransform: 'uppercase',
              letterSpacing: '0.08em', color: t.c.textTertiary,
            }}>
              Prototype controls
            </div>
            <button
              onClick={() => setOpen(false)}
              style={{ color: t.c.textTertiary, background: 'transparent', border: 'none', padding: 0 }}
            >
              <X size={16} />
            </button>
          </div>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
            <div>
              <div style={{ fontSize: 12.5, fontWeight: 500, color: t.c.textPrimary, marginBottom: 6 }}>View</div>
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 4 }}>
                <Button size="sm" variant={view === 'wizard' ? 'primary' : 'secondary'} onClick={() => setView('wizard')}>Wizard</Button>
                <Button size="sm" variant={view === 'settings' ? 'primary' : 'secondary'} onClick={() => setView('settings')}>Settings</Button>
                <Button size="sm" variant={view === 'eventlog' ? 'primary' : 'secondary'} onClick={() => setView('eventlog')}>Event Log</Button>
              </div>
            </div>
            <div style={{ paddingTop: 8, borderTop: `1px solid ${t.c.borderSubtle}` }}>
              <div style={{ fontSize: 12.5, fontWeight: 500, color: t.c.textPrimary, marginBottom: 6 }}>Site languages</div>
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 4 }}>
                <Button
                  size="sm"
                  variant={env.detectedLanguages.length === 1 ? 'primary' : 'secondary'}
                  onClick={() => setEnv({ ...env, detectedLanguages: ['et'] })}
                >
                  Single (ET)
                </Button>
                <Button
                  size="sm"
                  variant={env.detectedLanguages.length > 1 ? 'primary' : 'secondary'}
                  onClick={() => setEnv({ ...env, detectedLanguages: ['et', 'en'] })}
                >
                  Multi (ET+EN)
                </Button>
              </div>
            </div>
            <div style={{
              display: 'flex', alignItems: 'center', justifyContent: 'space-between',
              paddingTop: 8, borderTop: `1px solid ${t.c.borderSubtle}`,
            }}>
              <div style={{ fontSize: 12.5, fontWeight: 500, color: t.c.textPrimary }}>Upstream plugin active</div>
              <Toggle
                checked={env.upstreamPluginActive}
                onChange={(v) => setEnv({ ...env, upstreamPluginActive: v })}
              />
            </div>
            <div style={{
              display: 'flex', alignItems: 'center', justifyContent: 'space-between',
              paddingTop: 8, borderTop: `1px solid ${t.c.borderSubtle}`,
            }}>
              <div style={{ fontSize: 12.5, fontWeight: 500, color: t.c.textPrimary }}>Elementor installed</div>
              <Toggle
                checked={env.elementorPresent}
                onChange={(v) => setEnv({ ...env, elementorPresent: v })}
              />
            </div>
            <div style={{
              display: 'flex', alignItems: 'center', justifyContent: 'space-between',
            }}>
              <div style={{ fontSize: 12.5, fontWeight: 500, color: t.c.textPrimary }}>Contact Form 7 installed</div>
              <Toggle
                checked={env.cf7Present}
                onChange={(v) => setEnv({ ...env, cf7Present: v })}
              />
            </div>
            <div style={{ paddingTop: 8, borderTop: `1px solid ${t.c.borderSubtle}` }}>
              <Button size="sm" variant="ghost" icon={RefreshCw} onClick={resetState} fullWidth>
                Reset wizard state
              </Button>
            </div>
            <div style={{ fontSize: 11, color: t.c.textTertiary, lineHeight: 1.6, paddingTop: 4 }}>
              These controls simulate environment detection and let you preview different paths. Not visible to real users.
            </div>
          </div>
        </div>
      )}
      <button
        onClick={() => setOpen(!open)}
        style={{
          background: t.c.textPrimary, color: t.c.textWhite,
          borderRadius: t.r.pill, padding: '0 14px', height: 40, border: 'none',
          display: 'inline-flex', alignItems: 'center', gap: 8,
          fontSize: 12.5, fontWeight: 500, boxShadow: t.shadow.pop,
          transition: 'background 120ms ease',
        }}
      >
        <Sliders size={14} />
        Prototype controls
      </button>
    </div>
  );
};

/* ============================================================================
 * 12. WIZARD STATE REDUCER
 * ========================================================================== */

const initialBackfillState = {
  status: 'idle',
  processed: 0,
  processedByLang: {},
  total: MOCK.storeTotals.customers,
  totalByLang: {},
  isModeA: false,
  languages: [],
  lastRun: null,
};

const initialAutomationState = (kind) => ({
  enabled: true,
  // For single-lang and Mode C: single workflow.
  workflow: null,
  // For Mode A/B: per-language workflow id mapping. Keys are language codes.
  workflowsByLang: {},
  // For Mode A/B: which language row is the "default fallback" for contacts
  // without a detected language. Variant 1 of the spec discussion — no
  // separate Default row, just a radio button on one of the language rows.
  defaultLang: null,
  // Abandoned cart only: cutoff time in minutes (when to consider cart abandoned).
  ...(kind === 'abandonedCart' ? { cutoffMinutes: 30 } : {}),
});

const initialWizardState = {
  smailyAccounts: {},
  multilingualMode: 'B',
  defaultAccount: null,
  recEngine: { endpoint: '', token: '', testStatus: 'idle', tenantInfo: null },
  subscribers: {
    syncEnabled: true,
    fields: defaultFieldSelections(),
    showOnWPRegistration: true,
    showOnWCCheckout: true,
    backfill: initialBackfillState,
  },
  automations: {
    welcome:        initialAutomationState('welcome'),
    firstOrder:     initialAutomationState('firstOrder'),
    abandonedCart:  initialAutomationState('abandonedCart'),
  },
  recommendations: {
    syncOrders:     true,
    syncCustomers:  true,
    syncProducts:   true,
    trackCartEvents: true,
    trackBrowsing:  false, // privacy-sensitive, opt-in
    // Combined backfill for orders/customers/products. They share a single
    // run because they're useful only together — the rec-engine learns from
    // the joined dataset.
    backfill: {
      status: 'idle', // 'idle' | 'running' | 'completed' | 'failed'
      processed: { orders: 0, customers: 0, products: 0 },
      total:     { orders: 0, customers: 0, products: 0 },
      lastRun: null,
    },
  },
};

function advanceBackfill(bf) {
  if (bf.status !== 'running') return bf;
  if (bf.isModeA) {
    const newByLang = { ...bf.processedByLang };
    let allDone = true;
    for (const key of [...bf.languages, '_default']) {
      const total = bf.totalByLang[key] || 0;
      const cur = newByLang[key] || 0;
      if (cur < total) {
        const inc = Math.max(1, Math.ceil(total / 50));
        newByLang[key] = Math.min(total, cur + inc);
        if (newByLang[key] < total) allDone = false;
      }
    }
    return {
      ...bf, processedByLang: newByLang,
      status: allDone ? 'completed' : 'running',
      lastRun: allDone ? new Date().toISOString() : bf.lastRun,
    };
  }
  const inc = Math.max(1, Math.ceil(bf.total / 50));
  const next = Math.min(bf.total, bf.processed + inc);
  const done = next >= bf.total;
  return {
    ...bf, processed: next,
    status: done ? 'completed' : 'running',
    lastRun: done ? new Date().toISOString() : bf.lastRun,
  };
}

function wizardReducer(state, action) {
  switch (action.type) {
    case 'SMAILY_CRED':
      return {
        ...state,
        smailyAccounts: {
          ...state.smailyAccounts,
          [action.key]: {
            ...(state.smailyAccounts[action.key] || { testStatus: 'idle' }),
            ...action.values,
          },
        },
      };
    case 'SMAILY_TEST': {
      const newAccounts = {
        ...state.smailyAccounts,
        [action.key]: {
          ...(state.smailyAccounts[action.key] || {}),
          testStatus: action.status,
          ...(action.accountInfo ? { accountInfo: action.accountInfo } : {}),
        },
      };
      let defaultAccount = state.defaultAccount;
      if (action.status === 'success' && action.key !== 'primary'
          && state.multilingualMode === 'A' && !defaultAccount) {
        defaultAccount = action.key;
      }
      return { ...state, smailyAccounts: newAccounts, defaultAccount };
    }
    case 'SET_MODE':
      return { ...state, multilingualMode: action.mode };
    case 'SET_DEFAULT':
      return { ...state, defaultAccount: action.lang };
    case 'REC_CRED':
      return { ...state, recEngine: { ...state.recEngine, ...action.values } };
    case 'REC_TEST':
      return {
        ...state,
        recEngine: {
          ...state.recEngine,
          testStatus: action.status,
          tenantInfo: action.tenantInfo ?? state.recEngine.tenantInfo,
        },
      };
    case 'SUBSCRIBERS_TOGGLE_SYNC':
      return { ...state, subscribers: { ...state.subscribers, syncEnabled: action.value } };
    case 'SUBSCRIBERS_SET_FIELDS':
      return { ...state, subscribers: { ...state.subscribers, fields: action.fields } };
    case 'SUBSCRIBERS_TOGGLE_OPTIN':
      return { ...state, subscribers: { ...state.subscribers, [action.key]: action.value } };
    case 'BACKFILL_START': {
      const isModeA = !!action.isModeA;
      const totalByLang = isModeA ? { ...MOCK.backfillSplit } : {};
      const processedByLang = isModeA
        ? Object.fromEntries(Object.keys(totalByLang).map((k) => [k, 0]))
        : {};
      return {
        ...state,
        subscribers: {
          ...state.subscribers,
          backfill: {
            ...initialBackfillState,
            status: 'running',
            isModeA,
            languages: isModeA ? action.languages : [],
            processed: 0,
            processedByLang,
            total: isModeA
              ? Object.values(totalByLang).reduce((a, b) => a + b, 0)
              : MOCK.storeTotals.customers,
            totalByLang,
          },
        },
      };
    }
    case 'BACKFILL_TICK':
      return {
        ...state,
        subscribers: { ...state.subscribers, backfill: advanceBackfill(state.subscribers.backfill) },
      };
    case 'BACKFILL_RESET':
      return { ...state, subscribers: { ...state.subscribers, backfill: initialBackfillState } };

    // === STEP 3 — AUTOMATIONS ===
    case 'AUTOMATION_TOGGLE':
      return {
        ...state,
        automations: {
          ...state.automations,
          [action.kind]: { ...state.automations[action.kind], enabled: action.value },
        },
      };
    case 'AUTOMATION_SET_WORKFLOW': {
      // Single dropdown (single-lang / Mode C): action.lang is undefined.
      // Per-language row (Mode A/B): action.lang is 'et' / 'en' / etc.
      const auto = state.automations[action.kind];
      if (action.lang === undefined) {
        return {
          ...state,
          automations: {
            ...state.automations,
            [action.kind]: { ...auto, workflow: action.workflowId },
          },
        };
      }
      return {
        ...state,
        automations: {
          ...state.automations,
          [action.kind]: {
            ...auto,
            workflowsByLang: { ...auto.workflowsByLang, [action.lang]: action.workflowId },
          },
        },
      };
    }
    case 'AUTOMATION_SET_DEFAULT_LANG':
      return {
        ...state,
        automations: {
          ...state.automations,
          [action.kind]: { ...state.automations[action.kind], defaultLang: action.lang },
        },
      };
    case 'AUTOMATION_SET_CUTOFF':
      return {
        ...state,
        automations: {
          ...state.automations,
          abandonedCart: { ...state.automations.abandonedCart, cutoffMinutes: action.value },
        },
      };

    // === STEP 4 — RECOMMENDATIONS ===
    case 'REC_TOGGLE':
      return {
        ...state,
        recommendations: { ...state.recommendations, [action.key]: action.value },
      };
    case 'REC_BACKFILL_START': {
      // Compute per-type totals based on which toggles are enabled. Disabled
      // types contribute 0 (so they don't appear in the progress UI).
      const r = state.recommendations;
      const total = {
        orders:    r.syncOrders    ? MOCK.storeTotals.orders    : 0,
        customers: r.syncCustomers ? MOCK.storeTotals.customers : 0,
        products:  r.syncProducts  ? MOCK.storeTotals.products  : 0,
      };
      return {
        ...state,
        recommendations: {
          ...state.recommendations,
          backfill: {
            status: 'running',
            processed: { orders: 0, customers: 0, products: 0 },
            total,
            lastRun: null,
          },
        },
      };
    }
    case 'REC_BACKFILL_TICK': {
      const bf = state.recommendations.backfill;
      if (bf.status !== 'running') return state;
      const newProcessed = { ...bf.processed };
      let allDone = true;
      for (const key of ['orders', 'customers', 'products']) {
        const tot = bf.total[key] || 0;
        const cur = newProcessed[key] || 0;
        if (cur < tot) {
          // Different rates per type — orders are biggest, finish slowest.
          const ratePer50 = key === 'orders' ? 60 : key === 'customers' ? 50 : 50;
          const inc = Math.max(1, Math.ceil(tot / ratePer50));
          newProcessed[key] = Math.min(tot, cur + inc);
          if (newProcessed[key] < tot) allDone = false;
        }
      }
      return {
        ...state,
        recommendations: {
          ...state.recommendations,
          backfill: {
            ...bf,
            processed: newProcessed,
            status: allDone ? 'completed' : 'running',
            lastRun: allDone ? new Date().toISOString() : bf.lastRun,
          },
        },
      };
    }
    case 'REC_BACKFILL_RESET':
      return {
        ...state,
        recommendations: {
          ...state.recommendations,
          backfill: {
            status: 'idle',
            processed: { orders: 0, customers: 0, products: 0 },
            total: { orders: 0, customers: 0, products: 0 },
            lastRun: null,
          },
        },
      };

    case 'RESET':
      return initialWizardState;
    default:
      return state;
  }
}

/* ============================================================================
 * 13. ROOT
 * ========================================================================== */

export default function SmailyConnectPlusPlugin() {
  const [env, setEnv] = useState(MOCK.defaultEnv);
  const [view, setView] = useState('wizard');
  const [currentStep, setCurrentStep] = useState(1);
  const [completedSteps, setCompletedSteps] = useState([]);
  const [state, dispatch] = useReducer(wizardReducer, initialWizardState);

  const canAdvance = useMemo(() => {
    if (view !== 'wizard') return false;
    if (currentStep === 1) {
      const isMulti = env.detectedLanguages.length > 1;
      if (isMulti && state.multilingualMode === 'A') {
        return env.detectedLanguages.every(
          (l) => state.smailyAccounts[l]?.testStatus === 'success'
        );
      }
      return state.smailyAccounts.primary?.testStatus === 'success';
    }
    return true;
  }, [currentStep, state, env, view]);

  const advanceHint = useMemo(() => {
    if (canAdvance || currentStep !== 1) return null;
    const isMulti = env.detectedLanguages.length > 1;
    if (isMulti && state.multilingualMode === 'A') {
      const remaining = env.detectedLanguages.filter(
        (l) => state.smailyAccounts[l]?.testStatus !== 'success'
      );
      if (remaining.length === env.detectedLanguages.length) {
        return 'Test all language accounts to continue';
      }
      return `Test ${remaining.map((l) => MOCK.languageNames[l]).join(' & ')} to continue`;
    }
    return 'Test your Smaily connection to continue';
  }, [canAdvance, currentStep, env, state]);

  const advance = () => {
    if (!completedSteps.includes(currentStep)) {
      setCompletedSteps([...completedSteps, currentStep]);
    }
    if (currentStep < WIZARD_STEPS.length) setCurrentStep(currentStep + 1);
  };
  const goBack = () => currentStep > 1 && setCurrentStep(currentStep - 1);

  const currentStepDef = WIZARD_STEPS.find((s) => s.num === currentStep);

  const renderStep = () => {
    switch (currentStep) {
      case 1: return <Step1Connect state={state} dispatch={dispatch} env={env} />;
      case 2: return <Step2Subscribers state={state} dispatch={dispatch} env={env} />;
      case 3: return <Step3WooCommerce state={state} dispatch={dispatch} env={env} />;
      case 4: return <Step4Recommendations state={state} dispatch={dispatch} env={env} onBackToStep1={() => setCurrentStep(1)} />;
      case 5: return <Step5Integrations env={env} />;
      case 6: return <Step6Done state={state} env={env} onViewSettings={() => setView('settings')} onViewEventLog={() => setView('eventlog')} />;
      default: return <PlaceholderStep step={currentStepDef} />;
    }
  };

  return (
    <>
      <GlobalStyles />
      <div
        className="scp-root"
        style={{
          height: '100vh', maxHeight: '100vh', width: '100vw',
          display: 'flex', background: t.c.pageBg, overflow: 'hidden',
          fontFamily: t.font.sans, color: t.c.textPrimary,
          fontSize: 14, lineHeight: 1.5,
        }}
      >
        {view === 'wizard' && (
          <>
            <StepRail
              currentStep={currentStep}
              completed={completedSteps}
              onStepClick={setCurrentStep}
            />
            <div style={{ flex: 1, display: 'flex', flexDirection: 'column', minWidth: 0, minHeight: 0 }}>
              <div
                className="scp-scroll"
                style={{ flex: 1, overflowY: 'auto', minHeight: 0 }}
              >
                <div style={{ padding: '40px', maxWidth: 820 }}>
                  {renderStep()}
                </div>
              </div>
              <WizardFooter
                currentStep={currentStep}
                canAdvance={canAdvance}
                onBack={goBack}
                onNext={advance}
                hint={advanceHint}
              />
            </div>
          </>
        )}
        {view === 'settings' && (
          <SettingsView
            state={state}
            dispatch={dispatch}
            env={env}
            onRunWizard={() => {
              setView('wizard');
              setCurrentStep(1);
            }}
            onViewEventLog={() => setView('eventlog')}
          />
        )}
        {view === 'eventlog' && (
          <EventLogView
            onBackToWizard={() => setView('wizard')}
            onViewSettings={() => setView('settings')}
          />
        )}

        <DevPanel
          env={env}
          setEnv={setEnv}
          view={view}
          setView={setView}
          resetState={() => dispatch({ type: 'RESET' })}
        />
      </div>
    </>
  );
}
