import { describe, expect, it } from 'vitest'
import {
  MEMBER_CLEARABLE_FIELDS,
  MEMBER_REQUIRED_FIELDS,
  clearedFields,
  countSatisfiedRequired,
  isPlausibleEmail,
  missingRequiredFields,
  type MemberRequiredValues,
} from './memberFormRequirements'

/** A form filled in far enough for the API to accept it. */
function complete(overrides: Partial<MemberRequiredValues> = {}): MemberRequiredValues {
  return {
    first_name: 'Dietrich',
    last_name: 'Dollenfett',
    email: 'dietrich@example.de',
    date_of_birth: '1980-01-01',
    preferred_language: 'de',
    ...overrides,
  }
}

describe('missingRequiredFields', () => {
  it('reports nothing for a complete form', () => {
    expect(missingRequiredFields(complete())).toEqual([])
  })

  it('names every empty field at once, not just the first', () => {
    expect(missingRequiredFields(complete({ first_name: '', email: '' }))).toEqual([
      'first_name',
      'email',
    ])
  })

  it('reports them in form order, so "jump to the first" lands at the top', () => {
    expect(missingRequiredFields({ ...complete(), date_of_birth: '', last_name: '' })).toEqual([
      'last_name',
      'date_of_birth',
    ])
  })

  it('treats whitespace as missing — the backend trims it and refuses it too', () => {
    expect(missingRequiredFields(complete({ first_name: '   ' }))).toEqual(['first_name'])
  })

  it('covers every declared required field', () => {
    const allBlank = Object.fromEntries(
      MEMBER_REQUIRED_FIELDS.map((field) => [field, '']),
    ) as MemberRequiredValues
    expect(missingRequiredFields(allBlank)).toEqual([...MEMBER_REQUIRED_FIELDS])
  })
})

describe('countSatisfiedRequired', () => {
  it('counts a blank form as one — the language selector always carries a value', () => {
    const blank = { ...complete({ first_name: '', last_name: '', email: '', date_of_birth: '' }) }
    expect(countSatisfiedRequired(blank)).toBe(1)
  })

  it('counts a complete form as all of them', () => {
    expect(countSatisfiedRequired(complete())).toBe(MEMBER_REQUIRED_FIELDS.length)
  })
})

describe('clearedFields', () => {
  const stored = {
    card_uid: '0002011450',
    account_holder_name: 'Erika Dollenfett',
    mandate_signed_at: '2024-12-15',
  }

  it('reports nothing on a create — there is no stored member to delete from', () => {
    expect(clearedFields(null, { card_uid: '', account_holder_name: '' })).toEqual([])
  })

  it('reports nothing while the inputs still carry the stored values', () => {
    expect(clearedFields(stored, stored)).toEqual([])
  })

  it('names the value a blank input is about to delete', () => {
    expect(clearedFields(stored, { ...stored, card_uid: '' })).toEqual([
      { field: 'card_uid', previous: '0002011450' },
    ])
  })

  it('reports every emptied field, in form order', () => {
    expect(
      clearedFields(stored, { card_uid: '', account_holder_name: '', mandate_signed_at: '' }),
    ).toEqual([
      { field: 'card_uid', previous: '0002011450' },
      { field: 'account_holder_name', previous: 'Erika Dollenfett' },
      { field: 'mandate_signed_at', previous: '2024-12-15' },
    ])
  })

  it('does not call a replacement a deletion', () => {
    expect(clearedFields(stored, { ...stored, card_uid: 'AABBCCDD' })).toEqual([])
  })

  it('says nothing about a field that was never stored', () => {
    expect(clearedFields({ card_uid: null }, { card_uid: '' })).toEqual([])
    expect(clearedFields({}, { card_uid: '' })).toEqual([])
  })

  it('treats a whitespace-only input as emptied', () => {
    expect(clearedFields(stored, { ...stored, account_holder_name: '  ' })).toEqual([
      { field: 'account_holder_name', previous: 'Erika Dollenfett' },
    ])
  })

  it('leaves the IBAN out — a blank IBAN input means "keep", not "delete" (#392)', () => {
    expect(MEMBER_CLEARABLE_FIELDS).not.toContain('iban')
    expect(MEMBER_CLEARABLE_FIELDS).not.toContain('mandate_reference')
  })
})

describe('isPlausibleEmail', () => {
  it.each(['a@b.de', 'dietrich.dollenfett@example.de', ' spaced@example.com '])(
    'accepts %s',
    (value) => {
      expect(isPlausibleEmail(value)).toBe(true)
    },
  )

  it.each(['', 'dietrich', 'dietrich@', '@example.de', 'a b@example.de', 'a@b', 'a@b.d'])(
    'rejects %s',
    (value) => {
      expect(isPlausibleEmail(value)).toBe(false)
    },
  )
})
