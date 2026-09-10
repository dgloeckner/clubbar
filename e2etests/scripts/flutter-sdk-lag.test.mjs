/**
 * Tests for the Flutter SDK lag check.
 *
 * The check opens and closes issues unattended, so the cases that matter are
 * the ones where a wrong answer is either noise (an issue for a pin that is
 * current, a comment every Monday saying the same thing) or silence (a pin
 * below the lockfile's floor read as "current", a feed with no stable release
 * read as "nothing to do"). Each has a case below.
 *
 * The releases fixture is shaped after the real feed on 2026-09-10: stable at
 * 3.47.3 with a beta ahead of it, older stables behind, and `current_release`
 * naming the stable by hash rather than by version.
 */

import { test } from 'node:test'
import assert from 'node:assert/strict'

import {
  ISSUE_TITLE,
  assess,
  compareVersions,
  issueBody,
  latestStable,
  lockfileFloor,
  marker,
  pinnedVersion,
  runCheck,
} from './flutter-sdk-lag.mjs'

const BUILD_YAML = `
env:
  NODE_VERSION: '22'

  # The SDK that compiles the POS binary shipped to clubs.
  FLUTTER_VERSION: '3.47.1'

jobs:
  changes:
`

const PUBSPEC_LOCK = `
packages:
  yaml:
    dependency: transitive
    version: "3.1.3"
sdks:
  dart: ">=3.12.0 <4.0.0"
  flutter: ">=3.44.0"
`

const release = (version, channel, hash, date) => ({
  hash,
  channel,
  version,
  dart_sdk_version: '3.13.3',
  release_date: date,
})

const RELEASES = {
  current_release: {
    beta: 'beta-hash',
    dev: 'dev-hash',
    stable: 'stable-hash',
  },
  releases: [
    release('3.47.3', 'stable', 'stable-hash', '2026-09-09T18:13:37Z'),
    release('3.48.0-0.4.pre', 'beta', 'beta-hash', '2026-09-03T17:45:16Z'),
    release('3.47.2', 'stable', 'older-hash', '2026-08-27T17:48:28Z'),
    release('3.47.1', 'stable', 'pinned-hash', '2026-08-13T17:00:00Z'),
  ],
}

// ---------------------------------------------------------------------------
// Reading the inputs
// ---------------------------------------------------------------------------

test('pinnedVersion reads FLUTTER_VERSION out of the workflow env block', () => {
  assert.equal(pinnedVersion(BUILD_YAML), '3.47.1')
  assert.equal(pinnedVersion(`env:\n  FLUTTER_VERSION: "3.50.0"\n`), '3.50.0')
  assert.equal(pinnedVersion(`env:\n  FLUTTER_VERSION: 3.50.0\n`), '3.50.0')
})

test('pinnedVersion refuses a workflow with no pin, or a floating branch', () => {
  assert.throws(() => pinnedVersion('env:\n  NODE_VERSION: 22\n'), /FLUTTER_VERSION/)
  // The whole point of #648 was to stop cloning `stable`; a check that read
  // that as a version would be worse than none.
  assert.throws(() => pinnedVersion("env:\n  FLUTTER_VERSION: 'stable'\n"), /not a version/)
})

test('lockfileFloor reads the flutter constraint under sdks:', () => {
  assert.equal(lockfileFloor(PUBSPEC_LOCK), '3.44.0')
  assert.equal(lockfileFloor('packages: {}\n'), null)
})

test('latestStable is the release current_release.stable names', () => {
  const latest = latestStable(RELEASES)
  assert.equal(latest.version, '3.47.3')
  assert.equal(latest.releaseDate, '2026-09-09T18:13:37Z')
  assert.equal(latest.dartSdkVersion, '3.13.3')
})

test('latestStable never answers with a beta, even when it is the newest entry', () => {
  // A feed whose stable pointer is missing falls back to the highest stable
  // version — and the beta above it must not win on recency.
  const noPointer = { releases: RELEASES.releases }
  assert.equal(latestStable(noPointer).version, '3.47.3')
})

test('latestStable refuses a feed with no stable release rather than reporting "current"', () => {
  assert.throws(() => latestStable({ releases: [RELEASES.releases[1]] }), /no stable release/)
  assert.throws(() => latestStable({}), /no stable release/)
})

