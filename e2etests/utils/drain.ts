/**
 * Running the drain the way a hosting panel runs it (#403, #409).
 *
 * The chain these tests assert is finalize → **drain** → delivered message, and
 * the middle step has to be the real one. There is no test-only sending path
 * and there must not be one: `bin/cron.php` is what a panel's crontab calls, it
 * is where the `flock`, the wall-clock budget, the CLI-PHP heartbeat and the
 * pruning live, and a test that bypassed it would be green on a chain nobody
 * uses.
 *
 * ### Why the DSN is passed per run rather than set on the stack
 *
 * A drain claims whatever is due, globally — it has no notion of "this test's
 * messages". Wiring a transport into the long-running backend would therefore
 * make every drain in the suite a sender, including the six the URL-trigger
 * spec fires while the admin suite is asserting that a freshly queued
 * announcement is still `pending`. Those assertions would go intermittent for a
 * reason unrelated to what they test.
 *
 * So the stack's `MAIL_DSN` stays empty and the DSN is handed to the run that
 * is meant to deliver. `docker compose exec -e` is ordinary process
 * environment; `bin/cron.php` reads it through the same `Env`/`AppConfig` path
 * as a real installation reads `config.php`, so nothing about the code under
 * test changes — only which of the suite's drains has somewhere to send.
 */

import { spawnSync, type SpawnSyncReturns } from 'node:child_process'
import path from 'node:path'

/** `e2etests/utils` → the checkout root, where docker-compose.yml lives. */
const REPO_ROOT = path.resolve(__dirname, '..', '..')

/**
 * Mailpit as seen **from inside the backend container**, which is where the
 * drain runs. The tests reach the same server on `localhost:8025` from the
 * runner; both names are the same container.
 */
export const CONTAINER_MAIL_DSN = process.env.DRAIN_MAIL_DSN || 'smtp://mailpit:1025'

/** A drain must finish well inside its own 50-second wall-clock budget. */
const DRAIN_TIMEOUT_MS = 90_000

export interface DrainOptions {
  /**
   * Transport for this run. Point it somewhere unreachable to exercise the
   * failure path — a run whose transport refuses is the only way to produce a
   * `failed` row through the real code.
   */
  dsn?: string
  /** Messages claimed per round. The default batch is plenty for one test. */
  batchSize?: number
  /**
   * `--period`, for the Deckelauszug (#463). Names the statement period
   * explicitly instead of letting the run derive it from the server's today.
   *
   * It does **not** lift the catch-up cap: a period that is not the current one
   * is refused rather than mailed late (ADR-0039), so a test still has to name
   * the period it is actually standing in. What it buys is that the assertion
   * and the run agree on which period that is, rather than both computing it
   * from a clock and hoping they cross midnight together.
   */
  period?: string
  /**
   * `--budget`, in seconds. Worth raising above the 50-second default only when
   * a run has the whole membership's statements to clear — every member in the
   * database gets one, and leaving a tail of them queued makes the *next*
   * spec's drain slow for reasons that have nothing to do with it.
   */
  budgetSeconds?: number
}

/**
 * Run one drain and return everything it printed, stdout and stderr together.
 *
 * `bin/cron.php` exits 0 unless the run could not start at all — a message that
 * could not be delivered is recorded on its row, not raised here — so a
 * non-zero exit means the entrypoint itself failed and the error carries its
 * output.
 *
 * ### It throws on an aborted run, and that is the point
 *
 * `DrainService::run()` catches everything, so a run that threw mid-batch still
 * exits 0 and still prints a summary. Before #463 that summary was
 * indistinguishable from an idle tick, and a chain spec whose own message
 * happened to be the one sent *before* the throw passed over a queue that had
 * stopped — which is exactly what happened, locally, three times in a row,
 * while CI failed on the same commit for the honest reason.
 *
 * So the harness refuses to let that be quiet: an `ABORTED` summary raises here,
 * carrying the run's own output, rather than surfacing later as "expected 1
 * message, received 0" in whichever spec drew the short straw.
 *
 * Escape hatch: `MAIL_DRAIN_COMMAND` replaces the docker invocation wholesale,
 * for the Docker-free local stack described in CLAUDE.md (mariadb + `php -S`).
 * It is run from the repository root with `MAIL_DSN` in its environment, and
 * the flags below are **appended to it** — so it must end with the `cron.php`
 * invocation itself and not with a redirect or a pipe. Since #463 that is not
 * cosmetic: a statement test names its period on the command line, and an
 * override that swallowed the flag would drain a different period than the one
 * being asserted.
 */
