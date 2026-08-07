'use strict'

// Targeted ESLint flat config for the E2E suite (issue #168 §4).
//
// Intentionally minimal: this exists to enforce the single custom rule
// `clubbar/no-data-dependent-skip` (banning data-dependent test.skip()/test.fixme() calls per
// the issue #146 ruling), not to run a general lint sweep of the suite. Do not enable
// eslint:recommended or typescript-eslint recommended sets here — that would produce hundreds
// of unrelated errors across this codebase.

const tsParser = require('@typescript-eslint/parser')
const clubbar = require('./eslint-rules')

module.exports = [
  {
    ignores: ['node_modules/**', 'playwright-report/**', 'test-results/**', 'results/**', 'dist/**'],
  },
  {
    files: ['tests/**/*.ts'],
    languageOptions: {
      parser: tsParser,
      ecmaVersion: 2022,
      sourceType: 'module',
    },
    plugins: {
      clubbar,
    },
    rules: {
      'clubbar/no-data-dependent-skip': 'error',
    },
  },
]
