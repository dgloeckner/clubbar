# UC-A22: Export Transactions

**Implementation Status**: Implemented (diverges from spec)
**Divergence**: No standalone export button in Journal UI. Transaction export is covered via settlement CSV exports. Backend endpoint exists. Confirmed acceptable by stakeholder.

## Actor
Admin

## Preconditions
- Admin is logged in

## Trigger
Admin opens Reports → Transactions → Export

## Main Flow
1. Admin navigates to Reports → Transactions
2. Admin selects date range
3. Admin optionally filters by member or product
4. Admin clicks "Export CSV"
5. System generates CSV file
6. Browser downloads file

## Filter Options

| Filter | Description |
|--------|-------------|
| Date range | From/to dates (required) |
| Member | Specific member or all |
| Product | Specific product or all |
| Type | Purchase, Correction, or all |

## CSV Format

```
date;member_name;product;type;amount
2024-01-15 14:23:00;Max Mustermann;Beer 0.5L;purchase;3.50
2024-01-15 14:25:00;Max Mustermann;;adjustment;-3.50
```

| Column | Content |
|--------|---------|
| date | ISO timestamp |
| member_name | Full name |
| product | Product name (empty for adjustments) |
| type | purchase/correction |
| amount | Signed decimal |

## Filename Format
`transactions-YYYY-MM-DD-to-YYYY-MM-DD.csv`

## Postconditions
- CSV file downloaded
- Audit log entry for export

## Test Derivation
- Export all: all transactions in range included
- Filter by member: only that member's transactions
- Filter by product: only that product
- Date range: only transactions within range
- Empty result: file with headers only
- File format: valid CSV, correct encoding
- Filename: contains date range
- Audit log: export logged
