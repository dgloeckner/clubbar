import { describe, expect, it } from 'vitest'
import {
  countChangedFields,
  deriveMemberStatusTiles,
  statusGapFields,
  type MemberStatusInput,
  type MemberStatusTile,
  type MemberStatusTileId,
} from './memberFormStatus'

/**
 * The member dialog's status strip (#830).
 *
 * The strip replaced four separate indicators that agreed about the facts and
 * disagreed about the tone. What has to be asserted here is that it does not
 * repeat their two failures: reporting the *loaded* member while the fields
 * below preview a different one (#392), and inventing gaps the roster's
 * Datenqualität panel does not count (#629).
 */

const complete: MemberStatusInput = {
  cardUid: '0002012363',
  dateOfBirth: '1981-05-08',
  email: 'anna@example.org',
  sepa: 'valid',
  willHaveIban: true,
  mandateSignedAt: '2026-09-01',
  saved: { hasCardUid: true, hasDateOfBirth: true, hasEmail: true },
}

function base(overrides: Partial<MemberStatusInput> = {}): MemberStatusInput {
  return { ...complete, ...overrides }
}

function tile(tiles: MemberStatusTile[], id: MemberStatusTileId): MemberStatusTile {
  const found = tiles.find((candidate) => candidate.id === id)
  if (!found) throw new Error(`no ${id} tile`)
  return found
}

describe('deriveMemberStatusTiles', () => {
  it('always returns all three tiles, in a fixed order', () => {
    // A tile that vanished when it was green would make the strip unusable for
    // confirming that nothing is wrong — only for finding out that something is.
    expect(deriveMemberStatusTiles(base()).map((entry) => entry.id)).toEqual([
      'terminal',
      'sepa',
      'reachable',
    ])
    expect(
      deriveMemberStatusTiles(base({ cardUid: '', email: '', sepa: 'missing' })).map((e) => e.id),
    ).toEqual(['terminal', 'sepa', 'reachable'])
  })

  it('reads a member who has everything as green throughout', () => {
    const tiles = deriveMemberStatusTiles(base())

    expect(tiles.every((entry) => entry.tone === 'ok')).toBe(true)
    expect(tiles.flatMap((entry) => entry.gaps)).toEqual([])
    expect(tile(tiles, 'sepa').since).toBe('2026-09-01')
  })

  describe('terminal', () => {
    it('separates "cannot book at all" from "cannot book everything"', () => {
      // The two fields fail differently and a single red/green light would
      // either overstate the birth date or hide it.
      const noCard = tile(deriveMemberStatusTiles(base({ cardUid: '' })), 'terminal')
      expect(noCard.tone).toBe('losing')
      expect(noCard.gaps.map((gap) => gap.field)).toEqual(['card_uid'])

      const noBirthDate = tile(deriveMemberStatusTiles(base({ dateOfBirth: '' })), 'terminal')
      expect(noBirthDate.tone).toBe('partial')
      expect(noBirthDate.messageKey).toBe('members.status.terminal.ageLimited')
      expect(noBirthDate.gaps.map((gap) => gap.field)).toEqual(['date_of_birth'])
    })

    it('names both fields when both are missing', () => {
      const result = tile(
        deriveMemberStatusTiles(
          base({ cardUid: '', dateOfBirth: '', saved: null }),
        ),
        'terminal',
      )

      expect(result.tone).toBe('gap')
      expect(result.messageKey).toBe('members.status.terminal.noAccess')
      expect(result.gaps.map((gap) => gap.field)).toEqual(['card_uid', 'date_of_birth'])
    })

    it('previews a card being added and a card being taken away', () => {
      const gaining = tile(
        deriveMemberStatusTiles(
          base({ saved: { hasCardUid: false, hasDateOfBirth: true, hasEmail: true } }),
        ),
        'terminal',
      )
      expect(gaining.tone).toBe('pending')
      expect(gaining.messageKey).toBe('members.status.terminal.willGainAccess')

      const losing = tile(deriveMemberStatusTiles(base({ cardUid: '   ' })), 'terminal')
      expect(losing.tone).toBe('losing')
      expect(losing.messageKey).toBe('members.status.terminal.willLoseAccess')
    })

    it('treats a create as new access rather than as access already granted', () => {
      const result = tile(deriveMemberStatusTiles(base({ saved: null })), 'terminal')

      expect(result.tone).toBe('pending')
      expect(result.gaps).toEqual([])
    })
  })

  describe('sepa', () => {
    it('previews the save rather than reporting the load', () => {
      // The whole reason `SepaFormStatus` exists (#392): the tile must not say
      // "gültig" over a field that is being emptied.
      expect(tile(deriveMemberStatusTiles(base({ sepa: 'willBecomeInvalid' })), 'sepa').tone).toBe(
        'losing',
      )
      expect(tile(deriveMemberStatusTiles(base({ sepa: 'willBecomeValid' })), 'sepa').tone).toBe(
        'pending',
      )
    })

    it('names the parts of the mandate that are open, never the reference', () => {
      // A blank reference is minted by the server (ADR-0006), so listing it
      // would send an admin to fix a field that is not broken.
      const result = tile(
        deriveMemberStatusTiles(
          base({ sepa: 'missing', willHaveIban: false, mandateSignedAt: '' }),
        ),
        'sepa',
      )

      expect(result.tone).toBe('gap')
      expect(result.gaps.map((gap) => gap.field)).toEqual(['iban', 'mandate_signed_at'])
    })

    it('offers the way back when this save would revoke the mandate', () => {
      const result = tile(
        deriveMemberStatusTiles(base({ sepa: 'willBecomeInvalid', willHaveIban: false })),
        'sepa',
      )

      expect(result.gaps.map((gap) => gap.field)).toEqual(['iban'])
    })

    it('carries no gaps and no date once the mandate is complete', () => {
      const result = tile(deriveMemberStatusTiles(base({ sepa: 'willBecomeValid' })), 'sepa')

      expect(result.gaps).toEqual([])
      expect(result.since).toBeUndefined()
    })
  })

  describe('reachable', () => {
    it('moves through all four states as the address is typed and cleared', () => {
      expect(tile(deriveMemberStatusTiles(base()), 'reachable').tone).toBe('ok')

      expect(
        tile(
          deriveMemberStatusTiles(
            base({ saved: { hasCardUid: true, hasDateOfBirth: true, hasEmail: false } }),
          ),
          'reachable',
        ).tone,
      ).toBe('pending')

      const losing = tile(deriveMemberStatusTiles(base({ email: '' })), 'reachable')
      expect(losing.tone).toBe('losing')
      expect(losing.gaps.map((gap) => gap.field)).toEqual(['email'])

      expect(tile(deriveMemberStatusTiles(base({ email: '', saved: null })), 'reachable').tone).toBe(
        'gap',
      )
    })
  })

  it('only ever names the four gaps the roster counts', () => {
    // The dialog and the Datenqualität panel must not be able to disagree
    // about what "incomplete" means (#629) — a count whose list holds
    // different members teaches an admin to distrust the number.
    const tiles = deriveMemberStatusTiles(
      base({ cardUid: '', dateOfBirth: '', email: '', sepa: 'missing', willHaveIban: false, saved: null }),
    )

    const gaps = new Set(tiles.flatMap((entry) => entry.gaps).map((gap) => gap.gap))
    expect([...gaps].sort()).toEqual(['card_uid', 'date_of_birth', 'email', 'sepa'])
  })
})

