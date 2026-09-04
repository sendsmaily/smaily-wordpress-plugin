import { defineConfig } from 'vitest/config';
import { resolve } from 'node:path';

/**
 * Vitest configuration — admin React + RecEngineClient stub tests.
 *
 * Separate from vite.config.ts so the build pipeline and the test
 * pipeline have orthogonal options (Vitest pulls jsdom; production
 * Vite build doesn't). They share the @admin / @client aliases so
 * tests import the same paths the components use.
 */
export default defineConfig({
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: [resolve(__dirname, 'admin/src/test-setup.ts')],
    include: ['admin/src/**/*.test.{ts,tsx}', 'public/js/**/*.test.ts'],
    coverage: {
      provider: 'v8',
      include: ['admin/src/**/*.{ts,tsx}', 'public/js/**/*.ts'],
      exclude: [
        'admin/src/test-setup.ts',
        'admin/src/**/*.test.{ts,tsx}',
        'admin/src/index.tsx', // mount-only, covered by manual sanity test
        'public/js/beacon.ts', // thin auto-boot entry, logic lives in beacon-core.ts
        'public/js/landing.ts', // thin auto-boot entry, logic lives in lib/attribution.ts
      ],
      reporter: ['text', 'html'],

      /**
       * Per Erkki's sub-PR 2.C spec: enforce 70 % only on the classes
       * PROJECT_PLAN.md §1.4 marks critical. Primitives and step
       * components have no threshold — they're visual surfaces best
       * covered by integration tests in sub-PR 2.E rather than unit
       * tests of every prop combination.
       */
      thresholds: {
        'admin/src/state/wizard-reducer.ts': {
          lines: 70,
          functions: 70,
          branches: 70,
        },
        'admin/src/state/settings-reducer.ts': {
          lines: 70,
          functions: 70,
        },
        'admin/src/utils/cn.ts': {
          lines: 70,
          functions: 70,
        },
        // Sub-PR 2.E.1 — hooks consume API wrappers. Threshold lifts as
        // sub-PR 2.G adds the remaining hooks (useWorkflows, etc).
        'admin/src/hooks/**/*.ts': {
          lines: 70,
          functions: 70,
        },
        'admin/src/api/**/*.ts': {
          lines: 70,
          functions: 70,
        },
      },
    },
  },

  resolve: {
    alias: {
      '@admin': resolve(__dirname, 'admin/src'),
      '@client': resolve(__dirname, 'public/js/lib'),
    },
  },
});
