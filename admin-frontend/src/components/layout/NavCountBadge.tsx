import { theme } from '../../styles/design-system'

/**
 * A count worn by a nav icon (#782).
 *
 * It wraps the icon rather than sitting beside the label, and that is the whole
 * reason it works here: `DesktopNav` decides what fits by measuring an
 * off-screen copy of each entry, so anything rendered *outside* the entry's own
 * nodes would change the real width without changing the measured one — and the
 * last section in the row would fall off the end for a reason nothing explains
 * (the failure #742 fixed). Inside the icon node, the badge is measured with it.
 *
 * Zero renders nothing. A queue that is empty should look like a section, not
 * like a section with a `0` pinned to it — a badge that is always there is a
 * badge nobody reads.
 */
export function NavCountBadge({
  count,
  children,
  testId,
}: {
  count: number
  children: React.ReactNode
  testId: string
}) {
  if (count <= 0) return <>{children}</>

  return (
    <span style={{ position: 'relative', display: 'inline-flex' }}>
      {children}
      <span
        data-testid={testId}
        // The number itself, not a dot: "three people are waiting" is a
        // different piece of work from "somebody is waiting", and the queue this
        // counts is one a treasurer empties in a sitting.
        style={{
          position: 'absolute',
          top: -6,
          left: 12,
          minWidth: 16,
          height: 16,
          padding: '0 4px',
          borderRadius: 8,
          // Solid, not the translucent panel background: this sits on top of an
          // icon rather than behind body text, and `badges.danger.bg` at 10%
          // alpha over a dark nav is a smudge with an unreadable number in it.
          // The colour is the same token the panels tint from.
          background: theme.badges.danger.text,
          color: '#ffffff',
          fontSize: 10,
          fontWeight: 700,
          lineHeight: '14px',
          textAlign: 'center',
          boxSizing: 'border-box',
        }}
      >
        {count > 99 ? '99+' : count}
      </span>
    </span>
  )
}
