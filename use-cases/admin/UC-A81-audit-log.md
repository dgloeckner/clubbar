# UC-A81: Audit Log

## Actor
Admin

## Preconditions
- Admin is logged in

## Trigger
Admin opens Settings → Audit Log

## Main Flow
1. Admin clicks "Audit Log" in Settings
2. System displays chronological log entries
3. Admin can filter and search
4. Admin can view entry details

## Log Entry Display

| Column | Content |
|--------|---------|
| Timestamp | Date and time |
| Admin | Who performed action |
| Action | What was done |
| Entity | What was affected |
| Details | Summary of changes |

## Action Types

| Action | Description |
|--------|-------------|
| create | New record created |
| update | Record modified |
| delete | Record removed/deactivated |
| login | Admin logged in |
| logout | Admin logged out |
| export | Data exported |
| settlement | Settlement created |
| anonymize | Member data anonymized |

## Filters

| Filter | Options |
|--------|---------|
| Date range | From/to |
| Admin | Specific admin or all |
| Action | Specific type or all |
| Entity type | Member, Product, etc. |
| Search | Text search in details |

## Entry Detail View
- Full before/after values
- Sensitive data masked
- IP address and user agent

## Postconditions
- Audit log displayed

## Data Retention
- Configurable retention period
- Default: 2 years
- GDPR: Anonymized entries retained

## Test Derivation
- All actions logged: verify each action type
- Filter by date: only matching entries
- Filter by admin: only that admin's actions
- Filter by action: only that type
- Detail view: shows full changes
- Sensitive data: masked in display
- Pagination: handles large logs
- Export: log can be exported
