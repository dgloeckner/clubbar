/**
 * The backup alarm, end to end (#693, ADR-0049).
 *
 *     empty backup directory → bin/cron.php (scan + enqueue + drain) → Mailpit
 *
 * ### What only this file can show
 *
 * The PHPUnit suites pin what counts as broken, the dedup day, the refusals and
 * the rendering in both languages. None of them can show that a club whose
 * backup cron was never added **receives an email saying so** — because a row
 * that was never written is indistinguishable from a row that was written and
 * never sent, and only one of those is the alarm (Pattern 010).
 *
 * That distinction carries more here than almost anywhere else in the suite.
 * `backup.heartbeat_url` (#712) is optional and most clubs will not have one, so
 * for them this mail *is* the monitoring. A feature that queues perfectly and
 * delivers nothing would leave them exactly as unprotected as before, and every
 * unit test would still be green.
 *
 * ### Blast radius, and why this project runs first and cleans up after itself
 *
 * The scan reads one directory shared by the whole stack, and **every**
 * `bin/cron.php` run scans before it drains. So on a stack whose backup has
 * never run, every drain in every mail project afterwards would queue a warning
 * to every admin — and those projects assert exact mailbox counts.
 *
 * Two decisions contain that, in the same spirit as `mail-digest`'s cadence:
 *
 *   - **This project runs before the other chains** and is what they depend on,
 *     so the broken state exists only while this file is using it.
 *   - **It ends healthy.** The last test triggers a real backup through the real
 *     cron URL, and `afterAll` guarantees it even if a test failed — so every
 *     drain after this one scans a directory with an archive in it and queues
 *     nothing.
 *
 * Implements E2E Testing Patterns:
 * - Pattern 001: creates its own admin recipient rather than asserting on a shared one
 * - Pattern 004: `fullyParallel: false`; the drains happen one at a time
 * - Pattern 010: every assertion reads what a real drain delivered to Mailpit
 */

import { execFileSync } from 'node:child_process'
import path from 'node:path'
import { test, expect } from '../../fixtures/auth.fixture'
import {
  assertMailpitReachable,
  createMailpitClient,
  MailpitClient,
} from '../../utils/mailpit'
import { drainMailQueue } from '../../utils/drain'
import { stepUp } from '../../fixtures/stepUp'

const REPO_ROOT = path.resolve(__dirname, '../../..')
const MAIL_CONFIG = 'http://localhost:8080/api/admin/mail-config'
const CRON_SECRET = process.env.CRON_SECRET || 'dev-cron-secret-x'
const BACKUP_TRIGGER = 'http://localhost:8080/api/cron/backup'

/** The backup directory as the container sees it. */
const BACKUP_DIR = '/app/backups'

/**
 * Runs a shell line inside the backend container.
 *
 * The archives are moved rather than deleted, and restored afterwards: this is
 * a developer's own stack as often as it is CI, and a spec that quietly threw
 * away the local half of somebody's backups would be a poor way to prove a
 * backup feature works.
 */
function inBackend(script: string): string {
  return execFileSync('docker', ['compose', 'exec', '-T', 'backend', 'sh', '-lc', script], {
    cwd: REPO_ROOT,
    encoding: 'utf8',
    timeout: 60_000,
  })
}