/**
 * `bin/cron.php`'s own message (see the file itself) when its non-blocking
 * `flock` is already held — by the URL trigger route, which takes the exact
 * same lock file (`DrainService::lockIn`) so a CLI run and an HTTP-triggered
 * one can never overlap. `tests/api/cron-drain.spec.ts` fires several real
 * HTTP-triggered runs, and — unlike this file's own drains — that project
 * gives no guarantee about exactly when the *process* holding the lock for
 * one of those requests actually exits relative to when its HTTP response
 * lands, only that the test itself is done. A drain call here landing in
 * that window is not an error and not an empty queue: it is a run that
 * printed exactly this and exited 0 having done nothing, indistinguishable
 * from genuine idleness to everything downstream — which is why retrying
 * belongs here, at the one place that can tell the difference, rather than
 * in every assertion that waits on a message afterwards.
 */
const LOCK_HELD_MESSAGE = 'Another drain run holds'
const LOCK_RETRY_ATTEMPTS = 3
const LOCK_RETRY_DELAY_MS = 2_000

/** Synchronous sleep — `drainMailQueue` is spawnSync-based throughout. */
function sleepSync(ms: number): void {
  Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, ms)
}

export function drainMailQueue(options: DrainOptions = {}): string {
  const dsn = options.dsn ?? CONTAINER_MAIL_DSN
  const batchSize = options.batchSize ?? 200
  const override = process.env.MAIL_DRAIN_COMMAND

  const args = ['--batch', String(batchSize)]
  if (options.period) {
    args.push('--period', options.period)
  }
  if (options.budgetSeconds) {
    args.push('--budget', String(options.budgetSeconds))
  }

  for (let attempt = 1; attempt <= LOCK_RETRY_ATTEMPTS; attempt++) {
    // spawnSync rather than execFileSync: the latter returns stdout only, and
    // everything a drain says about trouble — a missing extension, an aborted
    // run, a message it could not deliver — it says on stderr.
    const run: SpawnSyncReturns<string> = override
      ? spawnSync('sh', ['-c', `${override} ${args.join(' ')}`], {
          cwd: REPO_ROOT,
          encoding: 'utf8',
          timeout: DRAIN_TIMEOUT_MS,
          env: { ...process.env, MAIL_DSN: dsn },
        })
      : spawnSync(
          'docker',
          ['compose', 'exec', '-T', '-e', `MAIL_DSN=${dsn}`, 'backend', 'php', '/app/bin/cron.php', ...args],
          { cwd: REPO_ROOT, encoding: 'utf8', timeout: DRAIN_TIMEOUT_MS }
        )

    const output = `${run.stdout ?? ''}${run.stderr ?? ''}`

    if (run.error || run.status !== 0) {
      throw new Error(
        'Could not run the mail drain (backend/bin/cron.php). The chain tests need the compose stack ' +
          'up, or MAIL_DRAIN_COMMAND pointing at an equivalent CLI.\n' +
          `${output}\n${run.error?.message ?? ''}`
      )
    }

    if (output.includes('ABORTED')) {
      throw new Error(
        'The drain run aborted, so whatever it did or did not deliver says nothing about the queue. ' +
          'Fix this before reading any mail assertion below it.\n' +
          output
      )
    }

    if (output.includes(LOCK_HELD_MESSAGE)) {
      if (attempt < LOCK_RETRY_ATTEMPTS) {
        sleepSync(LOCK_RETRY_DELAY_MS)
        continue
      }
      throw new Error(
        `The drain's lock was still held after ${LOCK_RETRY_ATTEMPTS} attempts, ` +
          `${LOCK_RETRY_ATTEMPTS * LOCK_RETRY_DELAY_MS}ms apart. That is not this file's own drains — they run ` +
          'one at a time — so something outside this test is holding backend/storage/mail-drain.lock well past ' +
          "a single request's lifetime.\n" +
          output
      )
    }

    return output
  }

  // Unreachable — the loop above always returns or throws — but satisfies the
  // compiler's control-flow analysis without an `any`-typed escape hatch.
  throw new Error('drainMailQueue: exhausted retries without a definitive result')
}
