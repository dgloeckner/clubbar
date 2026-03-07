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
- Too many failed attempts (5 in 15 minutes)
- Display "Too many attempts, try again later"
- Account temporarily locked

## Test Derivation
- Happy path: valid credentials → dashboard shown
- Wrong password: display error, no session
- Wrong email: display error, no session
- Empty fields: validation error
- Disabled account: display disabled message
- Rate limiting: 6th attempt blocked
- Session persists: refresh page, still logged in
