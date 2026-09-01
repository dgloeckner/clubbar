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
 * ### Why the lock lives in the repo and not in the OS temp directory
 *
 * The first version put it at a fixed path under `os.tmpdir()`, which CodeQL
 * flagged as a high-severity insecure temporary file — correctly. That
 * directory is world-writable and the name was predictable, so any other user
 * on the machine could pre-create it (a lock nobody can ever take) or plant a
 * symlink, after which this file's writes land wherever they point.
 *
 * The delete made it worse rather than merely unlucky: a recursive `rm` aimed
 * at a path somebody else may control is precisely the shape CLAUDE.md's
 * "Destructive Test Cleanup" section exists to prevent — the same class of
 * mistake that once removed `/lib64` from a container here.
 *
 * Under the checkout the path is not world-writable, and it is also the more
 * honest scope: the workers that contend for that row are the workers of one
 * Playwright run, which share one checkout.
 *
 * ### Why a lock directory and not something cleverer
 *
 * `mkdir` is atomic on every filesystem this suite runs on, and every contender
 * is a worker process on one machine. A MariaDB `GET_LOCK()` would be the
 * obvious answer and is not usable here: `execSql` spawns a client per
 * statement, so the session holding the lock exits with it.
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

import { mkdirSync, readFileSync, rmdirSync, statSync, unlinkSync, writeFileSync } from 'node:fs'
import path from 'node:path'

/** As `utils/sql.ts` derives it: this file is `<repo>/e2etests/utils/`. */
const REPO_ROOT = path.resolve(__dirname, '..', '..')

/** Git-ignored, and inside the checkout rather than in a shared temp directory. */
const LOCK_ROOT = path.join(REPO_ROOT, 'e2etests', '.locks')
const LOCK_DIR = path.join(LOCK_ROOT, 'self-registration')
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

/**
 * Give up the lock by name, never by sweep.
 *
 * Two `unlink`/`rmdir` calls rather than one recursive `rm`: the only thing
 * this can delete is a file called `owner` and the empty directory that held
 * it. A directory with anything else in it survives, loudly, instead of being
 * cleared — which is the property CLAUDE.md's destructive-cleanup rule asks for
 * and the one the first version did not have.
 */
function removeLock(): void {
  try {
    unlinkSync(OWNER_FILE)
  } catch {
    // Already gone; the rmdir below is still worth attempting.
  }
  try {
    rmdirSync(LOCK_DIR)
  } catch {
    // Not empty, or not there. Either way this process no longer claims it.
  }
}

function breakIfStale(): void {
  try {
    if (Date.now() - statSync(OWNER_FILE).mtimeMs > STALE_MS) {
      removeLock()
    }
  } catch {
    // No owner file: either the lock is being taken right now, or it was
    // released between the check and the stat — which is the outcome this was
    // trying to produce.
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

  // The parent may be created freely; the lock itself never is, because its
  // creation failing is what the mutex is made of.
  mkdirSync(LOCK_ROOT, { recursive: true })

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
  removeLock()
}
