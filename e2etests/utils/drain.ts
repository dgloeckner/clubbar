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

import { execFileSync } from 'node:child_process'
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
}

/**
 * Run one drain and return what it printed.
 *
 * `bin/cron.php` exits 0 unless the run could not start at all — a message that
 * could not be delivered is recorded on its row, not raised here — so a
 * non-zero exit means the entrypoint itself failed and the error carries its
 * output.
 *
 * Escape hatch: `MAIL_DRAIN_COMMAND` replaces the docker invocation wholesale,
 * for the Docker-free local stack described in CLAUDE.md (mariadb + `php -S`).
 * It is run from the repository root with `MAIL_DSN` in its environment.
 */
export function drainMailQueue(options: DrainOptions = {}): string {
  const dsn = options.dsn ?? CONTAINER_MAIL_DSN
  const batchSize = options.batchSize ?? 200
  const override = process.env.MAIL_DRAIN_COMMAND

  try {
    if (override) {
      return execFileSync('sh', ['-c', override], {
        cwd: REPO_ROOT,
        encoding: 'utf8',
        timeout: DRAIN_TIMEOUT_MS,
        env: { ...process.env, MAIL_DSN: dsn },
      })
    }

    return execFileSync(
      'docker',
      [
        'compose',
        'exec',
        '-T',
        '-e',
        `MAIL_DSN=${dsn}`,
        'backend',
        'php',
        '/app/bin/cron.php',
        '--batch',
        String(batchSize),
      ],
      { cwd: REPO_ROOT, encoding: 'utf8', timeout: DRAIN_TIMEOUT_MS }
    )
  } catch (error) {
    const details = error as { stdout?: string; stderr?: string; message?: string }
    throw new Error(
      'Could not run the mail drain (backend/bin/cron.php). The chain tests need the compose stack ' +
        'up, or MAIL_DRAIN_COMMAND pointing at an equivalent CLI.\n' +
        `stdout: ${details.stdout ?? ''}\nstderr: ${details.stderr ?? ''}\n${details.message ?? ''}`
    )
  }
}
