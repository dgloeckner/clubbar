# UC-A81: Audit Log

**Implementation Status**: Implemented

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
| activate | Record reactivated |
| deactivate | Record deactivated |
| login | Admin logged in |
| logout | Admin logged out |
| login_failed | Failed login, or a failed step-up re-authentication |
| export | Data exported (reserved, not currently emitted) |
| transaction_storno | A booking reversed in full |
| settlement_create | Settlement created |
| settlement_cancel | Settlement cancelled |
| settlement_export | Settlement exported |
| settlement_submit | Settlement submitted to the bank |
| settlement_reverse | Settlement payment reversed, per member |
| collection_hold_placed | A collection hold placed on a member after a bank return |
| collection_hold_cleared | A collection hold cleared |
| totp_enrolled | Admin enabled two-factor authentication |
| totp_reset | An admin reset another admin's two-factor authentication |
| mandate_document_upload | SEPA mandate document uploaded |
| mandate_document_delete | SEPA mandate document deleted |
| anonymize | Member data anonymized (GDPR) |

See [ADR-0013](../../adr/0013-audit-logging.md#audit-actions) for the full old_values/new_values shape of each action.

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
