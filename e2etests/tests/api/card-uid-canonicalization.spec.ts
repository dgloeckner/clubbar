import { test } from '../../fixtures/auth.fixture';
import { expect } from '@playwright/test';
// `Math.random()` here made CodeQL read a card UID as a value generated in a
// security context. It is only test-data uniqueness, but the repo already uses
// node:crypto for exactly this, so there is no reason to argue with the scanner.
import { randomUUID } from 'node:crypto';

/**
 * E2E: a chip has one spelling in the database, whatever it was typed as.
 *
 * `members.card_uid` is matched by exact string comparison, and the same 4-byte
 * chip is printed as `001EB4CB`, `001eb4cb`, `00:1E:B4:CB`, `0x001EB4CB` or
 * `1EB4CBA` depending on which reader or diagnostic tool the volunteer copied
 * it from (ADR-0055). Storing whichever arrived means the card works only until
 * somebody swaps the reader — and the uniqueness check does not notice that
 * `001EB4CB` and `00:1E:B4:CB` are one card being handed to two members.
 *
 * These drive the real endpoint and read back what was actually stored.
 *
 * Related files:
 * - backend/src/Shared/Utils/CardUid.php
 * - backend/src/Modules/Members/Controllers/AdminController.php
 * - adr/0055-canonical-card-uid.md
 */
test.describe('Members API - card UID canonicalization', () => {
  const API_BASE = 'http://localhost:8080/api';

  /**
   * A UID unique to this run, in the canonical spelling, plus the same chip in
   * the dialects a reader prints. Unique per test (Pattern 001): `card_uid` is
   * UNIQUE, so a shared literal would make these tests refuse each other.
   */
  /** `n` hex digits of randomness, so parallel workers cannot collide. */
  function rand(n: number): string {
    return randomUUID().replace(/-/g, '').slice(0, n).toUpperCase();
  }

  function chip(): { canonical: string; grouped: string; lower: string; prefixed: string } {
    // Eight hex digits: a leading zero byte (so the canonical form is visibly
    // not what a reader dropping it would send), then a run unique to this
    // millisecond and this worker.
    const canonical = `00${rand(2)}${Date.now().toString(16).toUpperCase().slice(-4)}`;
    const bytes = canonical.match(/../g)!;

    return {
      canonical,
      grouped: bytes.join(':'),
      lower: canonical.toLowerCase(),
      prefixed: `0x${canonical}`,
    };
  }

  async function createMember(request: any, cardUid: string | undefined, token: string) {
    return request.post(`${API_BASE}/admin/members`, {
      data: {
        first_name: `Canon${token}`,
        last_name: 'Test',
        email: `canon-${token}@test.com`,
        date_of_birth: '1985-06-15',
        iban: 'DE89370400440532013000',
        mandate_reference: `MAN${token}`.slice(0, 35),
        mandate_signed_at: '2024-01-15',
        preferred_language: 'de',
        ...(cardUid === undefined ? {} : { card_uid: cardUid }),
      },
    });
  }

  test('every spelling a reader prints is stored as one canonical UID', async ({
    authenticatedRequest,
  }) => {
    // Each dialect goes to its own member, because they are all the same card
    // and the column is UNIQUE — which is itself the point of the next test.
    for (const spell of ['lower', 'grouped', 'prefixed'] as const) {
      const card = chip();
      const token = `${spell}${Date.now()}${rand(4)}`;

      const created = await createMember(authenticatedRequest, card[spell], token);
      expect(created.ok(), `POST with ${spell} spelling ${card[spell]}`).toBeTruthy();

      // What was stored, not what was sent.
      expect((await created.json()).card_uid).toBe(card.canonical);

      // …and what a re-read returns, so this is the row and not the response
      // the controller happened to build.
      const memberId = (await created.json()).id;
      const fetched = await authenticatedRequest.get(`${API_BASE}/admin/members/${memberId}`);
      expect((await fetched.json()).card_uid).toBe(card.canonical);
    }
  });

  test('two spellings of one chip cannot reach two members', async ({
    authenticatedRequest,
  }) => {
    const card = chip();
    const token = `dup${Date.now()}`;

    const first = await createMember(authenticatedRequest, card.canonical, `${token}a`);
    expect(first.ok()).toBeTruthy();

    // The same card, written the way a different tool prints it. Before
    // canonicalization the UNIQUE index saw two different values and let this
    // through — one chip, two members, and the bookings went to whoever tapped.
    const second = await createMember(authenticatedRequest, card.grouped, `${token}b`);

    expect(second.status()).toBe(422);
    expect(JSON.stringify(await second.json()).toLowerCase()).toContain('card_uid');
  });

  test('a leading zero dropped between the card and the keyboard is restored', async ({
    authenticatedRequest,
  }) => {
    const token = `pad${Date.now()}`;
    // Seven digits: half a written byte, which is a leading zero that went
    // missing rather than a card seven nibbles wide.
    const typed = `1E${rand(2)}${Date.now().toString(16).toUpperCase().slice(-3)}`;

    const created = await createMember(authenticatedRequest, typed, token);

    expect(created.ok(), await created.text()).toBeTruthy();
    expect((await created.json()).card_uid).toBe(`0${typed}`);
  });

  test('a PATCH is canonicalized like a create', async ({ authenticatedRequest }) => {
    const token = `patch${Date.now()}`;
    const created = await createMember(authenticatedRequest, undefined, token);
    expect(created.ok()).toBeTruthy();
    const memberId = (await created.json()).id;

    const card = chip();
    const patched = await authenticatedRequest.patch(`${API_BASE}/admin/members/${memberId}`, {
      data: { card_uid: card.grouped },
    });

    expect(patched.ok(), await patched.text()).toBeTruthy();
    expect((await patched.json()).card_uid).toBe(card.canonical);
  });

  test('a value that is not a card UID is refused rather than guessed at', async ({
    authenticatedRequest,
  }) => {
    const token = `bad${Date.now()}`;

    const created = await createMember(authenticatedRequest, 'die blaue Karte', token);

    expect(created.status()).toBe(422);
    expect(Object.keys((await created.json()).messages)).toContain('card_uid');
  });

  test('a digits-only UID is stored as hex, not read as decimal', async ({
    authenticatedRequest,
  }) => {
    const token = `dec${Date.now()}`;
    // `0002012363` is the decimal spelling of `001EB4CB` *and* a well-formed
    // five-byte hex UID. Nothing in the string says which, so the backend — the
    // store of record — reads it as what it literally is and never guesses.
    // Decimal is resolved where the answer is known: the terminal's configured
    // reader profile, or an admin converting it explicitly in the form.
    const typed = `0002${Date.now().toString().slice(-6)}`;

    const created = await createMember(authenticatedRequest, typed, token);

    expect(created.ok(), await created.text()).toBeTruthy();
    expect((await created.json()).card_uid).toBe(typed);
  });
});
