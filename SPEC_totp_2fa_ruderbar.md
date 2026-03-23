# Spec: TOTP Two-Factor Authentication for Ruderbar Admin

## Overview

Add mandatory TOTP-based two-factor authentication (RFC 6238) to the Ruderbar POS admin web frontend.
The implementation covers the Slim 4 PHP backend and the admin frontend. No external services or API keys are required.

Every admin user must complete 2FA enrollment before accessing the admin panel. This applies to all new accounts and to existing accounts after an admin 2FA reset.

---

## Scope

- TOTP setup flow (QR code, secret generation, verification)
- Modified login flow (password → mandatory MFA step or mandatory setup step)
- Admin reset flow (remove 2FA from any user account; forces re-enrollment on next login)
- Database migration
- No recovery codes (out of scope — admin reset is sufficient)
- No WebAuthn (out of scope)

---

## Stack

| Layer    | Technology                          |
|----------|-------------------------------------|
| Backend  | PHP 8.3, Slim 4                     |
| 2FA Lib  | `robthree/twofactorauth`            |
| Database | MariaDB                             |
| Auth     | Session-based (existing, no JWT)    |
| Frontend | React SPA admin panel (existing)    |

---

## Database Migration

Add two columns to the `admin_users` table (new migration file, not modifying `001_initial_schema.sql`):

```sql
ALTER TABLE admin_users
    ADD COLUMN totp_secret     VARCHAR(255) NULL    COMMENT 'AES-encrypted TOTP secret',
    ADD COLUMN totp_enabled    TINYINT(1)   NOT NULL DEFAULT 0;
```

- `totp_secret`: stores the TOTP secret, AES-encrypted at rest using an app-level key from `.env`
- `totp_enabled`: `0` = 2FA not configured, `1` = 2FA active
- Pending secret during enrollment is held in `$_SESSION` until confirmed — never persisted prematurely

---

## Backend Endpoints

All routes follow the existing `/api/auth/` prefix.

### `POST /api/auth/login`

**Existing endpoint — modify behavior.**

After successful password verification, three outcomes are possible:

| Condition | Session state | Response |
|-----------|--------------|----------|
| `totp_enabled = 1` | Set `mfa_pending_user_id` + `mfa_pending_expires` in session; do NOT set `admin_user_id` | `{ "requiresMfa": true }` |
| `totp_enabled = 0` | Set `admin_user_id`, `csrf_token`, `totp_setup_required = true` in session | `{ "requiresTotpSetup": true, "admin": {...}, "csrf_token": "..." }` |

The third outcome (fully authenticated, no further step) only applies to users who have already enrolled (`totp_enabled = 1`) and are completing the MFA step via `POST /api/auth/mfa`.

Response when MFA verification is required (enrolled user):
```json
{
  "requiresMfa": true
}
```

Response when 2FA setup is required (new or reset user):
```json
{
  "requiresTotpSetup": true,
  "admin": {
    "id": "<uuid>",
    "email": "admin@example.org",
    "display_name": "Admin Name",
    "locale": "de",
    "last_login_at": null,
    "totp_enabled": false
  },
  "csrf_token": "<token>"
}
```

The `requiresTotpSetup` response issues a full session because the user needs an authenticated context to call `/api/auth/2fa/setup` and `/api/auth/2fa/confirm`. However, access to all other admin endpoints is blocked by middleware until setup is complete (see Backend Enforcement below).

---

### `POST /api/auth/mfa`

Exchange a valid MFA-pending session + TOTP code for a fully authenticated session.

Request:
```json
{
  "code": "123456"
}
```

Logic:
1. Verify `$_SESSION['mfa_pending_user_id']` exists and `mfa_pending_expires > time()`
2. Load admin user, decrypt `totp_secret`
3. Verify code using `TwoFactorAuth::verifyCode()` (allow ±1 window for clock skew)
4. On success:
   - Regenerate session ID (session fixation prevention, consistent with login)
   - Set `$_SESSION['admin_user_id'] = $userId`
   - Set `$_SESSION['csrf_token'] = bin2hex(random_bytes(32))`
   - Unset `mfa_pending_user_id` and `mfa_pending_expires`
   - Return full login response
5. On failure: return `401`; do not destroy MFA-pending session (user may retry within TTL)

Response on success — same shape as existing login success:
```json
{
  "message": "Login successful",
  "admin": { "id": "...", "email": "...", "display_name": "...", "locale": "de", "last_login_at": "...", "totp_enabled": true },
  "csrf_token": "<token>"
}
```

This endpoint is public (no `AdminSessionAuth` middleware) — the session carries the MFA-pending state.

---

### Backend Enforcement (Middleware)

When `POST /api/auth/login` issues a session for a user with `totp_enabled = 0`, it sets `$_SESSION['totp_setup_required'] = true`.

The `AdminSessionAuth` middleware must check this flag on every request:

- If `$_SESSION['totp_setup_required'] === true` AND the route is **not** `/api/auth/2fa/setup` or `/api/auth/2fa/confirm`:
  - Return `403` with `{ "error": "totp_setup_required", "message": "Two-factor authentication setup is required before accessing the admin panel." }`

