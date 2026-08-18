/**
 * Tests for the CI lane check. The check gates the build, so it gets tests —
 * the same reasoning `eslint-rules/*.test.js` records for the lint rules.
 *
 * The first case below is the real one: `mail-lifecycle` was added to the
 * config and never named in a lane, and every pull request stayed green.
 */

import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

import {
  NOT_IN_CI,
  declaredProjects,
  projectsNamedInCi,
  staleExclusions,
  unrunProjects,
} from './check-ci-lanes.mjs'

const HERE = dirname(fileURLToPath(import.meta.url))

const CONFIG = `
  projects: [
    { name: 'setup auth', testMatch: 'auth.setup.ts' },
    { name: 'api-tests', testDir: './tests/api' },
    { name: 'mail-chain', testDir: './tests/mail' },
  ]
`

const WORKFLOW = `
  - { lane: api, projects: --project=api-tests }
  - { lane: chain, projects: --project=mail-chain }
`

test('a project named in a lane is satisfied', () => {
  assert.deepEqual(unrunProjects(CONFIG, WORKFLOW, { 'setup auth': 'a setup project' }), [])
})

test('a project added to the config but never named in CI is reported', () => {
  const config = CONFIG.replace(
    "{ name: 'mail-chain', testDir: './tests/mail' },",
    "{ name: 'mail-chain', testDir: './tests/mail' },\n    { name: 'mail-lifecycle', testDir: './tests/mail-lifecycle' },",
  )

  assert.deepEqual(unrunProjects(config, WORKFLOW, { 'setup auth': 'a setup project' }), ['mail-lifecycle'])
})

test('a project dropped from a lane is reported, which is the merge-time case', () => {
  const workflow = WORKFLOW.replace(' --project=mail-chain', '')

  assert.deepEqual(unrunProjects(CONFIG, workflow, { 'setup auth': 'a setup project' }), ['mail-chain'])
})

test('an exclusion satisfies a project, so opting out stays possible', () => {
  const workflow = WORKFLOW.replace(' --project=mail-chain', '')
  const excluded = { 'setup auth': 'a setup project', 'mail-chain': 'deliberately not run' }

  assert.deepEqual(unrunProjects(CONFIG, workflow, excluded), [])
})

test('an exclusion for a project that no longer exists is reported', () => {
  assert.deepEqual(staleExclusions(CONFIG, { 'terminal-touch': 'no specs' }), ['terminal-touch'])
})

test('project names and --project flags are read as the files really write them', () => {
  assert.deepEqual(declaredProjects(CONFIG), ['setup auth', 'api-tests', 'mail-chain'])
  assert.deepEqual(projectsNamedInCi(WORKFLOW), ['api-tests', 'mail-chain'])
})

/**
 * The check is only worth its comment if it is true of the repository as it
 * stands: every declared project either runs in CI or is excluded by name.
 */
test('the repository itself satisfies the check', () => {
  const config = readFileSync(resolve(HERE, '..', 'playwright.config.ts'), 'utf8')
  const workflow = readFileSync(resolve(HERE, '..', '..', '.github', 'workflows', 'build.yaml'), 'utf8')

  assert.deepEqual(unrunProjects(config, workflow), [])
  assert.deepEqual(staleExclusions(config), [])
  assert.ok(Object.keys(NOT_IN_CI).length > 0, 'the exclusions are documented, not empty')
})
