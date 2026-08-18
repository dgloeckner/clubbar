# Role-Aware Navigation and Page Access

**Purpose**: show each admin office only the panel it can use, land it
somewhere it can work, and name the refusal when it reaches a page it may not
open.

**Design**: [ADR-0044](../../adr/0044-tiered-admin-roles.md) · issue
[#516](https://github.com/dgloeckner/clubbar/issues/516)

---

## The one rule

**This is not enforcement.** The backend's `RouteRoleMap` refuses every request
on its own, and it would still refuse them if this whole file were deleted.
What lives here is the panel not showing doors that answer 403 — a courtesy and
a usability decision, never the control.

That is why hiding is safe to do casually and why *relying* on it never is: any
page may still receive a 403 for reasons the client cannot predict (a role
revoked while the tab was open), which is what the refusal screen is for.

---

## Where the table lives

`src/utils/adminRoles.ts` maps a section path to the roles that may open it:

```typescript
export const SECTION_ROLES: Record<string, AdminRole[]> = {
  '/dashboard': TREASURY,
  '/products': BAR,
  '/audit-log': ADMIN_ONLY,
  // ...
}
```

Two rules, both taken from the server-side map:

1. **Default-deny** — a path with no entry is `admin`-only. A section added
   without a classification is invisible to the lesser roles until somebody
   grants it in a diff a reviewer sees.
2. **Grants are additive** — holding several roles is the union of them. There
   are no deny rules.

Sub-routes inherit their section: `/members/excluded` is covered by
`/members`, so only section roots appear in the table.

---

## Adding a page

1. Add the route in `App.tsx` inside a `ProtectedRoute` — that is what renders
   the refusal screen for a caller whose roles do not cover it.
2. Add the nav entry to `MainLayout` (and `BottomTabBar` if it belongs on
   mobile).
3. **Add the path to `SECTION_ROLES`.** `adminRoles.test.ts` reads both nav
   components' source and fails on any `path:` it cannot find in the table, so
   step 2 without step 3 is a red build rather than a silently `admin`-only
   entry.
4. Pick the roles from what the *page's endpoints* are granted in
   `backend/src/Modules/Auth/Domain/RouteRoleMap.php`. A page that fans out to
   several endpoints takes the intersection: showing a page whose sidebar panel
   answers 403 is worse than not showing the page.

## Landing

`landingPath(roles)` answers where "home" is for a session: the first page the
caller may actually open. `LoginPage` navigates there after login, MFA and TOTP
enrolment, `/` redirects there, and so does the 404 fallback.

The roles come off the `AuthResult` those calls return, **not** off
`useAuth().roles` — the navigation happens in the same tick the state is set,
so the context value in scope is still the previous render's.

## Refusing

`ProtectedRoute` renders `InsufficientRolePage` in place of the page when
`permitsPath()` says no. It renders *inside* the layout rather than
redirecting: a redirect leaves the caller on a working page with no explanation
of why the one they asked for vanished, and makes a stale bookmark
unfixable.

## When the roles change mid-session

A 403 carrying `insufficient_role` is not a broken request — it is the server
disagreeing with what the panel believes. `src/api/client.ts` reports those to
a handler `AuthProvider` registers, which re-reads the profile and updates the
roles. The navigation and the route guard then correct themselves; nobody is
logged out, because the session is perfectly alive.

The refresh is single-flight: the profile call is itself role-gated, so a
role-less account's refresh would 403 and ask for another one.

## Testing

- Pure logic (the table, parsing, landing, the completeness property):
  `src/utils/adminRoles.test.ts`.
- Behaviour: `e2etests/tests/admin/role-navigation.spec.ts` mints an admin per
  office via `createIsolatedAdmin(playwright, prefix, roles)` and asserts the
  whole visible nav set, not one entry at a time — a per-entry assertion
  quietly misses an entry that should *not* be there.
