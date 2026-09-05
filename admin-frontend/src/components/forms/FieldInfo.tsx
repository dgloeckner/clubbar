/**
 * FieldInfo — the explanation a field only sometimes needs (#830).
 *
 * The member form used to keep its explanations permanently on screen: a grey
 * paragraph under Kontoinhaber ("Nur ausfüllen, wenn der Kontoinhaber vom
 * Mitglied abweicht (z.B. Elternteil zahlt für Kind)"), another under
 * Mandatsreferenz, a third under Eigenes Limit. Each is genuinely useful — the
 * first time. Afterwards they are three paragraphs of body text in a dialog
 * whose *Speichern* button was below the fold, and the reason it was.
 *
 * So the short form becomes the field's placeholder, where it is read exactly
 * when the field is empty and needed, and the long form moves behind this: a
 * small **i** beside the label that opens on hover and on tap alike. Tap
 * matters as much as hover, because on a phone hover does not exist and a
 * hover-only tooltip is simply a piece of text nobody can reach.
 *
 * Why not `Tooltip`: that one is hover-only, `pointerEvents: none`, and
 * `whiteSpace: nowrap` — right for a three-word label on an icon button, and
 * unable to hold a sentence or be opened by a finger.
 */

import { useEffect, useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { theme } from '../../styles/design-system'

export interface FieldInfoProps {
  /** Already-translated explanation. One or two sentences. */
  content: string
  /** Base test id; the trigger is `${testId}`, the panel `${testId}-content`. */
  testId?: string
}

export function FieldInfo({ content, testId }: FieldInfoProps) {
  const { t } = useTranslation()
  // Hover and tap are tracked separately, and the panel shows while *either*
  // holds. One `open` flag made the mouse path close it: the pointer arrives,
  // `mouseenter` opens it, and the click that follows toggles it straight back
  // shut — so a mouse user could never pin it open, and a synthetic click in a
  // test found nothing.
  const [hovered, setHovered] = useState(false)
  const [pinned, setPinned] = useState(false)
  const open = hovered || pinned
  const containerRef = useRef<HTMLSpanElement>(null)
  const panelId = useId()

  // Escape and an outside click both close it. Without the second, a popover
  // opened by tap on a phone stays over the field beside it until that exact
  // icon is tapped again, which reads as the dialog being stuck.
  useEffect(() => {
    if (!open) return

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        setPinned(false)
        setHovered(false)
      }
    }
    const onPointerDown = (event: PointerEvent) => {
      if (!containerRef.current?.contains(event.target as Node)) setPinned(false)
    }

    document.addEventListener('keydown', onKeyDown)
    document.addEventListener('pointerdown', onPointerDown)
    return () => {
      document.removeEventListener('keydown', onKeyDown)
      document.removeEventListener('pointerdown', onPointerDown)
    }
  }, [open])

  return (
    <span ref={containerRef} style={{ position: 'relative', display: 'inline-flex' }}>
      <button
        type="button"
        data-testid={testId}
        aria-expanded={open}
        aria-controls={open ? panelId : undefined}
        aria-label={t('common.moreInfo')}
        onClick={(event) => {
          // The trigger sits inside a <label>, whose click would otherwise be
          // forwarded to the input and steal the caret.
          event.preventDefault()
          setPinned((previous) => !previous)
        }}
        onMouseEnter={() => setHovered(true)}
        onMouseLeave={() => setHovered(false)}
        onFocus={() => setHovered(true)}
        onBlur={() => setHovered(false)}
        style={{
          display: 'inline-flex',
          alignItems: 'center',
          justifyContent: 'center',
          width: '16px',
          height: '16px',
          padding: 0,
          borderRadius: theme.borderRadius.full,
          background: 'transparent',
          border: `1px solid ${theme.colors.border.muted}`,
          color: theme.colors.text.secondary,
          fontSize: '10px',
          fontWeight: theme.typography.fontWeight.bold,
          fontStyle: 'italic',
          lineHeight: 1,
          cursor: 'help',
        }}
      >
        i
      </button>

      {open && (
        <span
          id={panelId}
          data-testid={testId ? `${testId}-content` : undefined}
          role="tooltip"
          style={{
            position: 'absolute',
            top: 'calc(100% + 6px)',
            left: 0,
            zIndex: 1200,
            // Wide enough for a sentence, capped so it cannot outgrow a phone.
            width: 'max-content',
            maxWidth: 'min(280px, 70vw)',
            padding: `${theme.spacing.sm} ${theme.spacing.md}`,
            background: theme.colors.bg.tooltip,
            border: `1px solid ${theme.colors.border.light}`,
            borderRadius: theme.borderRadius.sm,
            boxShadow: theme.shadows.lg,
            color: theme.colors.text.primary,
            fontSize: theme.typography.fontSize.xs,
            fontWeight: theme.typography.fontWeight.normal,
            lineHeight: theme.typography.lineHeight.normal,
            whiteSpace: 'normal',
            pointerEvents: 'none',
          }}
        >
          {content}
        </span>
      )}
    </span>
  )
}
