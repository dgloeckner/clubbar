/**
 * A date field that works the same on a laptop and on a phone.
 *
 * Replaces `<input type="date">`, which the panel used everywhere. That control
 * is a different thing in every browser, and the one thing it is nowhere is
 * good at a *birth date*: it opens on today, so 1979 is either a hundred taps
 * on a chevron or the undocumented trick of typing over the year segment, and
 * on a phone it is a spinner wheel that also starts at the current year.
 * ADR-0045 put `date_of_birth` on the required path of creating a member, so
 * that control now sits between the club and its youth-protection duty.
 *
 * The design here is the one every usability study of date entry lands on:
 *
 * - **Typing is the primary path.** A birth date is remembered, not chosen, so
 *   the field is a text input first. `23111979` becomes `23.11.1979` as you
 *   type, `1.1.1990` and a pasted `1979-11-23` are both understood, and the
 *   locale decides the order (`getDateFormat`).
 * - **The calendar is for the dates you pick rather than know** — a settlement
 *   date, a report range — and is one tap away for everyone else.
 * - **Month and year are each one tap inside the calendar**, never a chevron
 *   marathon: the header's month and year are buttons into their own grids.
 * - **Touch gets a bottom sheet**, not a 320px popover pinned to a field
 *   halfway up a scrolling modal, with 44px targets throughout.
 * - **The value never becomes ambiguous**: ISO `YYYY-MM-DD` on the wire and in
 *   a hidden input, localised text on screen.
 *
 * Birth-date mode adds the two things a birth date specifically wants: the
 * calendar opens in the *year* view when the field is empty, and the age the
 * date implies is shown under the field — a slip in the year is invisible in
 * `12.03.1997` and obvious as "48 Jahre".
 */

