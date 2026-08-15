'use strict'

// Test-first (issue #168 §4): RuleTester cases for the no-data-dependent-skip rule,
// run with the Node built-in test runner (`node --test eslint-rules/`).
// ESLint 9's RuleTester supports node:test out of the box.

const { RuleTester } = require('eslint')
const rule = require('./no-data-dependent-skip')

const ruleTester = new RuleTester({
  languageOptions: {
    ecmaVersion: 2022,
    sourceType: 'module',
    parser: require('@typescript-eslint/parser'),
  },
})

ruleTester.run('no-data-dependent-skip', rule, {
  valid: [
    // Env-derived condition with a reason is the canonical legal form from the #146 ruling.
    {
      code: `test.skip(!process.env.LLM_API_KEY, 'needs LLM_API_KEY')`,
    },
    // A module-scope const whose initializer is itself an allowed (env-derived) expression
    // stays legal when referenced by identifier.
    {
      code: `
        const FEATURE_CONFIGURED = !!process.env.SOME_API_KEY
        test.skip(!FEATURE_CONFIGURED, 'SOME_API_KEY not set — skipping positive test')
      `,
    },
    // A boolean literal condition is statically decidable (not data-dependent).
    {
      code: `test.skip(true, 'HEIC fixture not available — see #146')`,
    },
    // The declarative modifier form: first arg is a title string, second a test function.
    // This is a completely different overload of test.skip and must not be reported.
    {
      code: `test.skip('renders the thing', async () => {})`,
    },
  ],
  invalid: [
    // Bare skip: always data- or control-flow-dependent, and carries no reason at all.
    {
      code: `test.skip()`,
      errors: [{ messageId: 'bare' }],
    },
    // Bare skip guarded by an `if` on ambient data is still a bare call as far as the rule sees it.
    {
      code: `if (rows.length === 0) test.skip()`,
      errors: [{ messageId: 'bare' }],
    },
    // A reason string doesn't rescue a data-dependent condition — the condition itself
    // must be statically decidable per the #146 ruling.
    {
      code: `test.skip(rowCount === 0, 'No members in table to test')`,
      errors: [{ messageId: 'dataDependent' }],
    },
    // A call expression (reading a live HTTP response) is data-dependent, not statically decidable.
    {
      code: `test.skip(resp.status() !== 409, 'reason')`,
      errors: [{ messageId: 'dataDependent' }],
    },
    // Allowed condition shape, but missing the required reason argument.
    {
      code: `test.skip(!process.env.X)`,
      errors: [{ messageId: 'missingReason' }],
    },
  ],
})

console.log('no-data-dependent-skip: all RuleTester cases passed')