After `POST /api/auth/2fa/confirm` succeeds, unset `$_SESSION['totp_setup_required']`.

This ensures mandatory enrollment cannot be bypassed via direct API calls. After an admin resets another user's 2FA, enforcement takes effect on that user's **next login** (existing sessions remain valid until expiry, consistent with how session invalidation works elsewhere in this system).

---

### `POST /api/auth/2fa/setup`

Begin 2FA enrollment for the currently authenticated user.

- Requires valid authenticated session (`AdminSessionAuth` middleware)
- Accessible even when `totp_setup_required = true` (mandatory setup flow)
- Generates a new TOTP secret via `TwoFactorAuth::createSecret()`
- Stores secret in `$_SESSION['totp_pending_secret']` (overwrite if called again)
- Returns a base64-encoded QR code image and the plain-text secret

Response:
```json
{
  "qrCode": "data:image/png;base64,<...>",
  "secret": "JBSWY3DPEHPK3PXP"
}
```

---

### `POST /api/auth/2fa/confirm`

Confirm enrollment by verifying the first TOTP code entered by the user.

- Requires valid authenticated session
- Accessible even when `totp_setup_required = true`

Request:
```json
{
  "code": "123456"
}
```

Logic:
1. Load `$_SESSION['totp_pending_secret']` — return `400` if not present
2. Verify code against pending secret
3. On success:
   - AES-encrypt the secret
   - Write to `admin_users.totp_secret`, set `totp_enabled = 1`
   - Unset `$_SESSION['totp_pending_secret']`
   - Unset `$_SESSION['totp_setup_required']`
4. On failure: return `401`

---

### `POST /api/auth/2fa/reset`

Remove 2FA from any user account. Requires the caller to have a valid authenticated session (any logged-in admin).

Request:
```json
{
  "userId": "<uuid>"
}
```

Note: `userId` is a UUID string (CHAR(36)), consistent with all other IDs in this system.

Logic:
1. Validate `userId` is a non-empty string
2. Set `totp_secret = NULL`, `totp_enabled = 0` for the given user
3. Return `200 OK`

> After reset, the affected user must re-enroll on their next login. Their current session (if active) remains valid until it expires naturally.

---

## Secret Storage

- Encrypt `totp_secret` with AES-256-CBC before writing to DB
- Use a symmetric app key stored in `.env` as `TOTP_ENCRYPTION_KEY`
- Decrypt on read before passing to `TwoFactorAuth::verifyCode()`

Store IV alongside the ciphertext as `base64(iv):base64(ciphertext)` in the single `totp_secret` column.

---

## OAS Changes (`api/admin.yaml`)

### Modified: `POST /api/auth/login`

The `200` response now covers three mutually exclusive shapes:

```yaml
responses:
  '200':
    description: Login result — one of three outcomes
    content:
      application/json:
        schema:
          oneOf:
            - $ref: '#/components/schemas/LoginSuccessResponse'
            - $ref: '#/components/schemas/MfaRequiredResponse'
            - $ref: '#/components/schemas/TotpSetupRequiredResponse'
```

New schemas:

```yaml
MfaRequiredResponse:
  type: object
  required: [requiresMfa]
  properties:
    requiresMfa:
      type: boolean
      enum: [true]

TotpSetupRequiredResponse:
  type: object
  required: [requiresTotpSetup, admin, csrf_token]
  properties:
    requiresTotpSetup:
      type: boolean
      enum: [true]
    admin:
      $ref: '#/components/schemas/AdminProfile'
    csrf_token:
      type: string
```

### New: `POST /api/auth/mfa`

Public endpoint (no session auth required):

```yaml
/auth/mfa:
  post:
    summary: Complete MFA login step
    operationId: completeMfaLogin
    requestBody:
      required: true
      content:
        application/json:
          schema:
            type: object
            required: [code]
            properties:
              code:
                type: string
                pattern: '^\d{6}$'
    responses:
      '200':
        description: MFA verified, session fully authenticated
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/LoginSuccessResponse'
      '401':
        $ref: '#/components/responses/Unauthorized'
```

### New: `POST /api/auth/2fa/setup`, `POST /api/auth/2fa/confirm`, `POST /api/auth/2fa/reset`

Add under the authenticated `/api/auth` group. See endpoint sections above for shapes.

### New: error code `totp_setup_required`

Add `totp_setup_required` to the `ErrorResponse.error` enum (alongside existing codes like `invalid_credentials`). Returned as `403` when an authenticated-but-not-enrolled session accesses a blocked endpoint.

### Modified: `AdminProfile` schema

```yaml
AdminProfile:
  type: object
  properties:
    # ... existing fields (id, email, display_name, locale, last_login_at) ...
    totp_enabled:
      type: boolean
      description: Whether TOTP two-factor authentication is active for this account
```

`totp_enabled` is returned by:
- `GET /api/auth/profile`
- `POST /api/auth/login` (in the `admin` object of `LoginSuccessResponse` and `TotpSetupRequiredResponse`)
- `GET /api/admin/admin-users` (list) — so the admin panel can show per-user 2FA status and "Reset 2FA" button

