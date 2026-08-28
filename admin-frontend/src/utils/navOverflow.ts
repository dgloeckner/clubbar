/**
 * How many header nav entries fit before the rest have to move into "More"
 * (#742).
 *
 * The header nav used to be a scroller with its scrollbar hidden, so an entry
 * that did not fit was not cut off in a way anybody could act on — it was
 * simply gone. On a 1713px window in German that entry was "Audit-Log": the
 * section existed, the link existed, and the only way to reach it was to know
 * it was there and drag a scrollbar that had been styled away.
 *
 * Nothing about that is fixable by picking better numbers. Label widths depend
 * on the language (`Benachrichtigungen` is twice `Notifications`), the visible
 * set depends on which offices the account holds (ADR-0044), and the space
 * left over depends on the club name in the logo block. So the count is
 * measured at runtime and this function is the arithmetic on those
 * measurements — pure, so it can be tested without a browser.
 *
 * @param widths     Rendered width of each entry, in nav order.
 * @param moreWidth  Rendered width of the "More" button.
 * @param available  Width the nav has to lay out in.
 * @param gap        Gap between two adjacent entries.
 * @returns How many leading entries stay inline; the remainder go into "More".
 */
export function fitNavItems(
  widths: number[],
  moreWidth: number,
  available: number,
  gap: number
): number {
  // Before the first measurement — and in any environment that cannot measure
  // — show everything. Hiding an entry is the failure this exists to prevent,
  // so an unknown width must never be the reason one disappears.
  if (!Number.isFinite(available) || available <= 0) {
    return widths.length
  }

  const total = widths.reduce((sum, width) => sum + width, 0) + gap * Math.max(widths.length - 1, 0)
  if (total <= available) {
    return widths.length
  }

  // Everything past here needs the "More" button, so its width — and the gap
  // in front of it — come off the budget before the first entry is placed.
  let used = 0
  let count = 0
  for (let index = 0; index < widths.length; index++) {
    used += widths[index] + (index > 0 ? gap : 0)
    if (used + gap + moreWidth > available) {
      break
    }
    count = index + 1
  }

  return count
}
