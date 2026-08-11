# UC-A64: Manage Instance Branding

**Implementation Status**: Not Started

## Actor
Admin

## Preconditions
- Admin is logged in

## Trigger
Admin opens Settings → Instance

## Main Flow
1. Admin clicks "Instance" tab in Settings
2. System displays current instance name
3. Admin edits the instance name
4. Admin saves changes
5. System validates input
6. System updates configuration
7. System displays success message
8. Admin panel header/title, login page, and Terminal reflect the new name (Terminal on its next sync/health poll)

## Form Fields

| Field | Required | Validation |
|-------|----------|------------|
| Instance name | Yes | Non-empty, max 100 chars |

## Field Usage
- Instance name: Admin panel header, browser tab title, login page; Terminal header (unless overridden by a per-terminal `config.json` `displayName`, see issue #297); TOTP issuer shown in admins' authenticator apps

## Postconditions
- Instance configuration updated
- Audit log entry
- Admin SPA reflects the new name immediately (re-fetched on save)
- Terminal reflects the new name on its next `/health` poll
- New TOTP enrollments use the new name as issuer (existing enrollments in an authenticator app are unaffected — the issuer string is only set at enrollment time)

## Error Cases

### E1: Empty instance name
- Display "Instance name is required"

### E2: Instance name too long (> 100 chars)
- Display "Instance name must be 100 characters or fewer"

## Test Derivation
- Update instance name: saved correctly, returned on next `GET /instance-config`
- Empty instance name: validation error, 422
- Instance name over 100 chars: validation error, 422
- Login page (no session): shows configured instance name
- Admin header/browser title: shows configured instance name after save
- Audit log: change logged with old/new value
- Unauthenticated `GET /instance-config`: succeeds (public, no session required)
- Unauthenticated `PATCH /admin/instance-config`: rejected 401

## Related
- [ADR-0034: Instance Branding Configuration](../../adr/0034-instance-branding-configuration.md)
