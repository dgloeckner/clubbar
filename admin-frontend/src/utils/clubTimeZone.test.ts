import { describe, it, expect, afterEach } from 'vitest'
import { getClubTimeZone, setClubTimeZone, resetClubTimeZone } from './clubTimeZone'

afterEach(() => resetClubTimeZone())

describe('the club’s reading zone', () => {
  it('is undefined until the instance config has been read', () => {
    expect(getClubTimeZone()).toBeUndefined()
  })

  it('is whatever the instance config reported', () => {
    setClubTimeZone('Europe/Berlin')
    expect(getClubTimeZone()).toBe('Europe/Berlin')
  })

  /**
   * A missing or blank field must leave the zone unset rather than store '',
   * which `Intl` rejects with a RangeError — one malformed config field would
   * otherwise throw on every timestamp in the panel.
   */
  it('treats a missing, null or blank zone as unset', () => {
    setClubTimeZone('Europe/Berlin')
    setClubTimeZone(undefined)
    expect(getClubTimeZone()).toBeUndefined()

    setClubTimeZone('Europe/Berlin')
    setClubTimeZone(null)
    expect(getClubTimeZone()).toBeUndefined()

    setClubTimeZone('Europe/Berlin')
    setClubTimeZone('   ')
    expect(getClubTimeZone()).toBeUndefined()
  })
})
