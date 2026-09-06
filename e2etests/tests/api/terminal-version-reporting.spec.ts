import type { APIRequestContext } from '@playwright/test';

import { test, expect } from '../../fixtures/auth.fixture';
import { stepUp } from '../../fixtures/stepUp';

/**
 * Terminals report what they are running (#318, ADR-0054 requirement 10).
 *
 * A terminal installs exactly the version its backend reports, and blacklists a
 * version whose update failed. Those two rules together mean a terminal can
 * stop updating *for good* — exact-match makes the only candidate the tag it
 * just blacklisted — and nothing on the Pi says so. So reporting is part of the
 * mechanism rather than a nice-to-have, and these tests exercise it through the
 * whole stack: a terminal syncs with the headers, the backend records them
 * beside `last_sync_at`, and the admin API serves them back with the
 * classification already made.
 *
 * The header is **fail-open**, which is the property most worth pinning here:
 * an old terminal, a build reporting `dev`, or a proxy that strips unknown
 * headers must keep selling drinks and simply report nothing. A version
 * mechanism that can refuse a sale is worse than no version mechanism.
 */

const SYNC_MEMBERS = '/api/sync/members';
const SINCE = { since: '1970-01-01T00:00:00Z' };

const VERSION_HEADER = 'X-Terminal-Version';
const BLOCKED_HEADER = 'X-Terminal-Blocked-Version';

/** A terminal of its own per test, so nothing here depends on another's headers. */
async function createTerminal(authenticatedRequest: APIRequestContext, label: string) {
  const timestamp = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
  const response = await authenticatedRequest.post('/api/admin/terminals', {
    data: { ...stepUp(), name: `Version ${label} ${timestamp}`, device_id: `device-version-${label}-${timestamp}` },
  });
  expect(response.status()).toBe(201);
  return response.json();
}

