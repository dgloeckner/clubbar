/**
 * A statement against the stack's database, for the one thing the API cannot
 * express (#438).
 *
 * ### Why this exists, and why it stays this small
 *
 * Every other spec in this suite drives the system through its own HTTP
 * surface, and that is the right default: a test that reaches around the API
 * asserts a database, not a product. This helper is the exception, and it is
 * worth stating exactly which one, because the exception should not grow.
 *
 * The credential expiry warnings are computed from `token_expires_at` and
 * `encryption_keys.expires_at`. **Neither is settable from outside.** A terminal
 * token's lifetime comes from `AppConfig::$tokenTtlDays`, not from the create
 * request; an activated key's expiry is the moment of activation plus 365 days.
 * So there is no sequence of API calls that produces a credential seven days
 * from expiry, and without one there is no way to observe the chain this
 * feature is: *scan → outbox → drain → a mail an admin actually receives*.
 *
 * The alternative was to assert the chain at the outbox instead, which is what
 * the PHPUnit tests already do — and Pattern 010 exists because that is not the
 * same claim.
 *
 * ### The second exception (#521)
 *
 * `discardPendingMailBacklog()` below, for the same reason in a different
 * place: there is no admin endpoint that discards queued mail. `GET
 * /notifications` lists it and `POST /notifications/{id}/retry` re-queues one;
 * nothing throws any away, deliberately — an outbox an admin can empty is not
 * an outbox. So the *suite's* backlog, which is an artefact of the suite and
 * not of the product, has nowhere else to be cleared from.
 *
 * ### Shape
 *
 * `docker compose exec` and `spawnSync`, the same mechanism (and the same
 * `CLUBBAR_*` escape hatch for the Docker-free local stack) that `drain.ts`
 * uses to run the real `bin/cron.php`. Nothing here is imported by the
 * application; it is test scaffolding for one file.
 */

import { spawnSync } from 'node:child_process'
import path from 'node:path'

/** `e2etests/utils` → the checkout root, where docker-compose.yml lives. */
const REPO_ROOT = path.resolve(__dirname, '..', '..')

const SQL_TIMEOUT_MS = 30_000

/**
 * Run one statement and return its output.
 *
 * Set `CLUBBAR_MYSQL_COMMAND` to run against a local MariaDB instead of the
 * compose stack — e.g. `mariadb -uroot clubbar -e`, to which the statement is
 * appended as a single argument.
 */
export function execSql(statement: string): string {
  const override = process.env.CLUBBAR_MYSQL_COMMAND

  const result = override
    ? spawnSync(override.split(' ')[0], [...override.split(' ').slice(1), statement], {
        cwd: REPO_ROOT,
        encoding: 'utf8',
        timeout: SQL_TIMEOUT_MS,
      })
    : spawnSync(
        'docker',
        [
          'compose',
          'exec',
          '-T',
          'database',
          'mariadb',
          '-uclubbar',
          '-pclubbar',
          'clubbar',
          '-e',
          statement,
        ],
        { cwd: REPO_ROOT, encoding: 'utf8', timeout: SQL_TIMEOUT_MS },
      )

  if (result.status !== 0) {
    throw new Error(
      `SQL failed (${result.status}): ${statement}\n${result.stderr || result.stdout || result.error?.message}`,
    )
  }

  return result.stdout ?? ''
}

/**
 * Move a terminal's token expiry to `days` from now.
 *
 * The one call this module exists for. A negative value backdates it, which is
 * how the "already expired, so no advance warning" case is produced.
 *
 * Returns the deadline that was actually written, as the UTC instant the column
 * holds (#365). A caller asserting on the date a mail prints must compare
 * against *this*, not against `Date.now() + days`: the interval is added to the
 * database's clock, not the runner's, and the mail renders the result in the
 * club's zone rather than in UTC.
 *
 * @returns `2026-08-27T22:46:03Z`.
 */
export function setTerminalTokenExpiry(terminalId: string, days: number): string {
  const id = terminalId.replace(/'/g, '')

  execSql(
    `UPDATE terminals SET token_expires_at = NOW() + INTERVAL ${Number(days)} DAY ` +
      `WHERE id = '${id}'`,
  )

  const output = execSql(
    `SELECT DATE_FORMAT(token_expires_at, '%Y-%m-%dT%H:%i:%sZ') FROM terminals WHERE id = '${id}'`,
  )
  const written = output.match(/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z/)
  if (written === null) {
    throw new Error(`No token_expires_at readable back for terminal ${id}:\n${output}`)
  }

  return written[0]
}

/**
 * Throw away mail the rest of the suite queued and nobody is waiting for.
 *
 * ### Why the chains need this
 *
 * Admin-addressed mail fans out to **every active admin** — ADR-0043's issuance
 * notice does, and ADR-0044's lifecycle notices do, by design and by written
 * decision. In a club that is a handful of rows. In this suite it is quadratic:
 * every spec that mints a throwaway admin leaves it *active* forever, so the
 * hundredth account creation queues a hundred rows, and a full run ends with
 * thousands of them pending.
 *
 * A chain spec then finalizes its own settlement, runs one drain and waits for
 * one message. The drain claims oldest-first, so it spends its whole batch and
 * its whole wall-clock budget on that backlog and never reaches the row the
 * spec is waiting for — which surfaces as `Mailpit should hold exactly 1
 * message(s) for <member>: Expected 1, Received 0`, in a file that did nothing
 * wrong.
 *
 * ### Why it is safe
 *
 * The chain projects are the only specs that assert on *delivered* mail, and
 * they run after this by construction (see `playwright.config.ts`). Everything
 * else asserts on outbox rows through the API, finds them by id (Pattern 003),
 * and had already finished with them: `minimumAgeSeconds` protects any row a
 * concurrently-running project queued moments ago.
 *
 * @returns the number of rows discarded, for the setup step to report.
 */
export function discardPendingMailBacklog(minimumAgeSeconds = 60): number {
  const age = Math.max(0, Math.trunc(minimumAgeSeconds))

  const before = countPendingMail()
  execSql(
    `DELETE FROM mail_outbox WHERE status = 'pending' ` +
      `AND queued_at < NOW() - INTERVAL ${age} SECOND`,
  )

  return before - countPendingMail()
}

/** How many messages are queued and unsent right now. */
export function countPendingMail(): number {
  const output = execSql(`SELECT COUNT(*) FROM mail_outbox WHERE status = 'pending'`)
  const match = output.match(/(\d+)/g)

  return match ? Number(match[match.length - 1]) : 0
}
