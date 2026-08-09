import type { APIRequestContext } from '@playwright/test'
import { minimumExecutionDate, serverToday } from './dates'
import type { ApiRequestLike } from './request-context'

/**
 * Per-test settlement factory (issue #98, ruling #146).
 *
 * The settlement export tests used to read `GET /api/admin/settlements` and
 * take whatever row happened to be first — settlements another test in the
 * same file had created. Under the default 4 workers that row may not exist
 * yet, so the money-critical export tests skipped themselves and the suite
 * reported green (E2E Pattern 001: test data isolation).
 *
 * This factory gives every test its own member, purchase and settlement, so
 * the exports can be asserted against amounts and names the test itself chose.
 */

export type SettlementMethod = 'direct_debit' | 'bank_transfer' | 'write_off'

export interface SettlementFixtureOptions {
  /** Amount of the single purchase covered by the settlement. Default 2500. */
  amountCents?: number
  /** Settlement method (ruling #163). Default 'direct_debit' — the only SEPA-exportable one. */
  method?: SettlementMethod
  /**
   * How many members the settlement covers. Default 1.
   *
   * Reversal is per-member (ruling #148), so its tests need a run whose other
   * members can be shown to stay settled. Each member gets their own purchase
   * of `amountCents`; a non-direct-debit settlement must stay at one member
   * (ruling #163) and the factory refuses more.
   */
  memberCount?: number
}

/** One member of a factory-built settlement. */
export interface CreatedSettlementMember {
  id: string
  /** "First Last" — exactly as the CSV export renders it. */
  name: string
  email: string
  mandateReference: string
  amountCents: number
  transactionId: string
}

export interface CreatedSettlement {
  id: string
  memberId: string
  /** "First Last" — exactly as the CSV export renders it. */
  memberName: string
  memberEmail: string
  iban: string
  mandateReference: string
  amountCents: number
  transactionIds: string[]
  settlementDate: string
  executionDate: string
  /** Every member the settlement covers; the fields above describe the first. */
  members: CreatedSettlementMember[]
}

export interface SettlementFactory {
  create(options?: SettlementFixtureOptions): Promise<CreatedSettlement>
}

/** IBAN used for every factory member; a well-known SEPA test IBAN. */
export const FACTORY_IBAN = 'DE89370400440532013000'

/**
 * Alphanumeric, collision-resistant suffix.
 *
 * Names reach the SEPA XML through `SepaSanitizer`, which strips characters
 * outside the SEPA-allowed set — an underscore would be dropped and the
 * assertion would then compare against a name the file never contains.
 */
function uniqueSuffix(): string {
  return `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 7)}`
}

/**
 * Ensure the (singleton) SEPA creditor config is filled in, because
 * `SepaExportService` refuses to export without it.
 *
 * Only written when missing: the config is global state shared by every
 * worker, and rewriting it needlessly would fight the tests that assert on it.
 */
export async function ensureSepaConfigured(request: ApiRequestLike): Promise<void> {
  const current = await request.get('/api/admin/sepa-config')
  if (current.status() === 200 && (await current.json()).is_configured === true) {
    return
  }

  const response = await request.put('/api/admin/sepa-config', {
    data: {
      creditor_id: 'DE98ZZZ09999999999',
      creditor_name: 'Settlement Factory Club',
      creditor_iban: FACTORY_IBAN,
      creditor_address_street: 'Musterstrasse 1',
      creditor_address_city: 'Berlin',
      creditor_address_country: 'DE',
    },
  })

  if (response.status() !== 200) {
    throw new Error(`Could not configure SEPA creditor (${response.status()}): ${await response.text()}`)
  }
}

/**
 * One member with one unsettled purchase, entered the way production does it:
 * the member through the admin API, the purchase through terminal sync.
 */