import { useCallback, useEffect, useId, useLayoutEffect, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { theme } from '../../styles/design-system'
import { useBreakpoint } from '../../hooks/useBreakpoint'
import { toIsoDate } from '../../utils/dates'
import {
  buildDatePlaceholder,
  calculateAge,
  clampIso,
  formatIsoForDisplay,
  getDateFormat,
  isIsoInRange,
  maskDateInput,
  parseDateInput,
  shiftIsoByYears,
  splitIso,
} from '../../utils/dateField'
import { DateCalendar, type CalendarView } from './DateCalendar'

export interface DateFieldProps {
  /** ISO `YYYY-MM-DD`, or `''` for empty. */
  value: string
  /** Called with an ISO date, or `''` when the field is empty or unreadable. */
  onChange: (iso: string) => void
  /** Base for the field's test ids; the text input carries it unchanged. */
  testId: string
  min?: string
  max?: string
  required?: boolean
  disabled?: boolean
  /** The page has an error for this field (e.g. a 422), so it renders red. */
  invalid?: boolean
  /** Extra ids for `aria-describedby`, e.g. the page's error paragraph. */
  describedBy?: string
  ariaLabel?: string
  /** `filter` is the compact toolbar variant; `form` fills its column. */
  variant?: 'form' | 'filter'
  /** `birthdate` opens the year view first and shows the resulting age. */
  mode?: 'date' | 'birthdate'
  /** Offers a Clear action in the calendar footer (filters, optional dates). */
  clearable?: boolean
  name?: string
}

/** Roughly the age of an adult member — where the year view opens from empty. */
const BIRTHDATE_DEFAULT_OFFSET_YEARS = 30

export function DateField({
  value,
  onChange,
  testId,
  min,
  max,
  required = false,
  disabled = false,
  invalid = false,
  describedBy,
  ariaLabel,
  variant = 'form',
  mode = 'date',
  clearable = false,
  name,
}: DateFieldProps) {
  const { t, i18n } = useTranslation()
  const breakpoint = useBreakpoint()
  const isSheet = breakpoint === 'mobile' || breakpoint === 'smallMobile'

  const locale = i18n.language || 'de'
  const spec = useMemo(() => getDateFormat(locale), [locale])
  const todayIso = useMemo(() => toIsoDate(new Date()), [])
  const hintId = useId()

  const [text, setText] = useState(() => formatIsoForDisplay(value, spec))
  const [open, setOpen] = useState(false)
  const [position, setPosition] = useState<{ top: number; left: number } | null>(null)

  const inputRef = useRef<HTMLInputElement>(null)
  // The input row, not the component root: the mobile sheet renders *inside*
  // the root, so an outside-click test against the root treats a tap on the
  // sheet's own backdrop as a tap on the field and never closes it. It is also
  // the right thing to anchor the popover to — the hint line below is not.
  const fieldRef = useRef<HTMLDivElement>(null)
  const panelRef = useRef<HTMLDivElement>(null)
  const isEditing = useRef(false)

  // The field is controlled by the page, so a value set elsewhere (loading a
  // member, a filter reset) has to reach the text — but not mid-keystroke,
  // which would fight the user for the caret.
  useEffect(() => {
    if (isEditing.current) return
    setText(formatIsoForDisplay(value, spec))
  }, [value, spec])

  const placeholder = buildDatePlaceholder(spec, {
    day: t('dateField.dayLetters'),
    month: t('dateField.monthLetters'),
    year: t('dateField.yearLetters'),
  })

  const parsed = parseDateInput(text, spec)
  const outOfRange = parsed !== null && !isIsoInRange(parsed, min, max)
  const unreadable = text.trim() !== '' && parsed === null
  const localError = unreadable
    ? t('dateField.invalidFormat', { format: placeholder })
    : outOfRange
      ? t('dateField.outOfRange')
      : null

  // A field the user has made unreadable must not submit the last good value
  // it happened to hold, so the browser blocks the submit with the same
  // message the field shows.
  useEffect(() => {
    inputRef.current?.setCustomValidity(localError ?? '')
  }, [localError])

  const age = mode === 'birthdate' ? calculateAge(value, new Date()) : null

  /*
   * One line under the field: what is wrong, else the age the date implies,
   * else nothing. The format is the input's own placeholder, so repeating it
   * underneath only doubles it — and on a filter row it doubles it per field.
   */
  const hint = localError ?? (age !== null ? t('dateField.age', { count: age }) : null)

  const commit = (nextText: string) => {
    const iso = parseDateInput(nextText, spec)
    if (nextText.trim() === '') {
      if (value !== '') onChange('')
      return
    }
    if (iso && isIsoInRange(iso, min, max)) {
      if (iso !== value) onChange(iso)
      return
    }
    // Unreadable or out of range: the field keeps showing what was typed and
    // says why, and the value it used to hold is dropped rather than saved.
    if (value !== '') onChange('')
  }

  const handleInput = (raw: string) => {
    isEditing.current = true
    const masked = maskDateInput(raw, spec)
    setText(masked)
    commit(masked)
  }

  const handleBlur = () => {
    isEditing.current = false
    const iso = parseDateInput(text, spec)
    // Normalise only what is actually a date: `1.1.1990` becomes `01.01.1990`,
    // while `1.1.` is left alone so the user can see what they have to fix.
    if (iso && isIsoInRange(iso, min, max)) setText(formatIsoForDisplay(iso, spec))
  }

  const initialFocus = useMemo(() => {
    if (splitIso(value)) return value
    const anchor = mode === 'birthdate' ? shiftIsoByYears(todayIso, -BIRTHDATE_DEFAULT_OFFSET_YEARS) : todayIso
    return clampIso(anchor, min, max)
  }, [value, mode, todayIso, min, max])

  const initialView: CalendarView = mode === 'birthdate' && !splitIso(value) ? 'year' : 'day'

  const closePanel = useCallback((restoreFocus: boolean) => {
    setOpen(false)
    setPosition(null)
    if (restoreFocus) inputRef.current?.focus()
  }, [])

  const updatePosition = useCallback(() => {
    const anchor = fieldRef.current
    if (!anchor) return
    const rect = anchor.getBoundingClientRect()
    const panel = panelRef.current
    const height = panel?.offsetHeight ?? 360
    const width = panel?.offsetWidth ?? 300
    const gap = 6

    let top = rect.bottom + gap
    if (top + height > window.innerHeight - 8) {
      const above = rect.top - gap - height
      top = above >= 8 ? above : Math.max(8, window.innerHeight - height - 8)
    }
    const left = Math.max(8, Math.min(rect.left, window.innerWidth - width - 8))
    setPosition({ top, left })
  }, [])

  // Anchored to the field on every scroll, because the field it belongs to
  // usually sits inside a modal that scrolls independently of the page.
  useLayoutEffect(() => {
    if (!open || isSheet) return
    updatePosition()
    window.addEventListener('scroll', updatePosition, true)
    window.addEventListener('resize', updatePosition)
    return () => {
      window.removeEventListener('scroll', updatePosition, true)
      window.removeEventListener('resize', updatePosition)
    }
  }, [open, isSheet, updatePosition])

  /*
   * Escape is bound on `window` in the capture phase rather than on the panel,
   * the way `useModalEscape` does it: a handler on the panel only fires while
   * focus happens to be inside it, and the picker can be open with focus still
   * on the button that opened it. Capturing also stops the key reaching the
   * modal behind, so one Escape closes the picker and not the form.
   */
  useEffect(() => {
    if (!open) return
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key !== 'Escape') return
      event.stopPropagation()
      closePanel(true)
    }
    window.addEventListener('keydown', onKeyDown, true)
    return () => window.removeEventListener('keydown', onKeyDown, true)
  }, [open, closePanel])

  useEffect(() => {
    if (!open) return
    const onPointerDown = (event: MouseEvent | TouchEvent) => {
      const target = event.target as Node
      if (fieldRef.current?.contains(target) || panelRef.current?.contains(target)) return
      closePanel(false)
    }
    document.addEventListener('mousedown', onPointerDown)
    document.addEventListener('touchstart', onPointerDown)
    return () => {
      document.removeEventListener('mousedown', onPointerDown)
      document.removeEventListener('touchstart', onPointerDown)
    }
  }, [open, closePanel])

  const handleSelect = (iso: string) => {
    isEditing.current = false
    setText(formatIsoForDisplay(iso, spec))
    onChange(iso)
    closePanel(true)
  }

  const handleClear = () => {
    isEditing.current = false
    setText('')
    onChange('')
    closePanel(true)
  }

  const borderColor = invalid || localError ? theme.colors.semantic.danger : theme.colors.border.light
  const compact = variant === 'filter'

  const calendar = (
    <DateCalendar
      value={splitIso(value) ? value : ''}
      initialFocus={initialFocus}
      initialView={initialView}
      min={min}
      max={max}
      todayIso={todayIso}
      locale={locale}
      layout={isSheet ? 'sheet' : 'popover'}
      autoFocus
      // "Today" is a shortcut on a date you choose and nonsense on one you
      // were born on.
      showToday={mode !== 'birthdate'}
      onSelect={handleSelect}
      onClose={() => closePanel(true)}
      onClear={clearable ? handleClear : undefined}
      testId={`${testId}-calendar`}
    />
  )

  return (
    <div style={{ position: 'relative', width: '100%' }}>
      <div ref={fieldRef} style={{ position: 'relative', display: 'flex', alignItems: 'center' }}>
        <input
          ref={inputRef}
          data-testid={testId}
          name={name}
          type="text"
          // A numeric keypad rather than the full keyboard: every character
          // this field accepts is a digit or a separator.
          inputMode="numeric"
          autoComplete={mode === 'birthdate' ? 'bday' : 'off'}
          placeholder={placeholder}
          required={required}
          disabled={disabled}
          aria-label={ariaLabel}
          aria-invalid={invalid || localError !== null}
          aria-describedby={[hint ? hintId : null, describedBy].filter(Boolean).join(' ') || undefined}
          value={text}
          onChange={(e) => handleInput(e.target.value)}
          onBlur={handleBlur}
          onKeyDown={(e) => {
            if (e.key === 'ArrowDown' && !open) {
              e.preventDefault()
              setOpen(true)
            }
          }}
          style={{
            width: '100%',
            padding: compact
              ? `${theme.spacing.sm} 40px ${theme.spacing.sm} ${theme.spacing.md}`
              : `${theme.spacing.md} 44px ${theme.spacing.md} ${theme.spacing.lg}`,
            background: theme.colors.bg.input,
            border: `1px solid ${borderColor}`,
            borderRadius: theme.borderRadius.md,
            color: theme.colors.text.primary,
            fontSize: theme.typography.fontSize.base,
            boxSizing: 'border-box',
          }}
        />

        <button
          type="button"
          data-testid={`${testId}-open-calendar`}
          aria-label={t('dateField.openCalendar')}
          aria-haspopup="dialog"
          aria-expanded={open}
          disabled={disabled}
          onClick={() => (open ? closePanel(true) : setOpen(true))}
          style={{
            position: 'absolute',
            right: compact ? 4 : 6,
            width: compact ? 32 : 36,
            height: compact ? 32 : 36,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            background: 'transparent',
            border: 'none',
            borderRadius: theme.borderRadius.sm,
            color: theme.colors.text.secondary,
            cursor: disabled ? 'default' : 'pointer',
            fontSize: theme.typography.fontSize.lg,
            lineHeight: 1,
          }}
        >
          <CalendarIcon />
        </button>

        {/*
          The ISO value, for anything reading the field rather than typing into
          it: a plain form post, and the E2E suite, which asserts on the value
          the API will receive rather than on the locale's rendering of it.
        */}
        <input type="hidden" data-testid={`${testId}-value`} value={value} readOnly />
      </div>

      {hint && (
        <p
          id={hintId}
          data-testid={`${testId}-hint`}
          style={{
            margin: `${theme.spacing.xs} 0 0`,
            fontSize: theme.typography.fontSize.xs,
            color: localError ? theme.colors.semantic.danger : theme.colors.text.secondary,
          }}
        >
          {hint}
        </p>
      )}

      {open && !isSheet && (
        <div
          ref={panelRef}
          style={{
            position: 'fixed',
            top: position?.top ?? 0,
            left: position?.left ?? 0,
            zIndex: 1200,
            visibility: position ? 'visible' : 'hidden',
          }}
        >
          {calendar}
        </div>
      )}

      {open && isSheet && (
        <div
          data-testid={`${testId}-sheet`}
          onKeyDown={(e) => trapTab(e, panelRef.current)}
          style={{
            position: 'fixed',
            inset: 0,
            zIndex: 1300,
            background: 'rgba(0, 0, 0, 0.6)',
            display: 'flex',
            alignItems: 'flex-end',
          }}
        >
          <div ref={panelRef} style={{ width: '100%' }}>
            {calendar}
          </div>
        </div>
      )}
    </div>
  )
}

/**
 * Keeps Tab inside the sheet, which covers the page it is over. The popover
 * needs none of this: it sits next to its field and Tab should leave it.
 */
function trapTab(event: React.KeyboardEvent, container: HTMLElement | null) {
  if (event.key !== 'Tab' || !container) return
  const focusable = Array.from(
    container.querySelectorAll<HTMLElement>('button:not([disabled]), [tabindex]:not([tabindex="-1"])')
  )
  if (focusable.length === 0) return

  const first = focusable[0]
  const last = focusable[focusable.length - 1]
  const active = document.activeElement as HTMLElement | null

  if (event.shiftKey && (active === first || !container.contains(active))) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && active === last) {
    event.preventDefault()
    first.focus()
  }
}

function CalendarIcon() {
  return (
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
      <rect x="3" y="5" width="18" height="16" rx="2" />
      <path d="M3 10h18M8 3v4M16 3v4" strokeLinecap="round" />
    </svg>
  )
}