// ---------------------------------------------------------------------------
// Versions
// ---------------------------------------------------------------------------

test('compareVersions is numeric per component, not lexical', () => {
  assert.ok(compareVersions('3.47.10', '3.47.9') > 0)
  assert.ok(compareVersions('3.47.1', '3.47.3') < 0)
  assert.equal(compareVersions('3.47.3', '3.47.3'), 0)
  assert.ok(compareVersions('3.47', '3.47.0') === 0)
  assert.ok(compareVersions('4.0.0', '3.99.99') > 0)
})

// ---------------------------------------------------------------------------
// The verdict
// ---------------------------------------------------------------------------

test('a pin at the latest stable is current', () => {
  const verdict = assess({
    pinned: '3.47.3',
    latest: latestStable(RELEASES),
    floor: '3.44.0',
  })
  assert.equal(verdict.status, 'current')
})

test('a pin behind stable is lagging', () => {
  const verdict = assess({
    pinned: '3.47.1',
    latest: latestStable(RELEASES),
    floor: '3.44.0',
  })
  assert.equal(verdict.status, 'lagging')
  assert.equal(verdict.pinned, '3.47.1')
  assert.equal(verdict.latest.version, '3.47.3')
})

test('a pin ahead of the feed is current, not lagging (the feed can trail a fresh bump)', () => {
  const verdict = assess({
    pinned: '3.48.0',
    latest: latestStable(RELEASES),
    floor: '3.44.0',
  })
  assert.equal(verdict.status, 'current')
})

test('a pin below the lockfile floor is below-floor, whatever stable says', () => {
  // `flutter pub get` refuses this outright, so CI is already red or about to
  // be; the check must say so rather than file it under "lagging".
  const verdict = assess({
    pinned: '3.47.3',
    latest: latestStable(RELEASES),
    floor: '3.48.0',
  })
  assert.equal(verdict.status, 'below-floor')
  assert.equal(verdict.floor, '3.48.0')
})

test('no lockfile floor is not an error', () => {
  const verdict = assess({
    pinned: '3.47.3',
    latest: latestStable(RELEASES),
    floor: null,
  })
  assert.equal(verdict.status, 'current')
})

// ---------------------------------------------------------------------------
// What the issue says
// ---------------------------------------------------------------------------

test('the issue body names both versions, the floor, and where to bump', () => {
  const verdict = assess({
    pinned: '3.47.1',
    latest: latestStable(RELEASES),
    floor: '3.44.0',
  })
  const body = issueBody(verdict)
  assert.match(body, /3\.47\.1/)
  assert.match(body, /3\.47\.3/)
  assert.match(body, /3\.44\.0/)
  assert.match(body, /FLUTTER_VERSION/)
  assert.match(body, /build\.yaml/)
  assert.match(body, /2026-09-09/)
  assert.ok(body.includes(marker('3.47.3')), 'the body carries the marker for this stable')
  assert.ok(body.trimEnd().endsWith('_Generated by [Claude Code](https://claude.ai/code)_'))
})

test('a below-floor body says CI cannot resolve, not merely that it is behind', () => {
  const verdict = assess({
    pinned: '3.47.3',
    latest: latestStable(RELEASES),
    floor: '3.48.0',
  })
  assert.match(issueBody(verdict), /pubspec\.lock/)
  assert.match(issueBody(verdict), /below/)
})

// ---------------------------------------------------------------------------
// Driving the tracker
// ---------------------------------------------------------------------------

/** A fake tracker that records what the check did to it. */
function tracker({ open = null } = {}) {
  const calls = []
  return {
    calls,
    findOpenIssue: async (title) => {
      calls.push(['find', title])
      return open
    },
    createIssue: async (title, body, labels) => {
      calls.push(['create', title, body, labels])
      return 901
    },
    commentOnce: async (number, mark, body) => {
      calls.push(['comment', number, mark, body])
      return !(open?.comments ?? []).some((text) => text.includes(mark))
    },
    closeIssue: async (number, comment) => {
      calls.push(['close', number, comment])
    },
  }
}

const inputs = (buildYaml = BUILD_YAML) => ({
  buildYaml,
  pubspecLock: PUBSPEC_LOCK,
  releases: RELEASES,
})

