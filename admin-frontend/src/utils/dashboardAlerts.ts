/**
 * The wording of the dashboard's two setup banners (#741).
 *
 * Both alerts arrive from `GET /api/admin/dashboard` carrying a `message` the
 * backend composed — and that message is always English, because the API is
 * language-agnostic by design (ADR-0002): it returns facts, and the client
 * picks the language. The dashboard was rendering those two strings verbatim,
 * so a German admin read "SEPA creditor details are not configured — settlements
 * cannot be exported to the bank" in the middle of an otherwise German page.
 *
 * The Jugendschutz and terminal-anomaly banners beside them already did it the
 * right way round: build the sentence here from the machine-readable fields.
 * This module is the same move for the remaining two, kept out of the page so
 * the mapping can be tested against the locale files without rendering React.
 *
 * `message` is deliberately *not* a fallback. Falling back to it would put the
 * English sentence back on the screen for exactly the states nobody translated
 * — which is the bug, arriving quietly instead of loudly.
 */

/** A locale key plus whatever it interpolates. Feed it straight to `t()`. */
export interface AlertMessage {
  key: string
  values?: Record<string, string | number>
}

/** The fields of the `encryption_key` alert this module reads. */
export interface EncryptionKeyAlert {
  state?: string
  key_identifier?: string | null
  days_until_expiry?: number | null
}

/** The fields of the `sepa_config` alert this module reads. */
export interface SepaConfigAlert {
  state?: string
}

/**
 * How the IBAN encryption key's remaining lifetime reads (ADR-0036, #394).
 *
 * Only `missing` and `expired` are their own sentences; `info`, `warning` and
 * `critical` differ by how loud the banner is, not by what it says, so they
 * share one countdown string. `ok` never reaches here — the page hides the
 * banner while severity is `none` — but it maps to the countdown rather than
 * throwing, because a banner shown by a future severity rule should still say
 * something true.
 */
export function encryptionKeyMessage(alert: EncryptionKeyAlert): AlertMessage {
  if (alert.state === 'missing') {
    return { key: 'dashboard.encryptionKeyMissing' }
  }

  // Null rather than empty string is what a PENDING key looks like; naming it
  // "" would render "key  has expired" with a hole in it.
  const key = alert.key_identifier ?? ''

  if (alert.state === 'expired') {
    return { key: 'dashboard.encryptionKeyExpired', values: { key } }
  }

  return {
    key: 'dashboard.encryptionKeyExpiring',
    // i18next needs `count` by that name to pick the plural form. A key with
    // no expiry has no countdown either; 0 reads as "expires today", which is
    // the safe direction to be wrong in.
    values: { key, count: alert.days_until_expiry ?? 0 },
  }
}

/**
 * Which half of the SEPA setup is missing (#360/#456).
 *
 * `creditor_missing` is the default arm on purpose: it is the state of a club
 * that has configured nothing, so it is the honest thing to say about an alert
 * whose state this build does not recognise — and the creditor details are the
 * first thing the admin would go and check either way.
 */
export function sepaConfigMessage(alert: SepaConfigAlert): AlertMessage {
  return alert.state === 'mandate_template_missing'
    ? { key: 'dashboard.sepaConfigMandateMissing' }
    : { key: 'dashboard.sepaConfigCreditorMissing' }
}
