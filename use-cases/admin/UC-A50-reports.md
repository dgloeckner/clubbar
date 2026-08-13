# UC-A50: Reports

**Implementation Status**: Partially implemented — action needed (see plans/action-items-use-cases.md)

## Actor
Admin

## Preconditions
- Admin is logged in

## Trigger
Admin opens Reports section

## Overview

Unified reporting interface for analyzing transaction data. Supports multiple report types with configurable dimensions, metrics, filters, and visualizations.

## Main Flow
1. Admin opens Reports section
2. Admin selects report type (or starts with default: Revenue)
3. Admin configures:
   - Date range
   - Grouping dimensions
   - Filters
4. System calculates and displays results
5. Admin can:
   - Switch visualizations (table/chart)
   - Drill down into details

## Report Types

| Report | Primary Metric | Default Grouping | Description |
|--------|---------------|------------------|-------------|
| Revenue | Amount (€) | Category | Financial analysis |
| Consumption | Quantity | Product | Usage analysis |
| Transactions | Count | Day | Activity analysis |

All reports use the same underlying data and filters - only the default presentation differs.

## Dimensions (Group By)

| Dimension | Description | Drill-down |
|-----------|-------------|------------|
| Category | Product categories | → Products in category |
| Product | Individual products | → Transactions |
| Member | Per-member totals | → Member transactions |
| Day | Daily aggregation | → Hourly breakdown |
| Week | Weekly aggregation | → Daily breakdown |
| Month | Monthly aggregation | → Daily breakdown |
| Year | Yearly aggregation | → Monthly breakdown |

**Multiple dimensions**: Can combine e.g., "Category + Month" for category trends over time.

## Metrics

| Metric | Description | Format |
|--------|-------------|--------|
| Revenue | Sum of transaction amounts | € with 2 decimals |
| Quantity | Sum of transaction quantities | Integer |
| Transactions | Count of transactions | Integer |
| Avg. Transaction | Revenue / Transactions | € with 2 decimals |
| Avg. Quantity | Quantity / Transactions | Decimal (1 place) |

**Primary vs Secondary**: Each report type has a primary metric (shown in charts) but all metrics available in tables.

## Filters

| Filter | Type | Options |
|--------|------|---------|
| Date Range | Date picker | Last 7/30/90 days, This month/quarter/year, Custom |
| Category | Multi-select | All categories (including inactive) |
| Product | Multi-select | All products (including inactive) |
| Member | Search/select | Active and inactive members |
| Settlement Status | Select | All, Settled, Unsettled |

**Filter persistence**: Selected filters persist during session.

## Summary Cards

Dynamic based on current filters:

| Card | Content |
|------|---------|
| Total Revenue | Sum of filtered transactions |
| Total Quantity | Sum of filtered quantities |
| Transaction Count | Number of filtered transactions |
| Period Comparison | vs. previous period (%) |

## Table View

### Default Columns
| Column | Sortable | Description |
|--------|----------|-------------|
| Dimension | Yes | Category/Product/Member/Date |
| Revenue | Yes | Sum in € |
| Quantity | Yes | Units |
| Transactions | Yes | Count |
| % of Total | Yes | Share of filtered total |

### Sorting
- Click column header to sort
- Default: by primary metric descending
- Secondary sort: alphabetical on dimension

### Pagination
- 25/50/100 rows per page
- Total row count shown

## Chart View

### Chart Types
| Type | Best For | Dimensions |
|------|----------|------------|
| Bar | Category comparison | Category, Product |
| Line | Trends over time | Day, Week, Month |
| Pie | Distribution | Category (limited items) |
| Stacked Bar | Composition over time | Category + Time |

### Chart Controls
- Toggle between chart types
- Switch primary metric
- Show/hide data labels
- Zoom time range (for time charts)

## Drill-Down

Click any row or chart segment to drill down:

| From | To |
|------|----|
| Category | Products in that category |
| Product | Individual transactions |
| Month | Days in that month |
| Member | Member's transactions |

**Breadcrumb navigation**: Shows drill-down path, click to go back.

## Export

CSV export was removed from the implementation — it produced a plain, largely
unused download with none of the options described below, and was cut rather
than built out.

## Saved Reports

### Save Configuration
- Save current filters, grouping, and view as named report
- Quick access from dropdown

### Preset Reports
| Name | Configuration |
|------|---------------|
| Monthly Revenue | Revenue by Month, current year |
| Category Performance | Revenue by Category, last 30 days |
| Top Products | Quantity by Product, last 30 days, sorted desc |
| Settlement Prep | Unsettled transactions by Member |

## Postconditions
- Report displayed based on configuration

## Variants

### V1: Time Comparison
1. Admin enables "Compare to previous period"
2. System shows current vs previous period side-by-side
3. Percentage change calculated for each row

### V2: Member-Specific Report
1. Admin filters by specific member
2. All visualizations show only that member's data
3. Useful for balance disputes

## Error Cases

### E1: No Data in Range
- Display "No transactions found for selected filters"
- Show suggestion to adjust date range or filters

### E2: Too Much Data
- If query would return >10,000 rows: warn and suggest adding filters
- Performance limit to prevent timeout

## Test Derivation

**Metrics:**
- Revenue sum: matches transaction amount sum
- Quantity sum: matches transaction quantity sum
- Transaction count: matches actual count
- Percentages: add up to 100%

**Filters:**
- Date range: only transactions within range
- Category filter: only that category's products
- Product filter: only that product
- Member filter: only that member's transactions
- Settlement filter: settled/unsettled correctly separated

**Grouping:**
- Category grouping: products grouped correctly
- Time grouping: correct date bucketing
- Multi-dimension: combinations work correctly

**Drill-down:**
- Category → shows products
- Product → shows transactions
- Time → shows sub-period

**Charts:**
- Bar chart: bars match table values
- Line chart: data points match
- Pie chart: segments = 100%

**Saved Reports:**
- Save: configuration persisted
- Load: filters/view restored
- Preset reports: load correctly

## Related

- [UC-A30: Create Settlement](./UC-A30-create-settlement.md) - Uses "Settlement Prep" report
- [UC-A40: List Products](./UC-A40-list-products.md) - Product performance data