test('lagging with nothing open: one issue, with the labels a triaged issue carries', async () => {
  const api = tracker()
  const result = await runCheck(api, inputs())

  assert.equal(result.status, 'lagging')
  assert.equal(result.action, 'opened')
  const create = api.calls.find(([kind]) => kind === 'create')
  assert.ok(create, 'an issue was created')
  assert.equal(create[1], ISSUE_TITLE)
  assert.match(create[2], /3\.47\.3/)
  assert.deepEqual(create[3], ['dependencies', 'terminal-frontend', 'ready-for-agent', 'priority: low'])
  assert.ok(!api.calls.some(([kind]) => kind === 'close'))
})

test('lagging with an issue open for the same stable: says nothing', async () => {
  const api = tracker({
    open: { number: 901, body: `…${marker('3.47.3')}`, comments: [] },
  })
  const result = await runCheck(api, inputs())

  assert.equal(result.action, 'unchanged')
  assert.ok(!api.calls.some(([kind]) => kind === 'create'))
  assert.ok(!api.calls.some(([kind]) => kind === 'close'))
  // commentOnce was asked and declined, or not asked at all — either way no
  // new text landed. What matters is that the marker was the one checked.
  const comment = api.calls.find(([kind]) => kind === 'comment')
  if (comment) assert.equal(comment[2], marker('3.47.3'))
})

test('lagging with an issue open for an older stable: one comment naming the new one', async () => {
  const api = tracker({
    open: { number: 901, body: `…${marker('3.47.2')}`, comments: [] },
  })
  const result = await runCheck(api, inputs())

  assert.equal(result.action, 'commented')
  const comment = api.calls.find(([kind]) => kind === 'comment')
  assert.ok(comment)
  assert.equal(comment[1], 901)
  assert.equal(comment[2], marker('3.47.3'))
  assert.match(comment[3], /3\.47\.3/)
  assert.ok(!api.calls.some(([kind]) => kind === 'create'))
})

test('a comment for a stable already commented on is not repeated', async () => {
  const api = tracker({
    open: {
      number: 901,
      body: `…${marker('3.47.1')}`,
      comments: [`…${marker('3.47.3')}`],
    },
  })
  const result = await runCheck(api, inputs())
  assert.equal(result.action, 'unchanged')
})

test('current with an issue open: closes it', async () => {
  const api = tracker({
    open: { number: 901, body: `…${marker('3.47.3')}`, comments: [] },
  })
  const result = await runCheck(api, inputs(BUILD_YAML.replace('3.47.1', '3.47.3')))

  assert.equal(result.status, 'current')
  assert.equal(result.action, 'closed')
  const close = api.calls.find(([kind]) => kind === 'close')
  assert.equal(close[1], 901)
  assert.match(close[2], /3\.47\.3/)
})

test('current with nothing open: does nothing', async () => {
  const api = tracker()
  const result = await runCheck(api, inputs(BUILD_YAML.replace('3.47.1', '3.47.3')))
  assert.equal(result.action, 'none')
  assert.deepEqual(
    api.calls.filter(([kind]) => kind !== 'find'),
    []
  )
})

test('below-floor opens the same issue, so there is never more than one', async () => {
  const api = tracker()
  const result = await runCheck(api, {
    ...inputs(BUILD_YAML.replace('3.47.1', '3.47.3')),
    pubspecLock: PUBSPEC_LOCK.replace('3.44.0', '3.48.0'),
  })
  assert.equal(result.status, 'below-floor')
  assert.equal(result.action, 'opened')
  const create = api.calls.find(([kind]) => kind === 'create')
  assert.equal(create[1], ISSUE_TITLE)
  assert.match(create[2], /3\.48\.0/)
})

test('dry run reads everything and touches nothing', async () => {
  const api = tracker()
  const result = await runCheck(api, { ...inputs(), dryRun: true })
  assert.equal(result.status, 'lagging')
  assert.equal(result.action, 'opened')
  assert.deepEqual(
    api.calls.filter(([kind]) => kind !== 'find'),
    []
  )
  assert.match(result.summary, /dry run/i)
})

test('the summary reads as a sentence with the versions in it', async () => {
  const result = await runCheck(tracker(), inputs())
  assert.match(result.summary, /3\.47\.1/)
  assert.match(result.summary, /3\.47\.3/)
})
