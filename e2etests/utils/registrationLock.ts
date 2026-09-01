/**
 * A cross-file mutex over `self_registration_config` (#784).
 *
 * ### The problem `mode: 'serial'` does not solve
 *
 * That row is a **singleton** by design — one club, one poster secret, one
 * switch — and four spec files write it: the public API suite, the public page,
 * the admin inbox and the settings controls. Each of them declares
 * `mode: 'serial'`, which orders the tests *within* a file and says nothing
 * about the file running beside it on another worker.
 *
 * So the failure this prevents is not hypothetical and not a flake to retry:
 * worker A writes the secret it is about to present, worker B overwrites it
 * half a second later, and A's valid secret comes back as the uniform 404 the
 * surface answers an unknown one with. The spec that loses the race reports
 * "a valid secret was refused", which is a true sentence about a system that is
 * working correctly, and it lands in whichever file lost — never the one that
 * caused it.
 *
 * Pattern 001's answer, unique data per test, cannot apply to a row the schema
 * allows exactly one of. Pattern 004's is this: serialise the resource.
 *
 * ### Why a lock directory and not something cleverer
 *
 * `mkdir` is atomic on every filesystem this suite runs on, and every contender
 * is a worker process on one machine — Playwright workers share a filesystem,
 * and the CI lanes that could contend get a database each. A MariaDB
 * `GET_LOCK()` would be the obvious answer and is not usable here: `execSql`
 * spawns a client per statement, so the session holding the lock exits with it.
 *
 * The lock is taken for the whole test rather than around the write, because
 * the window that matters spans the write *and* the request that presents what
 * was written — a browser round trip, in the page specs.
 *
 * ### Staleness
 *
 * A worker killed mid-test would otherwise hold the lock forever, turning one
 * crash into a suite-wide hang. An owner older than `STALE_MS` is broken open;
 * the number is far longer than any test here takes and far shorter than a CI
 * job's patience.
 */

import { mkdirSync, rmSync, statSync, writeFileSync, readFileSync } from 'node:fs'
import path from 'node:path'
import { tmpdir } from 'node:os'

const LOCK_DIR = path.join(tmpdir(), 'clubbar-self-registration.lock')
const OWNER_FILE = path.join(LOCK_DIR, 'owner')

/** Longer than any test that holds it; shorter than a job that would hang on it. */
const STALE_MS = 90_000
const POLL_MS = 25
const ACQUIRE_TIMEOUT_MS = 120_000

/** Whether this process is currently the owner — so release is never somebody else's. */
let held = false

function sleep(ms: number): void {
  // Synchronous on purpose: the callers are Playwright's `beforeEach`/`afterEach`
  // hooks, and a lock that could be awaited around would not be one.
  Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, ms)
}

function breakIfStale(): void {
  try {
    if (Date.now() - statSync(LOCK_DIR).mtimeMs > STALE_MS) {
      rmSync(LOCK_DIR, { recursive: true, force: true })
    }
  } catch {
    // Gone between the check and the stat: somebody else released it, which is
    // the outcome this was trying to produce.
  }
}

/**
 * Take exclusive ownership of the club's configuration row.
 *
 * Re-entrant within a process to the extent that matters: a file's tests are
 * serial, so a second call from the same worker means a previous `afterEach`
 * did not run, and holding on is safer than double-releasing.
 */
export function lockSelfRegistration(): void {
  if (held) return

  const deadline = Date.now() + ACQUIRE_TIMEOUT_MS
  for (;;) {
    try {
      mkdirSync(LOCK_DIR)
      writeFileSync(OWNER_FILE, `${process.pid}`)
      held = true
      return
    } catch {
      breakIfStale()
      if (Date.now() > deadline) {
        // Never fail the test for the lock alone: whatever is wrong here, the
        // spec's own assertions are a better description of it than a timeout
        // in a helper. Report it and proceed unserialised.
        const owner = (() => {
          try {
            return readFileSync(OWNER_FILE, 'utf8')
          } catch {
            return 'unknown'
          }
        })()
        console.warn(
          `[registrationLock] gave up after ${ACQUIRE_TIMEOUT_MS}ms; owner pid ${owner}. Proceeding.`,
        )
        return
      }
      sleep(POLL_MS)
    }
  }
}

/** Release it. Safe to call when this process never held it. */
export function unlockSelfRegistration(): void {
  if (!held) return
  held = false
  rmSync(LOCK_DIR, { recursive: true, force: true })
}
