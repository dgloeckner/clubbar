# UC-A03: Change Password

**Implementation Status**: Implemented

## Actor
Admin

## Preconditions
- Admin is logged in

## Trigger
Admin opens Settings → My Account

## Main Flow
1. Admin navigates to account settings
2. System displays password change form
3. Admin enters current password
4. Admin enters new password
5. Admin confirms new password
6. Admin enters a current code from their authenticator app
7. Admin submits form
8. System verifies the step-up credential (current password **and** TOTP code)
9. System validates new password requirements
10. System updates password
11. System displays success message

## Postconditions
- Password updated
- **The acting session remains valid**; every other session on this account is invalidated
- Next login requires new password

## Step-Up Re-Authentication

The current password alone is not enough: it is exactly what an attacker holding
a hijacked session already has, and rotating it is the first thing they would do.
The form therefore asks for the same credential the cross-account resets ask for
— the caller's own password *and* their own fresh 6-digit TOTP code (ADR-0015,
ADR-0036). The code field appears only for accounts with 2FA enrolled, which
under ADR-0026 is every account past the enrolment gate.

## Password Requirements
- Minimum 8 characters
- At least one uppercase letter
- At least one lowercase letter
- At least one digit

## Error Cases

### E1: Current Password Incorrect
- Display "Current password incorrect"
- Form not cleared
- The attempt is counted against the login rate limiter (5 per 15 minutes, per account) and audited as a failed step-up

### E0: 2FA Code Missing or Wrong
- Display the credential error; the form stays filled so the code can be retried
- The admin is **not** signed out — a rejected credential is not a dead session
- Counted against the same rate limiter as E1

### E2: Passwords Don't Match
- New and confirm fields differ
- Display "Passwords do not match"

### E3: Requirements Not Met
- Display specific requirement not met
- Example: "Password must be at least 8 characters"

## Test Derivation
- Happy path: change password → success message → **the new password authenticates**
- Wrong current password: error shown
- Missing TOTP code on an enrolled account: rejected
- Wrong TOTP code: rejected, admin stays signed in
- Mismatched passwords: error shown
- Too short: requirement error
- Missing uppercase: requirement error
- Missing digit: requirement error
- Login with new password: success
- Login with old password: failure
- A second session on the same account stops working; the acting one does not

A happy-path test must complete the **request**, not just the form. Every
password test here once asserted a client-side rejection, all of which return
before any HTTP call — which is how a frontend calling `POST … confirm_password`
against a backend serving `PATCH … new_password_confirmation` shipped unnoticed.

## Related
- [UC-A04](./UC-A04-change-email.md): Change own email address
- [ADR-0015](../../adr/0015-authentication-and-authorization-strategy.md): Authentication strategy, self-service credential changes
