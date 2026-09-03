import { describe, expect, it } from 'vitest'
import { deriveSepaFormStatus, type SepaFormStatusInput } from './sepaStatus'

const VALID_IBAN = 'DE89370400440532013000'
const INVALID_IBAN = 'DE89370400440532013001'

/** A member with bank details on file and a complete mandate. */
function base(overrides: Partial<SepaFormStatusInput> = {}): SepaFormStatusInput {
  return {
    savedIsValid: true,
    hasStoredIban: true,
    removalPending: false,
    typedIban: '',
    mandateReference: 'MREF123',
    mandateSignedAt: '2025-01-15',
    ...overrides,
  }
}

describe('deriveSepaFormStatus', () => {
  it('reports the saved state when nothing in the form changes it', () => {
    expect(deriveSepaFormStatus(base())).toBe('valid')
  })

  it('reports missing for a member with no bank details and nothing typed', () => {
    expect(
      deriveSepaFormStatus(
        base({ savedIsValid: false, hasStoredIban: false, mandateReference: '' })
      )
    ).toBe('missing')
  })

  describe('the signature date (ADR-0020, #164)', () => {
    it('does not call a mandate valid while the date is empty', () => {
      // What the screen used to show: "SEPA-Mandat gültig" above an empty
      // Mandatsdatum, on a member the export would then collect from under an
      // invented DtOfSgntr.
      expect(deriveSepaFormStatus(base({ mandateSignedAt: '' }))).toBe('willBecomeInvalid')
    })

    it('previews the revocation when the admin clears a stored date', () => {
      // The same warning removing the account gets, because it costs the same
      // thing: a member the club can no longer collect from.
      expect(
        deriveSepaFormStatus(base({ savedIsValid: true, mandateSignedAt: '   ' }))
      ).toBe('willBecomeInvalid')
    })

    it('does not promise validity for a typed IBAN with no signature date', () => {
      expect(
        deriveSepaFormStatus(
          base({
            savedIsValid: false,
            hasStoredIban: false,
            typedIban: VALID_IBAN,
            mandateReference: '',
            mandateSignedAt: '',
          })
        )
      ).toBe('missing')
    })

    it('promises validity once the IBAN and the date are both there', () => {
      expect(
        deriveSepaFormStatus(
          base({
            savedIsValid: false,
            hasStoredIban: false,
            typedIban: VALID_IBAN,
            mandateReference: '',
            mandateSignedAt: '2026-02-01',
          })
        )
      ).toBe('willBecomeValid')
    })
  })

  describe('pending removal', () => {
    it('previews the revocation instead of the saved "valid"', () => {
      expect(deriveSepaFormStatus(base({ removalPending: true }))).toBe(
        'willBecomeInvalid'
      )
    })

    it('previews it for a member whose mandate was already incomplete', () => {
      // IBAN on file but no reference — saved state is invalid, and removing
      // the account is still a change worth announcing.
      expect(
        deriveSepaFormStatus(
          base({ savedIsValid: false, removalPending: true, mandateReference: '' })
        )
      ).toBe('willBecomeInvalid')
    })

    it('stays "missing" when there is nothing to revoke', () => {
      expect(
        deriveSepaFormStatus(
          base({
            savedIsValid: false,
            hasStoredIban: false,
            removalPending: true,
            mandateReference: '',
          })
        )
      ).toBe('missing')
    })
  })

  describe('a typed IBAN for a member who has none', () => {
    const noAccount = { savedIsValid: false, hasStoredIban: false, mandateReference: '' }

    it('previews validity once the IBAN passes the checksum', () => {
      expect(deriveSepaFormStatus(base({ ...noAccount, typedIban: VALID_IBAN }))).toBe(
        'willBecomeValid'
      )
    })

    it('stays "missing" while the IBAN is still being typed', () => {
      expect(deriveSepaFormStatus(base({ ...noAccount, typedIban: 'DE893704' }))).toBe(
        'missing'
      )
    })

    it('stays "missing" for an IBAN that fails the checksum', () => {
      expect(deriveSepaFormStatus(base({ ...noAccount, typedIban: INVALID_IBAN }))).toBe(
        'missing'
      )
    })

    it('previews validity with a blank reference — the server assigns one', () => {
      expect(
        deriveSepaFormStatus(base({ ...noAccount, typedIban: VALID_IBAN, mandateReference: '' }))
      ).toBe('willBecomeValid')
    })

    it('treats a whitespace-only reference as blank, not as a value', () => {
      expect(
        deriveSepaFormStatus(
          base({ ...noAccount, typedIban: VALID_IBAN, mandateReference: '   ' })
        )
      ).toBe('willBecomeValid')
    })
  })

  it('previews validity for a stored IBAN that gains a mandate reference', () => {
    expect(
      deriveSepaFormStatus(
        base({ savedIsValid: false, hasStoredIban: true, mandateReference: 'MREF999' })
      )
    ).toBe('willBecomeValid')
  })

  it('does not downgrade a saved-valid member while a new IBAN is half typed', () => {
    expect(deriveSepaFormStatus(base({ typedIban: 'DE893704' }))).toBe('valid')
  })
})
