# UC-A61: Manage Admin Users

**Implementation Status**: Implemented

## Actor
Admin

## Preconditions
- Admin is logged in

## Trigger
Admin opens Settings → Administrators

## Main Flow
1. Admin clicks "Administrators" in Settings
2. System displays admin user list
3. List shows for each admin:
   - Email/username
   - Display name
   - Status (active/inactive)
   - Last login
4. Admin can add, edit, or deactivate users

## List Columns

| Column | Content |
|--------|---------|
| Username | Login identifier |
| Display Name | Human-readable name |
| Status | Active/Inactive |
| Last Login | Timestamp |
| Actions | Edit, Reset password, Deactivate |

## Available Actions
- Create new admin (UC-A62)
- Reset password (UC-A63)
- Deactivate admin
- Reactivate admin

## Postconditions
- Admin list displayed

## Business Rules
- Cannot deactivate own account
- At least one active admin required

## Test Derivation
- List admins: all shown
- Last login: accurate timestamp
- Self-deactivate: prevented
- Last admin: cannot deactivate
