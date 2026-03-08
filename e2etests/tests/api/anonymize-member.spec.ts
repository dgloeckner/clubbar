import { test, expect } from '../../fixtures/auth.fixture';

/**
 * E2E Tests: GDPR Member Anonymization (UC-DSGVO-02)
 *
 * Verifies complete GDPR Art. 17 compliance:
 * - All PII fields set to NULL after anonymization
 * - Historical audit log entries scrubbed (no PII reconstructable)
 * - Anonymization audit entry contains no PII
 * - Pre-deletion checks (balance, settlement, already-anonymized)
 * - Transactions preserved after anonymization
 *
 * Related files:
 * - backend/src/Modules/Members/Services/MembersService.php (anonymizeMember)
 * - backend/src/Modules/Members/Repositories/MembersRepository.php (anonymize)
 * - backend/src/Modules/AuditLog/Repositories/AuditLogRepository.php (scrubByEntityId)
 * - use-cases/dsgvo/uc-dsgvo-02-right-to-erasure.md
 */

const API_BASE = 'http://localhost:8080/api';

test.describe('GDPR Member Anonymization', () => {

  test('4.1 - anonymizes all PII fields to NULL', async ({ authenticatedRequest, testTransactions }) => {
    // Create member with full data
    const member = await testTransactions.createMember('GdprAnon41', 'TestUser');
    expect(member.id).toBeTruthy();
    expect(member.first_name).toContain('GdprAnon41');

    // Anonymize
    const anonResponse = await authenticatedRequest.post(`${API_BASE}/admin/members/${member.id}/anonymize`);
    expect(anonResponse.ok()).toBeTruthy();
    const anonBody = await anonResponse.json();

    // Verify all PII fields are NULL
    expect(anonBody.first_name).toBeNull();
    expect(anonBody.last_name).toBeNull();
    expect(anonBody.email).toBeNull();
    expect(anonBody.iban).toBeNull();
    expect(anonBody.account_holder_name).toBeNull();
    expect(anonBody.mandate_reference).toBeNull();

    // card_uid should be ANON-{uuid}, not null
    expect(anonBody.card_uid).toMatch(/^ANON-/);

    // Functional fields updated
    expect(anonBody.is_active).toBeFalsy();
    expect(anonBody.deleted_at).toBeTruthy();

    // Verify via GET as well
    const getResponse = await authenticatedRequest.get(`${API_BASE}/admin/members/${member.id}`);
    expect(getResponse.ok()).toBeTruthy();
    const getBody = await getResponse.json();
    expect(getBody.first_name).toBeNull();
    expect(getBody.last_name).toBeNull();
    expect(getBody.email).toBeNull();
    expect(getBody.iban).toBeNull();
  });

  test('4.2 - scrubs historical audit log entries', async ({ authenticatedRequest, testTransactions }) => {
    // Create member (generates 'create' audit entry with PII)
    const member = await testTransactions.createMember('GdprAudit42', 'ScrubTest');

    // Update member name (generates 'update' audit entry with old/new name PII)
    const updateResponse = await authenticatedRequest.patch(`${API_BASE}/admin/members/${member.id}`, {
      data: { first_name: 'UpdatedName42' },
    });
    expect(updateResponse.ok()).toBeTruthy();

    // Anonymize (should scrub all historical audit entries)
    const anonResponse = await authenticatedRequest.post(`${API_BASE}/admin/members/${member.id}/anonymize`);
    expect(anonResponse.ok()).toBeTruthy();

    // Query audit log for this member
    const auditResponse = await authenticatedRequest.get(
      `${API_BASE}/admin/audit-log?filters[entity_type]=member&filters[entity_id]=${member.id}`
    );
    expect(auditResponse.ok()).toBeTruthy();
    const auditData = await auditResponse.json();
    expect(auditData.items.length).toBeGreaterThanOrEqual(2); // at least create + anonymize

    // All non-anonymize entries should have old_values and new_values scrubbed
    for (const entry of auditData.items) {
      if (entry.action !== 'anonymize') {
        expect(entry.old_values).toBeNull();
        expect(entry.new_values).toBeNull();
      }
    }
  });

  test('4.3 - anonymization audit entry contains no PII', async ({ authenticatedRequest, testTransactions }) => {
    const member = await testTransactions.createMember('GdprNoPii43', 'AuditCheck');

    // Anonymize
    await authenticatedRequest.post(`${API_BASE}/admin/members/${member.id}/anonymize`);

    // Find the anonymize audit entry
    const auditResponse = await authenticatedRequest.get(
      `${API_BASE}/admin/audit-log?filters[entity_type]=member&filters[entity_id]=${member.id}&filters[action]=anonymize`
    );
    expect(auditResponse.ok()).toBeTruthy();
    const auditData = await auditResponse.json();

    const anonymizeEntries = auditData.items.filter((e: any) => e.action === 'anonymize');
    expect(anonymizeEntries.length).toBe(1);

    const entry = anonymizeEntries[0];
    // old_values must be null (no PII)
    expect(entry.old_values).toBeNull();

    // new_values should only contain deleted_at
    if (entry.new_values !== null) {
      const newVals = typeof entry.new_values === 'string' ? JSON.parse(entry.new_values) : entry.new_values;
      expect(newVals).toHaveProperty('deleted_at');
      // Must NOT contain any PII fields
      expect(newVals).not.toHaveProperty('first_name');
      expect(newVals).not.toHaveProperty('last_name');
      expect(newVals).not.toHaveProperty('email');
      expect(newVals).not.toHaveProperty('iban');
    }
  });

  test('4.4 - blocks anonymization with outstanding balance', async ({ authenticatedRequest, testTransactions }) => {
    // Create member with a transaction (positive balance)
    const member = await testTransactions.createMember('GdprBalance44', 'BlockTest');
    await testTransactions.createSyncTransaction(member.id, 500); // €5.00

    // Attempt anonymize — should be blocked
    const anonResponse = await authenticatedRequest.post(`${API_BASE}/admin/members/${member.id}/anonymize`);
    expect(anonResponse.status()).toBe(409);
    const error = await anonResponse.json();
    expect(error.message).toContain('outstanding balance');

    // Verify member still has PII (not anonymized)
    const getResponse = await authenticatedRequest.get(`${API_BASE}/admin/members/${member.id}`);
    expect(getResponse.ok()).toBeTruthy();
    const memberData = await getResponse.json();
    expect(memberData.first_name).toContain('GdprBalance44');
    expect(memberData.deleted_at).toBeNull();
  });

  test('4.5 - blocks anonymization with pending settlement', async ({ authenticatedRequest, testTransactions }) => {
    // Create member, transaction, and settlement
    const member = await testTransactions.createMember('GdprSettle45', 'BlockTest');
    const txId = await testTransactions.createSyncTransaction(member.id, 1000);
    await testTransactions.createSettlement([txId]);

    // Attempt anonymize — should be blocked (member in active settlement)
    const anonResponse = await authenticatedRequest.post(`${API_BASE}/admin/members/${member.id}/anonymize`);
    expect(anonResponse.status()).toBe(409);
    const error = await anonResponse.json();
    expect(error.message).toContain('settlement');
  });

  test('4.6 - rejects double anonymization', async ({ authenticatedRequest, testTransactions }) => {
    const member = await testTransactions.createMember('GdprDouble46', 'DoubleTest');

    // First anonymize — success
    const first = await authenticatedRequest.post(`${API_BASE}/admin/members/${member.id}/anonymize`);
    expect(first.ok()).toBeTruthy();

    // Second anonymize — should fail
    const second = await authenticatedRequest.post(`${API_BASE}/admin/members/${member.id}/anonymize`);
    expect(second.ok()).toBeFalsy();
    // Could be 404 (findById excludes deleted) or 409 (already anonymized)
    expect([404, 409]).toContain(second.status());
  });

  test('4.7 - preserves transactions after anonymization', async ({ authenticatedRequest, testTransactions }) => {
    const member = await testTransactions.createMember('GdprTxn47', 'PreserveTest');

    // Create transactions and settle them (so balance is considered settled)
    const txId1 = await testTransactions.createSyncTransaction(member.id, 300);
    const txId2 = await testTransactions.createSyncTransaction(member.id, 500);

    // Create a correction to zero out the balance
    await testTransactions.createCorrection(member.id, -800, 'Zero out for anonymization');

    // Anonymize
    const anonResponse = await authenticatedRequest.post(`${API_BASE}/admin/members/${member.id}/anonymize`);
    expect(anonResponse.ok()).toBeTruthy();

    // Verify transactions still exist via transactions API
    const txnResponse = await authenticatedRequest.get(
      `${API_BASE}/admin/transactions?filters[member_id]=${member.id}`
    );
    expect(txnResponse.ok()).toBeTruthy();
    const txnData = await txnResponse.json();
    // Should have at least the 3 transactions (2 purchases + 1 correction)
    expect(txnData.items.length).toBeGreaterThanOrEqual(3);

    // Verify transaction amounts are intact
    const amounts = txnData.items.map((t: any) => t.amount_cents);
    expect(amounts).toContain(300);
    expect(amounts).toContain(500);
  });

  test('4.8 - no PII reconstructable from any table', async ({ authenticatedRequest, testTransactions }) => {
    const uniqueMarker = `GDPR48_${Date.now()}`;
    const member = await testTransactions.createMember(uniqueMarker, 'Reconstruct');
    const originalFirstName = member.first_name;
    const originalEmail = member.email;

    // Update member to generate audit trail with PII
    await authenticatedRequest.patch(`${API_BASE}/admin/members/${member.id}`, {
      data: { first_name: `${uniqueMarker}Updated` },
    });

    // Anonymize
    const anonResponse = await authenticatedRequest.post(`${API_BASE}/admin/members/${member.id}/anonymize`);
    expect(anonResponse.ok()).toBeTruthy();

    // Check member table — no PII
    const memberResponse = await authenticatedRequest.get(`${API_BASE}/admin/members/${member.id}`);
    expect(memberResponse.ok()).toBeTruthy();
    const memberData = await memberResponse.json();
    expect(memberData.first_name).toBeNull();
    expect(memberData.email).toBeNull();

    // Check audit log — no PII in any entry
    const auditResponse = await authenticatedRequest.get(
      `${API_BASE}/admin/audit-log?filters[entity_type]=member&filters[entity_id]=${member.id}`
    );
    expect(auditResponse.ok()).toBeTruthy();
    const auditData = await auditResponse.json();

    for (const entry of auditData.items) {
      // old_values and new_values must not contain the original name or email
      const oldStr = JSON.stringify(entry.old_values);
      const newStr = JSON.stringify(entry.new_values);
      expect(oldStr).not.toContain(originalFirstName);
      expect(oldStr).not.toContain(originalEmail);
      // For non-anonymize entries, values should be null entirely
      if (entry.action !== 'anonymize') {
        expect(entry.old_values).toBeNull();
        expect(entry.new_values).toBeNull();
      }
    }
  });
});
