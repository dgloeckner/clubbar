/**
 * Tests for the Dependabot group check. It gates the build, so it gets tests —
 * the same reasoning `check-ci-lanes.test.mjs` records.
 *
 * The first two cases are the real one, in both tenses: react 19 (#594) came
 * without @testing-library/react, and `npm ci` refused the lockfile.
 */

import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

import {
  UNGROUPED_PEERS,
  catchAllDrift,
  ciNodeVersions,
  engineViolations,
  directDependencies,
  groupOf,
  installedVersions,
  npmEntries,
  parseYaml,
  pubDirectDependencies,
  pubEntries,
  pubGroupCoverage,
  peerRangeIsExhausted,
  satisfies,
  splitLockedPeers,
  staleExemptions,
  unresolvablePeers,
} from './check-dependabot-groups.mjs'

const HERE = dirname(fileURLToPath(import.meta.url))
const REPO = resolve(HERE, '..', '..')

const REACT_18 = { name: 'react', version: '18.3.1', peers: {} }
const REACT_19 = { name: 'react', version: '19.2.8', peers: {} }
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

// --- now: the tree in front of us ------------------------------------------

test('#594: a lockfile whose peer range the installed version violates', () => {
  const problems = unresolvablePeers([REACT_19, TESTING_LIBRARY], { 'node_modules/react': '19.2.8' })

  assert.equal(problems.length, 1)
  assert.match(problems[0], /@testing-library\/react@14\.3\.1 peers react@"\^18\.0\.0"/)
  assert.match(problems[0], /installs react 19\.2\.8/)
  assert.match(problems[0], /needs a @testing-library\/react release that accepts react 19\.2\.8/)
})

test('a tree whose ranges all hold reports nothing', () => {
  assert.deepEqual(unresolvablePeers([REACT_18, TESTING_LIBRARY], { 'node_modules/react': '18.3.1' }), [])
})

test('a nested copy is the resolution npm made, so it is the one read', () => {
  const installed = {
    'node_modules/react': '19.2.8',
    'node_modules/@testing-library/react/node_modules/react': '18.3.1',
  }

  assert.deepEqual(unresolvablePeers([REACT_19, TESTING_LIBRARY], installed), [])
})

test('an optional peer nobody installed is not a violation', () => {
  const typed = { name: 'i18next', version: '26.3.6', peers: { typescript: '^5 || ^6 || ^7' } }

  assert.deepEqual(unresolvablePeers([typed], {}), [])
})

// --- next major: the forecast ----------------------------------------------

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

test('recharts is locked once react reaches the end of its range', () => {
  const problems = splitLockedPeers([REACT_19, RECHARTS], [...SPLIT, { name: 'rest', patterns: ['recharts'], exclude: [] }])

  assert.equal(problems.length, 1)
  assert.match(problems[0], /a react 20 bump must carry recharts with it/)
})

test('a peer that is not a direct dependency is nobody’s group to fix', () => {
  assert.deepEqual(splitLockedPeers([TESTING_LIBRARY], SPLIT), [])
})

test('an exemption whose peer edge is gone is stale; one not yet locked is not', () => {
  const exempt = { 'i18next -> typescript': 'a reason' }
  const stillDeclared = [{ name: 'i18next', version: '26.3.6', peers: { typescript: '^5 || ^6 || ^7' } }]
  const dropped = [{ name: 'i18next', version: '27.0.0', peers: {} }]

  assert.deepEqual(staleExemptions(stillDeclared, exempt), [])
  assert.deepEqual(staleExemptions(dropped, exempt), ['i18next -> typescript'])
  assert.deepEqual(staleExemptions([], exempt), [])
})

// --- the runtime ------------------------------------------------------------

test('#619: a dependency whose Node floor is above the one CI runs', () => {
  const jsdom = { name: 'jsdom', version: '30.0.1', peers: {}, engines: '^22.22.2 || ^24.15.0 || >=26.0.0' }

  assert.deepEqual(engineViolations([jsdom], ['22.9999.9999']), [])

  const under20 = engineViolations([jsdom], ['20.9999.9999'])
  assert.equal(under20.length, 1)
  assert.match(under20[0], /jsdom@30\.0\.1 needs Node "\^22\.22\.2 \|\| \^24\.15\.0 \|\| >=26\.0\.0"/)
  assert.match(under20[0], /CI runs 20\.x/)
})

test('a dependency that declares no engines makes no claim', () => {
  assert.deepEqual(engineViolations([{ name: 'react', version: '18.3.1', peers: {} }], ['20.9999.9999']), [])
})

test('every Node the workflow sets up is checked, not just the first', () => {
  const violations = engineViolations(
    [{ name: 'orval', version: '8.24.0', peers: {}, engines: '>=22.18.0' }],
    ['22.9999.9999', '20.9999.9999'],
  )

  assert.equal(violations.length, 1)
  assert.match(violations[0], /CI runs 20\.x/)
})

test('ciNodeVersions reads both a literal pin and one held in env', () => {
  assert.deepEqual(ciNodeVersions("      - uses: actions/setup-node@v7\n        with:\n          node-version: '20'"), [
    '20.9999.9999',
  ])
  assert.deepEqual(
    ciNodeVersions("env:\n  NODE_VERSION: '22'\n\n          node-version: ${{ env.NODE_VERSION }}"),
    ['22.9999.9999'],
  )
  assert.deepEqual(ciNodeVersions("node-version: '22.22.2'"), ['22.22.2'])
})

// --- the pieces underneath --------------------------------------------------

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

