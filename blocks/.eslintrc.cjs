/**
 * ESLint stop-boundary for the upstream Gutenberg blocks workspace.
 *
 * Without this file, `wp-scripts lint-js` running from blocks/checkout-optin
 * or blocks/newsletter-signup walks up the directory tree and finds the
 * project root's `.eslintrc.cjs` (admin-React config). That config references
 * @typescript-eslint plugins which aren't installed in the blocks workspace,
 * causing CI's "Lint blocks" step to abort with "plugin not found".
 *
 * `root: true` tells ESLint to stop the upward search here. Each individual
 * block keeps its own `.eslintrc` with the WP-recommended preset and
 * per-block overrides.
 */
module.exports = {
  root: true,
};