test.describe('Backup health warning chain', () => {
  let mail: MailpitClient
  let dispose: () => Promise<void>
  let originalMailConfig: { sender_name?: string; sender_address?: string } | null = null

  /**
   * The backup warnings in one mailbox, by subject.
   *
   * Filtered rather than counted, because creating the recipient is itself a
   * notifiable event: a new admin account also receives `admin_account_created`,
   * `admin_role_changed` and whatever credential-expiry tiers the seeded
   * installation is currently inside. Asserting an exact mailbox size would be
   * asserting on those, which belong to other features and other specs.
   */
  async function backupWarnings(recipient: string): Promise<string[]> {
    const messages = await mail.messagesTo(recipient)

    return messages
      .map((message) => message.Subject)
      .filter((subject) => subject.toLowerCase().includes('backup is not working'))
  }

  test.beforeAll(async ({ authenticatedRequest }) => {
    await assertMailpitReachable()
    ;({ mail, dispose } = await createMailpitClient())

    // The drain refuses to send at all without a sender address
    // (`MailConfigDto::isComplete()`), and so does the scan — deliberately, so
    // an installation with no mail configured never fills its Notifications
    // page with red rows it never asked for. This project is the first of the
    // chain, so nothing has configured it yet. Restored below; the row is a
    // singleton (Pattern 001's singleton form).
    originalMailConfig = await (await authenticatedRequest.get(MAIL_CONFIG)).json()
    const patched = await authenticatedRequest.patch(MAIL_CONFIG, {
      data: { sender_name: 'Club Bar', sender_address: 'backup-alarm@test.example' },
    })
    expect(patched.status()).toBe(200)

    // The failing state, made deliberately rather than assumed. On a fresh CI
    // stack the directory is already empty; on a developer's it is not, and a
    // spec that only passed on one of those is worse than no spec.
    inBackend(
      `mkdir -p ${BACKUP_DIR}/.spec-stash && ` +
        `mv ${BACKUP_DIR}/clubbar-*.cbb ${BACKUP_DIR}/index.jsonl ${BACKUP_DIR}/.spec-stash/ 2>/dev/null; true`
    )
  })

  test.afterAll(async ({ authenticatedRequest }) => {
    // Leave the installation healthy whatever happened above, so no later drain
    // in the chain queues a warning into a mailbox another project is counting.
    // The stashed archives come back beside the one the last test took, rather
    // than being replaced by it.
    try {
      inBackend(`mv ${BACKUP_DIR}/.spec-stash/* ${BACKUP_DIR}/ 2>/dev/null; rmdir ${BACKUP_DIR}/.spec-stash 2>/dev/null; true`)

      if (originalMailConfig) {
        await authenticatedRequest.patch(MAIL_CONFIG, {
          data: {
            sender_name: originalMailConfig.sender_name,
            sender_address: originalMailConfig.sender_address,
          },
        })
      }
    } finally {
      await dispose?.()
    }
  })

  /**
   * **The failure the whole milestone exists for.** A cron never added to the
   * hosting panel produces no error anybody sees, and is otherwise
   * indistinguishable from a job running fine every night.
   */
  test('a backup that never ran reaches the admin who can fix it', async ({ authenticatedRequest }) => {
    const recipient = `backup-health-${Date.now()}@test.example`

    const created = await authenticatedRequest.post('http://localhost:8080/api/admin/admin-users', {
      data: {
        email: recipient,
        display_name: 'Backup Health Reader',
        password: 'Sicher-Passwort-2026',
        locale: 'en',
        roles: ['admin'],
        // Creating an admin is behind a step-up (#337, ADR-0036): the caller's
        // own password and a fresh TOTP code.
        ...stepUp(),
      },
    })
    expect(created.status(), await created.text()).toBe(201)

    drainMailQueue()

    // The drain is synchronous, so delivery has already happened by the time it
    // returns; the poll covers Mailpit's own indexing rather than the send.
    await expect
      .poll(async () => (await backupWarnings(recipient)).length, {
        message: 'the backup warning should have been delivered',
      })
      .toBe(1)

    const summaries = await mail.messagesTo(recipient)
    const summary = summaries.find((m) => m.Subject.toLowerCase().includes('backup is not working'))!
    const message = await mail.message(summary.ID)

    // The body has to name the actual cause, or an admin starts debugging the
    // backup rather than the scheduler that never ran it.
    expect(message.Text).toContain('never run')
    expect(message.Text).toContain('hosting panel')

    // The measured detail lives on the security self-check page; this mail
    // deliberately carries no paths, filenames or sizes.
    expect(message.Text).not.toContain(BACKUP_DIR)
    expect(message.Text).not.toContain('.cbb')
  })

  /**
   * **Nothing on success** — the property the channel's usefulness rests on,
   * and the one that cannot be shown without a real drain. A club that receives
   * "backups are fine" every quarter-hour has a filter rule by Tuesday, and the
   * first real warning lands behind it.
   *
   * Runs last on purpose: it is also what leaves the stack healthy for every
   * project that follows.
   */
  test('a healthy backup delivers nothing at all', async ({ authenticatedRequest, request }) => {
    const recipient = `backup-quiet-${Date.now()}@test.example`

    const created = await authenticatedRequest.post('http://localhost:8080/api/admin/admin-users', {
      data: {
        email: recipient,
        display_name: 'Backup Quiet Reader',
        password: 'Sicher-Passwort-2026',
        locale: 'en',
        roles: ['admin'],
        // Creating an admin is behind a step-up (#337, ADR-0036): the caller's
        // own password and a fresh TOTP code.
        ...stepUp(),
      },
    })
    expect(created.status(), await created.text()).toBe(201)

    // A real run through the real trigger, which is what makes the installation
    // healthy — the same endpoint a hosting panel would call.
    const triggered = await request.post(BACKUP_TRIGGER, { headers: { 'X-Cron-Secret': CRON_SECRET } })
    expect(triggered.status()).toBe(204)

    drainMailQueue()

    // Not an empty mailbox — creating this admin queued its own notices, and
    // those are other features working correctly. What must be absent is a
    // backup warning, and only that.
    expect(await backupWarnings(recipient)).toEqual([])
  })
})
