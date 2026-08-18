# Pattern 011: Testing a Role You Are Not

**Problem**: every spec in the suite is authenticated as the seeded `admin`,
which is the one seat from which an authorization boundary is invisible. A route
quietly granted to a lesser office, or quietly taken away from one, looks
identical from there.

**Solution**: mint the office you are testing, per worker, and make requests as
it. Never demote the shared admin.

---

## Why not just demote the shared admin

`playwright/.auth/admin.json` is one storage state used by every worker
(Pattern 002). The account behind it is read by every other spec's session
bootstrap, so taking its `admin` role away — even briefly, even with a restore
in `afterEach` — is observable, mid-run, by unrelated tests running beside
yours. That is the same trap `utils/isolatedAdmin.ts` was written for, and it
fails as a flake in a file that never mentioned roles.

## The fixtures

`fixtures/roleRequests.ts` provides two worker-scoped API contexts:

```typescript
import { test, expect } from '../../fixtures/roleRequests'

test('a Getränkewart cannot reach the members list', async ({ getraenkewartRequest }) => {
  const response = await getraenkewartRequest.get(`${API_BASE}/admin/members`)

  expect(response.status()).toBe(403)
  expect((await response.json()).error).toBe('insufficient_role')
})
```

Worker-scoped, not per-test: minting an account costs a step-up with a TOTP
code and the first login costs an enrolment. One pair per worker keeps that off
the per-test path while still giving parallel workers accounts they cannot
contend over (Pattern 004).

For UI tests, `createIsolatedAdmin(playwright, prefix, roles)` mints the same
kind of account and `signInAndEnroll(loginPage, page, email, password, landing)`
drives it through the real login and enrolment forms — `landing` because the
page an office arrives on is part of what is under test.

## The rule that keeps the fixtures usable

**Never log the fixture's account out, and never reset its 2FA.** The context is
shared by every test in the worker, and the next one gets a 401 it cannot
explain. If a test needs to destroy a session, it mints its own account for it.

## Asserting a boundary

Assert **the refusal by name**, not merely a non-2xx:

```typescript
expect(response.status()).toBe(403)
expect((await response.json()).error).toBe('insufficient_role')
```

`403` alone also matches a CSRF rejection, and a test that accepts either would
pass against a completely broken session.

In the allowed direction, assert **not refused** rather than `200` when the probe
carries an empty body — a 400 or a 404 means the office got through the door,
which is the claim. Sending real bodies to sixty endpoints to get 2xx is a
different and far more destructive test.

## Where this is used

- `tests/api/role-access-matrix.spec.ts` — the grant table as data, walked as
  each lesser office
- `tests/api/role-flows.spec.ts` — each office's actual job, run to completion
  and read back
- `tests/admin/role-navigation.spec.ts` — the panel's hidden navigation, the
  per-role landing and the refusal screen
