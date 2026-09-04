/**
 * ESLint config for the admin React bundle and the RecEngineClient
 * TypeScript stub. Scope is intentionally narrow — blocks/ stays
 * self-contained with its own ESLint config inside that directory.
 *
 * Rule selection ratified for sub-PR 2.B:
 *   - react-hooks/rules-of-hooks (error)         — catches conditional hook calls
 *   - react-hooks/exhaustive-deps (warn)         — useEffect/useCallback dep tracking
 *   - @typescript-eslint/no-explicit-any (warn)  — keeps the gradient toward typed code
 *   - @typescript-eslint/consistent-type-imports (error, inline) — clean type imports
 *
 * The lint script in package.json runs --max-warnings 0, so warnings
 * fail CI just like errors. Override per file with eslint-disable when
 * a genuine exception arises (e.g. a deliberate `any` inside test
 * scaffolding); don't relax these rules project-wide.
 */
module.exports = {
  root: true,
  parser: '@typescript-eslint/parser',
  parserOptions: {
    ecmaVersion: 2022,
    sourceType: 'module',
    ecmaFeatures: { jsx: true },
  },
  env: {
    browser: true,
    es2022: true,
  },
  plugins: [
    '@typescript-eslint',
    'react',
    'react-hooks',
    'jsx-a11y',
  ],
  extends: [
    'eslint:recommended',
    'plugin:@typescript-eslint/recommended',
    'plugin:react/recommended',
    'plugin:react/jsx-runtime',
    'plugin:jsx-a11y/recommended',
  ],
  settings: {
    react: {
      version: '18.3',
    },
  },
  rules: {
    'react-hooks/rules-of-hooks': 'error',
    'react-hooks/exhaustive-deps': 'warn',

    '@typescript-eslint/no-explicit-any': 'warn',
    '@typescript-eslint/consistent-type-imports': [
      'error',
      {
        prefer: 'type-imports',
        fixStyle: 'inline-type-imports',
      },
    ],
    '@typescript-eslint/no-unused-vars': [
      'warn',
      {
        // Match the underscore-prefix convention TypeScript already
        // honours in tsconfig.json — see RecEngineClient stub params.
        argsIgnorePattern: '^_',
        varsIgnorePattern: '^_',
      },
    ],

    // React 17+ doesn't need React in scope for JSX.
    'react/react-in-jsx-scope': 'off',
  },
  overrides: [
    {
      // Test files have looser rules — type-asserting test fixtures
      // and using `any` for mock objects is acceptable trade-off.
      files: ['**/*.test.{ts,tsx}', 'tests/**/*.{ts,tsx}'],
      rules: {
        '@typescript-eslint/no-explicit-any': 'off',
      },
    },
  ],
  ignorePatterns: [
    'dist/',
    'node_modules/',
    'vendor/',
    'blocks/',
    'admin/src/**/*.d.ts',
  ],
};
