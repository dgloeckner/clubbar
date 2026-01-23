# UC-A03: Change Password

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
6. Admin submits form
7. System validates current password
8. System validates new password requirements
9. System updates password
10. System displays success message

## Postconditions
- Password updated
- Session remains valid
- Next login requires new password

## Password Requirements
- Minimum 8 characters
- At least one uppercase letter
- At least one lowercase letter
- At least one digit

## Error Cases

### E1: Current Password Incorrect
- Display "Current password incorrect"
- Form not cleared

### E2: Passwords Don't Match
- New and confirm fields differ
- Display "Passwords do not match"

### E3: Requirements Not Met
- Display specific requirement not met
- Example: "Password must be at least 8 characters"

## Test Derivation
- Happy path: change password → success message
- Wrong current password: error shown
- Mismatched passwords: error shown
- Too short: requirement error
- Missing uppercase: requirement error
- Missing digit: requirement error
- Login with new password: success
- Login with old password: failure
