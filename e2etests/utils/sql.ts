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
 * ### The third exception (#778)
 *
 * `configureSelfRegistration()` below. The poster secret and the availability
 * switch have no admin endpoint until #783 builds one, and even then the secret
 * is minted server-side and shown once — a test cannot ask for a *known* secret
 * through any API this product will ever have, because handing one back on
 * demand is exactly what a poster credential must not do. So the suite writes
 * the hash it wants to test against, the same way it writes a token expiry it
 * cannot request.
 *
 * ### Shape
 *
 * `docker compose exec` and `spawnSync`, the same mechanism (and the same
 * `CLUBBAR_*` escape hatch for the Docker-free local stack) that `drain.ts`
 * uses to run the real `bin/cron.php`. Nothing here is imported by the
 * application; it is test scaffolding for one file.
 */

import { spawnSync } from 'node:child_process'
import { copyFileSync, mkdirSync, readdirSync, rmSync, writeFileSync } from 'node:fs'
import { createHash } from 'node:crypto'
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

/**
 * Put the club's self-registration into a known state.
 *
 * Writes the SHA-256 of a secret the test chose, so the test can then present
 * that secret to the public endpoint. `PosterSecret::hash()` is a plain
 * SHA-256 of the raw value — see `backend/src/Modules/Registrations/Domain/PosterSecret.php`,
 * where the reasoning for it not being a password hash is written down.
 *
 * The document URL matters as much as the switch: self-registration fails
 * closed without one, because collecting a name, a birth date and an IBAN from
 * somebody who was never shown a notice is the failure that condition exists to
 * prevent (ADR-0052 decision 6).
 *
 * @param secret The raw secret the test will present. Hashed here, never stored raw.
 * @param options `enabled` defaults to true; `disabledReason` is the club's
 *        member-facing text; `documentUrl` defaults to a plausible published
 *        Anmeldung. Pass `documentUrl: null` to assert the fail-closed path.
 *        `retentionDays` is the club's purge horizon, which the onboarding
 *        page's done screen says out loud (#804); it defaults to the shipped
 *        30 so a test that does not care leaves the row as every other test
 *        expects to find it.
 */
export function configureSelfRegistration(
  secret: string,
  options: {
    enabled?: boolean
    disabledReason?: string | null
    documentUrl?: string | null
    retentionDays?: number
  } = {},
): void {
  const enabled = options.enabled ?? true
  const reason = options.disabledReason ?? null
  const documentUrl =
    options.documentUrl === undefined ? CLUB_DOCUMENT_URL : options.documentUrl
  const retentionDays = options.retentionDays ?? 30

  const hash = createHash('sha256').update(secret).digest('hex')
  const reasonSql = reason === null ? 'NULL' : `'${reason.replace(/'/g, "''")}'`
  const urlSql = documentUrl === null ? 'NULL' : `'${documentUrl.replace(/'/g, "''")}'`

  execSql(
    `UPDATE self_registration_config SET enabled = ${enabled ? 1 : 0}, ` +
      `secret_hash = '${hash}', disabled_reason = ${reasonSql}, ` +
      `retention_days = ${Number(retentionDays)} WHERE id = 1`,
  )
  execSql(`UPDATE sepa_config SET mandate_template_url = ${urlSql} WHERE id = 1`)
}

/**
 * Put `sepa_config.mandate_template_url` back to a usable value.
 *
 * That column is not self-registration's alone: `SepaConfigDto` reads an empty
 * one as incomplete SEPA configuration, and the settlement specs run beside
 * these on other workers. Anything here that clears it — the fail-closed test —
 * has to put it back, or it breaks a spec that never touched registrations.
 */
export function restoreClubDocumentUrl(url = CLUB_DOCUMENT_URL): void {
  execSql(`UPDATE sepa_config SET mandate_template_url = '${url.replace(/'/g, "''")}' WHERE id = 1`)
}

/**
 * The club document URL the suite configures — and it is deliberately one the
 * backend can actually fetch (#780).
 *
 * `localhost` here is the **backend container's own** localhost, because the
 * fetch runs inside it: the fixture is copied into `backend/public/` by
 * `serveClubDocument()` below and served by the same nginx that serves the API.
 * A URL on the public internet would make this suite depend on somebody else's
 * webhost being up, and one that resolves only from the test runner would be
 * fetched by nothing.
 */
export const CLUB_DOCUMENT_URL = 'http://localhost/e2e-club-anmeldung.pdf'

/**
 * Put a real, fillable Anmeldung where the backend can fetch it.
 *
 * The file is `backend/tests/Fixtures/documents/club-anmeldung.pdf` — a genuine
 * WeasyPrint `--pdf-forms --uncompressed-pdf` build, three pages, fields on
 * page 1 — so what the document specs assert is the real pipeline end to end
 * rather than a stand-in.
 *
 * Copied into `backend/public/`, which is git-ignored for this name and removed
 * by `stopServingClubDocument()`. It is not committed there: a fillable mandate
 * template sitting in a public web root of every installation is not something
 * to ship by accident.
 */
