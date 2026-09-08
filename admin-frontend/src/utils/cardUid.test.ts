import { describe, it, expect } from 'vitest'
import {
  CARD_UID_PATTERN,
  canonicalizeCardUid,
  cardUidFromDecimal,
  isCanonicalCardUid,
  looksLikeDecimalCardUid,
  sanitizeCardUidInput,
} from './cardUid'

/** The worked example, in every spelling a reader or a tool prints it. */
const CANONICAL = '001EB4CB'

describe('canonicalizeCardUid', () => {
  it.each([
    ['already canonical', CANONICAL],
    ['lower case', '001eb4cb'],
    ['mixed case', '001Eb4Cb'],
    ['colon separated', '00:1E:B4:CB'],
    ['hyphen separated', '00-1e-b4-cb'],
    ['space separated', '00 1E B4 CB'],
    ['dot separated', '00.1e.b4.cb'],
    ['0x prefixed', '0x001EB4CB'],
    ['pasted with whitespace', '  001eb4cb\n'],
  ])('collapses %s onto one stored value', (_label, typed) => {
    expect(canonicalizeCardUid(typed)).toBe(CANONICAL)
  })

  it('completes a half-written byte', () => {
    // An odd digit count is a leading zero that went missing between the card
    // and the keyboard.
    expect(canonicalizeCardUid('1EB4CBA')).toBe('01EB4CBA')
  })

  it('refuses a value with whole bytes missing rather than inventing them', () => {
    // Input here comes from fingers: `ABCD` is somebody who stopped typing, not
    // a two-byte card. The terminal pads a short scan, because a reader has no
    // fingers.
    expect(canonicalizeCardUid('ABCD')).toBeNull()
    expect(canonicalizeCardUid('1EB4CB')).toBeNull()
  })

  it.each([
    ['empty', ''],
    ['whitespace', '   '],
    ['not hex', 'GHIJKLMN'],
    ['prose', 'die blaue Karte'],
    ['the anonymization placeholder', 'ANON-8ba7b8109dad11d'],
    ['past the column', 'AABBCCDDEEFF001122334'],
  ])('returns null for %s', (_label, typed) => {
    expect(canonicalizeCardUid(typed)).toBeNull()
  })

  it('is idempotent', () => {
    const once = canonicalizeCardUid('00:1e:b4:cb')!
    expect(canonicalizeCardUid(once)).toBe(once)
  })

  it('never produces something the format rule would refuse', () => {
    for (const typed of ['001eb4cb', '00:1E:B4:CB', '1EB4CBA', '0x001EB4CB']) {
      const canonical = canonicalizeCardUid(typed)!
      expect(CARD_UID_PATTERN.test(canonical)).toBe(true)
    }
  })
})

describe('sanitizeCardUidInput', () => {
  it('keeps the separators a UID is pasted with', () => {
    // Stripping them as the volunteer types would turn a pasted `0x001EB4CB`
    // into `0001EB4CB`: the `x` gone, the `0` left behind, and a UID one nibble
    // adrift that still looks plausible.
    expect(sanitizeCardUidInput('0x001eb4cb')).toBe('0X001EB4CB')
    expect(sanitizeCardUidInput('00:1e:b4:cb')).toBe('00:1E:B4:CB')
  })

  it('drops what can never be part of a UID in any spelling', () => {
    // Spaces stay: a reader tool prints `00 1E B4 CB`, and the separators are
    // removed when the field is left, not per keystroke.
    expect(sanitizeCardUidInput('Karte #7 (rot)')).toBe('AE 7 ')
  })
})

describe('cardUidFromDecimal', () => {
  it('reads a decimal reader output as the hex it stands for', () => {
    expect(cardUidFromDecimal('0002012363')).toBe(CANONICAL)
    expect(cardUidFromDecimal('2012363')).toBe(CANONICAL)
  })

  it('pads a small value out to the narrowest card UID', () => {
    // A number carries no width of its own, so it is given the minimum.
    expect(cardUidFromDecimal('30')).toBe('0000001E')
  })

  it('handles a value wider than 32 bits', () => {
    // 0x01A2B3C4D5, an EM4100 five-byte id — past what a JS number holds
    // exactly, which is why this goes through BigInt.
    expect(cardUidFromDecimal('7024657621')).toBe('01A2B3C4D5')
  })

  it('returns null for anything that is not a decimal number', () => {
    expect(cardUidFromDecimal('001EB4CB')).toBeNull()
    expect(cardUidFromDecimal('')).toBeNull()
  })

  it('returns null for a value too wide to be a card UID', () => {
    expect(cardUidFromDecimal('9'.repeat(30))).toBeNull()
  })
})

describe('looksLikeDecimalCardUid', () => {
  it('offers the conversion when the two readings differ', () => {
    expect(looksLikeDecimalCardUid('0002012363')).toBe(true)
  })

  it('stays quiet when both readings are the same chip', () => {
    // Nothing to ask about: nine reads as nine in either base.
    expect(looksLikeDecimalCardUid('00000009')).toBe(false)
  })

  it('stays quiet for a value that is plainly hex', () => {
    expect(looksLikeDecimalCardUid('001EB4CB')).toBe(false)
  })
})

describe('isCanonicalCardUid', () => {
  it('accepts whole uppercase hex bytes, four to ten of them', () => {
    expect(isCanonicalCardUid('001EB4CB')).toBe(true)
    expect(isCanonicalCardUid('AABBCCDDEEFF00112233')).toBe(true)
  })

  it('rejects the spellings canonicalization exists to remove', () => {
    expect(isCanonicalCardUid('001eb4cb')).toBe(false)
    expect(isCanonicalCardUid('00:1E:B4:CB')).toBe(false)
    expect(isCanonicalCardUid('01EB4CB')).toBe(false)
    expect(isCanonicalCardUid('1EB4CB')).toBe(false)
  })
})
