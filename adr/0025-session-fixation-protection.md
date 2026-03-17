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

## References

- OWASP Session Management Cheat Sheet — Session Fixation
- Security review finding H4 (`plans/2026-03-17-backend-security-review.md`)
- `backend/src/Modules/Auth/Controllers/AuthController.php` — implementation site
