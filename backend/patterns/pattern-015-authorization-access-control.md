# Pattern 015: Authorization & Access Control

**Status**: Active

**Related ADRs**: ADR-0015 (Authentication and Authorization Strategy), ADR-0044 (Tiered Admin Roles)

**Purpose**: Decide, for every request, whether the caller may make it — by
credential type first, then by the office the account holds.

---

## Context

Authentication (Patterns 012–013) answers *who is calling*. This pattern answers
*whether they may*, and it does so on two axes that are easy to confuse:

| Axis | Question | Decided by |
|------|----------|-----------|
| **Credential** | Is this a terminal device or an admin session? | Route group middleware (`TerminalTokenAuth` / `AdminSessionAuth`) |
| **Office** | Which admin role does this account hold? | `RouteRoleMap`, consulted inside `AdminSessionAuth` |

The second axis is new as of ADR-0044. Before it, every authenticated admin
reached every admin endpoint, and "authorization" for the panel meant nothing
beyond "is there a session". Any code or comment still saying *all admins have
equal access* is out of date.

**The rules, in one place:**

- **Terminal devices** reach `/api/sync/*` and nothing else. A Bearer token
  never passes `AdminSessionAuth`, and a session never passes
  `TerminalTokenAuth` — the separation is structural, not conditional.
- **Admin sessions** reach `/api/admin/*` and `/api/auth/*`, filtered by the
  roles the account holds.
- **Members** never call the API. They are identified by RFID at a terminal
  (Pattern 014), which is not authentication.

---

## Layer 1: credential type, by route group

`routes.php` puts each group behind its own middleware. There is no per-route
decision to get wrong and no controller that can forget one:

```php
$app->group('/api/sync', function (RouteCollectorProxy $group) { /* ... */ })
    ->add($terminalTokenAuth);

$app->group('/api/admin', function (RouteCollectorProxy $group) { /* ... */ })
    ->add($adminSessionAuth);
```

## Layer 2: the route→roles map

`App\Modules\Auth\Domain\RouteRoleMap` is one declarative table, consulted by
`AdminSessionAuth` after the session is established. It is **not** per-controller
checks: those scatter the model across sixty call sites, fail *open* by omission
— a controller with no check is simply open, and nothing surfaces that — and
cannot be verified by a single test over the route table.

```php
private const MAP = [
    'GET /api/admin/members'    => self::TREASURY,   // admin + kassenwart
    'DELETE /api/admin/members/{memberId}' => self::ADMIN_ONLY,
    'GET /api/admin/products'   => self::BAR,        // admin + getraenkewart
    'GET /api/admin/reports/{reportType}' => self::EVERY_ROLE,
];
```

### Three rules

1. **Default-deny.** A route with no entry is `admin`-only. When somebody adds a
   route six months from now and does not touch the map, it works for `admin`
   and answers 403 for everyone else until a human deliberately grants it. The
   failure mode is "a Kassenwart cannot reach the new page" — visible, reported,
   fixed in a minute — rather than a silent grant.
2. **Grants are additive.** There are no deny rules. Holding two roles is the
   union of both. A future role needing "everything except X" is a signal that X
   belongs behind its own grant.
3. **The key is method + pattern.** `GET /sepa-config` and `PATCH /sepa-config`
   are the same path and different grants, and that is the common shape rather
   than the exception. The pattern is the one **Slim matched**
   (`/api/admin/members/{memberId}`), never the concrete path — so the map is a
   literal transcription of `routes.php`, with no path matching to get wrong.

Every entry lists `AdminRole::ADMIN` explicitly even though it is a strict
superset of the other two: reading a row must answer "who may do this" without
also knowing a rule about who is implied.

### Adding a route

1. Register it in `routes.php`.
2. Add its `"METHOD /pattern"` to `RouteRoleMap::MAP` with the roles it needs.
   `ADMIN_ONLY` is a perfectly good answer — it just has to be written down.
3. If the panel gains a page for it, classify that page in the frontend's own
   table (`admin-frontend/src/utils/adminRoles.ts`).

Step 2 is not optional and not on your memory: `RouteRoleMapCompletenessTest`
reads the **real route collector** and fails on any session-authenticated route
with no entry, naming it. It fails in both directions — a stale entry for a
route that no longer exists fails too.

### What a refusal looks like

| Situation | Status | Body |
|-----------|:------:|------|
| No session, or an expired one | 401 | `{"error": "admin_not_authenticated"}` |
| A live session whose roles do not cover the route | 403 | `{"error": "insufficient_role", "message": "This section is not available for your role."}` |
| A rejected step-up credential | 401 | `{"error": "invalid_credentials"}` |

401 is checked before 403: an expired session is not a role problem, and telling
an anonymous caller which roles a route needs is free reconnaissance.

The middleware also attaches `admin_roles` to the request, so a controller can
make a finer decision without re-reading the database:

```php
/** @var list<AdminRole> $roles */
$roles = $request->getAttribute('admin_roles', []);
```

## Layer 3: allow-lists, where the boundary lands on a parameter

Some boundaries are not routes. `GET /api/admin/reports/{reportType}` is shared
by all three offices, but `group_by=member` turns a revenue report into a list of
named people and what they drink, while `group_by=category` is the drinks list
doing its job.

`ReportGroupByPolicy` answers that, and it is an **allow-list** on purpose: a
dimension added later is refused until somebody grants it, in a diff a reviewer
sees. Every role is enumerated, `admin` included, so a new dimension fails the
build until it is classified — the map's completeness property, applied to a
parameter.

Two details worth copying if you ever add a second one:

- **A value that is not a dimension at all stays a 400 for every role.** A typo
  is a malformed request, not an authorization failure, and answering 403 to it
  tells the caller their role is the problem when it is not.
