/**
 * A valid German IBAN that nobody else in the database holds.
 *
 * Most specs can share the fixture IBAN, because nothing about them depends on
 * which account a member has. The ones that cannot are the ones where the
 * account *is* the subject:
 *
 * - the registration inbox flags a submission whose IBAN fingerprint matches an
 *   existing member's mandate, and the batch acceptance skips a flagged row —
 *   so a spec asserting "this registration is accepted" on the shared IBAN is
 *   asserting on whatever every other spec left behind;
 * - the mandate print puts the IBAN on a page, and "the keyed PDF contains this
 *   member's number" is only a claim about this member if the number is theirs
 *   alone.
 *
 * Bank code 37040044 is real and seeded (so the bank-name lookup resolves); the
 * account number is drawn per call and the check digits computed from it.
 */

import { randomInt } from 'node:crypto'

export function uniqueIban(): string {
  const account = String(randomInt(1, 9_999_999_999)).padStart(10, '0')
  const bban = `37040044${account}`

  // ISO 13616: append the country code as digits (D=13, E=14) and "00", take
  // mod 97, subtract from 98.
  const check = 98n - (BigInt(`${bban}131400`) % 97n)

  return `DE${String(check).padStart(2, '0')}${bban}`
}
