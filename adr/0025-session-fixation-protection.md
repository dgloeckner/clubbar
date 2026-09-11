# ADR-0025: Session Fixation Protection on Login

**Status**: Accepted

**Date**: 2026-03-17

---

## Context

When a user visits the admin panel before logging in, PHP may create a session for pre-login state (e.g., CSRF tokens). That session carries an ID stored in the browser's session cookie.

**Session fixation attack:** An attacker who learns or plants a pre-login session ID can wait for the victim to authenticate. If the server does not replace the session ID after login, the attacker inherits an authenticated session without knowing the victim's password.

The scenario is most realistic when:

- The application accepts session IDs supplied by the client (e.g., via URL `?PHPSESSID=abc123`) — PHP's default behaviour when `session.use_only_cookies = Off`
- The application is served under a shared domain where a sibling subdomain can set cookies on the parent domain (cookie injection)
- The attacker has temporary access to the victim's browser (physical access, shared computer)

The current `AuthController::login()` method starts a session and writes `admin_user_id` and `csrf_token` to `$_SESSION` without first regenerating the session ID. This violates OWASP A07:2021 (Identification and Authentication Failures).

---

## Decision

**Call `session_regenerate_id(true)` immediately after successful credential verification and before writing any data to `$_SESSION`.**

The `true` argument instructs PHP to delete the old session file on disk (not just issue a new cookie), preventing an attacker from resuming the old session even if they know the ID.

The one-line fix is placed between credential verification and `$_SESSION` writes in `AuthController::login()`.

---

## Consequences

**Positive**

- Eliminates session fixation as an attack vector — the authenticated session ID is always freshly generated and unknown to any prior observer
- Destroys the old (unauthenticated) session file immediately, preventing reuse
- No change to external API contract: the response body and Set-Cookie header remain identical to callers

**Negative**

- Any pre-login session data (stored before `login()` is called) is lost on successful login. Currently no pre-login session data is stored, so this has no practical impact.
- Negligible overhead: one filesystem rename/delete per login (no measurable performance impact)

---

## Amendment (2026-09-10): periodic rotation forwards, it does not delete

**Status**: Accepted — amends the Decision above for one case it did not cover.

This ADR is about the *login* transition, and for that its reasoning is
unchanged: `session_regenerate_id(true)` still runs at login, at the MFA
upgrade, and when an account changes its own credentials. In all three the old
ID belongs to a session nobody should be able to resume, the browser is making
exactly one request, and deleting the old file immediately is exactly right.

[#340](https://github.com/dgloeckner/clubbar/issues/340) later added a *fourth*
call site with none of those properties: `AdminSessionAuth` rotating the ID of a
**live, authenticated** session every `SESSION_REGEN_INTERVAL` seconds, as
defence in depth against a leaked cookie. Reusing the same `true` there turned
a hardening measure into a way to sign admins out.

The failure needs no attacker and no unusual deployment. `session.use_strict_mode`
(ADR-0016) refuses to adopt an ID with no file behind it, so a request arriving
on the just-deleted ID is not merely rejected — PHP mints a **fresh empty
session** and its `Set-Cookie` writes that into the browser. The tab is then
pinned to a session that will never authenticate, so reloading lands on the
login form. Two entirely ordinary things produce such a request:

- **Concurrency.** Opening any list page in the panel fires several requests at
  once. PHP serialises them on the session lock; whichever rotates deletes the
  file out from under the rest.
- **A cancelled request.** The panel aborts superseded requests. PHP completes
  the rotation regardless, but the browser never receives the new cookie.

**Decision**: the periodic rotation leaves a **tombstone** rather than a hole.
The old session is overwritten with a forwarding record — the successor's ID and
the moment of rotation, and nothing else — and a request arriving on the old ID
within a 60-second grace window is carried across to the real session, with the
cookie re-sent as it goes. After the window the old ID is refused like any other
unauthenticated session.

Mechanically it is `session_regenerate_id(false)` followed by re-opening the old
session to overwrite it, rather than the `session_create_id()` + `ini_set()`
sequence PHP's manual suggests. `ini_set()` is refused on a session directive
while a session is active, so the manual's sequence cannot restore
`use_strict_mode` afterwards — it would leave the hardening off for the rest of
the request, which `/api/admin/security-check` reads live and would report as
missing.

**Consequences**

- A rotated-away session ID stays exchangeable for its successor for a grace
  window (`SessionRotation::GRACE_SECONDS`, tightened to 10s by the 2026-09-11
  amendment below). That is the cost, and it is bounded: the tombstone holds no
  `admin_user_id`, no `csrf_token` and none of `SessionTimeout`'s stamps, so it
  is not a login — the most a leaked pre-rotation ID buys is the successor it
  was already one response away from, and only for the window out of every
  rotation interval.
- The window is sized for what it has to cover: requests already in flight
  (milliseconds) and the browser's next request after a cancelled one (a page
  navigation). It is not a session lifetime and must not grow into one.
