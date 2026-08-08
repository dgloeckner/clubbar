import { describe, expect, it } from 'vitest'
import {
  buildCreateSepaConfigRequest,
  buildUpdateSepaConfigRequest,
  isCreditorIdSet,
  type SepaConfigFormData,
} from './sepaConfig'

const form: SepaConfigFormData = {
  creditor_id: 'DE98ZZZ09999999999',
  creditor_name: 'Rowing Club Springfield',
  creditor_iban: 'DE89370400440532013000',
  creditor_address_street: 'Main Street 123',
  creditor_address_city: 'Munich',
  creditor_address_country: 'DE',
  payment_reference_prefix: 'Club Bar Settlement',
}

describe('isCreditorIdSet', () => {
  it('is false when no config has been loaded', () => {
    expect(isCreditorIdSet(null)).toBe(false)
    expect(isCreditorIdSet(undefined)).toBe(false)
  })

  it('is false for the empty singleton row the backend ships with', () => {
    expect(isCreditorIdSet({})).toBe(false)
    expect(isCreditorIdSet({ creditor_id: '' })).toBe(false)
    expect(isCreditorIdSet({ creditor_id: '   ' })).toBe(false)
  })

  it('is true once a creditor ID is stored', () => {
    expect(isCreditorIdSet({ creditor_id: 'DE98ZZZ09999999999' })).toBe(true)
  })
})

describe('buildCreateSepaConfigRequest', () => {
  it('carries every edited field, including the payment reference prefix', () => {
    expect(buildCreateSepaConfigRequest(form)).toEqual({
      creditor_id: 'DE98ZZZ09999999999',
      creditor_name: 'Rowing Club Springfield',
      creditor_iban: 'DE89370400440532013000',
      creditor_address_street: 'Main Street 123',
      creditor_address_city: 'Munich',
      creditor_address_country: 'DE',
      payment_reference_prefix: 'Club Bar Settlement',
    })
  })

  it('sends empty strings rather than dropping unfilled fields', () => {
    expect(buildCreateSepaConfigRequest({})).toEqual({
      creditor_id: '',
      creditor_name: '',
      creditor_iban: '',
      creditor_address_street: '',
      creditor_address_city: '',
      creditor_address_country: '',
      payment_reference_prefix: '',
    })
  })
})

describe('buildUpdateSepaConfigRequest', () => {
  it('carries the payment reference prefix (#90: it used to be dropped)', () => {
    expect(buildUpdateSepaConfigRequest(form).payment_reference_prefix).toBe('Club Bar Settlement')
  })

  it('lets an existing prefix be cleared', () => {
    expect(buildUpdateSepaConfigRequest({ ...form, payment_reference_prefix: '' })).toHaveProperty(
      'payment_reference_prefix',
      '',
    )
    expect(buildUpdateSepaConfigRequest({ ...form, payment_reference_prefix: undefined })).toHaveProperty(
      'payment_reference_prefix',
      '',
    )
  })

  it('omits the immutable creditor ID', () => {
    expect('creditor_id' in buildUpdateSepaConfigRequest(form)).toBe(false)
  })

  it('carries the remaining creditor fields', () => {
    expect(buildUpdateSepaConfigRequest(form)).toEqual({
      creditor_name: 'Rowing Club Springfield',
      creditor_iban: 'DE89370400440532013000',
      creditor_address_street: 'Main Street 123',
      creditor_address_city: 'Munich',
      creditor_address_country: 'DE',
      payment_reference_prefix: 'Club Bar Settlement',
    })
  })
})