test('satisfies covers the range grammar peer declarations use', () => {
  assert.equal(satisfies('19.2.8', '^18.0.0'), false)
  assert.equal(satisfies('18.3.1', '^18.0.0'), true)
  assert.equal(satisfies('7.0.2', '^5 || ^6 || ^7'), true)
  assert.equal(satisfies('8.0.0', '^5 || ^6 || ^7'), false)
  assert.equal(satisfies('19.0.0', '>= 16.8.0'), true)
  assert.equal(satisfies('16.3.0', '>= 16.8.0'), false)
  assert.equal(satisfies('19.2.8', '>=18'), true)
  assert.equal(satisfies('4.1.11', '4.1.11'), true)
  assert.equal(satisfies('4.1.12', '4.1.11'), false)
  assert.equal(satisfies('1.5.0', '>=1.0.0 <2.0.0'), true)
  assert.equal(satisfies('2.0.0', '>=1.0.0 <2.0.0'), false)
  assert.equal(satisfies('0.2.9', '^0.2.3'), true)
  assert.equal(satisfies('0.3.0', '^0.2.3'), false)
  assert.equal(satisfies('30.0.1', '*'), true)
})

test('installedVersions keys every package by its path in the tree', () => {
  const map = installedVersions({
    packages: {
      '': { name: 'root' },
      'node_modules/react': { version: '19.2.8' },
      'node_modules/@testing-library/react/node_modules/react': { version: '18.3.1' },
    },
  })

  assert.deepEqual(map, {
    'node_modules/react': '19.2.8',
    'node_modules/@testing-library/react/node_modules/react': '18.3.1',
  })
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

// --- the committed repository ------------------------------------------------

test('every exemption carries a reason worth reading', () => {
  for (const [pair, reason] of Object.entries(UNGROUPED_PEERS)) {
    assert.match(pair, /^\S+ -> \S+$/, `"${pair}" is not a "<dependent> -> <dependency>" pair`)
    assert.ok(reason.length > 40, `the reason for "${pair}" is too short to be a reason`)
  }
})

test('a pubspec yields its direct dependencies and not the SDK’s', () => {
  const pubspec = [
    'name: clubbar_terminal',
    'version: 1.0.0+1',
    '',
    'environment:',
    '  sdk: ^3.10.8',
    '',
    'dependencies:',
    '  flutter:',
    '    sdk: flutter',
    '  # Database & ORM',
    '  drift: ^2.32.0',
    '  collection: any',
    '',
    'dev_dependencies:',
    '  flutter_test:',
    '    sdk: flutter',
    '  build_runner: ^2.13.1',
    '',
    'flutter:',
    '  uses-material-design: true',
    '  assets:',
    '    - assets/icons/products/',
    '  fonts:',
    '    - family: JetBrains Mono',
    '      fonts:',
    '        - asset: assets/fonts/JetBrainsMono-Medium.ttf',
  ].join('\n')

  // `flutter` and `flutter_test` come with the SDK, and `environment.sdk`,
  // the asset list and the font tree are not dependencies at all.
  assert.deepEqual(pubDirectDependencies(pubspec), ['drift', 'collection', 'build_runner'])
})

test('a pub package every group misses, and one two groups claim, are both reported', () => {
  const groups = [
    { name: 'drift', patterns: ['drift', 'drift_dev'], exclude: [] },
    { name: 'codegen', patterns: ['drift*'], exclude: [] },
    { name: 'catch-all', patterns: ['*'], exclude: ['drift', 'drift_dev', 'drift*'] },
  ]

  assert.deepEqual(pubGroupCoverage(['http'], groups), [])
  assert.deepEqual(pubGroupCoverage(['drift'], groups), [
    'drift is claimed by 2 groups (drift, codegen) — exactly one may',
  ])
  assert.deepEqual(pubGroupCoverage(['logger'], [{ name: 'drift', patterns: ['drift'], exclude: [] }]), [
    "logger is claimed by no group — add it to one, or to the catch-all's reach",
  ])
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
      const lockfile = JSON.parse(readFileSync(resolve(root, 'package-lock.json'), 'utf8'))
      const directs = directDependencies(JSON.parse(readFileSync(resolve(root, 'package.json'), 'utf8')), lockfile)

      assert.ok(directs.length > 0, `${directory} declares no direct dependencies`)
      assert.deepEqual(unresolvablePeers(directs, installedVersions(lockfile)), [])
      assert.deepEqual(
        engineViolations(directs, ciNodeVersions(readFileSync(resolve(REPO, '.github/workflows/build.yaml'), 'utf8'))),
        [],
      )
      assert.deepEqual(splitLockedPeers(directs, entry.groups), [])
      assert.deepEqual(staleExemptions(directs), [])
    }
  }

  // #649: the terminal had no entry at all, so 62 of its packages went stale
  // unseen. Assert the entry exists as well as that it covers the tree —
  // deleting it would otherwise make this loop vacuously green.
  const pub = pubEntries(config)

  assert.equal(pub.length, 1)
  assert.deepEqual(pub[0].directories, ['/terminal-frontend'])

  for (const entry of pub) {
    assert.deepEqual(catchAllDrift(entry.groups), [])

    for (const directory of entry.directories) {
      const directs = pubDirectDependencies(readFileSync(resolve(REPO, `.${directory}`, 'pubspec.yaml'), 'utf8'))

      assert.ok(directs.length > 0, `${directory} declares no direct dependencies`)
      assert.deepEqual(pubGroupCoverage(directs, entry.groups), [])
    }
  }
})
