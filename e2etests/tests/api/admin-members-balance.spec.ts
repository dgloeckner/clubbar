import type { APIRequestContext } from '@playwright/test';
import { test, expect } from '../../fixtures/auth.fixture';

/**
 * The Deckel on the member list (#371).
 *
 * `GET /api/admin/members` now carries `balance_cents` per row — the sum of
 * that member's transactions no settlement has collected. The figure is the
 * same one `TransactionsRepository::getUnsettledMemberBalanceCents` reports
 * for a single member and the dashboard's near-limit panel uses for its own,
 * so these tests assert the two properties that keep it honest: every
 * transaction type counts, and a collected transaction stops counting.
 *
 * Every test creates its own members under a token it puts in their surname
 * and scopes the request to that token, so the assertions hold whatever else
 * the database holds and whatever else runs beside them (Patterns 001, 004).
 * The token is deliberately alphanumeric: the surnames `createMember` builds
 * carry an underscore, and `search` cannot currently match one.
 */
test.describe('Member Deckel on the admin list', () => {
  /** A token unique to one test, used as both a surname and the search scope. */
  function deckelToken(): string {
    return `Dck${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`.replace(/[^A-Za-z0-9]/g, '');
  }

  /** The row for one member, out of the list the token scoped. */
  async function fetchRow(request: APIRequestContext, memberId: string, token: string) {
    const response = await request.get('/api/admin/members', { params: { search: token, per_page: 50 } });
    expect(response.status()).toBe(200);
    const row = (await response.json()).data.find((m: { id: string }) => m.id === memberId);
    expect(row, `member ${memberId} not found under search "${token}"`).toBeDefined();
    return row;
  }

  test('a member who has bought nothing owes nothing', async ({ authenticatedRequest, testTransactions }) => {
    const token = deckelToken();
    const member = await testTransactions.createMember('DeckelEmpty', token);

    const row = await fetchRow(authenticatedRequest, member.id, token);

    // Zero, not absent: the column has to render "0,00 €" rather than a blank
    // the treasurer would read as "not known".
    expect(row.balance_cents).toBe(0);
  });

  test('the Deckel is the sum of the purchases nobody has collected yet', async ({
    authenticatedRequest,
    testTransactions,
  }) => {
    const token = deckelToken();
    const member = await testTransactions.createMember('DeckelSum', token);
    await testTransactions.createSyncTransaction(member.id, 1250, 'Deckel test purchase 1');
    await testTransactions.createSyncTransaction(member.id, 375, 'Deckel test purchase 2');

    const row = await fetchRow(authenticatedRequest, member.id, token);

    expect(row.balance_cents).toBe(1625);
  });

  test('a storno takes its purchase back off the Deckel', async ({
    authenticatedRequest,
    testTransactions,
  }) => {
    const token = deckelToken();
    const member = await testTransactions.createMember('DeckelStorno', token);
    const purchaseId = await testTransactions.createSyncTransaction(member.id, 800, 'Deckel storno target');
    await testTransactions.createSyncTransaction(member.id, 200, 'Deckel storno bystander');

    const before = await fetchRow(authenticatedRequest, member.id, token);
    expect(before.balance_cents).toBe(1000);

    // A storno is a row of its own that negates the one it names, never an
    // edit of it (ADR-0004) — so the tab has to fall by exactly the purchase.
    await testTransactions.createStorno(member.id, 800, 'Deckel test storno', purchaseId);

    const after = await fetchRow(authenticatedRequest, member.id, token);
    expect(after.balance_cents).toBe(200);
  });

  test('a settled purchase leaves the Deckel', async ({ authenticatedRequest, testTransactions }) => {
    const token = deckelToken();
    const member = await testTransactions.createMember('DeckelSettled', token);
    const settledId = await testTransactions.createSyncTransaction(member.id, 2500, 'Deckel settled purchase');

    const before = await fetchRow(authenticatedRequest, member.id, token);
    expect(before.balance_cents).toBe(2500);

    await testTransactions.createSettlement([settledId]);

    // What the club has already collected is not still owed. This is the
    // property that separates the Deckel from a lifetime total, which is why
    // #83 deleted the lifetime one.
    const after = await fetchRow(authenticatedRequest, member.id, token);
    expect(after.balance_cents).toBe(0);
  });

  test('a single member reads the same Deckel as their row on the list', async ({
    authenticatedRequest,
    testTransactions,
  }) => {
    const token = deckelToken();
    const member = await testTransactions.createMember('DeckelDetail', token);
    await testTransactions.createSyncTransaction(member.id, 640, 'Deckel detail purchase');

    const detail = await authenticatedRequest.get(`/api/admin/members/${member.id}`);
    expect(detail.status()).toBe(200);

    const row = await fetchRow(authenticatedRequest, member.id, token);
    expect((await detail.json()).balance_cents).toBe(row.balance_cents);
    expect(row.balance_cents).toBe(640);
  });

  test('sort_by=balance orders the list by the tab, in both directions', async ({
    authenticatedRequest,
    testTransactions,
  }) => {
    const token = deckelToken();
    const big = await testTransactions.createMember('DeckelBig', token);
    const small = await testTransactions.createMember('DeckelSmall', token);
    const none = await testTransactions.createMember('DeckelNone', token);

    await testTransactions.createSyncTransaction(big.id, 5000, 'Deckel sort big');
    await testTransactions.createSyncTransaction(small.id, 500, 'Deckel sort small');

    const descending = await authenticatedRequest.get('/api/admin/members', {
      params: { search: token, sort_by: 'balance_desc' },
    });
    expect(descending.status()).toBe(200);
    expect((await descending.json()).data.map((m: { id: string }) => m.id)).toEqual([big.id, small.id, none.id]);

    const ascending = await authenticatedRequest.get('/api/admin/members', {
      params: { search: token, sort_by: 'balance_asc' },
    });
    expect((await ascending.json()).data.map((m: { id: string }) => m.id)).toEqual([none.id, small.id, big.id]);
  });
});
