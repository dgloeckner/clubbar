import { test, expect } from '../../fixtures/auth.fixture';
import { stepUp } from '../../fixtures/stepUp';
import { randomUUID } from 'crypto';

/**
 * Timestamp timezone contract (#365)
 *
 * The admin UI renders every instant by parsing the API's string and letting the
 * browser convert to the reader's local time. Two things have to hold for that
 * to produce the right clock face, and the bug broke the second one:
 *
 *  1. the string carries an explicit UTC designator, so it is parsed as an
 *     instant rather than as local wall-clock time, and
 *  2. the instant it denotes is the one the event actually happened at.
 *
 * On the integration host, PHP and MariaDB both ran in Europe/Berlin, so an
 * 18:46 CEST login was stored as "18:46" and shipped as "18:46Z" — correctly
 * *labelled*, two hours *wrong*. Assertion 1 passed; assertion 2 was what
 * failed, and it is the one these tests make.
 *
 * A freshly created record is the sharpest probe available over HTTP: the server
 * wrote its timestamp within a second of the request, so any offset the deployment
 * introduces shows up as an hour-sized gap against the test runner's clock. The
 * tolerance below is therefore wide enough for a slow CI box and far narrower
 * than the smallest timezone offset that could reappear here.
 *
 * Uses E2E Pattern 001: Test Data Isolation — every test creates its own record.
 */

/** Comfortably above real clock skew, comfortably below a 30-minute UTC offset. */
const TOLERANCE_MS = 5 * 60 * 1000;

/** RFC 3339 with an explicit UTC designator — "Z" or a numeric zero offset. */
const UTC_DESIGNATOR = /(Z|[+-]\d{2}:\d{2})$/;

function driftFromNow(timestamp: string): number {
  return Math.abs(Date.parse(timestamp) - Date.now());
}

function expectUtcInstantNearNow(timestamp: string, field: string): void {
  expect(timestamp, `${field} should be present`).toBeTruthy();

  // Without a designator the browser parses the string as *local* time, which
  // shifts the rendered value by the reader's own offset.
  expect(timestamp, `${field} must carry an explicit UTC offset`).toMatch(UTC_DESIGNATOR);
  expect(Number.isNaN(Date.parse(timestamp)), `${field} must be parseable`).toBe(false);

  const drift = driftFromNow(timestamp);
  expect(
    drift,
    `${field} is ${timestamp}, which is ${Math.round(drift / 60000)} minutes from now — ` +
      `the value is labelled UTC but was not written in UTC (#365)`,
  ).toBeLessThan(TOLERANCE_MS);
}

