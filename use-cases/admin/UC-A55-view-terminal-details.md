# UC-A55: View Terminal Details

## Actor
Admin

## Preconditions
- Admin is logged in
- Terminal exists in system
- Terminal ID is valid

## Trigger
Admin clicks on a terminal name in the list or clicks "Details" button

## Main Flow
1. Admin navigates to Terminals section
2. Admin clicks on a terminal to view details
3. System displays terminal detail page with information:
   - Terminal ID (UUID)
   - Terminal Name
   - Device ID
   - Active Status (Yes/No)
   - Created Date and Time
   - Last Updated Date and Time
   - Last Sync Timestamp (if available, otherwise "Never")
4. System displays action buttons:
   - Edit (to modify name or status)
   - Rotate Token (to generate new API token)
   - Revoke Access (to disable terminal)
   - Delete (to soft-delete terminal)
   - Back to List
5. Admin can:
   - View all terminal information
   - Navigate to edit or other actions
   - Return to terminal list

## Displayed Fields

| Field | Value | Notes |
|-------|-------|-------|
| Terminal ID | UUID (e.g., 550e8400-e29b-41d4-a716-446655440000) | For reference/records |
| Name | User-defined name | "Bar Counter Terminal 1" |
| Device ID | Unique identifier | Hardware serial, MAC address, etc. |
| Status | Active/Inactive | Shows operational status |
| Created | ISO 8601 timestamp | Read-only, never changes |
| Updated | ISO 8601 timestamp | Changes on any update |
| Last Sync | Timestamp or "Never" | Shows terminal connectivity |

## Not Displayed
- API Token (never shown after creation)
- API Token Hash (stored in database, never exposed)
- Password fields (none exist)
- Sensitive credentials

## Response Format (Success)
```json
{
  "terminal": {
    "id": "550e8400-e29b-41d4-a716-446655440000",
    "name": "Bar Counter Terminal 1",
    "device_id": "HC-2024-001",
    "is_active": true,
    "last_sync_at": "2026-01-26T17:45:00Z",
    "created_at": "2026-01-20T09:00:00Z",
    "updated_at": "2026-01-26T18:00:00Z"
  }
}
```

## Postconditions
- Terminal detail page displayed
- All fields shown correctly
- Action buttons accessible
- Admin can perform additional operations

## Business Rules
- Terminal detail is read-only (cannot edit directly from this page)
- Must click "Edit" button to modify terminal
- API token never shown (for security)
- Terminal must exist (404 if deleted)

## API Mapping
- Endpoint: GET /api/admin/terminals/{id}
- Response: 200 OK with TerminalDto (no api_token field)
- 404 if terminal not found

## Test Derivation
- Get details: verify all fields present and correct
- Field content: verify each field shows expected data
- ISO 8601 timestamps: verify date format is correct
- No token exposure: verify api_token not in response
- Last sync: verify timestamp shows for synced terminals
- Last sync never: verify "Never" for terminals without sync
- 404 error: verify error for nonexistent terminal ID
- Read-only: verify details cannot be edited on this page
- Actions available: verify Edit, Rotate, Revoke, Delete buttons present
- Status display: verify is_active=true shows "Active", false shows "Inactive"
