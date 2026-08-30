# UC-A62: Create Admin User

**Implementation Status**: Implemented

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
5. System creates admin account with **no usable password**
6. System mails an invitation link to the address (see
   [UC-A68](./UC-A68-invite-admin.md))
7. System shows the admin that the invitation was sent — the address, the
   expiry, and the link itself, so an installation whose mail is not working
   still has something to hand over
8. Dialog closes

## Form Fields

| Field | Required | Validation |
|-------|----------|------------|
| Email | Yes | Valid email, unique |
| Display Name | Yes | Max 100 characters |

## Credentials

**No password is generated.** The account is written with a hash of 32 random
bytes nobody has ever seen; the only way in is the invitation link, which the
invitee uses to set a password of their own. See
[UC-A68](./UC-A68-invite-admin.md) for the link's lifetime, single use and
revocation rules.

Until UC-A68, this step minted a 16-character password and handed it to the
creating admin, who then had to move a live credential to their colleague
through a channel of their own choosing.

## Postconditions
- Admin account created
- Account is active, and cannot be signed into until the invitation is accepted
- An invitation link has been queued to the account's address
- Audit log entries: `create`, `role_granted`, `invitation_sent`

## Error Cases

### E1: Duplicate Email
- Display "Email already exists"

### E2: Invalid Email
- Display "Invalid email format"

## Test Derivation
- Create admin: account active
- Response carries an invitation and **no** password
- Duplicate email: error shown
- New admin cannot log in until the invitation is accepted
- Audit log: creation logged
