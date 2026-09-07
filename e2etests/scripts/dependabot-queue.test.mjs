/**
 * Tests for the Dependabot merge queue's decision logic.
 *
 * This code merges to main unattended, so the interesting cases are the ones
 * where a wrong answer is expensive rather than annoying: reading a commit no
 * workflow ever ran as "green", letting a major through inside a group, or
 * merging a pull request whose build belongs to a commit that has since been
 * rebased away. Each has a case below.
 *
 * The fixtures are shaped after real data — #844 (a slim/slim security patch)
 * for the green shape, including the skipped path-filtered jobs and CodeQL's
 * neutral roll-up, which a naive "every check is success" rule would refuse
 * forever.
 */

import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

import {
  ALLOWED_UPDATE_TYPES,
  checksVerdict,
  chooseAction,
  classify,
  planQueue,
  resolveMergeability,
  runQueue,
  summaryMarkdown,
  updateTypesIn,
} from './dependabot-queue.mjs'

const commitMessage = (type = 'patch') => `Bump slim/slim from 4.15.2 to 4.15.3

---
updated-dependencies:
- dependency-name: slim/slim
  dependency-version: 4.15.3
  dependency-type: direct:production
  update-type: version-update:semver-${type}
  dependency-group: slim
...

Signed-off-by: dependabot[bot] <support@github.com>`

/** The check runs #844 carried when it was green, trimmed to the shapes that matter. */
const GREEN_CHECKS = [
  { name: 'changes', status: 'completed', conclusion: 'success' },
  { name: 'audit', status: 'completed', conclusion: 'success' },
  { name: 'test-backend', status: 'completed', conclusion: 'success' },
  { name: 'build-terminal', status: 'completed', conclusion: 'skipped' },
  { name: 'CodeQL', status: 'completed', conclusion: 'neutral' },
]

const pr = (overrides = {}) => ({
  number: 844,
  title: 'Bump slim/slim from 4.15.2 to 4.15.3',
  draft: false,
  user: 'dependabot[bot]',
  base: 'main',
  head: 'abc123',
  labels: ['dependencies', 'php'],
  ...overrides,
})

const details = (overrides = {}) => ({
  mergeable: true,
  mergeable_state: 'blocked',
  commitMessages: [commitMessage()],
  checkRuns: GREEN_CHECKS,
  statuses: [],
  behindBy: 0,
  ...overrides,
})

test('the update type comes from the commit trailer Dependabot writes', () => {
  assert.deepEqual(updateTypesIn([commitMessage('patch')]), ['patch'])
  assert.deepEqual(updateTypesIn([commitMessage('major')]), ['major'])
})

test('a group carries every update type its commits contain', () => {
  assert.deepEqual(updateTypesIn([commitMessage('minor'), commitMessage('major')]), ['minor', 'major'])
})

test('a commit with no trailer yields nothing', () => {
  assert.deepEqual(updateTypesIn(['Fix: reach the registrations inbox from a phone']), [])
})

test('skipped and neutral are green — path filters and CodeQL produce them on every run', () => {
  assert.equal(checksVerdict(GREEN_CHECKS).state, 'green')
})

test('a failed job is red even when everything around it passed', () => {
  const runs = [...GREEN_CHECKS, { name: 'test-e2e (ui, ui-1)', status: 'completed', conclusion: 'failure' }]
  const verdict = checksVerdict(runs)

  assert.equal(verdict.state, 'red')
  assert.match(verdict.reason, /test-e2e \(ui, ui-1\) \(failure\)/)
})

test('a cancelled or timed-out job is red, not green', () => {
  assert.equal(checksVerdict([...GREEN_CHECKS, { name: 'lint', status: 'completed', conclusion: 'cancelled' }]).state, 'red')
  assert.equal(checksVerdict([...GREEN_CHECKS, { name: 'lint', status: 'completed', conclusion: 'timed_out' }]).state, 'red')
})

test('an unfinished job is pending, and red still beats it', () => {
  const running = [...GREEN_CHECKS, { name: 'test-package', status: 'in_progress', conclusion: null }]
  assert.equal(checksVerdict(running).state, 'pending')

  const both = [...running, { name: 'lint', status: 'completed', conclusion: 'failure' }]
  assert.equal(checksVerdict(both).state, 'red')
})

test('a commit no build ever ran is pending, never green', () => {
  // The dangerous case: nothing is red because nothing ran at all. It happens
  // whenever a push is made by a token that cannot trigger workflows.
  const verdict = checksVerdict([])

  assert.equal(verdict.state, 'pending')
  assert.match(verdict.reason, /no Build run for this commit/)
})

