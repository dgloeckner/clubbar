/**
 * Seeding for the members a collection run leaves out.
 *
 * Three buckets exclude a member from a run, and each is reached by a
 * different sequence — none of them by simply setting a flag:
 *
 * | bucket       | how production makes one                            |
 * |--------------|-----------------------------------------------------|
 * | `credit`     | settle a purchase, *then* storno it                 |
 * | `held`       | submit a settlement to the bank, then reverse it    |
 * | `ineligible` | create the member without an IBAN or mandate        |
 *
 * The helpers here walk those sequences over the real HTTP stack, because a
 * shortcut would seed a state the application cannot actually produce. They
 * live in `utils/` rather than in one spec because both the New Settlement
 * preview and the standing "Excluded from collection" surface need them.
 */

import { expect, type APIRequestContext } from '@playwright/test'
import { generateUUID } from './transactions'
import { FACTORY_IBAN, type CreatedSettlement, type SettlementFactory } from './settlements'

export interface SeededMember {
  memberId: string
  lastName: string
  transactionIds: string[]
  totalCents: number
}

export interface SeedMemberOptions {
  /** Short label woven into the surname, so a failure names the case that seeded it. */
  tag: string
  amounts: number[]
  withMandate?: boolean
  /**
   * Surname prefix. Distinct per spec so a search in one suite cannot match a
   * member another suite seeded — Pattern 001, and the reason parallel workers
   * do not collide.
   */
  prefix?: string
}

/**
 * A member holding `amounts.length` unsettled purchases.
 *
 * A negative amount is synced as a storno so a member can be pushed into
 * credit, which is how the excluded sections get something to show.
 */
export async function seedMember(
  adminRequest: APIRequestContext,
  terminalRequest: APIRequestContext,
  options: SeedMemberOptions
): Promise<SeededMember> {
  const { tag, amounts, withMandate = true, prefix = 'NewSet' } = options
  const suffix = `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`
  const lastName = `${prefix}${tag}${suffix}`

  const memberResponse = await adminRequest.post('/api/admin/members', {
    data: {
      first_name: 'Run',
      last_name: lastName,
      email: `${prefix}-${tag}-${suffix}@test.example`.toLowerCase(),
      preferred_language: 'de',
      ...(withMandate ? { iban: FACTORY_IBAN, mandate_signed_at: '2024-01-01' } : {}),
    },
  })
  expect(memberResponse.status(), await memberResponse.text()).toBe(201)
  const member = await memberResponse.json()

  const transactionIds = amounts.map(() => generateUUID())
  const syncResponse = await terminalRequest.post('/api/sync/transactions', {
    data: {
      transactions: amounts.map((amountCents, index) => ({
        id: transactionIds[index],
        member_id: member.id,
        type: 'product',
        product_id: generateUUID(),
        quantity: 1,
        unit_price_cents: Math.abs(amountCents),
        amount_cents: amountCents,
        notes: `${prefix} fixture ${suffix} #${index}`,
        created_at: new Date(Date.now() - index * 60_000).toISOString(),
      })),
    },
  })
  expect(syncResponse.status(), await syncResponse.text()).toBe(201)

  return {
    memberId: member.id,
    lastName,
    transactionIds,
    totalCents: amounts.reduce((sum, cents) => sum + cents, 0),
  }
}

export interface SeededCreditMember extends SeededMember {
  /** What the club now owes, negative — the figure the credit listing shows. */
  creditCents: number
}

/**
 * A member the club owes money to.
 *
 * The sequence is the point and cannot be shortened: sync refuses a negative
 * amount outright (#79 forces every uploaded row to a purchase), and a storno
 * of an *unsettled* purchase merely nets it to zero. The member goes negative
 * only once the purchase has been collected and is *then* reversed — the
 * January overcharge discovered after January was billed (ruling #141).
 */
export async function seedCreditMember(
  adminRequest: APIRequestContext,
  terminalRequest: APIRequestContext,
  options: { tag: string; amountCents: number; prefix?: string }
): Promise<SeededCreditMember> {
  const { tag, amountCents, prefix = 'Excl' } = options

  const member = await seedMember(adminRequest, terminalRequest, {
    tag,
    amounts: [amountCents],
    prefix,
  })

  const dates = await adminRequest.get('/api/admin/settlements/execution-date-info')
  expect(dates.status()).toBe(200)
  const { today, minimum_date: executionDate } = await dates.json()

  const settled = await adminRequest.post('/api/admin/settlements', {
    data: {
      method: 'direct_debit',
      member_ids: [member.memberId],
      settlement_date: today,
      execution_date: executionDate,
    },
  })
  expect(settled.status(), await settled.text()).toBe(201)

  const storno = await adminRequest.post(
    `/api/admin/transactions/${member.transactionIds[0]}/storno`,
    { data: { reason: 'Overcharged, collected in error' } }
  )
  expect(storno.status(), await storno.text()).toBe(201)

  return { ...member, creditCents: -amountCents }
}

/**
 * A member with an open position and no usable mandate (#258).
 *
 * `withMandate: false` omits both the IBAN and the mandate date at creation,
 * which is exactly how a member arrives before anyone has collected their bank
 * details — the state the standing listing exists to surface.
 */
export async function seedMemberWithoutMandate(
  adminRequest: APIRequestContext,
  terminalRequest: APIRequestContext,
  options: { tag: string; amountCents: number; prefix?: string }
): Promise<SeededMember> {
  const { tag, amountCents, prefix = 'Excl' } = options

  return seedMember(adminRequest, terminalRequest, {
    tag,
    amounts: [amountCents],
    withMandate: false,
    prefix,
  })
}

export interface SeededHeldMember {
  memberId: string
  /** "First Last", as the factory built it. */
  memberName: string
  settlement: CreatedSettlement
  /** What the member still owes, held back from the next run. */
  heldCents: number
  /** The bank reference the reversal wrote into the hold reason. */
  bankReference: string
}

/**
 * A member on collection hold after a returned direct debit.
 *
 * Only a `bank_return` reversal places a hold: a `club_error` reversal takes
 * none, because re-collecting is the entire point of recording one
 * (ruling #148 §3). The settlement must reach the bank first — an unsubmitted
 * run has nothing to return.
 */
export async function seedHeldMember(
  adminRequest: APIRequestContext,
  factory: SettlementFactory,
  options: { tag: string; amountCents: number }
): Promise<SeededHeldMember> {
  const { tag, amountCents } = options
  const bankReference = `RET-${tag}-${Date.now().toString(36)}`.toUpperCase()

  const settlement = await factory.create({ amountCents })

  // Export then submit: the point of no return, after which a reversal is the
  // only way to undo the run.
  expect(
    (await adminRequest.get(`/api/admin/settlements/${settlement.id}/export/sepa-xml`)).status()
  ).toBe(200)
  expect((await adminRequest.post(`/api/admin/settlements/${settlement.id}/submit`)).status()).toBe(200)

  const reversed = await adminRequest.post(`/api/admin/settlements/${settlement.id}/reverse`, {
    data: { reason: 'bank_return', bank_reference: bankReference, notes: 'Returned unpaid' },
  })
  expect(reversed.status(), await reversed.text()).toBe(201)

  return {
    memberId: settlement.memberId,
    memberName: settlement.memberName,
    settlement,
    heldCents: amountCents,
    bankReference,
  }
}
