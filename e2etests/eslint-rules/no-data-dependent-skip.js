'use strict'

// Rule: no-data-dependent-skip
//
// Ruling (issue #146): data-dependent skipping of Playwright tests is banned because it
// lets the suite report green while silently skipping money-critical paths whenever ambient
// DB data happens to be missing. Environmental gating (e.g. "no LLM_API_KEY configured")
// stays legal, but the gating condition must be statically decidable — not derived from a
// live query, a response, or any other runtime value — and it must declare *why* via a
// non-empty reason string.
//
// See also issue #168 §4 (this lint rule) and the ADR / plan referenced there.

/**
 * Determine whether `node` is an expression that is "statically decidable": built only from
 * operators, literals, and `process.env.X` member accesses (optionally through identifiers
 * that resolve, via scope analysis, to a module-scope `const` whose own initializer is itself
 * statically decidable).
 *
 * @param {import('estree').Node} node
 * @param {import('eslint').Scope.Scope} scope
 * @returns {boolean}
 */
function isStaticallyDecidable(node, scope) {
  switch (node.type) {
    case 'Literal':
      return true

    case 'TemplateLiteral':
      // No interpolation of runtime values allowed — only static text.
      return node.expressions.every((expr) => isStaticallyDecidable(expr, scope))

    case 'UnaryExpression':
      // e.g. `!process.env.X`
      return isStaticallyDecidable(node.argument, scope)

    case 'LogicalExpression':
      // e.g. `!a && b`, `a || b`
      return isStaticallyDecidable(node.left, scope) && isStaticallyDecidable(node.right, scope)

    case 'BinaryExpression':
      // e.g. `process.env.X !== '1'`
      return isStaticallyDecidable(node.left, scope) && isStaticallyDecidable(node.right, scope)

    case 'ParenthesizedExpression':
      return isStaticallyDecidable(node.expression, scope)

    case 'MemberExpression': {
      // Only `process.env.X` (or `process.env['X']`) is an allowed leaf member access.
      const { object, property } = node
      if (
        object.type === 'MemberExpression' &&
        object.object.type === 'Identifier' &&
        object.object.name === 'process' &&
        object.property.type === 'Identifier' &&
        object.property.name === 'env' &&
        !object.computed
      ) {
        // property can be `.X` (Identifier, non-computed) or `['X']` (Literal, computed)
        if (!node.computed && property.type === 'Identifier') return true
        if (node.computed && property.type === 'Literal') return true
      }
      return false
    }

    case 'Identifier':
      return isAllowedModuleConstReference(node, scope)

    default:
      return false
  }
}

/**
 * Resolve an Identifier through ESLint scope analysis to a module-scope `const` declaration
 * whose initializer is itself statically decidable (this is how `EXTRACTION_CONFIGURED =
 * !!process.env.LLM_API_KEY` stays legal when referenced elsewhere by name).
 *
 * @param {import('estree').Identifier} identifierNode
 * @param {import('eslint').Scope.Scope} scope
 * @returns {boolean}
 */
function isAllowedModuleConstReference(identifierNode, scope) {
  const variable = findVariable(scope, identifierNode.name)
  if (!variable) return false

  const def = variable.defs[0]
  if (!def || def.type !== 'Variable') return false
  if (def.parent.kind !== 'const') return false

  // Must be declared at module scope (top-level `const`, not block/function-local).
  const declarationScope = variable.scope
  if (declarationScope.type !== 'module' && declarationScope.type !== 'global') return false

  const init = def.node.init
  if (!init) return false

  return isStaticallyDecidable(init, declarationScope)
}

/**
 * Walk up from `scope` looking for a variable named `name`, the way normal lexical
 * resolution would (ESLint scopes expose `.variables` per scope and `.upper` for the parent).
 *
 * @param {import('eslint').Scope.Scope} scope
 * @param {string} name
 * @returns {import('eslint').Scope.Variable | null}
 */
function findVariable(scope, name) {
  let current = scope
  while (current) {
    const found = current.variables.find((v) => v.name === name)
    if (found) return found
    current = current.upper
  }
  return null
}

/**
 * A reason argument must be a non-empty string literal or template literal.
 *
 * @param {import('estree').Node | undefined} node
 * @returns {boolean}
 */
function isNonEmptyReason(node) {
  if (!node) return false
  if (node.type === 'Literal') {
    return typeof node.value === 'string' && node.value.length > 0
  }
  if (node.type === 'TemplateLiteral') {
    // A template literal with any content (quasis or expressions) counts as a reason.
    const hasText = node.quasis.some((q) => q.value.raw.length > 0)
    return hasText || node.expressions.length > 0
  }
  return false
}

/** @type {import('eslint').Rule.RuleModule} */
module.exports = {
  meta: {
    type: 'problem',
    docs: {
      description:
        'Ban data-dependent test.skip()/test.fixme() calls in the Playwright E2E suite (issue #146 ruling); require a statically-decidable, declared-reason environmental gate instead.',
    },
    schema: [],
    messages: {
      bare: 'Bare {{callee}}() is banned (issue #146): it always depends on ambient data or control flow and declares no reason. Use a statically-decidable environmental condition with a reason instead, e.g. {{callee}}(!process.env.SOME_FLAG, "why").',
      dataDependent:
        '{{callee}}() condition is data-dependent (issue #146 ruling: banned). The condition must be statically decidable — only literals, process.env.X checks, and module-scope consts built from those. Do not gate on query results, HTTP responses, or other runtime data; fix the underlying fixture instead.',
      missingReason:
        '{{callee}}() needs a non-empty reason as its second argument (issue #146 ruling) explaining the environmental gate, e.g. {{callee}}(!process.env.SOME_FLAG, "why").',
    },
  },

  create(context) {
    return {
      CallExpression(node) {
        const { callee } = node
        if (
          callee.type !== 'MemberExpression' ||
          callee.object.type !== 'Identifier' ||
          callee.object.name !== 'test' ||
          callee.property.type !== 'Identifier' ||
          (callee.property.name !== 'skip' && callee.property.name !== 'fixme')
        ) {
          return
        }

        const calleeName = `test.${callee.property.name}`
        const args = node.arguments

        if (args.length === 0) {
          context.report({ node, messageId: 'bare', data: { callee: calleeName } })
          return
        }

        const [first, second] = args

        // Declarative modifier form: test.skip('title', async () => {...}) — always legal,
        // it's not a conditional skip at all.
        if (first.type === 'Literal' && typeof first.value === 'string' && second) {
          const isFn =
            second.type === 'ArrowFunctionExpression' || second.type === 'FunctionExpression'
          if (isFn) return
        }

        const scope = context.sourceCode
          ? context.sourceCode.getScope(node)
          : context.getScope()

        if (!isStaticallyDecidable(first, scope)) {
          context.report({ node, messageId: 'dataDependent', data: { callee: calleeName } })
          return
        }

        if (!isNonEmptyReason(second)) {
          context.report({ node, messageId: 'missingReason', data: { callee: calleeName } })
        }
      },
    }
  },
}
