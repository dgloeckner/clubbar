/**
 * Tests for the Dependabot group check. It gates the build, so it gets tests —
 * the same reasoning `check-ci-lanes.test.mjs` records.
 *
 * The first case below is the real one: react 19 (#594) came without
 * @testing-library/react, and `npm ci` refused the lockfile.
 */

import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

import {
  catchAllDrift,
  directDependencies,
  groupOf,
  npmEntries,
  parseYaml,
  peerRangeIsExhausted,
  splitLockedPeers,
} from './check-dependabot-groups.mjs'

const HERE = dirname(fileURLToPath(import.meta.url))
const REPO = resolve(HERE, '..', '..')

const REACT_18 = { name: 'react', version: '18.3.1', peers: {} }
const TESTING_LIBRARY = {
  name: '@testing-library/react',
  version: '14.3.1',
  peers: { react: '^18.0.0', 'react-dom': '^18.0.0' },
}
const RECHARTS = {
  name: 'recharts',
  version: '3.7.0',
  peers: { react: '^16.8.0 || ^17.0.0 || ^18.0.0 || ^19.0.0' },
}

const SPLIT = [
  { name: 'react', patterns: ['react', 'react-dom'], exclude: [] },
  { name: 'vite', patterns: ['vite', '@testing-library/*'], exclude: [] },
]
const TOGETHER = [{ name: 'react', patterns: ['react', 'react-dom', '@testing-library/*'], exclude: [] }]

test('a peer that stops at the current major and sits in another group is reported', () => {
  const problems = splitLockedPeers([REACT_18, TESTING_LIBRARY], SPLIT)

  assert.equal(problems.length, 1)
  assert.match(problems[0], /@testing-library\/react peers react@"\^18\.0\.0"/)
  assert.match(problems[0], /group "vite" and react in "react"/)
})

test('the same pair in one group is satisfied', () => {
  assert.deepEqual(splitLockedPeers([REACT_18, TESTING_LIBRARY], TOGETHER), [])
})

test('an exemption silences one edge, by name and with a reason', () => {
  const exempt = { '@testing-library/react -> react': 'checked by hand: the bump resolves' }

  assert.deepEqual(splitLockedPeers([REACT_18, TESTING_LIBRARY], SPLIT, exempt), [])
})

test('a peer range with room for the next major needs no grouping', () => {
  assert.deepEqual(splitLockedPeers([REACT_18, RECHARTS], SPLIT), [])
})

test('a peer that is not a direct dependency is nobody’s group to fix', () => {
  assert.deepEqual(splitLockedPeers([TESTING_LIBRARY], SPLIT), [])
})

test('peerRangeIsExhausted reads the ranges this repo actually carries', () => {
  assert.equal(peerRangeIsExhausted('^18.0.0', 18), true)
  assert.equal(peerRangeIsExhausted('^18.3.1', 18), true)
  assert.equal(peerRangeIsExhausted('4.1.11', 4), true)
  assert.equal(peerRangeIsExhausted('^7.0.0 || ^8.0.0', 8), true)
  assert.equal(peerRangeIsExhausted('^18.0.0 || ^19.0.0', 18), false)
  assert.equal(peerRangeIsExhausted('>= 16.8.0', 18), false)
  assert.equal(peerRangeIsExhausted('>=7', 8), false)
  assert.equal(peerRangeIsExhausted('*', 3), false)
})

test('the catch-all is claimed last, never over a named group', () => {
  const groups = [
    { name: 'react', patterns: ['react'], exclude: [] },
    { name: 'rest', patterns: ['*'], exclude: ['react'] },
  ]

  assert.equal(groupOf(groups, 'react'), 'react')
  assert.equal(groupOf(groups, 'lunr'), 'rest')
})

test('a group pattern missing from the catch-all exclusions is drift', () => {
  const drifted = [
    { name: 'react', patterns: ['react', '@types/react'], exclude: [] },
    { name: 'rest', patterns: ['*'], exclude: ['react', 'jsdom'] },
  ]

  assert.deepEqual(catchAllDrift(drifted), [
    '"@types/react" is claimed by a group but missing from rest\'s exclude-patterns',
    '"jsdom" is excluded from rest but claimed by no group',
  ])
})

test('the YAML reader handles the shapes dependabot.yml uses', () => {
  const parsed = parseYaml(`
version: 2

updates:
  # A comment, and a "quoted" one.
  - package-ecosystem: "npm"
    directories:
      - "/admin-frontend"
      - "/e2etests"
    groups:
      react:
        patterns:
          - "react"
        exclude-patterns: []
  - package-ecosystem: "composer"
    directory: "/backend"
`)

  assert.equal(parsed.version, '2')
  assert.equal(parsed.updates.length, 2)
  assert.deepEqual(parsed.updates[0].directories, ['/admin-frontend', '/e2etests'])
  assert.deepEqual(parsed.updates[0].groups.react.patterns, ['react'])
  assert.equal(parsed.updates[1].directory, '/backend')
})

test('the committed config parses into the entries the check needs', () => {
  const config = parseYaml(readFileSync(resolve(REPO, '.github', 'dependabot.yml'), 'utf8'))
  const entries = npmEntries(config)

  assert.equal(entries.length, 1)
  assert.deepEqual(entries[0].directories, ['/admin-frontend', '/e2etests'])
  assert.ok(entries[0].groups.some((group) => group.name === 'react'))
})

test('the committed manifests and config agree — this is the check itself', () => {
  const config = parseYaml(readFileSync(resolve(REPO, '.github', 'dependabot.yml'), 'utf8'))

  for (const entry of npmEntries(config)) {
    assert.deepEqual(catchAllDrift(entry.groups), [])

    for (const directory of entry.directories) {
      const root = resolve(REPO, `.${directory}`)
      const directs = directDependencies(
        JSON.parse(readFileSync(resolve(root, 'package.json'), 'utf8')),
        JSON.parse(readFileSync(resolve(root, 'package-lock.json'), 'utf8')),
      )

      assert.ok(directs.length > 0, `${directory} declares no direct dependencies`)
      assert.deepEqual(splitLockedPeers(directs, entry.groups), [])
    }
  }
})
