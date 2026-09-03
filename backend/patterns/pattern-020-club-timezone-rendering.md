# Pattern 020: One Clock for the Books — Render in the Club's Zone

**Category**: Time / Presentation
**Status**: ✅ Required
**Introduced by**: Issue #365 (aggregation and export half)
**Related**: Pattern 003 (DTOs — labelling an instant `Z`), ADR-0031 (storage is UTC)

---

## Problem

Storage was settled long ago and is not in question: `Utc::apply()` pins the PHP
runtime, `ConnectionFactory` pins the database session to `+00:00`, and
`DateFormatter::toUtcIso()` puts the `Z` on the way out. Every column holds UTC.

That is only half a timestamp. The other half is the conversion *back*, and it
kept being forgotten in a way nothing could catch:

| Surface | What it did | What the reader saw |
|---------|-------------|---------------------|
| Peak-hours report | `GROUP BY HOUR(occurred_at)` | A smooth curve peaking at 18:00 for a bar busiest at 20:00 |
| "Heute" / WTD / MTD | `sumRevenueSince(date('Y-m-d'))` | Every sale between midnight and 02:00 local missing from today and counted yesterday |
| Daily revenue chart | `GROUP BY DATE(occurred_at)` | Saturday's late takings partly under Sunday |
| Journal CSV | `substr($iso, 0, 10)` | A sale at 00:30 CEST dated to the previous day |
| Date filters | `occurred_at >= '2026-09-01'` | Both ends of every range two hours late |
| Session times | `date('H:i:s', $ts)` | A till that opened at 20:00 reported as 18:00 |

None of these failed. No exception, no empty result, no log line — the figure
was plausible, the chart was smooth, the row rendered. That is the signature of
this whole bug class, and it is why the rule below is mechanical rather than a
matter of judgement at each call site.

## The rule

**An instant leaves the database in UTC and is rendered in the club's zone.
Every conversion is explicit, and there are exactly three ways to do it.**

```php
use App\Shared\Time\ClubTimeZone;   // one value
use App\Shared\Time\ClubLocalSql;   // a GROUP BY
```

| You are… | Use | Example |
|----------|-----|---------|
| printing one instant server-side (mail, CSV, PDF) | `ClubTimeZone::moment($value)` | `MailFormat::dateTime()`, the journal export |
| naming a day, week or month | `ClubTimeZone::today()` / `::startOfWeek()` / `::startOfMonth()` | the dashboard's revenue windows |
| turning a filter's day into a query bound | `ClubTimeZone::startsAtUtc()` / `::endsBeforeUtc()` | every `date_from` / `date_to` |
| bucketing rows in SQL | `ClubLocalSql::localInstant('t.occurred_at', $from, $to)` | `GROUP BY DATE(…)`, `HOUR(…)`, `WEEK(…)` |
| emitting an instant to a client | `DateFormatter::toUtcIso()` — **not** the club's zone | every DTO |

The last row is the one that looks like an exception and is not. An API sends
the instant, labelled; the *client* converts. The admin panel does it with
`getClubTimeZone()`, seeded from `GET /instance-config`'s `time_zone`, so the
screen agrees with the mail for the same row.

### A calendar day is never converted

`settlement_date`, `mandate_signed_at` and their kind mean the 21st in every
zone, and adding an offset to one is how a deadline silently moves a day. Both
`ClubTimeZone::moment()` and the frontend's `parseApiDate()` branch on the shape
of the value — a date with no time is a calendar day — so **the shape is the
contract** and neither side has to be told which field it is holding.

### Why `CONVERT_TZ` is not the answer

`CONVERT_TZ(occurred_at, '+00:00', 'Europe/Berlin')` returns **NULL** on a stock
MariaDB: named zones need the `mysql.time_zone` tables, which a default install
leaves empty and which we cannot populate on shared hosting — the same
constraint `Utc::SQL_OFFSET` already documents. A NULL would not error; it would
empty the chart or collapse every row into one bucket.

`ClubLocalSql` therefore builds the offset from PHP's own zone database, as a
`CASE` over the daylight-saving transitions in the query's range:

```sql
(t.occurred_at + INTERVAL CASE
     WHEN t.occurred_at < '2026-03-29 01:00:00' THEN 3600
     WHEN t.occurred_at < '2026-10-25 01:00:00' THEN 7200
     ELSE 3600 END SECOND)
```

Exact across DST, no server configuration, and the `WHERE` still filters the raw
column against UTC bounds so the range scan keeps its index — only the grouping
sees the shifted expression.

## Checklist for review

1. Does a new `date()`, `DATE()`, `HOUR()`, `WEEK()`, `YEAR()` or
   `DATE_FORMAT()` touch a UTC column? It needs `ClubLocalSql`.
2. Does a new server-rendered string carry a time of day? It needs
   `ClubTimeZone::moment()`. A wall-clock string has no `Z` and nothing
   downstream can convert it, so it must already be right.
3. Does a filter compare a `Y-m-d` against a `DATETIME`? It needs
   `startsAtUtc()` / `endsBeforeUtc()` — and the upper bound is **exclusive**,
   because a club day can be 23 or 25 hours long.
4. Does a DTO emit an instant? It needs `toUtcIso()`, not the club's zone.
5. Is it a calendar day? Then none of the above — leave it alone.

## Configuration

`CLUB_TIMEZONE` in `backend/.env`, any IANA name, `Europe/Berlin` by default. An
empty or unknown value falls back to the default rather than throwing: a mail
that arrives with the wrong hour still reaches somebody, one that throws in the
builder reaches nobody and blocks the drain behind it.

### The fallback is silent, and therefore reported

`ClubTimeZone::source()` says whether the effective zone was `configured`,
fallen back to as the `default`, or fallen back to because what was stated was
`invalid`. `GET /api/instance-config` carries it as `time_zone_source`, and the
admin dashboard warns on the last two.

The fallback has to stay silent at the point of use, for the reason above — but
silent is not the same as unreportable, and every other surface is incapable of
reporting it: a club reading its books an hour out sees nothing wrong anywhere,
because a wrong hour looks exactly like a right one. A club that never stated a
zone is on Berlin's clock by accident rather than by decision, and a club that
stated `Europe/Berlim` is there having tried not to be. Stating `Europe/Berlin`
explicitly is `configured` and warns about nothing — silence has to mean
something.

**This never touches the default time zone.** `date_default_timezone_set()` is
what #365 was about — the ~40 repository writes calling `date('Y-m-d H:i:s')`
would start writing local time into columns the API labels `Z` the moment it
moved. Reading is a per-value conversion, always.