test.describe('Timestamp timezone contract', () => {
  test('a newly created category reports created_at as a UTC instant', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.post('/api/admin/categories', {
      data: { names: { de: `TZ ${randomUUID().substring(0, 8)}`, en: `TZ ${randomUUID().substring(0, 8)}` } },
    });
    expect(response.status()).toBe(201);

    const created = await response.json();
    expectUtcInstantNearNow(created.created_at, 'category.created_at');
  });

  /**
   * The screen from the issue. The audit log is the sharpest case in the
   * codebase: `audit_log.created_at` is a DATETIME written by PHP's `date()`,
   * so it stores whatever zone the runtime is in, with no conversion on read to
   * mask the error.
   */
  test('an audit log entry for a just-performed action is stamped in UTC', async ({ authenticatedRequest }) => {
    const token = `Tz${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`;

    const created = await authenticatedRequest.post('/api/admin/members', {
      data: {
        first_name: 'Timezone',
        last_name: token,
        email: `${token}@test.com`,
        date_of_birth: '1985-06-15',
        preferred_language: 'de',
      },
    });
    expect(created.status()).toBe(201);
    const memberId = (await created.json()).id;

    // Filtered by entity, not read off the top of the global log — the suite
    // runs four workers, so position says nothing (E2E Pattern 003).
    const response = await authenticatedRequest.get('/api/admin/audit-log', {
      params: { entity_id: memberId, per_page: 20 },
    });
    expect(response.status()).toBe(200);

    const entries = (await response.json()).data;
    expect(entries.length, 'the member creation should be audited').toBeGreaterThan(0);

    expectUtcInstantNearNow(entries[0].created_at, 'audit_log.created_at');
  });

  /**
   * The notification queue (#407), which is where the contract was broken next:
   * `QueuedMailDto` shipped `queued_at` as the bare MariaDB datetime, so an
   * invitation queued at 21:33 CEST was listed as 19:33 — the UTC the column
   * holds, printed as if it were the reader's own clock. Nothing looked wrong,
   * because a bare datetime parses.
   *
   * Creating an admin is the cheapest way to make the server queue a message:
   * onboarding is by invitation link, and the mail carrying it is enqueued in
   * the same request.
   */
  test('a just-queued notification reports queued_at as a UTC instant', async ({ authenticatedRequest }) => {
    const email = `tz-queue-${Date.now()}-${Math.floor(Math.random() * 10000)}@test.example.com`;

    const created = await authenticatedRequest.post('/api/admin/admin-users', {
      data: { ...stepUp(), email, display_name: 'Timezone Queue', locale: 'de' },
    });
    expect(created.status()).toBe(201);

    // Searched by the snapshot recipient address, which is unique to this test
    // — the suite runs four workers, so nothing may be read off the top of the
    // shared queue (E2E Pattern 003).
    const response = await authenticatedRequest.get('/api/admin/notifications', {
      params: { search: email, per_page: 5 },
    });
    expect(response.status()).toBe(200);

    const rows = (await response.json()).data;
    expect(rows.length, 'the invitation mail should be queued').toBeGreaterThan(0);

    expectUtcInstantNearNow(rows[0].queued_at, 'notification.queued_at');
  });

  /**
   * The profile screen prints this one with its time of day, and it is written
   * by PHP's `date()` on login, so nothing on the way out converts it.
   *
   * Only the label is asserted, not the drift: the session was established in
   * `auth.setup.ts`, which can be many minutes before this test runs, so "near
   * now" would be a clock on the suite's runtime rather than on the value.
   */
  test('the profile reports last_login_at with a UTC designator', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/auth/profile');
    expect(response.status()).toBe(200);

    const lastLoginAt = (await response.json()).admin.last_login_at;
    expect(lastLoginAt, 'the fixture signed in, so there is a last login').toBeTruthy();
    expect(lastLoginAt, 'last_login_at must carry an explicit UTC offset').toMatch(UTC_DESIGNATOR);
    expect(
      Date.parse(lastLoginAt),
      `last_login_at ${lastLoginAt} is in the future — the value is labelled UTC but was not written in UTC`,
    ).toBeLessThan(Date.now() + 60 * 1000);
  });

  /**
   * Guards the other direction: a timestamp the *server* generates must not run
   * ahead of the client. Before the fix a Berlin-configured host labelled local
   * time as UTC, which put every fresh timestamp in the reader's future — the
   * "logged in at 20:46" the issue reported for an 18:46 login.
   */
  test('server timestamps are not in the future', async ({ authenticatedRequest }) => {
    const requestedAt = Date.now();

    const response = await authenticatedRequest.post('/api/admin/categories', {
      data: { names: { de: `TZ ${randomUUID().substring(0, 8)}`, en: `TZ ${randomUUID().substring(0, 8)}` } },
    });
    const created = await response.json();

    const createdAt = Date.parse(created.created_at);

    // Allowing a small lead keeps this from flaking on ordinary clock skew
    // between the runner and the backend container.
    expect(
      createdAt,
      `created_at ${created.created_at} is more than a minute ahead of the client clock`,
    ).toBeLessThan(requestedAt + 60 * 1000);
  });
});
