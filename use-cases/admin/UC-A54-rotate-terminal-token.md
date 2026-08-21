# UC-A54: Rotate Terminal Token

**Implementation Status**: Implemented

## Actor
Admin

## Preconditions
- Admin is logged in
- Terminal exists in system with active or expired API token
- Terminal ID is valid

## Trigger
Admin clicks "Rotate token" for a terminal on the Settings → Security & Credentials page

## Main Flow
1. Admin navigates to Settings → Security & Credentials
2. Admin clicks "Rotate token" for the target terminal
3. System displays a confirmation dialog explaining that the current token
   keeps working until the new one is used at the terminal for the first
   time, and asks for the admin's current password (step-up, ADR-0036)
4. Admin confirms with their password
5. System generates a new secure 64-character API token and stores it as a
   **pending** token alongside the current one (current token is untouched)
6. System logs the rotation to the audit log (`terminal_token_rotated`)
7. System emails every active `admin` account a security notice that a new
   credential was issued for this terminal (ADR-0043) — out of band from
   whichever admin session performed the rotation. The lesser offices are not
   written to: the terminal screen this notice is about is `admin`-only
   (ADR-0044, #633)
8. System displays the new token in the response (plaintext, one-time
   display)
9. Both the current and the pending token authenticate the terminal from
   this point on

## Token Rotation Effects
- Current token hash is **not** replaced or invalidated — it keeps
  authenticating
- A `pending_token_hash` (with its own expiry) is stored alongside it
- `last_sync_at` is **not** cleared; the terminal keeps syncing on its
  current token exactly as before, so the Dashboard's Terminal-Status
  widget (which derives ONLINE/OFFLINE purely from `last_sync_at`, not from
  which token is in use) is unaffected by rotation and correctly keeps
  showing ONLINE
- The next request the terminal makes with the **new** token promotes it:
  the pending token becomes the active one and the old token is retired in
  the same statement (`TerminalTokenAuthenticator::promote()`, audit action
  `terminal_token_activated`). Nothing on the admin side has to declare the
  rotation "finished" — first use does it
- A token that was written down but never entered at the terminal never
  displaces the one still in service
- A terminal whose current token had already expired rotates the same way,
  but there is nothing to overlap with: the pending token is accepted on
  its own as soon as it is entered

## Response Format (Success)
```json
{
  "terminal": {
    "id": "550e8400-e29b-41d4-a716-446655440000",
    "name": "Bar Counter Terminal 1",
    "device_id": "HC-2024-001",
    "is_active": true,
    "last_sync_at": "2026-08-18T18:40:00Z",
    "token_issued_at": "2025-08-20T09:00:00Z",
    "token_expires_at": "2026-08-20T09:00:00Z",
    "pending_token_expires_at": "2027-08-18T18:55:00Z",
    "has_pending_token": true,
    "created_at": "2025-08-20T09:00:00Z",
    "updated_at": "2026-08-18T18:55:00Z"
  },
  "api_token": "x9y8z7w6v5u4t3s2r1q0p9o8n7m6l5k4j3i2h1g0f9e8d7c6b5a4z3y2x1w0",
  "message": "Token rotated successfully. The new API token will not be shown again. The current token keeps working until the new one is used at the terminal for the first time."
}
```

## Security Rules
- New token generated with a cryptographically secure random number
  generator
- Rotation requires step-up (current password, ADR-0036) on top of the
  admin session — the same gate as other credential-minting actions
- Every active admin is notified by email when a token is issued or
  rotated for a terminal (ADR-0043), independent of the session that
  performed the rotation
- New token shown only once in the rotation response; no subsequent API
  call returns it in plaintext
- Both tokens authenticate during the overlap window; the pending token's
  own expiry (`pending_token_expires_at`) is set from the same TTL config
  as a fresh token

## Postconditions
- Terminal has both a current, active token hash and a pending token hash
- Old token remains valid and `last_sync_at` continues to update on it
  until the terminal switches over
- Rotation recorded in the audit log (`terminal_token_rotated`); promotion
  is recorded separately (`terminal_token_activated`) the first time the
  terminal authenticates with the new token
- All active admins have been (best-effort) emailed a security notice

## Business Rules
- Rotation is an **overlap**, not a cut: the old token is never invalidated
  by the rotate action itself, only by the terminal's first use of the new
  one
- Should be used when a token may be compromised, is nearing expiry, or as
  routine credential hygiene
- Rotation never forces the terminal offline or interrupts sales — this is
  the reason overlap rotation replaced immediate invalidation (#395)
- Cannot rotate a token for a nonexistent terminal
- "Rotation pending" (an issued-but-not-yet-used replacement) is a distinct
  state from the Dashboard's ONLINE/OFFLINE status; it is surfaced on the
  Security & Credentials page (`has_pending_token`), not on the Dashboard

## API Mapping
- Endpoint: `POST /api/admin/terminals/{id}/rotate-token`
- Request: `{ "current_password": "..." }` (step-up credentials)
- Response: 200 OK with `TerminalWithToken` (includes `has_pending_token`,
  `pending_token_expires_at`) + new plaintext token
- Audit Log: `terminal_token_rotated` on issuance; `terminal_token_activated`
  on first use of the new token
- Mail: `TERMINAL_TOKEN_ISSUED` notice to all active admins (ADR-0043)

## Test Derivation
- Rotate token: verify new pending token generated and returned
- Token changed: verify new token differs from the current one
- Token length: verify new token is 64 hex characters
- Old token still valid: verify the previous token still authenticates
  after rotation
- New token valid: verify the terminal can authenticate with the new token
- Promotion on first use: verify that authenticating with the new token
  retires the old one and clears `has_pending_token`
- `last_sync_at` unaffected by rotation itself: verify it is not cleared by
  the rotate call
- Step-up enforced: verify rotation without a valid `current_password` is
  rejected
- Audit log: verify `terminal_token_rotated` logged on rotation and
  `terminal_token_activated` logged on promotion
- Admin notification: verify a `TERMINAL_TOKEN_ISSUED` mail is queued to
  active admins
- 404 for nonexistent: verify error if terminal doesn't exist
- Token not repeated: rotate twice, verify the second pending token differs
  from the first
- Message included: verify the response message explains the overlap
  behavior