- **Aggregates are not the boundary.** `summary.unique_member_count` is
  unchanged for every role: a count with no names attached is not a member list.

## The step-up axis

Roles say *whether* you may; a step-up (`StepUpAuthService`, ADR-0015) says
*prove it is still you*. They are orthogonal and both apply: `PATCH /admin-users/{id}` is `admin`-only **and**
demands the caller's own password and TOTP code when the role set actually
changes. Granting a role is minting authority, so it costs a credential even
though the session is already `admin`.

## The panel's own table is not enforcement

`admin-frontend` keeps a second, smaller table (`src/utils/adminRoles.ts`) so it
can hide navigation a role cannot use and land each office on a page it can
open. It is a separate table rather than a copy — the server maps API routes,
the panel maps SPA sections, and one page fans out to several endpoints.

It is a courtesy, never a control. The server refuses independently on every
request, which is what makes a bookmark, a stale tab and a role changed
mid-session all safe.

---

## Authorization Matrix

`admin` reaches everything, so only the lesser offices are worth tabulating.

| Endpoint | Terminal (Bearer) | `admin` | `kassenwart` | `getraenkewart` |
|----------|:---:|:---:|:---:|:---:|
| `GET /api/sync/*` | ✅ | ❌ | ❌ | ❌ |
| `GET /api/auth/profile` | ❌ | ✅ | ✅ | ✅ |
| `GET /api/admin/dashboard` | ❌ | ✅ | ✅ | ❌ |
| `GET /api/admin/members` | ❌ | ✅ | ✅ | ❌ |
| `DELETE /api/admin/members/{id}` | ❌ | ✅ | ❌ | ❌ |
| `POST /api/admin/members/{id}/anonymize` | ❌ | ✅ | ❌ | ❌ |
| `GET`/`POST` `/api/admin/products`, `/categories` | ❌ | ✅ | ❌ | ✅ |
| `GET /api/admin/settlements` and the SEPA export | ❌ | ✅ | ✅ | ❌ |
| `GET /api/admin/sepa-config` | ❌ | ✅ | ✅ | ❌ |
| `PATCH /api/admin/sepa-config` | ❌ | ✅ | ❌ | ❌ |
| `GET`/`PATCH` `/api/admin/mail-config` | ❌ | ✅ | ❌ | ❌ |
| `GET /api/admin/audit-log` | ❌ | ✅ | ❌ | ❌ |
| `/api/admin/admin-users/*`, `/terminals/*`, `/encryption-keys/*` | ❌ | ✅ | ❌ | ❌ |
| `GET /api/admin/reports/{reportType}` | ❌ | ✅ | ✅ | ✅ (allow-listed `group_by`) |
| `GET /api/health` | ✅ | ✅ | ✅ | ✅ |

`mail-config` being `admin`-only is load-bearing rather than convenient: a
Kassenwart who could redirect the alert address could silence the notice that
reports their own promotion. Every other detection in ADR-0044 depends on it.

---

## Testing

| Claim | Where |
|-------|-------|
| The map's own logic — defaults, intersection, pattern-not-path | `tests/Unit/Modules/Auth/Domain/RouteRoleMapTest.php` |
| Every registered route is classified, and no entry is stale | `tests/Feature/Modules/Auth/RouteRoleMapCompletenessTest.php` |
| The refusal happens through the real stack, in the right order | `tests/Feature/Modules/Auth/RoleEnforcementHttpTest.php` |
| The parameter allow-list | `tests/Unit/Modules/Reports/Domain/ReportGroupByPolicyTest.php`, `tests/Feature/Modules/Reports/ReportGroupByRoleHttpTest.php` |
| The whole grant table, as data, over HTTP as each office | `e2etests/tests/api/role-access-matrix.spec.ts` |
| Each office's actual job, run to completion | `e2etests/tests/api/role-flows.spec.ts` |
| The panel hides what it cannot reach, and names the refusal | `e2etests/tests/admin/role-navigation.spec.ts` |

A fixture that inserts an `admin_users` row and stops simulates an account that
cannot exist, and the gate fails closed on it. `HttpTestCase::grantRoles()` is
how a feature test gives its admin the roles it needs:

```php
$id = $this->createAdminUser();
$this->grantRoles($id, AdminRole::KASSENWART);
```

E2E specs mint the office they are testing rather than demoting the shared
seeded admin — see E2E Pattern 011.

---

## Consequences

### Positive

- **One place to read the model.** "Who may do this?" is answered by one table,
  not by grepping sixty controllers.
- **Omission fails closed and loudly.** A forgotten route is `admin`-only and a
  red test, rather than an open endpoint nobody notices.
- **Escalation is expensive and visible.** Role grants carry a step-up, dedicated
  audit actions and out-of-band mail (ADR-0044).

### Negative

- **The map must be maintained beside `routes.php`.** Two files move together.
- **A boundary on a parameter needs its own allow-list**, which is a second
  mechanism to learn.
- **`admin` remains the root.** Whoever can grant a role can grant it to
  themselves; ADR-0044 accepts that and makes it loud rather than pretending
  otherwise.

### Mitigations

1. The completeness test makes the maintenance burden a build failure rather
   than a review burden.
2. Allow-lists enumerate every role, so a new value fails the build until it is
   classified.
3. Lifecycle mail reaches a club-level address that an `admin` cannot silence
   without also being the one who configured it.

---

## See Also

- **ADR-0044**: Tiered Admin Roles — the grant table and its threat model
- **ADR-0015**: Authentication and Authorization Strategy
- **Pattern 012**: Terminal API Token Authentication
- **Pattern 013**: Admin Session Authentication — where the step-up credential is verified
- **E2E Pattern 011**: Testing a Role You Are Not
- `admin-frontend/patterns/role-visibility.md`: the panel's own table
