# UC-A01: Login

**Implementation Status**: Implemented

## Actor
Admin

## Preconditions
- Admin has valid credentials
- Admin is not already logged in

## Trigger
Admin navigates to admin panel URL

## Main Flow
1. System displays login form
2. Admin enters email and password
3. Admin submits form
4. System validates credentials
5. System creates session
6. System redirects to dashboard

## Postconditions
- Session established (cookie set)
- Admin can access protected routes

## Error Cases

### E1: Invalid Credentials
- Email or password incorrect
- Display "Invalid credentials" message
- Form cleared, focus on email field
- No session created

### E2: Account Disabled
- Credentials valid but account inactive
- Display "Account disabled" message
- No session created

### E3: Rate Limited
- Too many failed attempts: 5 in 15 minutes, counted on **two** dimensions —
  per source IP and per account (ruling #145). Failed TOTP codes count too.
- Display "Too many attempts, try again later"
- Always windowed, never a permanent lock: an admin waits out the window rather
  than needing someone to unlock the account, so knowing an admin's email is not
  a denial-of-service lever
- The counter is cleared only after a **full** authentication (password *and*
  second factor), and only for that account — other accounts' attempts from the
  same host keep counting

### E4: Too Many Invalid TOTP Codes
- 5 wrong codes end the MFA-pending session; the password must be entered again
- The failures are also recorded against the rate limiter, so re-entering the
  password does not hand out a fresh window of guesses
- Display "Too many invalid codes. Please sign in again." on the password form

## Test Derivation
- Happy path: valid credentials → dashboard shown
- Wrong password: display error, no session
- Wrong email: display error, no session
- Empty fields: validation error
- Disabled account: display disabled message
- Rate limiting: 6th attempt blocked
- Rate limiting, per account: 6th attempt blocked even from 6 different IPs
- MFA cap: 5 wrong codes → pending session destroyed, correct code then refused
- Re-mint loop: password → 5 wrong codes → password returns 429, not a new window
- Clearing scope: full login forgets that account's attempts, not another's
- Session persists: refresh page, still logged in
