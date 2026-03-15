import { test } from '../../fixtures/auth.fixture';
import { expect } from '@playwright/test';

/**
 * E2E Test: Members API - Card UID Filtering
 *
 * Verifies that the members API correctly filters members by card_uid presence/absence.
 *
 * Related files:
 * - backend/src/Modules/Members/Repositories/MembersRepository.php
 * - backend/src/Modules/Members/Controllers/AdminController.php
 * - plans/2026-02-07-rfid-implementation-plan.md (Task 1.1)
 */

test.describe('Members API - Card UID Filter', () => {
  const API_BASE = 'http://localhost:8080/api';

  test('GET /admin/members?filters[has_card_uid]=true returns only members with card_uid', async ({ authenticatedRequest }) => {
    const testId = `CardFilter${Date.now()}`;
    const uniqueCardUid = `ABCD${Date.now().toString().slice(-8)}`;

    // Create member WITH card_uid
    const memberWith = await authenticatedRequest.post(`${API_BASE}/admin/members`, {
      data: {
        first_name: `${testId}With`,
        last_name: 'Test',
        email: `${testId}with@test.com`,
        iban: 'DE89370400440532013000',
        mandate_reference: `MAN${testId}W`,
        mandate_signed_at: '2024-01-15',
        preferred_language: 'de',
        card_uid: uniqueCardUid
      }
    });
    expect(memberWith.ok()).toBeTruthy();
    const memberWithData = await memberWith.json();

    // Create member WITHOUT card_uid
    const memberWithout = await authenticatedRequest.post(`${API_BASE}/admin/members`, {
      data: {
        first_name: `${testId}Without`,
        last_name: 'Test',
        email: `${testId}without@test.com`,
        iban: 'DE02120300000000202051',
        mandate_reference: `MAN${testId}WO`,
        mandate_signed_at: '2024-01-15',
        preferred_language: 'de'
      }
    });
    expect(memberWithout.ok()).toBeTruthy();
    const memberWithoutData = await memberWithout.json();

    // Filter: has_card_uid=true
    const responseWithCard = await authenticatedRequest.get(`${API_BASE}/admin/members?filters[has_card_uid]=true`);
    expect(responseWithCard.ok()).toBeTruthy();
    const dataWith = await responseWithCard.json();

    const withCardIds = dataWith.items.map((m: any) => m.id);
    expect(withCardIds).toContain(memberWithData.id);
    expect(withCardIds).not.toContain(memberWithoutData.id);

    // Filter: has_card_uid=false
    const responseWithoutCard = await authenticatedRequest.get(`${API_BASE}/admin/members?filters[has_card_uid]=false`);
    expect(responseWithoutCard.ok()).toBeTruthy();
    const dataWithout = await responseWithoutCard.json();

    const withoutCardIds = dataWithout.items.map((m: any) => m.id);
    expect(withoutCardIds).not.toContain(memberWithData.id);
    expect(withoutCardIds).toContain(memberWithoutData.id);
  });
});
