#!/usr/bin/env node
/**
 * A merge queue for Dependabot pull requests: merge the green ones, one at a
 * time, without a human clicking anything.
 *
 * Why this exists. Dependency updates are the one class of pull request whose
 * *review* is the build. A patch bump of slim/slim either passes 318 API specs,
 * 93 E2E specs, the backend suite, lint, typecheck, patch coverage, CodeQL and
 * the OSV scan on that exact commit, or it does not — and a human reading the
 * diff of a lockfile learns nothing the suite did not already say. What the
 * human does contribute is latency: on the morning this was written thirteen
 * pull requests were open, eleven of them Dependabot's, one of them a *security*
 * release of Slim (GHSA-h377-p8x2-prf9), green since 03:10 and unmerged. That is
 * the failure this closes. Waiting also costs correctness: an unmerged bump
 * drifts behind main until it conflicts, and a conflicted lockfile is work
 * nobody was going to schedule.
 *
 * What it will not do:
 *
 *   * **Majors.** `.github/dependabot.yml` is built so that a major arrives as
 *     its own pull request precisely because somebody should read it —
 *     react-router 6 -> 7 (#578) is not a lockfile diff. ALLOWED_UPDATE_TYPES
 *     is the whole policy, and it is one line.
 *   * **Anything labelled.** `blocked-upstream` (an unsatisfiable peer range,
 *     #620) and `do-not-merge` hold a pull request here indefinitely, which is
 *     the manual override.
 *   * **Anything red, pending, conflicted or draft.** A skipped job is not a
 *     failure and a missing job is not a pass — see checksVerdict below.
 *
 * Why one merge per run, rather than draining the whole list. The moment one
 * lands, every other pull request's green refers to a main that no longer
 * exists. Serialising is what makes this a queue instead of a batch: each run
 * re-reads the world, so the second merge is judged against the first. The
 * cost is a merge every half hour, which for eleven pull requests a week is
 * comfortably faster than a human.
 *
 * The run is driven by .github/workflows/dependabot-merge-queue.yaml, which
 * also documents the one setting that decides *how* the merge lands: with a
 * `MERGE_QUEUE_TOKEN` it behaves like a person clicking Merge, and without one
 * GITHUB_TOKEN cannot start the main build its own push should have started.
 *
 * Run it by hand against the live repository. With DRY_RUN it reads, decides
 * and prints, and touches nothing:
 *
 *     GITHUB_REPOSITORY=dgloeckner/clubbar DRY_RUN=true node e2etests/scripts/dependabot-queue.mjs
 */

