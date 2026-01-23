# UC-A62: Create Admin User

## Actor
Admin

## Preconditions
- Admin is logged in

## Trigger
Admin clicks "New Admin" in admin list

## Main Flow
1. Admin clicks "New Admin"
2. System displays form
3. Admin enters:
   - Email/username
   - Role
4. Admin submits form
5. System generates random password
6. System creates admin account
7. System displays password once
8. Admin copies/notes password
9. Dialog closes

## Form Fields

| Field | Required | Validation |
|-------|----------|------------|
| Email | Yes | Valid email, unique |
| Display Name | Yes | Max 100 characters |

## Password Generation
- 16 characters
- Mixed case, numbers, symbols
- Shown once, not retrievable

## Postconditions
- Admin account created
- Account is active
- Password shown to creator
- Audit log entry

## Error Cases

### E1: Duplicate Email
- Display "Email already exists"

### E2: Invalid Email
- Display "Invalid email format"

## Test Derivation
- Create admin: account active
- Generated password: meets complexity
- Password shown once: not in response later
- Duplicate email: error shown
- New admin can login: with generated password
- Audit log: creation logged
