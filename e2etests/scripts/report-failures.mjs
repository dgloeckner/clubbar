#!/usr/bin/env node
/**
 * Turn `results/latest.json` into a failure report that survives the job log.
 *
 * Why this exists
 * ---------------
 * A failed E2E run used to be undiagnosable from anything that reads the job
 * log programmatically. Docker Compose's teardown dumps the whole container log
 * — well over a megabyte of per-request access lines — after the test step, and
 * GitHub's job-log API returns only the tail. Playwright's own summary, and the
 * shell one-liner that used to name the failing tests, both sat past that
 * horizon: present in the web UI, unreachable through the API.
 *
 * So this prints a compact, greppable block anchored by a marker, and the
 * workflow runs it *after* the bounded log dump so it lands at the end of the
 * log. It also writes the same report to the job summary and emits one
 * annotation per failure.
 *
 * Usage: node scripts/report-failures.mjs [path-to-results.json]
 * Exit code is always 0 — this reports, it does not decide the build.
 */

import { readFileSync, appendFileSync, existsSync } from 'node:fs'

const MARKER = 'E2E-FAILURES'
const resultsPath = process.argv[2] ?? 'results/latest.json'

/**
 * Playwright reports `spec.file` relative to `testDir` (./tests), while both a
 * repo-rooted annotation and a re-run command want it from the repo root.
 */
const REPO_PREFIX = 'e2etests/tests/'
const specPath = (file) => `tests/${file}`

/** Playwright nests suites arbitrarily deep; specs carry the pass/fail verdict. */
function collectSpecs(suite, acc = []) {
  for (const spec of suite.specs ?? []) acc.push(spec)
  for (const child of suite.suites ?? []) collectSpecs(child, acc)
  return acc
}

/**
 * The first real error message for a spec.
 *
 * A retried test carries one result per attempt; the last failing attempt is
 * the one worth showing, and Playwright's messages lead with the assertion, so
 * the first few lines carry the diagnosis.
 */
function firstError(spec, maxLines) {
  for (const test of spec.tests ?? []) {
    for (const result of test.results ?? []) {
      const message = result.error?.message ?? result.errors?.[0]?.message
      if (message) {
        return stripAnsi(message).split('\n').slice(0, maxLines).join('\n').trim()
      }
    }
  }
  return '(no error message recorded)'
}

// Playwright colours its messages. Written as an escape rather than the
// literal ESC byte the source used to carry, which was invisible in every
// diff and editor.
const ANSI = /\u001b\[[0-9;]*m/g
const stripAnsi = (s) => s.replace(ANSI, '')

/** One line, safe to put in an annotation's message field. */
const oneLine = (s) => s.split('\n').map((l) => l.trim()).filter(Boolean).join(' — ').slice(0, 400)

function main() {
  if (!existsSync(resultsPath)) {
    console.log(`${MARKER}: no ${resultsPath} — the run produced no JSON report.`)
    return
  }

  let report
  try {
    report = JSON.parse(readFileSync(resultsPath, 'utf8'))
  } catch (err) {
    console.log(`${MARKER}: could not parse ${resultsPath} — ${err.message}`)
    return
  }

  const specs = (report.suites ?? []).flatMap((suite) => collectSpecs(suite))
  const failed = specs.filter((spec) => spec.ok === false)
  // A spec that failed an attempt and passed a later one is flaky, not failing.
  const flaky = specs.filter(
    (spec) => spec.ok === true && (spec.tests ?? []).some((t) => (t.results ?? []).some((r) => r.status === 'failed')),
  )

  if (failed.length === 0) {
    console.log(`${MARKER}: none. ${specs.length} specs recorded${flaky.length ? `, ${flaky.length} flaky` : ''}.`)
    return
  }

  const lines = []
  lines.push('')
  lines.push('='.repeat(72))
  lines.push(`${MARKER}: ${failed.length} of ${specs.length} specs`)
  lines.push('='.repeat(72))

  const summaryRows = []
  for (const spec of failed) {
    const where = `${specPath(spec.file)}:${spec.line}`
    const detail = firstError(spec, 6)

    lines.push('')
    lines.push(`FAILED ${where}`)
    lines.push(`  ${spec.title}`)
    lines.push(`  re-run: npx playwright test ${specPath(spec.file)} --grep ${JSON.stringify(spec.title)}`)
    for (const line of detail.split('\n')) lines.push(`    ${line}`)

    summaryRows.push({ where, title: spec.title, detail })

    // Annotation: shows at the top of the job page and on the PR.
    console.log(
      `::error file=${REPO_PREFIX}${spec.file},line=${spec.line},title=E2E: ${spec.title.slice(0, 120)}::${oneLine(detail)}`,
    )
  }

  if (flaky.length > 0) {
    lines.push('')
    lines.push(`Flaky (passed on retry): ${flaky.map((s) => s.title).join(' · ')}`)
  }

  lines.push('')
  lines.push('='.repeat(72))
  console.log(lines.join('\n'))

  if (process.env.GITHUB_STEP_SUMMARY) {
    const md = [
      `## E2E failures (${failed.length})`,
      '',
      '| Test | Where | Error |',
      '| --- | --- | --- |',
      ...summaryRows.map(
        (r) => `| ${escapeCell(r.title)} | \`${r.where}\` | ${escapeCell(oneLine(r.detail))} |`,
      ),
      '',
    ].join('\n')
    appendFileSync(process.env.GITHUB_STEP_SUMMARY, md + '\n')
  }
}

const escapeCell = (s) => s.replace(/\|/g, '\\|').replace(/\n/g, ' ')

main()
