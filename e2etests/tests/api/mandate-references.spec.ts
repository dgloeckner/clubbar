import { test } from '../../fixtures/auth.fixture';
import { expect } from '@playwright/test';

/**
 * E2E: mandate references are minted from the install's counter (#936).
 *
 * The reference is the one identifier a member sees on their own Kontoauszug
 * and reads aloud to the Kassenwart when a collection is queried. It used to be
 * 32 hex characters from a UUID; it is now `<PREFIX>-<number>` drawn from a
 * single counter row, which is all the uniqueness SEPA asks for (one install is
 * one Gläubiger-ID).
 *
 * **On ordering.** These tests assert that a later mint is a *larger* number,
 * never that it is the *next* one: the counter is shared with every other spec
 * running in parallel and with self-registration, so a gap between two of this
 * file's creates is correct behaviour rather than a failure (Pattern 004).
 * Strict consecutiveness is asserted where the database is not shared, in
 * `backend/tests/Feature/Modules/Members/Repositories/MembersRepositoryTest.php`.
 *
 * Related:
 * - backend/src/Shared/Sepa/MandateReferenceMinter.php
 * - backend/db/migrations/069_mandate_reference_counter.sql
 * - adr/0006-sepa-mandate-reference-strategy.md
 */

const API_BASE = 'http://localhost:8080/api';
const REFERENCE = /^CB-\d{6,}$/;

/** The number out of a `CB-000042`. */
function numberOf(reference: string): number {
  expect(reference).toMatch(REFERENCE);
  return Number(reference.slice(reference.lastIndexOf('-') + 1));
}

test.describe('Mandate references', () => {
  let created: string[] = [];

  test.afterEach(async ({ authenticatedRequest }) => {
    for (const id of created) {
      await authenticatedRequest.delete(`${API_BASE}/admin/members/${id}`);
    }
    created = [];
  });

  async function createMember(
    authenticatedRequest: any,
    overrides: Record<string, unknown> = {},
  ): Promise<any> {
    const testId = `MRef${Date.now()}${Math.floor(Math.random() * 100000)}`;
    const response = await authenticatedRequest.post(`${API_BASE}/admin/members`, {
      data: {
        first_name: testId,
        last_name: 'Reference',
        email: `${testId.toLowerCase()}@test.com`,
        date_of_birth: '1985-06-15',
        iban: 'DE89370400440532013000',
        mandate_signed_at: '2024-01-15',
        preferred_language: 'de',
        ...overrides,
      },
    });
    expect(response.status(), await response.text()).toBe(201);

    const member = await response.json();
    created.push(member.id);
    return member;
  }

  test('a member created with an IBAN and no reference gets a short, readable one', async ({ authenticatedRequest }) => {
    const member = await createMember(authenticatedRequest);

    expect(member.mandate_reference).toMatch(REFERENCE);
    // The SEPA cap, and the reason the short form exists at all: this is what
    // gets printed in a 108pt field on the club's Anmeldung.
    expect(member.mandate_reference.length).toBeLessThanOrEqual(35);
  });

  test('two creates draw different, increasing numbers from one counter', async ({ authenticatedRequest }) => {
    const first = await createMember(authenticatedRequest);
    const second = await createMember(authenticatedRequest, { iban: 'DE02120300000000202051' });

    expect(second.mandate_reference).not.toBe(first.mandate_reference);
    expect(numberOf(second.mandate_reference)).toBeGreaterThan(numberOf(first.mandate_reference));
  });

  /**
   * A bank change ends the mandate and opens a new one (#164), so the new one
   * takes a new number — and the ended one keeps the reference it was signed
   * and collected under, because a return arriving months later is matched by
   * that `MREF+` (#165).
   */
  test('a bank change opens a mandate with a later number', async ({ authenticatedRequest }) => {
    const member = await createMember(authenticatedRequest);
    const original = member.mandate_reference;

    const updated = await authenticatedRequest.patch(`${API_BASE}/admin/members/${member.id}`, {
      data: { iban: 'DE02120300000000202051' },
    });
    expect(updated.ok(), await updated.text()).toBeTruthy();

    const after = await updated.json();
    expect(after.iban_last4).toBe('2051');
    expect(numberOf(after.mandate_reference)).toBeGreaterThan(numberOf(original));
  });

  /**
   * The prefix exists mainly so a reference carried over from a previous system
   * cannot collide with the club's own sequence, so a typed one is stored as
   * typed rather than replaced.
   */
  test('a reference the admin types in is stored unchanged', async ({ authenticatedRequest }) => {
    const named = `OLDSYS-${Date.now()}`;

    const member = await createMember(authenticatedRequest, { mandate_reference: named });

    expect(member.mandate_reference).toBe(named);
  });

  /** A member with no IBAN has no mandate, and therefore no reference (#164). */
  test('no IBAN means no reference and no number drawn', async ({ authenticatedRequest }) => {
    const member = await createMember(authenticatedRequest, { iban: '', mandate_signed_at: '' });

    expect(member.mandate_reference ?? null).toBeNull();
  });
});