/**
 * Reference-counted, and it has to be (#784).
 *
 * Four spec files serve this document, each in its own `beforeAll` and each
 * removing it in its own `afterAll`. Run in one job they overlap, so the first
 * file to finish deletes the fixture out from under the three still using it —
 * and the failure lands as a print that returned no PDF, in a spec that never
 * touched the file. Locally that is a full-suite run; in CI the lanes are
 * separate jobs, which is the only reason it has not bitten before.
 *
 * One token per holder, named by process and file. The document goes when the
 * last token does, so a worker crashing mid-run leaves at worst a stale PDF in
 * a git-ignored path rather than breaking somebody else's test.
 */
const CLUB_DOCUMENT = path.join(REPO_ROOT, 'backend/public/e2e-club-anmeldung.pdf')
const CLUB_DOCUMENT_HOLDERS = path.join(REPO_ROOT, 'backend/public/.e2e-club-anmeldung-holders')

/** This process's token — one per worker is enough; hooks within it nest. */
const holderToken = `${process.pid}`

export function serveClubDocument(): void {
  mkdirSync(CLUB_DOCUMENT_HOLDERS, { recursive: true })
  writeFileSync(path.join(CLUB_DOCUMENT_HOLDERS, holderToken), '')
  copyFileSync(
    path.join(REPO_ROOT, 'backend/tests/Fixtures/documents/club-anmeldung.pdf'),
    CLUB_DOCUMENT,
  )
}

export function stopServingClubDocument(): void {
  rmSync(path.join(CLUB_DOCUMENT_HOLDERS, holderToken), { force: true })

  let remaining: string[] = []
  try {
    remaining = readdirSync(CLUB_DOCUMENT_HOLDERS)
  } catch {
    // The directory is gone, so nobody is holding it.
  }

  if (remaining.length === 0) {
    rmSync(CLUB_DOCUMENT, { force: true })
    rmSync(CLUB_DOCUMENT_HOLDERS, { recursive: true, force: true })
  }
}

/**
 * The club's own name, as the onboarding page's masthead should show it.
 *
 * Read rather than written: `instance_config` is a global singleton (ADR-0034)
 * and `tests/admin/settings-instance-branding.spec.ts` renames it as part of
 * its own assertions. A test that wrote a name here would be racing that file
 * for a value neither of them needs to control — what the register page has to
 * prove is that it renders *whatever the club is called*, not a fixed string.
 */
export function clubInstanceName(): string {
  const output = execSql('SELECT instance_name FROM instance_config WHERE id = 1')

  // `mariadb -e` prints the column name and then the value, one per line. The
  // header is dropped by position rather than by matching it, so a club that
  // happens to be called "instance_name" still reads back correctly.
  const [, value] = output.split('\n')

  return (value ?? '').trim()
}

/** How many pending registrations exist right now. */
export function countPendingRegistrations(): number {
  const output = execSql('SELECT COUNT(*) FROM pending_registrations')
  const match = output.match(/(\d+)/g)

  return match ? Number(match[match.length - 1]) : 0
}

/**
 * Whether any stored row holds this IBAN in the clear.
 *
 * The one guarantee the pending store exists to keep, asserted from outside the
 * application: a database dump reveals no readable IBAN (ADR-0036).
 */
export function pendingRowsContainingPlaintext(iban: string): number {
  const needle = iban.replace(/'/g, '')
  const output = execSql(
    `SELECT COUNT(*) FROM pending_registrations WHERE CONCAT_WS('|', first_name, last_name, email, ` +
      `COALESCE(phone, ''), COALESCE(account_holder_name, ''), mandate_reference, ` +
      `CAST(iban_ciphertext AS CHAR), iban_last4, iban_fingerprint, COALESCE(bank_name, ''), ` +
      `privacy_notice_url) LIKE '%${needle}%'`,
  )
  const match = output.match(/(\d+)/g)

  return match ? Number(match[match.length - 1]) : 0
}

/**
 * Empty the registration rate-limit meter.
 *
 * The meter is per source address, and every spec in the suite arrives from the
 * same one — so without this a long run, or several runs inside the window,
 * spends a budget sized for a clubhouse rather than for a test runner, and
 * specs start failing on a 429 that says nothing about the code under test.
 */
export function clearRegistrationAttempts(): void {
  execSql('DELETE FROM registration_attempts')
}

/** Age a registration past its expiry, so a real cron run has something to purge. */
export function expireRegistration(registrationId: string): void {
  const id = registrationId.replace(/'/g, '')
  execSql(`UPDATE pending_registrations SET expires_at = '2000-01-01 00:00:00' WHERE id = '${id}'`)
}