---

## Frontend Changes

### Login Screen (`LoginPage.tsx`)

Three UI states driven by the login API response:

1. **Default**: email/password form
2. **`requiresMfa: true`**: replace form with 6-digit code input; submit to `POST /api/auth/mfa`; on success proceed as normal login
3. **`requiresTotpSetup: true`**: redirect to (or show) the mandatory 2FA setup flow (see below); user cannot navigate away until setup is complete

The session cookie is already set after step 1; no extra token is needed in step 2 or 3.

### Mandatory 2FA Setup Flow (triggered from login)

Shown as a full-page gate (not a dismissible modal) when `requiresTotpSetup: true`:

1. Call `POST /api/auth/2fa/setup` → display QR code + manual secret
2. Instruction: *"Scan this QR code with your authenticator app, then enter the 6-digit code to confirm."*
3. User enters code → `POST /api/auth/2fa/confirm`
4. On success: redirect to admin panel home

There is no "skip" or "cancel" option. The back button returns to login.

### Profile Page (`ProfilePage.tsx`)

Add a **"Two-Factor Authentication"** section driven by `admin.totp_enabled`:

- `totp_enabled = false`: show "Enable 2FA" button → triggers the same setup flow as above, but as a dismissible modal (voluntary re-enrollment after reset)
- `totp_enabled = true`: show "2FA is active" status indicator (no self-disable option)

### Admin Users Page

Add a **"Reset 2FA"** action per user row:

- Only shown when the user's `totp_enabled = true`
- Confirmation dialog: *"This will remove 2FA from [display_name]. They will need to re-enroll on next login."*
- On confirm: `POST /api/auth/2fa/reset` with `{ "userId": "<uuid>" }`
- On success: update row to reflect `totp_enabled = false`

---

## Onboarding Flow

### First Admin (Installation)

The first admin account is created via the installation/seed script with a temporary password.
On first login:
- Password is accepted, `totp_enabled = 0` → `requiresTotpSetup: true`
- Admin must complete 2FA enrollment before accessing the panel
- No special installation-time exception needed

### Subsequent Admin Accounts

Created by an existing admin via the Admin Users page:
1. Existing admin sets email, display name, and a temporary password
2. New admin logs in with the temporary password → `requiresTotpSetup: true`
3. New admin completes 2FA enrollment
4. New admin can optionally change their password from the profile page

---

## UX Decisions

| Decision | Choice |
|---|---|
| Is 2FA mandatory? | **Yes — required for all admin users** |
| When is 2FA enforced? | On every login; new accounts are gated before first access |
| Can a user disable their own 2FA? | No — admin reset only |
| What happens after admin reset? | User must re-enroll on next login; current session valid until expiry |
| Who can reset 2FA? | Any logged-in admin (no role system in this project) |
| Failed MFA attempts lockout? | Not required for v1 |

---

## Security Requirements

- MFA-pending session must expire after 5 minutes (enforced by `mfa_pending_expires` in `$_SESSION`)
- Session ID regenerated at both the MFA-pending step and full authentication step
- TOTP secrets must be encrypted at rest (`TOTP_ENCRYPTION_KEY` in `.env`)
- QR code / plain secret served once per setup call; not stored server-side after confirmation
- `AdminSessionAuth` middleware must block all non-2fa endpoints when `totp_setup_required = true`
- `POST /api/auth/2fa/reset` requires a valid authenticated session
- Use HTTPS in production (prevents TOTP code interception in transit)
- TOTP window: accept current ± 1 interval (covers ~90 seconds of clock skew)

---

## Out of Scope

- Recovery codes
- WebAuthn / Passkeys
- Email / SMS OTP
- Self-service 2FA disable
- Per-device "remember this device" tokens
- Failed MFA attempt lockout (v1)
- Forced password change on first login

---

## Acceptance Criteria

- [ ] A user with `totp_enabled = 1` must enter a valid TOTP code after password login before accessing the admin panel
- [ ] A user with `totp_enabled = 0` is issued a session but blocked from all endpoints except `/api/auth/2fa/setup` and `/api/auth/2fa/confirm`
- [ ] A user with `totp_enabled = 0` is shown a mandatory (non-dismissible) 2FA setup screen after login
- [ ] A user can enroll TOTP via QR code and confirm with a valid code
- [ ] After enrollment, `totp_enabled = 1` and full admin panel access is granted
- [ ] An invalid or expired TOTP code is rejected with `401`
- [ ] An MFA-pending session expires after 5 minutes
- [ ] An admin can reset 2FA for any user
- [ ] After reset, the affected user must re-enroll before accessing the admin panel on their next login
- [ ] The QR code is not retrievable after the setup flow is completed (new secret generated on each setup call)
- [ ] `totp_secret` is stored encrypted in the database
- [ ] `totp_enabled` is returned in the profile response and admin users list
- [ ] The first admin created during installation is subject to the same mandatory enrollment flow