test('a build missing only the ungated jobs is pending — a partial run is not a pass', () => {
  const partial = [{ name: 'test-backend', status: 'completed', conclusion: 'success' }]
  assert.equal(checksVerdict(partial).state, 'pending')
})

test('a failing commit status is red even with every check run green', () => {
  const verdict = checksVerdict(GREEN_CHECKS, [{ context: 'dco', state: 'failure' }])

  assert.equal(verdict.state, 'red')
  assert.match(verdict.reason, /dco \(failure\)/)
})

test('a green patch bump is ready to merge', () => {
  const row = classify(pr(), details())

  assert.equal(row.state, 'ready')
  assert.deepEqual(row.updateTypes, ['patch'])
})

test('a major is held, and so is a group that contains one', () => {
  assert.equal(classify(pr(), details({ commitMessages: [commitMessage('major')] })).state, 'held')

  const group = classify(pr(), details({ commitMessages: [commitMessage('patch'), commitMessage('major')] }))
  assert.equal(group.state, 'held')
  assert.match(group.reason, /major update/)
})

test('minor and patch are what merge unattended — nothing else', () => {
  assert.deepEqual(ALLOWED_UPDATE_TYPES, ['patch', 'minor'])
})

test('a blocking label holds a pull request no matter how green it is', () => {
  const row = classify(pr({ labels: ['dependencies', 'blocked-upstream'] }), details())

  assert.equal(row.state, 'held')
  assert.match(row.reason, /blocked-upstream/)
})

test('a conflicted pull request is held, and an uncomputed one waits', () => {
  assert.equal(classify(pr(), details({ mergeable: false })).state, 'held')
  assert.equal(classify(pr(), details({ mergeable: null })).state, 'waiting')
})

test('a red build is held even before GitHub has computed mergeability', () => {
  // The first live round reported #843 as "waiting" with red checks, because
  // the unknown mergeability was tested first. A red build is the reason it is
  // going nowhere; the summary has to say that.
  const row = classify(pr(), details({
    mergeable: null,
    checkRuns: [...GREEN_CHECKS, { name: 'lint', status: 'completed', conclusion: 'failure' }],
  }))

  assert.equal(row.state, 'held')
  assert.match(row.reason, /lint \(failure\)/)
})

test('mergeability is read again until GitHub answers', async () => {
  // Every merge to main invalidates it for every open pull request, and the
  // queue wakes on exactly that push: the first read is `null` by construction.
  const answers = [{ mergeable: null }, { mergeable: null }, { mergeable: true, mergeable_state: 'blocked' }]
  let reads = 0
  const slept = []

  const detail = await resolveMergeability(async () => answers[reads++], {
    delayMs: 5,
    sleep: async (ms) => slept.push(ms),
  })

  assert.equal(detail.mergeable, true)
  assert.equal(reads, 3)
  assert.deepEqual(slept, [5, 5])
})

test('an answer on the first read costs no wait at all', async () => {
  let reads = 0
  const detail = await resolveMergeability(async () => { reads++; return { mergeable: true } }, {
    sleep: async () => assert.fail('should not wait for an answer it already has'),
  })

  assert.equal(detail.mergeable, true)
  assert.equal(reads, 1)
})

test('giving up returns null, which waits — it never reads as mergeable', async () => {
  let reads = 0
  const detail = await resolveMergeability(async () => { reads++; return { mergeable: null } }, {
    attempts: 3,
    sleep: async () => {},
  })

  assert.equal(detail.mergeable, null)
  assert.equal(reads, 3)
  assert.equal(classify(pr(), details({ mergeable: detail.mergeable })).state, 'waiting')
})

test('a draft is held', () => {
  assert.equal(classify(pr({ draft: true }), details()).state, 'held')
})

test('a red build is held and a running one waits', () => {
  const red = [...GREEN_CHECKS, { name: 'lint', status: 'completed', conclusion: 'failure' }]
  assert.equal(classify(pr(), details({ checkRuns: red })).state, 'held')

  const running = [...GREEN_CHECKS, { name: 'lint', status: 'queued', conclusion: null }]
  assert.equal(classify(pr(), details({ checkRuns: running })).state, 'waiting')
})

test('strict mode holds a green build that predates the base tip; the default merges it', () => {
  const behind = details({ behindBy: 4 })

  assert.equal(classify(pr(), behind, { requireUpToDate: true }).state, 'behind')
  assert.equal(classify(pr(), behind).state, 'ready')
})

