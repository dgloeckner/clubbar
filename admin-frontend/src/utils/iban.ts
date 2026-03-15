/**
 * IBAN validation using ISO 7064 Mod 97-10 checksum.
 */

export function normalizeIban(iban: string): string {
  return iban.replace(/\s/g, '').toUpperCase()
}

export function validateIban(iban: string): boolean {
  const normalized = normalizeIban(iban)

  // Format check: country (2 letters) + check digits (2 digits) + BBAN (11-30 alphanum)
  if (!/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/.test(normalized)) {
    return false
  }

  // Rearrange: move first 4 chars to end
  const rearranged = normalized.slice(4) + normalized.slice(0, 4)

  // Replace letters with numbers (A=10 .. Z=35)
  const numeric = rearranged.replace(/[A-Z]/g, (ch) =>
    (ch.charCodeAt(0) - 55).toString()
  )

  // Mod 97 on large number (process in chunks to avoid BigInt)
  let remainder = 0
  for (let i = 0; i < numeric.length; i++) {
    remainder = (remainder * 10 + parseInt(numeric[i], 10)) % 97
  }

  return remainder === 1
}
