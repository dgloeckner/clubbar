# UC-A51: Member Ranking

**Implementation Status**: Removed — the tab, its API endpoints, and its CSV
export were removed from the admin panel. This document is kept as a record
of why the feature existed and why it was withdrawn rather than restricted.

## Actor
Admin

## Preconditions
- Admin is logged in

## Trigger
Admin opens Reports → Member Ranking

## Main Flow
1. Admin clicks "Member Ranking" in Reports
2. Admin selects date range and how many rows to show
3. System calculates and displays the ranking
4. Admin can view the top N consumption figures

## Display Mode

The ranking is **always anonymous**. There is no display-mode choice, no named
mode, and no parameter that produces one.

Rows are labelled `Member 1`, `Member 2`, … by their **ordinal position in the
response being rendered**. The label is not a persistent alias: `Member 1` in a
January report and `Member 1` in a February report are, in general, different
people. That matters — a stable pseudonym is re-identifiable by anyone who can
put a name to a single row and then carry it across every other report.

### Why named mode was removed

A **named ranking of members by consumption** is precisely the
consumption-profile view [ADR-0029](../../adr/0029-two-tier-retention-and-erasure.md)
prohibits: it converts a billing record into a behavioural profile, and it is
the strongest evidence a supervisory authority would use to argue that a decade
of drink records is Art. 9 special-category data (see
[`research/art9-rfid-display-retention.md`](../../research/art9-rfid-display-retention.md)).
It would also make the Art. 13(2)(f) privacy notice false, which declares that
no profiling occurs.

"For internal use only" is not a mitigation — the concern is the existence of
the profile, not who reads it.

Anonymous aggregate statistics are unaffected, and match the § 27 Abs. 3 BDSG
anonymise-early pattern.

## Ranking Table

| Column | Content |
|--------|---------|
| Rank | Position (1, 2, 3...) |
| Member | Ordinal label for this report ("Member N") |
| Total | Total consumption amount |
| Count | Transaction count |

## Options
- Top N (10, 25, 50, 100)

## Postconditions
- Ranking displayed

## Privacy Note
- The ranking never carries member names, in the UI, the API or the CSV export
- Names are not even read from the database — the query aggregates without selecting them
- Labels are positional, so two reports cannot be joined on them

## Test Derivation
- Ranking order: highest first
- Date range: only transactions in range
- Every label is `Member <rank>`; no real name appears anywhere in the response
- An `anonymize` parameter left over in a bookmark or stale client changes nothing
- CSV export carries the same positional labels
- Top N: correct count displayed
- Empty period: "No data" message
