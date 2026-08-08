import { test, expect } from '../../fixtures/auth.fixture';
import { readFileSync } from 'fs';
import { resolve } from 'path';

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
const FIXTURE_FILES = resolve(__dirname, '../../fixtures/files');

const mandateUpload = () => ({
  file: {
    name: 'test-mandate.pdf',
    mimeType: 'application/pdf',
    buffer: readFileSync(resolve(FIXTURE_FILES, 'test-mandate.pdf')),
  },
});

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
    expect(auditData.data.length).toBeGreaterThanOrEqual(2); // at least create + anonymize

    // All non-anonymize entries should have old_values and new_values scrubbed
    for (const entry of auditData.data) {
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

    const anonymizeEntries = auditData.data.filter((e: any) => e.action === 'anonymize');
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

  /**
   * #85: a refused anonymization used to destroy the member's signed SEPA
   * mandate anyway — the club's only proof that this member authorized
   * direct debits — because the document was deleted before the eligibility
   * checks ran. The member stays fully active, so the mandate must too.
   */
  test('4.4a - keeps the signed mandate document when an outstanding balance blocks anonymization', async ({ authenticatedRequest, testTransactions }) => {
    const member = await testTransactions.createMember('GdprMandate44a', 'KeepMandate');

    const uploadResponse = await authenticatedRequest.post(
      `${API_BASE}/admin/members/${member.id}/mandate-document`,
      { multipart: mandateUpload() }
    );
    expect(uploadResponse.status()).toBe(200);

    // €5.00 open tab — anonymization must be refused
    await testTransactions.createSyncTransaction(member.id, 500);

    const anonResponse = await authenticatedRequest.post(`${API_BASE}/admin/members/${member.id}/anonymize`);
    expect(anonResponse.status()).toBe(409);

    // The mandate is still downloadable: the failed attempt destroyed nothing
    const documentResponse = await authenticatedRequest.get(
      `${API_BASE}/admin/members/${member.id}/mandate-document`
    );
    expect(documentResponse.status()).toBe(200);
    expect((await documentResponse.body()).length).toBeGreaterThan(0);

    // ...and the member is still active, so the mandate is still needed
    const memberResponse = await authenticatedRequest.get(`${API_BASE}/admin/members/${member.id}`);
    const memberData = await memberResponse.json();
    expect(memberData.deleted_at).toBeNull();
  });

  test('4.4b - keeps the signed mandate document when an active settlement blocks anonymization', async ({ authenticatedRequest, testTransactions }) => {
    const member = await testTransactions.createMember('GdprMandate44b', 'KeepMandate');

    const uploadResponse = await authenticatedRequest.post(
      `${API_BASE}/admin/members/${member.id}/mandate-document`,
      { multipart: mandateUpload() }
    );
    expect(uploadResponse.status()).toBe(200);

    const txId = await testTransactions.createSyncTransaction(member.id, 1000);
    await testTransactions.createSettlement([txId]);

    const anonResponse = await authenticatedRequest.post(`${API_BASE}/admin/members/${member.id}/anonymize`);
    expect(anonResponse.status()).toBe(409);

    const documentResponse = await authenticatedRequest.get(
      `${API_BASE}/admin/members/${member.id}/mandate-document`
    );
    expect(documentResponse.status()).toBe(200);
  });

  /**
   * The counterpart of 4.4a: once the checks pass, the mandate goes with the
   * member (GDPR Art. 17) — both the record and the stored PDF.
   */
  test('4.4c - deletes the mandate document when anonymization succeeds', async ({ authenticatedRequest, testTransactions }) => {
    const member = await testTransactions.createMember('GdprMandate44c', 'DropMandate');

    const uploadResponse = await authenticatedRequest.post(
      `${API_BASE}/admin/members/${member.id}/mandate-document`,
      { multipart: mandateUpload() }
    );
    expect(uploadResponse.status()).toBe(200);

    const anonResponse = await authenticatedRequest.post(`${API_BASE}/admin/members/${member.id}/anonymize`);
    expect(anonResponse.ok()).toBeTruthy();

    const documentResponse = await authenticatedRequest.get(
      `${API_BASE}/admin/members/${member.id}/mandate-document`
    );
    expect(documentResponse.status()).toBe(404);

    // Deleting the mandate is audited with its original filename — that entry
    // must be swept up by the anonymization scrub, not left behind it.
    const auditResponse = await authenticatedRequest.get(
      `${API_BASE}/admin/audit-log?filters[entity_type]=member&filters[entity_id]=${member.id}`
    );
    expect(auditResponse.ok()).toBeTruthy();
    const auditData = await auditResponse.json();
    for (const entry of auditData.data) {
      if (entry.action !== 'anonymize') {
        expect(entry.old_values).toBeNull();
        expect(entry.new_values).toBeNull();
      }
    }
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

    // Zero the balance by reversing the two purchases. There is no free-amount
    // adjustment any more: a storno names one transaction and reverses it in
    // full (its amount derived as the exact negation, #169), so clearing a
    // tab of two purchases takes two stornos. amountCents is passed for
    // documentation only — createStorno() ignores it when relatedTransactionId
    // is supplied.
    await testTransactions.createStorno(member.id, -300, 'Zero out for anonymization', txId1);
    await testTransactions.createStorno(member.id, -500, 'Zero out for anonymization', txId2);

    // Anonymize
    const anonResponse = await authenticatedRequest.post(`${API_BASE}/admin/members/${member.id}/anonymize`);
    expect(anonResponse.ok()).toBeTruthy();

    // Verify transactions still exist via transactions API
    const txnResponse = await authenticatedRequest.get(
      `${API_BASE}/admin/transactions?filters[member_id]=${member.id}`
    );
    expect(txnResponse.ok()).toBeTruthy();
    const txnData = await txnResponse.json();
    // 2 purchases + 2 stornos, all retained: erasure removes the person, not
    // the accounting record (#165)
    expect(txnData.data.length).toBeGreaterThanOrEqual(4);

    // Verify transaction amounts are intact
    const amounts = txnData.data.map((t: any) => t.amount_cents);
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

    for (const entry of auditData.data) {
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