describe('statusGapFields', () => {
  it('lists each named field once, in tile order', () => {
    const tiles = deriveMemberStatusTiles(
      base({ cardUid: '', dateOfBirth: '', email: '', sepa: 'missing', willHaveIban: false, mandateSignedAt: '' }),
    )

    expect(statusGapFields(tiles)).toEqual([
      'card_uid',
      'date_of_birth',
      'iban',
      'mandate_signed_at',
      'email',
    ])
  })

  it('is empty when nothing is missing, so no input takes a warning border', () => {
    expect(statusGapFields(deriveMemberStatusTiles(base()))).toEqual([])
  })
})

describe('countChangedFields', () => {
  const opened = { first_name: 'Anna', card_uid: '0002', credit_limit: '' }

  it('reports nothing on a form nobody has typed into', () => {
    expect(countChangedFields(opened, { ...opened })).toBe(0)
  })

  it('counts each changed field once', () => {
    expect(countChangedFields(opened, { ...opened, first_name: 'Anne' })).toBe(1)
    expect(countChangedFields(opened, { ...opened, first_name: 'Anne', card_uid: '' })).toBe(2)
  })

  it('adds the IBAN changes that never show up in the field diff', () => {
    // The input starts blank by design (ADR-0036), so a removal and a
    // replacement both compare blank against blank.
    expect(countChangedFields(opened, { ...opened }, 1)).toBe(1)
  })

  it('reports nothing while there is no baseline — a create changes nothing', () => {
    expect(countChangedFields(null, opened, 3)).toBe(0)
  })
})
