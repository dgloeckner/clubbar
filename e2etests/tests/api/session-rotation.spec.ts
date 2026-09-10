import { test, expect, APIRequestContext } from '@playwright/test'
import { readFileSync } from 'fs'
import path from 'path'

/**
 * Periodic session-ID rotation must not end a live session.
 *
 * `AdminSessionAuth` replaces the session ID every `SESSION_REGEN_INTERVAL`
 * seconds (#340). It used to do that with `session_regenerate_id(true)`, which
 * deletes the old session file immediately — and with
 * `session.use_strict_mode` on, a request still carrying the old ID is then not
 * merely refused: PHP hands it a brand-new empty session and writes *that* into
 * the browser. One straggling request therefore signed the admin out for good,
 * with a reload landing on the login form.
 *
 * Two ordinary things in the panel produce a straggler, and neither is exotic:
 * a page that fires several requests at once (every list page does), and a
 * request the panel cancels (`useLatestRequest` aborts superseded ones, and
 * every page aborts on unmount). The second is the nastier of the two — the
 * server rotates, the browser never receives the new cookie, and the tab is
 * left holding an ID that no longer exists.
 *
 * These are the only tests in the suite that read `Set-Cookie` on an
 * *authenticated* request, which is why they are here rather than in a PHPUnit
 * Feature test: PHP's session module writes that header straight to the SAPI,
 * so an in-process test never sees it.
 *
 * ## Running these
 *
 * They need a backend whose rotation interval is seconds rather than the 900
 * an installation ships with, and that cannot be the shared stack's value: the
 * suite pins one session cookie (`playwright/.auth/admin.json`) for every spec
 * in the run, so a rotating stack turns that stored cookie into a tombstone and
 * strands every other worker. That is also why CI never caught the bug — at
 * 900s a two-minute lane never rotates once.
 *
 * So they run against a stack of their own, and skip with a message rather than
 * failing when the interval is not short:
 *
 * ```bash
 * SESSION_REGEN_INTERVAL=2 docker compose up -d backend
 * cd e2etests && E2E_SESSION_REGEN_INTERVAL=2 npx playwright test tests/api/session-rotation.spec.ts --project=api-tests
 * docker compose up -d backend    # back to the shared stack's 900
 * ```
 */

const API_BASE = 'http://localhost:8080/api'
const ADMIN_STORAGE_STATE_PATH = path.join('playwright', '.auth', 'admin.json')
const SESSION_COOKIE_NAME = API_BASE.startsWith('https://') ? '__Host-session' : '_session'

/**
 * What the backend under test is configured with. Announced by the runner
 * rather than discovered, because nothing on the API reports it — and guessing
 * would mean waiting fifteen minutes to find out.
 *
 * The gate is written as a bare `process.env` comparison rather than anything
 * computed: `no-data-dependent-skip` (#146) requires a skip condition to be
 * statically decidable, and `Number(...)` is a call, which it rightly refuses.
 * The parsed number below is only ever used to size a wait.
 */
const ROTATES_QUICKLY = (process.env.E2E_SESSION_REGEN_INTERVAL ?? '') !== ''
const REGEN_INTERVAL_SECONDS = Number(process.env.E2E_SESSION_REGEN_INTERVAL ?? '0')

/** The `name=value` pair from a response's Set-Cookie, or null when it sent none. */
function sessionCookieFrom(headers: Record<string, string>): string | null {
  const raw = headers['set-cookie']
  if (!raw) return null
  const line = (Array.isArray(raw) ? raw : [raw])
    .flatMap((h: string) => h.split('\n'))
    .find((h: string) => h.startsWith(`${SESSION_COOKIE_NAME}=`))
  return line ? line.split(';')[0] : null
}

/**
 * A session of this spec's own, from the state `auth.setup.ts` established.
 *
 * Not shared with other specs: these tests deliberately drive a session across
 * a rotation and then use its *previous* ID, which is not a thing to be doing
 * to a session another test is reading through.
 */
