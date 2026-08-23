import { test } from '../../fixtures/roleRequests'
import { expect } from '@playwright/test'

/**
 * E2E Test: the club's credit limit configuration (ADR-0047, UC-A65)
 *
 * `credit_limit_config` is a singleton, so these tests mutate one shared row
 * rather than creating their own data — Pattern 001 cannot apply, and the same
 * two things keep it safe that keep `mail-config.spec.ts` safe: the file is
 * `serial`, so its own tests cannot race each other, and it restores the row
 * afterwards, so it cannot leak into anything that reads a limit later.
 *
 * The grant is the part worth pinning. **Both** verbs are TREASURY, unlike
 * `sepa-config`, whose read is TREASURY and whose writes are `admin`-only:
 * a wrong Gläubiger-ID means a rejected collection run, a wrong ceiling is
 * undone by typing the right number, and deciding what members may run up on
 * their Deckel is the Kassenwart's own job.
 */
test.describe.serial('Credit limit configuration API', () => {
  const URL = 'http://localhost:8080/api/admin/credit-limit-config'

  let original: { default_limit_cents: number; warn_threshold_percent: number } | null = null

  test.beforeAll(async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get(URL)
    expect(response.ok()).toBeTruthy()
    original = await response.json()
  })

  test.afterAll(async ({ authenticatedRequest }) => {
    if (!original) return
    await authenticatedRequest.patch(URL, { data: original })
  })

  test('GET answers the club default and the warning band', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get(URL)
    expect(response.ok()).toBeTruthy()

    const config = await response.json()
    expect(typeof config.default_limit_cents).toBe('number')
    expect(typeof config.warn_threshold_percent).toBe('number')
  })

  test('PATCH stores both numbers and the next read returns them', async ({
    authenticatedRequest,
  }) => {
    const updated = await authenticatedRequest.patch(URL, {
      data: { default_limit_cents: 15_000, warn_threshold_percent: 70 },
    })
    expect(updated.ok(), await updated.text()).toBeTruthy()
    expect(await updated.json()).toMatchObject({
      default_limit_cents: 15_000,
      warn_threshold_percent: 70,
    })

    const reread = await (await authenticatedRequest.get(URL)).json()
    expect(reread.default_limit_cents).toBe(15_000)
    expect(reread.warn_threshold_percent).toBe(70)
  })

  /**
   * Zero is the club saying "cap nobody" — a setting, not an absence
   * (ADR-0047 decision 3), and the same convention the terminal's
   * `CreditLimitCheck` has always used.
   */
  test('a ceiling of zero is accepted as "no limit"', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.patch(URL, {
      data: { default_limit_cents: 0, warn_threshold_percent: 80 },
    })

    expect(response.ok(), await response.text()).toBeTruthy()
    expect((await response.json()).default_limit_cents).toBe(0)
  })

  /**
   * A negative ceiling would pass the `<= 0` test as "unlimited" and hand every
   * member infinite credit by accident. Refused rather than reinterpreted.
   */
  test('a negative ceiling is refused, not read as unlimited', async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.patch(URL, {
      data: { default_limit_cents: -1, warn_threshold_percent: 80 },
    })

    expect(response.status()).toBe(422)
    expect((await response.json()).messages).toHaveProperty('default_limit_cents')
  })

  test('an out-of-range warning band is refused', async ({ authenticatedRequest }) => {
    for (const percent of [0, 101]) {
      const response = await authenticatedRequest.patch(URL, {
        data: { default_limit_cents: 10_000, warn_threshold_percent: percent },
      })

      expect(response.status(), `warn_threshold_percent ${percent}`).toBe(422)
    }
  })

  test('a non-integer ceiling is refused', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.patch(URL, {
      data: { default_limit_cents: '100.50', warn_threshold_percent: 80 },
    })

    expect(response.status()).toBe(422)
  })

  test('both numbers are required', async ({ authenticatedRequest }) => {
    expect((await authenticatedRequest.patch(URL, { data: { default_limit_cents: 10_000 } })).status()).toBe(422)
    expect((await authenticatedRequest.patch(URL, { data: { warn_threshold_percent: 80 } })).status()).toBe(422)
  })

  test('the Kassenwart may read and write it', async ({ kassenwartRequest }) => {
    const read = await kassenwartRequest.get(URL)
    expect(read.status(), await read.text()).toBe(200)

    const write = await kassenwartRequest.patch(URL, {
      data: { default_limit_cents: 12_000, warn_threshold_percent: 75 },
    })
    expect(write.status(), await write.text()).toBe(200)
    expect((await write.json()).default_limit_cents).toBe(12_000)
  })

  /** The drinks list has no business with the Deckel (ADR-0044). */
  test('the Getränkewart reaches neither verb', async ({ getraenkewartRequest }) => {
    const read = await getraenkewartRequest.get(URL)
    expect(read.status()).toBe(403)
    expect((await read.json()).error).toBe('insufficient_role')

    const write = await getraenkewartRequest.patch(URL, {
      data: { default_limit_cents: 1_000, warn_threshold_percent: 50 },
    })
    expect(write.status()).toBe(403)
    expect((await write.json()).error).toBe('insufficient_role')
  })
})
