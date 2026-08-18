/**
 * Quiet the mail queue before the delivery chains run (#521).
 *
 * The four chain specs each finalize something, run one real drain and wait for
 * one message. That only works if the drain reaches their row: it claims
 * oldest-first, within a batch size and a wall-clock budget, and the suites
 * that ran before it leave thousands of rows ahead of theirs.
 *
 * The backlog is an artefact of the suite rather than of the product. Admin
 * mail fans out to every active admin — ADR-0043 for credential issuance,
 * ADR-0044 for the lifecycle notices — and every spec that mints a throwaway
 * admin leaves it active, so the fan-out grows with the size of the run. A club
 * with six admins queues six rows; a full test run queues thousands, and the
 * chain that happens to go last pays for all of them.
 *
 * So this discards what is queued and unclaimed before the chains start. It is
 * not a workaround for a slow drain: nothing in the product is different, and
 * the chains still prove the whole path — finalize → `bin/cron.php` → Mailpit —
 * against a queue that contains what they put in it.
 *
 * Ordering is structural, not alphabetical: this project depends on the suites
 * that fill the queue, and `mail-chain` depends on this (see
 * `playwright.config.ts`), so the window this opens is exactly "after everything
 * that queues, before everything that delivers".
 */

import { test as setup } from '@playwright/test'
import { countPendingMail, discardPendingMailBacklog } from '../utils/sql'

setup('discard the mail backlog the rest of the suite queued', async () => {
  const discarded = discardPendingMailBacklog()
  const remaining = countPendingMail()

  // Printed rather than asserted: a run whose earlier projects queued nothing
  // is a perfectly good run, and a threshold here would be a threshold about
  // the suite's own shape.
  console.log(`[mail backlog] discarded ${discarded} pending message(s); ${remaining} left in the queue`)
})