async function ownSession(request: APIRequestContext): Promise<string> {
  const stored = JSON.parse(readFileSync(ADMIN_STORAGE_STATE_PATH, 'utf-8'))
  const cookie = stored.cookies?.find((c: { name: string }) => c.name === SESSION_COOKIE_NAME)
  if (!cookie) throw new Error(`Missing ${SESSION_COOKIE_NAME} in ${ADMIN_STORAGE_STATE_PATH}`)
  return `${cookie.name}=${cookie.value}`
}

/** Drive the session until the server rotates it, and report both IDs. */
async function rotate(
  request: APIRequestContext,
  cookie: string
): Promise<{ previous: string; successor: string }> {
  // Up to a handful of attempts: the interval is 2s, so the first request after
  // a short wait normally rotates, but the suite runs four-wide and a slow
  // moment should not read as a missing feature.
  const wait = (REGEN_INTERVAL_SECONDS + 0.2) * 1000
  for (let attempt = 0; attempt < 8; attempt++) {
    await new Promise((resolve) => setTimeout(resolve, wait))
    const response = await request.get(`${API_BASE}/auth/profile`, { headers: { cookie } })
    expect(response.status()).toBe(200)

    const successor = sessionCookieFrom(response.headers())
    if (successor && successor !== cookie) {
      return { previous: cookie, successor }
    }
  }
  throw new Error(
    `the session ID never rotated after ${REGEN_INTERVAL_SECONDS}s — ` +
      'is the backend really running with that SESSION_REGEN_INTERVAL?'
  )
}

test.describe('Admin session-ID rotation', () => {
  test.describe('against a stack that rotates quickly', () => {
    test.skip(
      !ROTATES_QUICKLY,
      'needs a backend started with a short SESSION_REGEN_INTERVAL — see the header of this file'
    )

  test('rotates the session ID on its own schedule', async ({ request }) => {
    const cookie = await ownSession(request)
    const { previous, successor } = await rotate(request, cookie)

    expect(successor).not.toBe(previous)

    // The successor is a working session, not just a new name.
    const onSuccessor = await request.get(`${API_BASE}/auth/profile`, {
      headers: { cookie: successor },
    })
    expect(onSuccessor.status()).toBe(200)
    expect((await onSuccessor.json()).admin?.id).toBeTruthy()
  })

  /**
   * The regression itself. Before the fix this answered 401 and handed back a
   * `Set-Cookie` for an empty session, which is what pinned the browser to a
   * login it could never recover.
   */
  test('serves a request that arrives on the previous session ID', async ({ request }) => {
    const cookie = await ownSession(request)
    const { previous, successor } = await rotate(request, cookie)

    const straggler = await request.get(`${API_BASE}/auth/profile`, {
      headers: { cookie: previous },
    })

    expect(straggler.status()).toBe(200)
    expect((await straggler.json()).admin?.id).toBeTruthy()

    // And it repairs the browser: the response carries the successor, so a tab
    // that missed the rotation is holding a usable ID again rather than a dead
    // one. This is the half that fixes a cancelled request.
    expect(sessionCookieFrom(straggler.headers())).toBe(successor)
  })

  /**
   * The shape that actually bit: several requests in flight across the rotation
   * boundary, exactly as a list page produces on mount. Every one of them must
   * be served — one 401 among them is a logout.
   */
  test('serves every request of a burst that straddles a rotation', async ({ request }) => {
    const cookie = await ownSession(request)
    const { previous } = await rotate(request, cookie)

    const burst = await Promise.all(
      Array.from({ length: 6 }, () =>
        request.get(`${API_BASE}/auth/profile`, { headers: { cookie: previous } })
      )
    )

    expect(burst.map((r) => r.status())).toEqual([200, 200, 200, 200, 200, 200])
  })

  })

  /**
   * Needs no rotation, so it runs on the shared stack too: an ID the server has
   * never issued is refused as `admin_not_authenticated` rather than quietly
   * becoming a working session. That is the property `use_strict_mode` provides
   * and the forwarding above must not erode.
   */
  test('refuses a session ID the server never issued', async ({ request }) => {
    const forged = `${SESSION_COOKIE_NAME}=0123456789abcdef0123456789abcdef`

    const response = await request.get(`${API_BASE}/auth/profile`, {
      headers: { cookie: forged },
    })

    expect(response.status()).toBe(401)
    expect((await response.json()).error).toBe('admin_not_authenticated')
  })
})
