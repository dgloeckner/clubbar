# Pattern 017: Shared HTTP Layer — One Envelope, One Parser, One Responder

**Status**: Active
**Introduced by**: Issue #119
**Related**: Pattern 003 (DTOs), Pattern 006 (Thin Controllers), Pattern 007 (Centralized Exception Handling)

---

## Problem

Every controller needs the same three things: a way to write JSON, a way to
read pagination and sorting off the query string, and a way to shape a list
response. When each writes its own, they agree on the day they are written and
drift from then on.

By the time #119 was raised the backend had:

- **four list-response shapes** in production, only one of which matched
  `api/admin.yaml`, so the frontend needed a per-endpoint fallback chain
  before it could read an answer;
- **six pagination parsers**, of which one capped `per_page`, one clamped it,
  and four accepted any number the caller sent;
- **two sort dialects**, so `sort_by=name_asc` — the parameter the admin
  frontend actually sends — sorted the product list and was silently dropped
  by the member and settlement lists;
- **seventeen copies** of a four-line `json()` method.

None of these were hard to fix individually. That was the problem: each was
cheap enough to reimplement, and nothing kept the copies in step.

---

## Solution

Three classes in `App\Shared\Http`, used by every controller that answers with
JSON.

### `JsonResponder` (trait)

```php
class AdminController
{
    use JsonResponder;

    public function show(Request $request, Response $response, array $args): Response
    {
        return $this->json($response, $member->toArray());
    }
}
```

Never write `json_encode` into a response body by hand. The trait owns the
encoding flags and the content type.

### `ListQuery`

```php
$query = ListQuery::fromParams($request->getQueryParams(), defaultPerPage: 20);

$result = $this->service->list($query->perPage, $query->offset, $filters, $query->sortKey, $query->sortOrder);
```

| Property | Meaning |
|----------|---------|
| `page` | 1-indexed page the caller asked for |
| `perPage` | rows per page, already checked against the cap |
| `offset` | rows to skip, derived from `page` or taken verbatim |
| `sortKey` | field name, whichever dialect it arrived in |
| `sortOrder` | `asc` or `desc`, lowercased and validated |
| `search` | trimmed search term, or `null` |

It accepts all three dialects in the field — `page`/`per_page`,
`offset`/`limit`, and the combined `sort_by=name_asc` — and normalises them.
Adding a fourth spelling is a change in one file.

`per_page` above the cap (100 by default), or any non-integer pagination value,
throws `InvalidQueryParameterException`. Do not catch it: `ErrorHandler` turns
it into `400 invalid_request` with a `messages` map keyed by the parameter the
caller actually used. **Reject, do not clamp** — a silently truncated page is a
wrong answer that looks like a right one.

### `PaginatedResponse`

```php
return $this->json($response, PaginatedResponse::fromQuery($result->items, $result->total, $query));
```

```json
{
  "data": [ ... ],
  "pagination": { "page": 1, "per_page": 20, "total": 42, "total_pages": 3 }
}
```

This is the shape `api/admin.yaml` documents. It is not negotiable per
endpoint: a new list endpoint that invents its own keys is the bug this pattern
exists to prevent.

`data` is always a JSON array. `PaginatedResponse` re-indexes, because a
filtered PHP array has holes in its keys and `json_encode` renders that as an
object — which every `Array.isArray()` check in the frontend rejects.

---

## Supporting utilities

| Class | Replaces | Use for |
|-------|----------|---------|
| `App\Shared\Utils\Uuid::v4()` | 8 private `generateUuid()` methods | Any generated identifier |
| `App\Shared\Utils\Csv` | 4 hand-rolled CSV builders | Every CSV export |
| `App\Shared\Repository\UnsettledTransactions` | 6 copies of the "unsettled" subquery | Any query about settled money |

`Csv::build()` does RFC 4180 quoting, so a member named `Meier; Hans` no longer
shifts every column after it. `Csv::money()` writes integer cents as decimal
euros; a money column in an export is named for its unit (`Amount EUR`,
`amount_eur`) so the file says what it contains.

---

## Rules

1. **Never** add a `private function json()` to a controller — `use JsonResponder`.
2. **Never** read `page`, `per_page`, `limit`, `offset`, `sort`, `order` or
   `sort_by` from `getQueryParams()` directly — use `ListQuery::fromParams()`.
3. **Never** assemble a list body by hand — use `PaginatedResponse`.
4. **Never** write a UUID generator, a CSV joiner, or the `NOT EXISTS
   (settlement_items …)` subquery inline — the shared versions exist.
5. A per-endpoint deviation from the envelope needs an ADR, not a local
   `if`. Four of them once did, and it cost the frontend a fallback chain per
   screen.

---

## Anti-patterns

```php
// ✗ A fifth shape
return $this->json($response, ['categories' => $categories]);

// ✗ Cap by clamping: the caller asked for 500 and is not told they got 100
$limit = min((int) ($params['per_page'] ?? 50), 100);

// ✗ No cap at all
$perPage = (int) ($params['per_page'] ?? 20);

// ✗ Unescaped CSV; one member name with a ';' corrupts the file
$lines[] = implode(';', [$row['name'], $row['iban']]);
```

```php
// ✓
$query = ListQuery::fromParams($params);
$result = $this->service->list($query->perPage, $query->offset);

return $this->json($response, PaginatedResponse::fromQuery($result->items, $result->total, $query));
```

---

## Tests

- `tests/Unit/Shared/Http/ListQueryTest.php`
- `tests/Unit/Shared/Http/PaginatedResponseTest.php`
- `tests/Unit/Shared/Http/JsonResponderTest.php`
- `tests/Unit/Shared/Utils/CsvTest.php`
- `tests/Unit/Shared/Utils/UuidTest.php`
- `tests/Unit/Shared/Repository/UnsettledTransactionsTest.php`