import { execFileSync } from 'node:child_process'
import { appendFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

/** Only this author's pull requests are ever touched. */
export const DEPENDABOT = 'dependabot[bot]'

/**
 * Bumps that may merge unattended. A group carries the *highest* update type it
 * contains, so one major in a group of eight holds the whole group for review —
 * which is the conservative direction and the one #594 argues for.
 */
export const ALLOWED_UPDATE_TYPES = ['patch', 'minor']

/** Any of these on a pull request means a human has taken it off the queue. */
export const BLOCKING_LABELS = ['blocked-upstream', 'do-not-merge']

/**
 * The two jobs in build.yaml that are *not* gated on paths: `changes` computes
 * the gates and `audit` is deliberately ungated ("a known advisory is a build
 * failure, not a notification"). So their presence on a commit is the evidence
 * that the Build workflow actually ran for it. Without this the queue would
 * read a commit no workflow ever touched — zero checks, nothing red — as green,
 * which is the one way an automated merge can be badly wrong.
 */
export const REQUIRED_CHECKS = ['changes', 'audit']

/**
 * `skipped` is the normal outcome of build.yaml's path filters: a composer bump
 * skips every terminal job. `neutral` is what CodeQL's roll-up check reports.
 * Neither is a failure, and treating them as one would mean nothing ever merges.
 */
export const PASSING_CONCLUSIONS = ['success', 'skipped', 'neutral']

/** Hidden marker so a repeated run recognises its own comment instead of adding another. */
export const marker = (kind, sha) => `<!-- merge-queue:${kind}:${sha} -->`

/**
 * The `update-type:` trailers Dependabot writes into every commit it authors.
 * This is the same metadata dependabot/fetch-metadata reads; parsing the commit
 * means the queue works on a schedule, where no pull_request event exists.
 *
 * @returns {string[]} 'patch' | 'minor' | 'major', deduplicated.
 */
export function updateTypesIn(commitMessages) {
  const types = new Set()
  for (const message of commitMessages ?? []) {
    for (const match of String(message).matchAll(/update-type:\s*version-update:semver-(patch|minor|major)/g)) {
      types.add(match[1])
    }
  }
  return [...types]
}

/**
 * Is this commit green? Red beats pending beats missing beats green, so a run
 * that is still going never hides one that already failed.
 *
 * @returns {{state: 'red'|'pending'|'green', reason: string}}
 */
export function checksVerdict(checkRuns = [], statuses = [], required = REQUIRED_CHECKS) {
  const red = []
  const pending = []

  for (const run of checkRuns) {
    if (run.status !== 'completed') pending.push(run.name)
    else if (!PASSING_CONCLUSIONS.includes(run.conclusion)) red.push(`${run.name} (${run.conclusion})`)
  }

  // Commit statuses rather than check runs: nothing posts them today, but a
  // deployment or a DCO app would, and a queue that cannot see them would merge
  // over them.
  for (const status of statuses) {
    if (status.state === 'pending') pending.push(status.context)
    else if (status.state !== 'success') red.push(`${status.context} (${status.state})`)
  }

  if (red.length > 0) return { state: 'red', reason: `red: ${red.join(', ')}` }
  if (pending.length > 0) return { state: 'pending', reason: `still running: ${pending.slice(0, 3).join(', ')}` }

  const reported = new Set([...checkRuns.map((r) => r.name), ...statuses.map((s) => s.context)])
  const missing = required.filter((name) => !reported.has(name))
  if (missing.length > 0) {
    return { state: 'pending', reason: `no Build run for this commit (${missing.join(', ')} never reported)` }
  }

  return { state: 'green', reason: `${reported.size} checks green` }
}

/**
 * One pull request's place in the queue.
 *
 *   ready   — merge it now
 *   behind  — green, but its build predates main's tip (strict mode only)
 *   waiting — the build has not finished, or GitHub is still computing the merge
 *   held    — needs a person: red, major, conflicted, labelled, draft
 */
export function classify(pr, details, options = {}) {
  const {
    allowedUpdateTypes = ALLOWED_UPDATE_TYPES,
    blockingLabels = BLOCKING_LABELS,
    requiredChecks = REQUIRED_CHECKS,
    requireUpToDate = false,
  } = options

  const updateTypes = updateTypesIn(details.commitMessages)
  const checks = checksVerdict(details.checkRuns, details.statuses, requiredChecks)
  const row = {
    number: pr.number,
    title: pr.title,
    updateTypes,
    checks: checks.state,
    behindBy: details.behindBy ?? null,
  }
  const held = (reason) => ({ ...row, state: 'held', reason })

  if (pr.draft) return held('draft')

  const blocking = (pr.labels ?? []).filter((label) => blockingLabels.includes(label))
  if (blocking.length > 0) return held(`labelled ${blocking.join(', ')}`)

  if (updateTypes.length === 0) return held('no update-type trailer — not a Dependabot version bump')

  const disallowed = updateTypes.filter((type) => !allowedUpdateTypes.includes(type))
  if (disallowed.length > 0) return held(`${disallowed.join('/')} update — a person reviews these`)

  if (details.mergeable === false) return held('conflicts with the base branch')

  // Red before unknown-mergeability, deliberately. A failing build is the
  // reason this pull request is going nowhere whether or not GitHub has
  // finished computing whether it *could* merge, and the summary should say so
  // — the first live round reported #843 as "waiting" when its build was red.
  if (checks.state === 'red') return held(checks.reason)

  if (details.mergeable !== true) return { ...row, state: 'waiting', reason: 'mergeability not computed yet' }
  if (checks.state === 'pending') return { ...row, state: 'waiting', reason: checks.reason }

  if (requireUpToDate && (details.behindBy ?? 0) > 0) {
    return { ...row, state: 'behind', reason: `${details.behindBy} commits behind the base branch` }
  }

  return { ...row, state: 'ready', reason: checks.reason }
}

/**
 * What this run does. At most one thing: a merge if anything is ready, else a
 * branch update if anything is only out of date, else nothing.
 *
 * A held pull request never blocks the ones behind it — a red eslint bump that
 * has sat open since August would otherwise stop every patch release reaching
 * production. That is the one way this differs from a strict FIFO, and it is
 * deliberate: the queue is ordered, not blocking.
 */
export function chooseAction(rows) {
  const ready = rows.find((row) => row.state === 'ready')
  if (ready) return { kind: 'merge', row: ready }

  const behind = rows.find((row) => row.state === 'behind')
  if (behind) return { kind: 'update', row: behind }

  return { kind: 'idle', row: null }
}

/**
 * How many times to ask GitHub whether a pull request is mergeable, and how
 * long to wait between asks.
 *
 * `mergeable` is computed lazily: the first read after the base branch moves
 * returns `null` and *starts* the computation, which lands a second or two
 * later. Every merge to main therefore invalidates all of them at once — which
 * is exactly when the queue wakes. The first live round read `null` for nine
 * pull requests and did nothing at all; without this it can only ever act on
 * the round after the one it was woken for.
 */
export const MERGEABILITY_ATTEMPTS = 6
export const MERGEABILITY_DELAY_MS = 2000

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

/**
 * Read until GitHub has an answer, then stop. Giving up returns the last
 * `null`, which classifies as `waiting` — never as mergeable.
 */
export async function resolveMergeability(read, options = {}) {
  const { attempts = MERGEABILITY_ATTEMPTS, delayMs = MERGEABILITY_DELAY_MS, sleep = wait } = options

  let detail = await read()
  for (let attempt = 1; attempt < attempts && detail?.mergeable === null; attempt++) {
    await sleep(delayMs)
    detail = await read()
  }
  return detail
}

/** Classify every candidate, oldest first, then decide. `getDetails` is async. */
export async function planQueue(prs, getDetails, options = {}) {
  const candidates = prs
    .filter((pr) => pr.user === DEPENDABOT && pr.base === (options.baseBranch ?? 'main'))
    .sort((a, b) => a.number - b.number)

  const rows = []
  for (const pr of candidates) rows.push(classify(pr, await getDetails(pr), options))

  return { rows, action: chooseAction(rows) }
}

const ICONS = { ready: '✅', behind: '⏪', waiting: '⏳', held: '🚫' }

/** The job summary: the whole queue, in the order it will be worked. */
export function summaryMarkdown(rows, action, { dryRun = false } = {}) {
  const lines = ['## Dependabot merge queue', '']

  if (rows.length === 0) {
    lines.push('No open Dependabot pull requests.')
    return lines.join('\n')
  }

  lines.push('| PR | Update | Checks | State | Why |', '| --- | --- | --- | --- | --- |')
  for (const row of rows) {
    const types = row.updateTypes.length > 0 ? row.updateTypes.join(', ') : '—'
    lines.push(
      `| #${row.number} | ${types} | ${row.checks} | ${ICONS[row.state]} ${row.state} | ${row.reason} |`,
    )
  }

  lines.push('')
  if (action.kind === 'merge') lines.push(`${dryRun ? 'Would merge' : 'Merging'} **#${action.row.number}** — ${action.row.title}`)
  else if (action.kind === 'update') lines.push(`${dryRun ? 'Would update' : 'Updating'} **#${action.row.number}** onto the base branch first.`)
  else lines.push('Nothing to merge this round.')

  return lines.join('\n')
}

// ---------------------------------------------------------------------------
// The run itself. Everything GitHub-shaped goes through the `api` object below,
// so e2etests/scripts/dependabot-queue.test.mjs can drive a whole round —
// including a refused merge — without a network or a repository.
// ---------------------------------------------------------------------------

/**
 * One round of the queue: read the world, decide, do at most one thing.
 *
 * @returns {Promise<{rows: object[], action: object, summary: string, outcome: string}>}
 *   outcome is 'idle' | 'dry-run' | 'merged' | 'updated' | 'refused'.
 */
export async function runQueue(api, options = {}) {
  const { baseBranch = 'main', dryRun = false, requireUpToDate = false, tokenKind = null, log = console.log } = options

  const open = await api.listOpenPulls()

  // Keep what the decision was made on, so the steps below read the same world
  // the classification did rather than asking GitHub a second, different time.
  const seen = new Map()
  const { rows, action } = await planQueue(
    open,
    async (pr) => {
      const details = await api.details(pr)
      seen.set(pr.number, details)
      return details
    },
    { baseBranch, requireUpToDate },
  )

  const summary = summaryMarkdown(rows, action, { dryRun })
  const done = (outcome) => ({ rows, action, summary, outcome })

  if (action.kind === 'idle') return done('idle')
  if (dryRun) return done('dry-run')

  if (action.kind === 'update') {
    const pr = open.find((candidate) => candidate.number === action.row.number)

    // Green, but its build predates the base tip. Bring the branch forward; the
    // push is what re-runs the build, and the next round judges the result.
    await api.updateBranch(pr.number)
    log(`Requested a base update for #${pr.number}.`)
    return done('updated')
  }

  // Try each ready pull request in turn, stopping at the first that merges.
  //
  // A refusal must never block the ones behind it. #845 bumps the versions in
  // `.github/workflows/**`, which a GitHub App may not write without the
  // Workflows permission, so GitHub answers `Repository rule violations found`
  // — every round, forever. Returning there left #846, #847 and #848 queued
  // behind a pull request that could never merge, which is the same mistake as
  // letting a red one block the queue, arriving by a different door.
  const refusals = []
  let merged = null

  for (const row of rows.filter((candidate) => candidate.state === 'ready')) {
    const pr = open.find((candidate) => candidate.number === row.number)

    // A required review is the usual reason `mergeable_state` is "blocked"
    // here, and approving is the queue repeating what the build already said.
    // It can only happen to a pull request that has passed every gate above.
    if (seen.get(pr.number)?.mergeable_state === 'blocked') {
      try {
        await api.approve(pr.number)
      } catch (error) {
        log(`Could not approve #${pr.number}: ${messageOf(error)}`)
      }
    }

    try {
      // The merge is conditional on the commit this round actually read: if
      // Dependabot rebased while the queue was thinking, GitHub refuses rather
      // than merging a commit nothing verified.
      await api.merge(pr.number, pr.head)
      merged = pr
      break
    } catch (error) {
      const detail = messageOf(error)
      log(`Merge of #${pr.number} was refused: ${detail}`)
      await api.commentOnce(pr.number, marker('refused', pr.head), refusalComment(detail, pr.head))
      refusals.push(`> Merge of #${pr.number} was refused by GitHub: \`${detail.split('\n')[0]}\``)
    }
  }

  const withRefusals = (outcome) => ({
    ...done(outcome),
    summary: refusals.length > 0 ? `${summary}\n\n${refusals.join('\n')}` : summary,
  })

  if (!merged) return withRefusals('refused')

  const pr = merged
  log(`Merged #${pr.number}.`)

  // A push made with GITHUB_TOKEN starts no workflow run, so the merge that
  // just landed would never be built or deployed. Dispatch is the documented
  // exception to that rule, so ask for the run the push should have produced.
  // A real token's push behaves like a person's, and dispatching then would
  // only duplicate it.
  if (tokenKind === 'github-token') {
    try {
      await api.dispatchBuild(baseBranch)
      log(`Dispatched Build on ${baseBranch} — a GITHUB_TOKEN push does not trigger it.`)
    } catch (error) {
      log(`Could not dispatch Build: ${messageOf(error)}`)
    }
  }

  return withRefusals('merged')
}

/** `gh` puts the useful half of a failure on stderr, where an Error hides it. */
export function messageOf(error) {
  return String(error?.stderr ?? error?.message ?? error).trim()
}

/** What the queue leaves on a pull request it could not merge. Said once per commit. */
export function refusalComment(detail, sha) {
  return (
    'The merge queue could not merge this pull request, although its build is green on ' +
    `\`${sha.slice(0, 7)}\`:\n\n` +
    '```\n' +
    `${detail}\n` +
    '```\n\n' +
    'Usually this means branch protection on the base branch asks for something the ' +
    "queue's token cannot give — a review from a code owner, most often. The " +
    '`MERGE_QUEUE_TOKEN` setup is documented at the top of ' +
    '`.github/workflows/dependabot-merge-queue.yaml`.'
  )
}

// ---------------------------------------------------------------------------
// The gh-backed implementation of that interface, and the entry point.
// ---------------------------------------------------------------------------

function gh(args) {
  const out = execFileSync('gh', args, { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 })
  return out.trim() ? JSON.parse(out) : null
}

function ghText(args) {
  return execFileSync('gh', args, { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 })
}

export function githubApi(repo, { baseBranch = 'main', requireUpToDate = false } = {}) {
  return {
    listOpenPulls: async () =>
      gh([
        'api',
        `repos/${repo}/pulls?state=open&per_page=100`,
        '--jq',
        '[.[] | {number, title, draft, user: .user.login, base: .base.ref, head: .head.sha, labels: [.labels[].name]}]',
      ]) ?? [],

    details: async (pr) => ({
      ...(await resolveMergeability(() =>
        gh(['api', `repos/${repo}/pulls/${pr.number}`, '--jq', '{mergeable, mergeable_state}']),
      )),
      commitMessages: gh([
        'api',
        `repos/${repo}/pulls/${pr.number}/commits?per_page=100`,
        '--jq',
        '[.[].commit.message]',
      ]),
      checkRuns: gh([
        'api',
        `repos/${repo}/commits/${pr.head}/check-runs?per_page=100`,
        '--jq',
        '[.check_runs[] | {name, status, conclusion}]',
      ]),
      statuses: gh(['api', `repos/${repo}/commits/${pr.head}/status`, '--jq', '[.statuses[] | {context, state}]']),
      // Only strict mode cares how far behind a branch is, and the compare
      // endpoint returns the whole diff — no reason to pull a lockfile patch
      // eleven times an hour for a number nobody reads.
      behindBy: requireUpToDate
        ? gh(['api', `repos/${repo}/compare/${baseBranch}...${pr.head}`, '--jq', '.behind_by'])
        : null,
    }),

    approve: async (number) =>
      gh([
        'api',
        '-X',
        'POST',
        `repos/${repo}/pulls/${number}/reviews`,
        '-f',
        'event=APPROVE',
        '-f',
        'body=Approved by the Dependabot merge queue: the build is green on this commit.',
      ]),

    merge: async (number, sha) =>
      gh([
        'api',
        '-X',
        'PUT',
        `repos/${repo}/pulls/${number}/merge`,
        '-f',
        'merge_method=squash',
        '-f',
        `sha=${sha}`,
      ]),

    updateBranch: async (number) => ghText(['api', '-X', 'PUT', `repos/${repo}/pulls/${number}/update-branch`]),

    /** Say a thing once per head commit, so a run every half hour is not a thread. */
    commentOnce: async (number, mark, body) => {
      const existing = gh(['api', `repos/${repo}/issues/${number}/comments?per_page=100`, '--jq', '[.[].body]']) ?? []
      if (existing.some((text) => text.includes(mark))) return false

      gh(['api', '-X', 'POST', `repos/${repo}/issues/${number}/comments`, '-f', `body=${body}\n\n${mark}`])
      return true
    },

    dispatchBuild: async (ref) => ghText(['workflow', 'run', 'build.yaml', '--ref', ref]),
  }
}

async function main() {
  const repo = process.env.GITHUB_REPOSITORY
  if (!repo) throw new Error('GITHUB_REPOSITORY is not set')

  const baseBranch = process.env.BASE_BRANCH || 'main'
  const requireUpToDate = process.env.REQUIRE_UP_TO_DATE === 'true'

  const { summary } = await runQueue(githubApi(repo, { baseBranch, requireUpToDate }), {
    baseBranch,
    requireUpToDate,
    dryRun: process.env.DRY_RUN === 'true',
    tokenKind: process.env.TOKEN_KIND ?? null,
  })

  console.log(summary)
  if (process.env.GITHUB_STEP_SUMMARY) appendFileSync(process.env.GITHUB_STEP_SUMMARY, `${summary}\n`)
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  main().catch((error) => {
    console.error(messageOf(error))
    process.exit(1)
  })
}
