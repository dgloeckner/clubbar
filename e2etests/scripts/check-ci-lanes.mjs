#!/usr/bin/env node
/**
 * Every Playwright project must run somewhere in CI, or say why it does not.
 *
 * This exists because the failure it catches is **silent**. A project is added
 * to `playwright.config.ts`, its specs pass locally, the pull request goes
 * green — and the project was never named in `.github/workflows/build.yaml`,
 * so CI never ran a line of it. That is not hypothetical: `mail-lifecycle`
 * (#521) shipped exactly that way, and the CI job list has also drifted the
 * other direction when a lane was rewritten.
 *
 * The check is a transcription comparison, in the same spirit as the backend's
 * `RouteRoleMapCompletenessTest`: it reads the projects the config declares and
 * the `--project=` flags the workflow passes, and every project has to be in
 * one list or the other — running in CI, or excluded **here**, by name, with a
 * reason a reader can weigh.
 *
 * Run by the `lint-e2e` job, which needs no stack and finishes in seconds.
 */

import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const HERE = dirname(fileURLToPath(import.meta.url))
const CONFIG = resolve(HERE, '..', 'playwright.config.ts')
const WORKFLOW = resolve(HERE, '..', '..', '.github', 'workflows', 'build.yaml')

/**
 * Projects CI deliberately does not name, and why. A project belongs here only
 * when *not running it* is the decision — never to silence this check.
 */
export const NOT_IN_CI = {
  'setup auth': 'A setup project: every lane pulls it in as a dependency, so it is never named.',
  'setup walkthrough-data': 'Setup for the walkthrough captures below, pulled in the same way.',
  'rate-limit':
    'Needs the DISABLE_*_RATE_LIMITING flags removed from the stack, which the dev and CI ' +
    'compose files both set. Documented in playwright.config.ts; run with npm run test:rate-limit.',
  'terminal-touch': 'tests/terminal/ holds no specs yet — the project is ready for them, not skipped.',
  walkthrough: 'Documentation captures, run on demand via npm run walkthrough.',
  'walkthrough-screenshots': 'Documentation captures, run on demand.',
}

/** Every project the Playwright config declares. */
export function declaredProjects(configSource) {
  return [...configSource.matchAll(/name: '([^']+)'/g)].map((m) => m[1])
}

/** Every project the workflow passes to `playwright test`. */
export function projectsNamedInCi(workflowSource) {
  return [...workflowSource.matchAll(/--project=([A-Za-z0-9_-]+)/g)].map((m) => m[1])
}

/** @returns {string[]} one line per project that is neither run nor excluded. */
export function unrunProjects(configSource, workflowSource, excluded = NOT_IN_CI) {
  const inCi = new Set(projectsNamedInCi(workflowSource))

  return declaredProjects(configSource).filter((name) => !inCi.has(name) && !(name in excluded))
}

/** @returns {string[]} exclusions naming a project the config no longer declares. */
export function staleExclusions(configSource, excluded = NOT_IN_CI) {
  const declared = new Set(declaredProjects(configSource))

  return Object.keys(excluded).filter((name) => !declared.has(name))
}

function main() {
  const config = readFileSync(CONFIG, 'utf8')
  const workflow = readFileSync(WORKFLOW, 'utf8')

  const unrun = unrunProjects(config, workflow)
  const stale = staleExclusions(config)

  if (unrun.length === 0 && stale.length === 0) {
    const counted = declaredProjects(config).length - Object.keys(NOT_IN_CI).length
    console.log(`[ci lanes] ${counted} Playwright projects run in CI; ${Object.keys(NOT_IN_CI).length} excluded by name.`)
    return
  }

  if (unrun.length > 0) {
    console.error(
      `\nThese Playwright projects are declared but never run in CI:\n` +
        unrun.map((name) => `  - ${name}`).join('\n') +
        `\n\nAdd each to a lane's --project list in .github/workflows/build.yaml, or to NOT_IN_CI\n` +
        `in ${'e2etests/scripts/check-ci-lanes.mjs'} with the reason it does not run. A project CI\n` +
        `never names is a project whose specs cannot fail the build.\n`,
    )
  }

  if (stale.length > 0) {
    console.error(
      `\nThese exclusions name a project that no longer exists:\n` +
        stale.map((name) => `  - ${name}`).join('\n') +
        `\n\nRemove them, so the list keeps describing the suite.\n`,
    )
  }

  process.exit(1)
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  main()
}