async function createMemberWithPurchase(
  adminRequest: ApiRequestLike,
  terminalRequest: ApiRequestLike,
  amountCents: number
): Promise<CreatedSettlementMember> {
  const suffix = uniqueSuffix()
  const firstName = 'Settle'
  const lastName = `Fixture${suffix}`
  const email = `settle-${suffix}@test.example`

  const memberResponse = await adminRequest.post('/api/admin/members', {
    data: {
      first_name: firstName,
      last_name: lastName,
      email,
      preferred_language: 'de',
      iban: FACTORY_IBAN,
      mandate_signed_at: '2024-01-01',
    },
  })
  if (memberResponse.status() !== 201) {
    throw new Error(`Factory could not create member (${memberResponse.status()}): ${await memberResponse.text()}`)
  }
  const member = await memberResponse.json()

  const transactionId = crypto.randomUUID()
  const syncResponse = await terminalRequest.post('/api/sync/transactions', {
    data: {
      transactions: [
        {
          id: transactionId,
          member_id: member.id,
          type: 'product',
          product_id: crypto.randomUUID(),
          quantity: 1,
          unit_price_cents: amountCents,
          amount_cents: amountCents,
          notes: `Factory purchase ${suffix}`,
          created_at: new Date().toISOString(),
        },
      ],
    },
  })
  if (syncResponse.status() !== 201) {
    throw new Error(`Factory could not sync purchase (${syncResponse.status()}): ${await syncResponse.text()}`)
  }

  return {
    id: member.id,
    name: `${firstName} ${lastName}`,
    email,
    mandateReference: member.mandate_reference,
    amountCents,
    transactionId,
  }
}

/**
 * A context the factory can act through.
 *
 * `ApiRequestLike` rather than `APIRequestContext` so a CSRF-aware context from
 * `loginAs` can be passed in — that is what lets a test build a settlement as a
 * *different* admin, the only way to get more than one `created_by` value into
 * the settlements list.
 */
export type SettlementRequestContext = ApiRequestLike

/**
 * Build the factory over an authenticated admin context and a terminal
 * context (purchases enter through the terminal sync API, as they do in
 * production).
 */
export function settlementFactory(
  adminRequest: SettlementRequestContext,
  terminalRequest: SettlementRequestContext
): SettlementFactory {
  return {
    async create(options: SettlementFixtureOptions = {}): Promise<CreatedSettlement> {
      const amountCents = options.amountCents ?? 2500
      const method = options.method ?? 'direct_debit'
      const memberCount = options.memberCount ?? 1

      if (memberCount > 1 && method !== 'direct_debit') {
        // Ruling #163, enforced by the DB CHECK: a bank_transfer or write_off
        // covers exactly one member. Failing here beats a confusing 422.
        throw new Error(`A ${method} settlement covers exactly one member; asked for ${memberCount}`)
      }

      await ensureSepaConfigured(adminRequest)

      const members: CreatedSettlementMember[] = []
      for (let i = 0; i < memberCount; i++) {
        members.push(await createMemberWithPurchase(adminRequest, terminalRequest, amountCents))
      }

      const settlementDate = await serverToday(adminRequest)
      const executionDate = await minimumExecutionDate(adminRequest)

      const settlementResponse = await adminRequest.post('/api/admin/settlements', {
        data: {
          method,
          transaction_ids: members.map((m) => m.transactionId),
          settlement_date: settlementDate,
          execution_date: executionDate,
          period_start: settlementDate,
          period_end: settlementDate,
        },
      })
      if (settlementResponse.status() !== 201) {
        throw new Error(
          `Factory could not create settlement (${settlementResponse.status()}): ${await settlementResponse.text()}`
        )
      }
      const settlement = await settlementResponse.json()

      const [first] = members

      return {
        id: settlement.id,
        memberId: first.id,
        memberName: first.name,
        memberEmail: first.email,
        iban: FACTORY_IBAN,
        mandateReference: first.mandateReference,
        amountCents,
        transactionIds: members.map((m) => m.transactionId),
        settlementDate,
        executionDate,
        members,
      }
    },
  }
}
