# UC-A53: Delete Terminal

**Implementation Status**: Implemented

## Actor
Admin

## Preconditions
- Admin is logged in
- Terminal exists in system
- Terminal ID is valid

## Trigger
Admin clicks "Delete" button on a terminal in the list or detail view

## Main Flow
1. Admin navigates to Terminals section
2. Admin selects a terminal and clicks "Delete" button
3. System displays confirmation dialog:
   - "Are you sure you want to delete this terminal?"
   - "Terminal: [name]"
   - "Device ID: [device_id]"
   - Buttons: Cancel, Delete
4. Admin clicks "Delete" to confirm
5. System soft-deletes the terminal:
   - Sets is_active = false
   - Records timestamp of deletion
   - Logs deletion to audit log
   - Does NOT remove record from database
6. System displays success message
7. Terminal is removed from active list

## Soft Delete Behavior
- Terminal record remains in database for audit trail
- Inactive terminals do NOT appear in "list terminals" by default
- Inactive terminals CANNOT authenticate API requests
- Can be restored by setting is_active = true (reactivation)

## Response Format (Success)
```json
{
  "message": "Terminal deleted successfully"
}
```

## Postconditions
- Terminal is soft-deleted (is_active = false)
- Terminal removed from active terminal list
- Terminal cannot authenticate API requests
- Deletion recorded in audit log

## Business Rules
- Delete is soft-delete (records preserved for audit/compliance)
- Nonexistent terminal returns 404 error
- Deleted terminal can be reactivated by setting is_active=true
- Deletion does not remove API token hash from database (immutable record)

## API Mapping
- Endpoint: DELETE /api/admin/terminals/{id}
- Response: 200 OK with success message
- Audit Log: DEACTIVATE action logged with old is_active=true, new is_active=false

## Test Derivation
- Delete terminal: verify is_active set to false
- Verify deletion: terminal no longer in active list
- Verify 404: deleting nonexistent terminal returns 404
- Audit log: verify DEACTIVATE action logged
- Soft delete: verify record still exists in database
- Cannot authenticate: verify deleted terminal cannot use API token
- Can be restored: verify is_active can be set to true to reactivate
