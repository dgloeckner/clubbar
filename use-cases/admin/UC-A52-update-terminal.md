# UC-A52: Update Terminal

## Actor
Admin

## Preconditions
- Admin is logged in
- Terminal exists in system
- Terminal ID is valid

## Trigger
Admin clicks "Edit" button on a terminal in the list or detail view

## Main Flow
1. Admin navigates to Terminals section
2. Admin selects a terminal and clicks "Edit" button
3. System displays edit form with current values:
   - Terminal Name (text, max 100 chars)
   - Active Status (toggle/checkbox)
4. Admin modifies desired field(s):
   - Can update name only
   - Can update status only
   - Can update both together
5. Admin clicks "Save" button
6. System validates input:
   - Name: optional, but if provided must be non-empty and max 100 characters
   - At least one field must be changed (no empty updates allowed)
7. If validation passes:
   - System updates terminal record in database
   - System logs change to audit log
   - System displays success message with updated terminal details
8. If validation fails:
   - System displays error messages
   - Form is preserved for correction

## Updateable Fields
| Field | Type | Constraints |
|-------|------|-------------|
| name | string | Max 100 chars, non-empty if updating |
| is_active | boolean | true or false |

## Response Format (Success)
```json
{
  "terminal": {
    "id": "550e8400-e29b-41d4-a716-446655440000",
    "name": "Bar Counter Terminal 1 (Updated)",
    "device_id": "HC-2024-001",
    "is_active": false,
    "created_at": "2026-01-26T18:00:00Z",
    "updated_at": "2026-01-26T18:15:30Z"
  }
}
```

## Postconditions
- Terminal updated with new values
- Change recorded in audit log
- No changes to API token or device_id
- No changes to creation timestamp

## Business Rules
- Device ID is read-only (cannot be changed via update)
- API token is never exposed or changed via edit endpoint
- Cannot update non-existent terminal
- At least one field must be updated in each request

## API Mapping
- Endpoint: PATCH /api/admin/terminals/{id}
- Request body (partial):
  ```json
  {
    "name": "Bar Counter Terminal 1 (Updated)",
    "is_active": false
  }
  ```
- Request body (name only):
  ```json
  {
    "name": "Updated Name"
  }
  ```
- Request body (status only):
  ```json
  {
    "is_active": false
  }
  ```
- Response: 200 OK with updated TerminalDto
- Audit Log: UPDATE action logged with old and new values

## Test Derivation
- Update name only: verify name changed, status unchanged
- Update status only: verify status changed, name unchanged
- Update both: verify both fields changed
- Validation - empty name: verify 422 error
- Validation - name too long: verify 422 error
- Validation - no fields provided: verify 422 error
- Validation - nonexistent terminal: verify 404 error
- Audit log: verify UPDATE action logged with old/new values
- Token not exposed: verify response doesn't include api_token_hash
- Timestamp not updated: verify created_at unchanged, updated_at changed
- Device ID readonly: verify device_id cannot be changed