test('the first ready pull request is merged, and a held one does not block it', () => {
  // #709 has been red since August. A strict FIFO would let it stop every
  // security patch behind it from ever reaching production.
  const rows = [
    { number: 709, state: 'held', reason: 'red' },
    { number: 844, state: 'ready', reason: 'green' },
    { number: 845, state: 'ready', reason: 'green' },
  ]
  const action = chooseAction(rows)

  assert.equal(action.kind, 'merge')
  assert.equal(action.row.number, 844)
})

test('with nothing ready, the oldest out-of-date branch is updated instead', () => {
  const action = chooseAction([
    { number: 844, state: 'waiting', reason: 'building' },
    { number: 845, state: 'behind', reason: '2 commits behind' },
  ])

  assert.equal(action.kind, 'update')
  assert.equal(action.row.number, 845)
})

test('a run with nothing to do is idle, not an error', () => {
  assert.equal(chooseAction([]).kind, 'idle')
  assert.equal(chooseAction([{ number: 1, state: 'waiting', reason: 'building' }]).kind, 'idle')
})

test('only Dependabot pull requests against the base branch are considered, oldest first', async () => {
  const open = [
    pr({ number: 851, user: 'dgloeckner', title: 'A human pull request' }),
    pr({ number: 848 }),
    pr({ number: 840, base: 'release/1.x' }),
    pr({ number: 844 }),
  ]

  const { rows, action } = await planQueue(open, async () => details())

  assert.deepEqual(rows.map((row) => row.number), [844, 848])
  assert.equal(action.row.number, 844)
})

