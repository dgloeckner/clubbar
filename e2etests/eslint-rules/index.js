'use strict'

// Local ESLint plugin exposing the rules in this directory under the `clubbar` namespace.
// Registered by eslint.config.js.
module.exports = {
  rules: {
    'no-data-dependent-skip': require('./no-data-dependent-skip'),
  },
}
