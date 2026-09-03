/**
 * The zone the club reads in, for a panel that renders the club's books.
 *
 * Every instant this API returns is UTC and labelled `Z` (#365). Converting it
 * back needs a zone, and until now that was implicitly the *reader's* — the
 * browser's default. For a treasurer sitting in the clubhouse that is the same
 * clock the club runs on, which is why it never surfaced; for a Kassenwart
 * reconciling from a laptop abroad it is not, and the Deckelauszug in their
 * inbox (rendered server-side from `CLUB_TIMEZONE`) named different times for
 * the same sale than the journal on their screen. Neither was wrong by its own
 * rule, which is the problem: the club's books are stated in the club's zone,
 * so this is the zone every surface reads them in.
 *
 * ### Why a module-level value and not a prop
 *
 * `formatDate`/`formatDateTime` are pure functions called from about forty
 * components, most of them well below any provider that could inject a zone.
 * This is deployment configuration read once at bootstrap and never changed
 * afterwards — the same shape as the locale — so it is stored here and read
 * where it is needed rather than threaded through every call site.
 *
 * Undefined until `GET /instance-config` resolves, and undefined is meaningful:
 * `Intl` then falls back to the browser's zone, which is the behaviour this
 * replaced. A brief wrong-zone render during bootstrap is a far smaller failure
 * than defaulting to a zone the club does not use.
 */
let clubTimeZone: string | undefined

/** Set from the instance config at app bootstrap. */
export function setClubTimeZone(zone: string | null | undefined): void {
  clubTimeZone = typeof zone === 'string' && zone.trim() !== '' ? zone : undefined
}

/** The club's zone, or undefined while bootstrapping. */
export function getClubTimeZone(): string | undefined {
  return clubTimeZone
}

/** Test seam — restores the pre-bootstrap state. */
export function resetClubTimeZone(): void {
  clubTimeZone = undefined
}