test('the summary names every pull request and the one action taken', () => {
  const rows = [
    { number: 709, title: 'eslint', updateTypes: ['major'], checks: 'red', behindBy: null, state: 'held', reason: 'red: lint (failure)' },
    { number: 844, title: 'slim/slim', updateTypes: ['patch'], checks: 'green', behindBy: 0, state: 'ready', reason: '25 checks green' },
  ]
  const markdown = summaryMarkdown(rows, chooseAction(rows))

  assert.match(markdown, /#709/)
  assert.match(markdown, /Merging \*\*#844\*\*/)
})

test('an empty queue says so rather than rendering an empty table', () => {
  assert.match(summaryMarkdown([], chooseAction([])), /No open Dependabot pull requests/)
})

// ---------------------------------------------------------------------------
// A whole round, against a fake GitHub. These cover the wiring rather than the
// policy: what the queue actually *calls* when it decides to merge, and what it
// does when GitHub says no — which is the path that runs unattended with
// `contents: write` and therefore the one worth pinning down.
// ---------------------------------------------------------------------------

function fakeApi({ open, detailsFor, mergeFails = null } = {}) {
  const calls = { approved: [], merged: [], updated: [], comments: [], dispatched: [] }
  const posted = new Set()

  return {
    calls,
    listOpenPulls: async () => open,
    details: async (pr) => detailsFor(pr),
    approve: async (number) => calls.approved.push(number),
    merge: async (number, sha) => {
      calls.merged.push({ number, sha })
      if (mergeFails) throw Object.assign(new Error('gh failed'), { stderr: mergeFails })
    },
    updateBranch: async (number) => calls.updated.push(number),
    commentOnce: async (number, mark, body) => {
      if (posted.has(mark)) return false
      posted.add(mark)
      calls.comments.push({ number, mark, body })
      return true
    },
    dispatchBuild: async (ref) => calls.dispatched.push(ref),
  }
}

const silent = () => {}

test('a round merges the first ready pull request, at the commit it verified', async () => {
  const api = fakeApi({ open: [pr({ number: 709 }), pr({ number: 844, head: 'deadbee' })], detailsFor: (p) => (p.number === 709 ? details({ checkRuns: [...GREEN_CHECKS, { name: 'lint', status: 'completed', conclusion: 'failure' }] }) : details()) })

  const result = await runQueue(api, { log: silent })

  assert.equal(result.outcome, 'merged')
  assert.deepEqual(api.calls.merged, [{ number: 844, sha: 'deadbee' }])
  // "blocked" is a required review, which the queue supplies before merging.
  assert.deepEqual(api.calls.approved, [844])
})

test('a dry run decides everything and does nothing', async () => {
  const api = fakeApi({ open: [pr()], detailsFor: () => details() })

  const result = await runQueue(api, { dryRun: true, log: silent })

  assert.equal(result.outcome, 'dry-run')
  assert.deepEqual(api.calls.merged, [])
  assert.deepEqual(api.calls.approved, [])
  assert.match(result.summary, /Would merge/)
})

test('nothing mergeable means nothing is touched', async () => {
  const api = fakeApi({ open: [pr()], detailsFor: () => details({ commitMessages: [commitMessage('major')] }) })

  const result = await runQueue(api, { log: silent })

  assert.equal(result.outcome, 'idle')
  assert.deepEqual(api.calls.merged, [])
})

test('a refused merge is explained on the pull request, once per commit', async () => {
  const refusal = 'HTTP 405: At least 1 approving review is required by reviewers with write access.'
  const open = [pr()]
  const api = fakeApi({ open, detailsFor: () => details(), mergeFails: refusal })

  const first = await runQueue(api, { log: silent })
  const second = await runQueue(api, { log: silent })

  assert.equal(first.outcome, 'refused')
  assert.equal(second.outcome, 'refused')
  assert.equal(api.calls.comments.length, 1, 'a run every half hour must not become a comment thread')
  assert.match(api.calls.comments[0].body, /approving review/)
  assert.match(api.calls.comments[0].body, /MERGE_QUEUE_TOKEN/)
  assert.match(first.summary, /refused/)
})

test('a GITHUB_TOKEN merge asks for the build its own push cannot start; a real token does not', async () => {
  const withFallback = fakeApi({ open: [pr()], detailsFor: () => details() })
  await runQueue(withFallback, { tokenKind: 'github-token', log: silent })
  assert.deepEqual(withFallback.calls.dispatched, ['main'])

  const withToken = fakeApi({ open: [pr()], detailsFor: () => details() })
  await runQueue(withToken, { tokenKind: 'merge-queue-token', log: silent })
  assert.deepEqual(withToken.calls.dispatched, [])
})

test('strict mode updates the branch instead of merging a build that predates the base', async () => {
  const api = fakeApi({ open: [pr()], detailsFor: () => details({ behindBy: 3 }) })

  const result = await runQueue(api, { requireUpToDate: true, log: silent })

  assert.equal(result.outcome, 'updated')
  assert.deepEqual(api.calls.updated, [844])
  assert.deepEqual(api.calls.merged, [])
})

// ---------------------------------------------------------------------------
// The workflow's wake-ups. This is a transcription test in the spirit of
// check-ci-lanes.mjs, and it exists because the failure it catches is silent:
// the queue's first two scheduled rounds (17:37 and 18:07) never ran, the
// workflow stayed `active`, no run went red, and eleven green pull requests
// simply sat there. Nothing about a queue that has stopped looks broken, so the
// property worth pinning is that the cron is not its only clock.
// ---------------------------------------------------------------------------

const WORKFLOW = readFileSync(
  resolve(dirname(fileURLToPath(import.meta.url)), '..', '..', '.github', 'workflows', 'dependabot-merge-queue.yaml'),
  'utf8',
)

test('a merge to the base branch wakes the queue, so it never waits only on cron', () => {
  assert.match(WORKFLOW, /push:\s*\n\s*branches:\s*\n\s*- main/)
})

test('the queue wakes for a Build on main as well as on a Dependabot branch', () => {
  // main is how it chains: its own merge push is made with GITHUB_TOKEN and
  // starts nothing, so the Build it dispatches is the only signal that a merge
  // happened. Drop this and the queue merges once per cron round and no faster.
  const condition = WORKFLOW.match(/if: >-\n([\s\S]*?)\n {4}runs-on:/)[1]

  assert.match(condition, /startsWith\(github\.event\.workflow_run\.head_branch, 'dependabot\/'\)/)
  assert.match(condition, /github\.event\.workflow_run\.head_branch == 'main'/)
})

test('the cron is still there as the floor under both', () => {
  assert.match(WORKFLOW, /schedule:\s*\n\s*- cron: '[^']+'/)
})

test('the queue reaches for the App token first, the PAT second, GITHUB_TOKEN last', () => {
  // The order is the whole point: only the App keeps the automation
  // distinguishable from a person and its pushes able to start a build. Losing
  // a rung would silently demote every merge to a worse identity.
  const token = WORKFLOW.match(/GH_TOKEN: (.+)/)[1]

  assert.match(token, /env\.MERGE_QUEUE_GH_TOKEN \|\| secrets\.MERGE_QUEUE_TOKEN \|\| github\.token/)
})
