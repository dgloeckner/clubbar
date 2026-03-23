# ADR-0026: Mandatory TOTP Two-Factor Authentication for Admin Panel

**Status**: Accepted
**Date**: 2026-03-22

---

## Context

The admin panel provides access to member personal data (names, IBANs), financial settlement workflows, and GDPR anonymization operations. A compromised admin account would expose this data and enable destructive actions.

The existing single-factor authentication (email + password) is vulnerable to:

- Credential stuffing and password reuse attacks
- Phishing (password entered on a fake login page)
- Brute force (mitigated by rate limiting, but not eliminated)

Adding a second factor that an attacker cannot obtain remotely significantly reduces these risks. TOTP (RFC 6238, time-based one-time passwords) is the most practical option for a self-hosted system with no external dependencies.

---

## Decision

**TOTP two-factor authentication is mandatory for all admin users.**

Every admin account must complete TOTP enrollment before accessing any admin functionality. Accounts created by another admin (or during installation) are gated behind a mandatory setup flow on first login.

### Key design choices

| Aspect | Decision | Rationale |
|--------|----------|-----------|
| Mandatory vs optional | Mandatory | Optional 2FA protects only users who opt in; admin accounts are high-value targets |
| Second factor type | TOTP (RFC 6238) | No external service required; works offline; widely supported by authenticator apps |
| Secret storage | AES-256-CBC encrypted at rest | Protects secrets if the database is compromised |
| Self-disable | Not allowed | Prevents social engineering ("just disable it for me") |
| Reset mechanism | Any logged-in admin can reset another admin's 2FA | No role system; admin-to-admin recovery is sufficient |
| Pending secret storage | PHP `$_SESSION` (TTL 10 min) | Consistent with existing session-based auth; no additional infrastructure |
| MFA-pending state during login | PHP `$_SESSION` (TTL 5 min) | Avoids JWT for a single-use intermediate state; consistent with ADR-0015 |
| Enforcement layer | `AdminSessionAuth` middleware | Centralised; cannot be bypassed by direct API calls |

### Enrollment gate

When a user with `totp_enabled = 0` logs in:
- A full session is issued (so the user can call the 2fa/setup and 2fa/confirm endpoints)
- `totp_setup_required = true` is stored in the session
- The middleware blocks all other admin endpoints with `403 totp_setup_required`
- The frontend shows a mandatory, non-dismissible setup screen

This is distinct from the MFA verification step for enrolled users (`totp_enabled = 1`), where the session is not issued until the TOTP code is verified.

### Recovery after admin reset

When an admin resets another user's 2FA:
- `totp_enabled = 0` and `totp_secret = NULL` are written to the DB
- The affected user's active session (if any) remains valid until natural expiry
- On next login, the affected user goes through the mandatory enrollment gate

This is a deliberate trade-off: immediate session invalidation would require server-side session enumeration. Given that the admin who performs the reset is trusted, the window between reset and re-enrollment is acceptable.

---

## Consequences

### Positive

- Compromised passwords alone are insufficient to access admin data
- Mandatory enrollment ensures there are no unprotected accounts
- No external service dependency (works fully offline and on self-hosted infrastructure)
- TOTP is a well-understood standard with broad authenticator app support

### Negative

- **Onboarding friction**: New admins must install an authenticator app before first access
- **Device loss risk**: If an admin loses their authenticator device, another admin must reset their 2FA — there are no self-service recovery options
- **No recovery codes**: Out of scope for v1; admin reset is the recovery mechanism

### Mitigations

- Admin reset is available to any logged-in admin, so recovery does not require a superadmin
- TOTP is supported by many apps (Google Authenticator, Bitwarden, 1Password, Aegis, etc.) including those with cloud backup, reducing device-loss risk
- Recovery codes can be added in a future version if the device-loss scenario becomes a support burden

---

## Alternatives Considered

### Optional 2FA

**Rejected**: Optional 2FA creates a mixed security posture. Accounts without 2FA remain vulnerable, and there is no operational guarantee that all high-privilege accounts are protected.

### WebAuthn / Passkeys

**Rejected**: Higher security than TOTP but adds complexity (credential registration per device, attestation). Shared hosting may lack the required browser/server integration. Can be added later without removing TOTP.

### Email OTP

**Rejected**: Requires email server infrastructure and introduces a dependency on email deliverability. Slower UX. Email accounts can themselves be compromised.

### Recovery codes

**Deferred to v1+**: Admin reset covers the same recovery scenario without storing additional secrets. If admin-to-admin recovery proves operationally difficult, recovery codes can be added.

---

## Related Decisions

- [ADR-0015](./0015-authentication-and-authorization-strategy.md): Authentication and Authorization Strategy
- [ADR-0025](./0025-session-fixation-protection.md): Session Fixation Protection
- [ADR-0016](./0016-transport-security.md): Transport Security (HTTPS required; TOTP codes interceptable without TLS)
- [ADR-0013](./0013-audit-logging.md): Audit Logging (MFA events should be logged)
