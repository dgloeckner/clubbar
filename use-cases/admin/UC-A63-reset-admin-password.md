# UC-A63: Reset Admin Password

**Implementation Status**: Implemented

## Actor
Admin

## Preconditions
- Admin is logged in
- Target admin account exists

## Trigger
Admin clicks "Reset Password" on admin user

## Main Flow
1. Admin clicks "Reset Password" on user
2. System displays confirmation dialog
3. Admin confirms
4. System generates new random password
5. System updates password
6. System displays new password once
7. Admin copies/notes password
8. Dialog closes

## Password Generation
- Same as UC-A62
- 16 characters, mixed complexity

## Postconditions
- Password updated
- Old password no longer works
- New password shown to resetter
- Target user's sessions invalidated
- Audit log entry

## Business Rules
- Can reset own password (but UC-A03 preferred)
- Can reset other admin's password
- Target user must re-login

## Test Derivation
- Reset password: old password fails
- New password works: target can login
- Sessions invalidated: target logged out
- Password shown once: not retrievable
- Audit log: reset logged
