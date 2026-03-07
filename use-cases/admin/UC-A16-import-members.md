# UC-A16: Import Members (CSV)

**Implementation Status**: Not implemented — nice to have

## Actor
Admin

## Preconditions
- Admin is logged in
- CSV file prepared

## Trigger
Admin opens Members → Import

## Main Flow
1. Admin clicks "Import Members"
2. System displays import dialog
3. System shows expected CSV format
4. Admin selects CSV file
5. System parses file
6. System displays preview with validation status
7. Preview shows:
   - Valid rows (green)
   - Invalid rows with errors (red)
   - Duplicate warnings (yellow)
8. Admin reviews preview
9. Admin clicks "Import"
10. System creates member records for valid rows
11. System displays summary (imported/skipped/errors)

## CSV Format

```
first_name;last_name;email;iban;mandate_date
Max;Mustermann;max@example.com;DE89370400440532013000;2024-01-15
Erika;Musterfrau;;DE89370400440532013001;2024-01-15
```

| Column | Required | Notes |
|--------|----------|-------|
| first_name | Yes | |
| last_name | Yes | |
| email | No | Valid format if provided |
| iban | No | Valid format + checksum |
| mandate_date | No | YYYY-MM-DD, required if IBAN |

## Validation Rules
- IBAN: Format and checksum validated
- Mandate date: Not in future
- Duplicate detection: Name match with existing members
- Required fields: first_name, last_name

## Postconditions
- Valid members created
- UUID and mandate reference generated per member
- Invalid rows skipped with error report
- Audit log entry with import summary

## Error Cases

### E1: Invalid File Format
- Not CSV or wrong delimiter
- Display "Invalid file format"

### E2: Missing Required Columns
- Header row incomplete
- Display "Missing columns: [list]"

### E3: Empty File
- No data rows
- Display "File contains no data"

## Test Derivation
- Valid file: all rows imported
- Mixed file: valid rows imported, invalid skipped
- Invalid IBAN: row skipped with error
- Duplicate name: warning shown, import allowed
- Missing required field: row skipped
- Future mandate date: row skipped
- Empty file: error message
- Wrong delimiter: error message
- Import summary: counts correct
- Audit log: import logged
