# UC-A50: List Terminals

## Actor
Admin

## Preconditions
- Admin is logged in
- At least one terminal exists in the system

## Trigger
Admin opens Terminals section

## Main Flow
1. Admin navigates to Terminals management section
2. System displays paginated list of all terminals (20 per page by default)
3. Each terminal displays:
   - Terminal name
   - Device ID
   - Active status (Yes/No)
   - Last sync timestamp (if available)
   - Created date
4. Admin can:
   - Browse through pages
   - Filter by active status (Active only, Inactive, All)
   - Sort by column (optional)
   - Search by name or device ID (optional)

## List Columns

| Column | Content |
|--------|---------|
| Name | Terminal display name (e.g., "Bar Counter Terminal 1") |
| Device ID | Unique device identifier (hardware serial number, MAC address, etc.) |
| Status | Active (green) / Inactive (gray) |
| Last Sync | Timestamp of last successful API sync, or "Never" if never synced |
| Created | Creation date and time |
| Actions | View details, Edit, Delete |

## Filters

| Filter | Options |
|--------|---------|
| Status | All, Active only, Inactive |

## Pagination
- 20 terminals per page (configurable)
- Navigation controls at bottom of list
- Shows total terminal count

## Postconditions
- Terminal list displayed with pagination
- Filters applied correctly
- User can select a terminal for further actions

## API Mapping
- Endpoint: GET /api/admin/terminals?page=1&per_page=20&is_active=true
- Response: PaginatedResultDto with data array and pagination metadata

## Test Derivation
- List all terminals: verify correct count and fields present
- Filter by active: only active terminals (is_active=true) shown
- Filter by inactive: only inactive terminals (is_active=false) shown
- Pagination: navigate between pages, count correct
- Empty state: no terminals → display "No terminals found"
- Field display: all required columns visible
- Last sync: displays timestamp for synced terminals, "Never" for unsynced
- Link presence: each terminal has action buttons (view, edit, delete)