- `BrowserSession::endIfPresent()` follows the pointer before destroying, or
  ending the browser's session would leave the successor alive — the failure
  [#798](https://github.com/dgloeckner/clubbar/issues/798) exists to prevent.

---

## Amendment (2026-09-11): the window is a non-consuming bearer window, by choice

**Status**: Accepted — narrows the grace window, and records why it stays
non-consuming and unbound rather than making both changes.

[#898](https://github.com/dgloeckner/clubbar/issues/898) named the window
precisely: nothing consumes a tombstone on its first hop, nothing binds a hop
to who presents it, and it renews on every rotation for the life of a session —
so for an attacker holding a leaked pre-rotation cookie, the very rotation
meant to retire it instead hands them its replacement, for any request made
inside the window. The three properties are each necessary for what the
mechanism exists to do; the finding is that together they are worth pricing,
not that any one is a bug.

**Decision, on the two tightenings the issue proposed:**

1. **Shorten the window.** Accepted. `SessionRotation::GRACE_SECONDS` moves
   from 60 seconds to 10. Sixty was "generous" for requests already in flight
   (milliseconds) and the browser's next request after a cancelled one (a page
   navigation) — both comfortably under a second — so the extra 59 seconds
   bought nothing the window needs to cover and only lengthened the exposure
   this amendment exists to discuss. Ten keeps the same headroom and cuts a
   session's cumulative exposure sixfold.
2. **Consume the tombstone on its first successful hop.** Rejected, on
   evidence rather than the intuition that motivated it. The concurrency case
   the tombstone exists for is a genuine burst — a list query and a profile
   read racing the dashboard's poll, all in flight before the rotation, all
   presenting the same old ID after it — and PHP serialises them on the
   session lock rather than deduplicating them, so single-use would forward
   the first and refuse the rest as if each were a fresh sign-out.
   `e2etests/tests/api/session-rotation.spec.ts`'s "serves every request of a
   burst that straddles a rotation" asserts exactly that six-request shape
   stays all-200; it is the cheap test the issue itself proposed running before
   trusting either reading, and it settles the question against single-use.
   Binding the hop to the User-Agent, the third option the issue raised, was
   not reconsidered — replayed trivially by anyone holding the cookie, and it
   would sign out a browser that updates mid-session.

What is accepted, after this amendment, is narrower than before it: a
rotated-away ID is still a bearer credential for its successor, unconsumed and
unbound, but for ten seconds instead of sixty, and the reasoning for leaving it
that shape is now written down here rather than only in `SessionRotation`'s
class docblock.

---

## References

- OWASP Session Management Cheat Sheet — Session Fixation
- Security review finding H4 (`plans/2026-03-17-backend-security-review.md`)
- `backend/src/Modules/Auth/Controllers/AuthController.php` — implementation site
