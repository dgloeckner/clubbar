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
 */
export function setTerminalTokenExpiry(terminalId: string, days: number): void {
  execSql(
    `UPDATE terminals SET token_expires_at = NOW() + INTERVAL ${Number(days)} DAY ` +
      `WHERE id = '${terminalId.replace(/'/g, '')}'`,
  )
}