test.describe('Terminal version reporting', () => {
  test('a sync carrying the version header records it against that terminal', async ({
    authenticatedRequest,
    request,
  }) => {
    const created = await createTerminal(authenticatedRequest, 'record');

    // Nothing has been reported yet, and that must not read as agreement.
    const before = await authenticatedRequest.get(`/api/admin/terminals/${created.terminal.id}`);
    expect((await before.json()).terminal.reported_version).toBeNull();
    expect((await before.json()).terminal.version_state).toBe('unknown');

    const sync = await request.get(SYNC_MEMBERS, {
      headers: { Authorization: `Bearer ${created.api_token}`, [VERSION_HEADER]: 'v1.0.7' },
      params: SINCE,
    });
    expect(sync.status()).toBe(200);

    const after = await authenticatedRequest.get(`/api/admin/terminals/${created.terminal.id}`);
    const { terminal } = await after.json();
    expect(terminal.reported_version).toBe('v1.0.7');
    // Its own stamp, not `last_sync_at`: a terminal can keep syncing while
    // reporting nothing, and a stale version read as current is worse than none.
    expect(terminal.reported_version_at).toBeTruthy();
  });

  test('a terminal that blacklisted a version reports which one', async ({
    authenticatedRequest,
    request,
  }) => {
    const created = await createTerminal(authenticatedRequest, 'blocked');

    const sync = await request.get(SYNC_MEMBERS, {
      headers: {
        Authorization: `Bearer ${created.api_token}`,
        [VERSION_HEADER]: 'v1.0.6',
        [BLOCKED_HEADER]: 'v1.0.7',
      },
      params: SINCE,
    });
    expect(sync.status()).toBe(200);

    const { terminal } = await (
      await authenticatedRequest.get(`/api/admin/terminals/${created.terminal.id}`)
    ).json();
    expect(terminal.reported_version).toBe('v1.0.6');
    expect(terminal.blocked_version).toBe('v1.0.7');
  });

  test('a terminal that stops reporting a block clears it', async ({
    authenticatedRequest,
    request,
  }) => {
    // An operator ran `clubbar-update.sh --clear-block`. An alarm nobody can
    // dismiss is an alarm everybody learns to ignore.
    const created = await createTerminal(authenticatedRequest, 'unblock');
    const auth = { Authorization: `Bearer ${created.api_token}` };

    await request.get(SYNC_MEMBERS, {
      headers: { ...auth, [VERSION_HEADER]: 'v1.0.6', [BLOCKED_HEADER]: 'v1.0.7' },
      params: SINCE,
    });
    await request.get(SYNC_MEMBERS, {
      headers: { ...auth, [VERSION_HEADER]: 'v1.0.6' },
      params: SINCE,
    });

    const { terminal } = await (
      await authenticatedRequest.get(`/api/admin/terminals/${created.terminal.id}`)
    ).json();
    expect(terminal.blocked_version).toBeNull();
  });

  /**
   * Fail-open. Each of these syncs must succeed, and each must leave the
   * version columns exactly as they were rather than storing a value the
   * updater's own rules would refuse.
   */
  for (const [label, value] of [
    ['a build from git', 'dev'],
    ['a build from a commit', 'dev-4f2a9c1'],
    ['something a middlebox wrote', 'latest'],
    ['a path', '../../etc/passwd'],
    ['an empty header', ''],
  ] as const) {
    test(`${label} syncs normally and records no version`, async ({ authenticatedRequest, request }) => {
      const created = await createTerminal(authenticatedRequest, 'failopen');

      const sync = await request.get(SYNC_MEMBERS, {
        headers: { Authorization: `Bearer ${created.api_token}`, [VERSION_HEADER]: value },
        params: SINCE,
      });
      expect(sync.status()).toBe(200);

      const { terminal } = await (
        await authenticatedRequest.get(`/api/admin/terminals/${created.terminal.id}`)
      ).json();
      expect(terminal.reported_version).toBeNull();
      // The sync itself landed, which is the point of fail-open.
      expect(terminal.last_sync_at).toBeTruthy();
    });
  }

  test('a sync with no version header at all is unaffected', async ({
    authenticatedRequest,
    request,
  }) => {
    // Every terminal in every club, until it takes a build that carries the
    // header. This is the compatibility case, not an edge case.
    const created = await createTerminal(authenticatedRequest, 'absent');

    const sync = await request.get(SYNC_MEMBERS, {
      headers: { Authorization: `Bearer ${created.api_token}` },
      params: SINCE,
    });
    expect(sync.status()).toBe(200);

    const { terminal } = await (
      await authenticatedRequest.get(`/api/admin/terminals/${created.terminal.id}`)
    ).json();
    expect(terminal.reported_version).toBeNull();
    expect(terminal.version_state).toBe('unknown');
  });

  /**
   * One version, read by two things. `/api/health` publishes it and the
   * Terminals page measures every terminal against it; two readings of
   * `backend/VERSION` that could disagree about what counts as absent is
   * exactly the silent contradiction ADR-0054 refuses for the terminal's own
   * version.
   */
  test('the yardstick the panel uses is the version /api/health publishes', async ({
    authenticatedRequest,
    request,
  }) => {
    const created = await createTerminal(authenticatedRequest, 'yardstick');
    const health = await (await request.get('/api/health')).json();

    const { terminal } = await (
      await authenticatedRequest.get(`/api/admin/terminals/${created.terminal.id}`)
    ).json();
    expect(terminal.backend_version).toBe(health.version);
  });

  /**
   * A backend that is not on a release tag never moves a terminal, so it has no
   * opinion about whether one is up to date either. The development stack runs
   * with no `backend/VERSION`, which is precisely that case — so this asserts
   * the fail-closed branch on the deployment that actually exhibits it.
   */
  test('a backend that is not on a release tag classifies nothing', async ({
    authenticatedRequest,
    request,
  }) => {
    const created = await createTerminal(authenticatedRequest, 'devbackend');
    const health = await (await request.get('/api/health')).json();
    test.skip(/^v\d+\.\d+\.\d+/.test(health.version), 'this stack is on a release tag');

    await request.get(SYNC_MEMBERS, {
      headers: { Authorization: `Bearer ${created.api_token}`, [VERSION_HEADER]: 'v1.0.7' },
      params: SINCE,
    });

    const { terminal } = await (
      await authenticatedRequest.get(`/api/admin/terminals/${created.terminal.id}`)
    ).json();
    // The terminal reported honestly; the backend simply cannot say whether
    // that is current, and must not guess.
    expect(terminal.reported_version).toBe('v1.0.7');
    expect(terminal.version_state).toBe('unknown');
  });

  test('the terminal list carries the version fields, not only the detail view', async ({
    authenticatedRequest,
    request,
  }) => {
    // The Terminals page reads the list. A DTO that answers on one route and
    // not the other renders an empty column nobody can explain.
    const created = await createTerminal(authenticatedRequest, 'list');
    await request.get(SYNC_MEMBERS, {
      headers: { Authorization: `Bearer ${created.api_token}`, [VERSION_HEADER]: 'v1.0.7' },
      params: SINCE,
    });

    const list = await authenticatedRequest.get('/api/admin/terminals', { params: { per_page: 100 } });
    expect(list.status()).toBe(200);
    const body = await list.json();
    const row = body.data.find((t: { id: string }) => t.id === created.terminal.id);
    expect(row).toBeTruthy();
    expect(row.reported_version).toBe('v1.0.7');
    expect(row.version_state).toBeTruthy();
  });
});
