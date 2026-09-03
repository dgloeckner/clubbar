import { test } from '../../fixtures/auth.fixture'
import { expect } from '@playwright/test'

/**
 * A mandate is not a mandate until the member signed it (ADR-0020, #164).
 *
 * `is_sepa_valid` used to mean "somebody typed an IBAN and a reference exists",
 * with the signature date left out. Two things followed from that, and neither
 * was visible on any screen: the terminal let a member run up a tab against a
 * mandate the club could not collect on, and `SepaExportService` filled the
 * pain.008 `DtOfSgntr` with `?? $settlement['settlement_date']` — writing the
 * day the treasurer pressed *export* into the bank file as the day the member
 * signed. ADR-0020's amended definition already said all three parts were
 * required; only the code disagreed.
 *
 * This is the end-to-end half of that fix, and it is deliberately here rather
 * than only in `MandateCompletenessTest`: the PHP predicate and the SQL one
 * are two renderings of the same rule, and a unit test can only reach the
 * first. The assertion that matters is that they agree — a roster filtered for
 * "SEPA invalid" has to hold exactly the members the flag calls invalid, or
 * the treasurer's worklist and the export's exclusions diverge, which is the
 * failure ADR-0020 records from the last time these expressions drifted.
 *
 * Parallel-safe (Patterns 001/003/004): every member is created by this test
 * with a unique name, and every assertion finds them by id rather than by
 * position or by a total the other workers are also moving.
 */

const API_BASE = 'http://localhost:8080/api'
const IBAN = 'DE89370400440532013000'

interface Created {
  id: string
  lastName: string
}

test.describe('Mandate completeness requires the signature date', () => {
  /** A member with bank details, and a signature date only if one is given. */
  async function createMember(
    request: import('@playwright/test').APIRequestContext,
    tag: string,
    mandateSignedAt: string | null
  ): Promise<Created> {
    const suffix = `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`
    const lastName = `MandateDate${tag}${suffix}`

    const response = await request.post(`${API_BASE}/admin/members`, {
      data: {
        first_name: 'Sign',
        last_name: lastName,
        email: `mandate-date-${tag}-${suffix}@test.example`.toLowerCase(),
        date_of_birth: '1985-06-15',
        preferred_language: 'de',
        iban: IBAN,
        ...(mandateSignedAt ? { mandate_signed_at: mandateSignedAt } : {}),
      },
    })
    expect(response.status(), await response.text()).toBe(201)

    return { id: (await response.json()).id, lastName }
  }

  /** Whether the roster, filtered by SEPA status, holds this member. */
  async function listedUnder(
    request: import('@playwright/test').APIRequestContext,
    status: 'valid' | 'invalid',
    member: Created
  ): Promise<boolean> {
    const response = await request.get(
      `${API_BASE}/admin/members?sepa_status=${status}&search=${member.lastName}&limit=50`
    )
    expect(response.status(), await response.text()).toBe(200)

    const body = await response.json()
    const items = body.data ?? body.items ?? body
    return (items as Array<{ id: string }>).some((row) => row.id === member.id)
  }

  test('an IBAN without a signature date is not a collectable mandate', async ({
    authenticatedRequest,
  }) => {
    // The exact state the member form used to show a green "SEPA-Mandat gültig"
    // banner over: bank details on file, Mandatsdatum empty.
    const member = await createMember(authenticatedRequest, 'Unsigned', null)

    const read = await authenticatedRequest.get(`${API_BASE}/admin/members/${member.id}`)
    expect(read.status(), await read.text()).toBe(200)
    const body = await read.json()

    // The bank details really are there — this is not a member who failed to save.
    expect(body.iban_last4).toBe('3000')
    expect(body.mandate_reference).toBeTruthy()
    expect(body.mandate_signed_at).toBeFalsy()

    expect(body.is_sepa_valid, 'a mandate with no signature date is not valid').toBe(false)

    // The SQL rendering of the same rule, which is the half no unit test reaches.
    expect(await listedUnder(authenticatedRequest, 'invalid', member)).toBe(true)
    expect(await listedUnder(authenticatedRequest, 'valid', member)).toBe(false)
  })

  test('a signature date completes the mandate, on the flag and in the filter', async ({
    authenticatedRequest,
  }) => {
    const member = await createMember(authenticatedRequest, 'Signed', '2024-01-15')

    const read = await authenticatedRequest.get(`${API_BASE}/admin/members/${member.id}`)
    expect(read.status(), await read.text()).toBe(200)
    const body = await read.json()

    expect(body.mandate_signed_at).toBe('2024-01-15')
    expect(body.is_sepa_valid).toBe(true)

    expect(await listedUnder(authenticatedRequest, 'valid', member)).toBe(true)
    expect(await listedUnder(authenticatedRequest, 'invalid', member)).toBe(false)
  })

  test('supplying the date later turns the member collectable', async ({
    authenticatedRequest,
  }) => {
    // The remedy the exclusion asks for: one field, typed off the signed form.
    // It has to actually work, or the SEPA export's `no_mandate_date` bucket
    // is a dead end rather than a worklist.
    const member = await createMember(authenticatedRequest, 'Fixed', null)
    expect(await listedUnder(authenticatedRequest, 'invalid', member)).toBe(true)

    const update = await authenticatedRequest.patch(`${API_BASE}/admin/members/${member.id}`, {
      data: { mandate_signed_at: '2023-11-02' },
    })
    expect(update.status(), await update.text()).toBe(200)

    const read = await authenticatedRequest.get(`${API_BASE}/admin/members/${member.id}`)
    const body = await read.json()
    expect(body.mandate_signed_at).toBe('2023-11-02')
    expect(body.is_sepa_valid, 'typing the date in restores collectability').toBe(true)

    expect(await listedUnder(authenticatedRequest, 'valid', member)).toBe(true)
  })

  test('clearing the date revokes collectability again', async ({ authenticatedRequest }) => {
    // The other direction, because the form now warns about this save the way
    // it warns about removing the account: it costs the same thing.
    const member = await createMember(authenticatedRequest, 'Cleared', '2024-03-01')
    expect(await listedUnder(authenticatedRequest, 'valid', member)).toBe(true)

    const update = await authenticatedRequest.patch(`${API_BASE}/admin/members/${member.id}`, {
      data: { mandate_signed_at: null },
    })
    expect(update.status(), await update.text()).toBe(200)

    const read = await authenticatedRequest.get(`${API_BASE}/admin/members/${member.id}`)
    expect((await read.json()).is_sepa_valid).toBe(false)
    expect(await listedUnder(authenticatedRequest, 'invalid', member)).toBe(true)
  })
})
