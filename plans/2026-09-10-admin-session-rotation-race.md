# Admin sessions ended by their own session-ID rotation

**Status:** Complete
**Issue:** [#873](https://github.com/dgloeckner/clubbar/issues/873)

## Context

Reported from the live installation: an admin panel tab, open and signed in,
lands on the login form when a menu item is clicked. Reproduced by clicking
**Abrechnungen**, which mounts a page that fires `listSettlements` and
`GET /auth/profile` in the same tick.

None of the documented lifetimes explains it — idle is 2h, absolute 24h,
`gc_maxlifetime` and the cookie's `Max-Age` both 7200s. The session was not
expiring; it was being destroyed underneath the browser by the periodic
session-ID rotation of #340, which called `session_regenerate_id(true)`.

With `session.use_strict_mode` on, a request carrying the just-deleted ID is not
merely refused: PHP mints a fresh empty session and its `Set-Cookie` writes that
into the browser, pinning the tab to a session that can never authenticate.

The captured evidence: a 401 on `GET /api/auth/profile` carrying **no**
`Set-Cookie` but PHP's session cache-limiter headers — so `session_start()` ran
and *accepted* the ID. The session file existed and simply held no
`admin_user_id`, which is the fingerprint of an empty session minted by the
losing side of the race and then persisted.

## Milestones

- [x] **1. Reproduce and identify.** Rotation confirmed as the cause from the
      live capture; PHP's behaviour verified directly (strict mode replaces an
      ID with no file; `session_id()` + `session_start()` emits `Set-Cookie` in
      both directions).
- [x] **2. `SessionRotation` domain class.** Tombstone shape, 60s grace window,
      chain bound. 17 unit tests.
- [x] **3. `AdminSessionAuth` forwards instead of deleting.** `followRotation()`
      before every check; `rotateSessionId()` via `session_regenerate_id(false)`
      so `use_strict_mode` is never switched off. 4 new middleware tests, each
      verified to fail against the original code.
- [x] **4. `BrowserSession::endIfPresent()` follows the pointer** before
      destroying, or ending the browser's session would leave the successor
      alive — the failure #798 exists to prevent.
- [x] **5. E2E coverage.** `e2etests/tests/api/session-rotation.spec.ts`, the
      only place the `Set-Cookie` behaviour is observable (PHP writes that header
      to the SAPI, so an in-process Feature test never sees it).
- [x] **6. Docs.** ADR-0025 amendment, Pattern 013 §1a, `docs/deployment.md`.

## Verification

```bash
cd backend && php8.3 vendor/bin/phpunit -c phpunit.xml --testsuite Unit
# 3197 passed

cd e2etests && npx playwright test --project=api-tests --workers=4
# 841 passed, 0 failed

SESSION_REGEN_INTERVAL=2 docker compose up -d backend
cd e2etests && E2E_SESSION_REGEN_INTERVAL=2 npx playwright test tests/api/session-rotation.spec.ts --project=api-tests
# 5 passed
docker compose up -d backend
```

Each new test was also run against the original `session_regenerate_id(true)` and
confirmed to fail, so none of them is a test that cannot fail.

## Known limitation

The rotation specs skip on the shared stack. They need a backend whose interval
is seconds, and that cannot be the shared stack's value: the suite pins one
session cookie (`playwright/.auth/admin.json`) for the whole run, so a rotating
stack turns it into a tombstone and strands every worker — measured at 106
failures. Giving CI a lane with its own short-interval stack is the follow-up;
the unit tests carry the CI-enforced coverage in the meantime.

That same property is why CI never caught the original bug: at 900s a
two-minute lane never rotates once.
