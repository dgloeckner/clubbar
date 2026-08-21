/**
 * The calendar panel behind `DateField` — three views over one month cursor.
 *
 * Day, month and year are separate views rather than one grid with dropdowns
 * bolted on, because the two jumps that matter are *month* and *year* and both
 * have to be one tap away on a phone: the month name and the year in the
 * header are each a button into their own grid. Reaching November 1979 from
 * today is three taps (year block → year → month), not a hundred chevrons.
 *
 * The panel is presentation over `utils/dateField`; every date calculation
 * lives there and is unit-tested. This file owns the cursor, the view and the
 * keyboard model, and is covered by the Playwright suite.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { theme } from '../../styles/design-system'
import {
  YEAR_PAGE_SIZE,
  buildMonthGrid,
  compareIso,
  getMonthLabels,
  getWeekStart,
  getWeekdayLabels,
  getYearPage,
  isIsoInRange,
  makeIso,
  shiftIsoByDays,
  shiftIsoByMonths,
  shiftIsoByYears,
  shiftMonth,
  splitIso,
} from '../../utils/dateField'

export type CalendarView = 'day' | 'month' | 'year'

export interface DateCalendarProps {
  /** Currently selected date (ISO) or `''`. */
  value: string
  /** The date the views open on when nothing is selected yet. */
  initialFocus: string
  initialView?: CalendarView
  min?: string
  max?: string
  todayIso: string
  locale: string
  /** Bigger targets and a wider grid for touch. */
  layout: 'popover' | 'sheet'
  /** Move focus into the panel on mount (a picker opened by the user). */
  autoFocus?: boolean
  /** Offer the "Today" shortcut. Off for a birth date, where it means nothing. */
  showToday?: boolean
  onSelect: (iso: string) => void
  onClose: () => void
  onClear?: () => void
  testId: string
}

const srOnly: React.CSSProperties = {
  position: 'absolute',
  width: 1,
  height: 1,
  padding: 0,
  margin: -1,
  overflow: 'hidden',
  clip: 'rect(0 0 0 0)',
  whiteSpace: 'nowrap',
  border: 0,
}

export function DateCalendar({
  value,
  initialFocus,
  initialView = 'day',
  min,
  max,
  todayIso,
  locale,
  layout,
  autoFocus = false,
  showToday = true,
  onSelect,
  onClose,
  onClear,
  testId,
}: DateCalendarProps) {
  const { t } = useTranslation()

  const anchor = splitIso(value) ? value : initialFocus
  const [view, setView] = useState<CalendarView>(initialView)
  const [cursor, setCursor] = useState(() => {
    const parts = splitIso(anchor)!
    return { year: parts.year, month: parts.month - 1 }
  })
  const [focusedIso, setFocusedIso] = useState(anchor)
  const gridRef = useRef<HTMLDivElement>(null)
  const panelRef = useRef<HTMLDivElement>(null)
  // Focus follows the keyboard, but must not be stolen back from the input on
  // the first render of a popover the user opened by typing.
  const shouldFocusCell = useRef(false)

  const weekStart = useMemo(() => getWeekStart(locale), [locale])
  const weekdays = useMemo(() => getWeekdayLabels(locale, weekStart), [locale, weekStart])
  const monthsLong = useMemo(() => getMonthLabels(locale, 'long'), [locale])
  const monthsShort = useMemo(() => getMonthLabels(locale, 'short'), [locale])
  const weeks = useMemo(
    () => buildMonthGrid(cursor.year, cursor.month, { weekStart, min, max, todayIso }),
    [cursor.year, cursor.month, weekStart, min, max, todayIso]
  )
  const yearPage = useMemo(() => getYearPage(cursor.year), [cursor.year])

  const touch = layout === 'sheet'
  const cellSize = touch ? 44 : 38

  // Keep the focused day on the page the user is looking at, so paging with
  // the chevrons and then pressing an arrow key continues from what is shown.
  useEffect(() => {
    if (view !== 'day') return
    const parts = splitIso(focusedIso)
    if (parts && parts.year === cursor.year && parts.month - 1 === cursor.month) return
    const day = Math.min(parts?.day ?? 1, 28)
    setFocusedIso(makeIso(cursor.year, cursor.month + 1, day)!)
  }, [cursor.year, cursor.month, view, focusedIso])

  // Opening the panel moves focus into it, landing on the cell the arrow keys
  // will move from — otherwise the first arrow press goes to the page behind.
  useEffect(() => {
    if (!autoFocus) return
    const target =
      panelRef.current?.querySelector<HTMLElement>('[data-autofocus="true"]') ??
      panelRef.current?.querySelector<HTMLElement>('button:not([disabled])')
    target?.focus()
  }, [autoFocus])

  useEffect(() => {
    if (view !== 'day' || !shouldFocusCell.current) return
    const cell = gridRef.current?.querySelector<HTMLButtonElement>(`[data-date-cell="${focusedIso}"]`)
    cell?.focus()
  }, [focusedIso, view])

  const moveFocus = useCallback(
    (next: string) => {
      shouldFocusCell.current = true
      setFocusedIso(next)
      const parts = splitIso(next)
      if (parts) setCursor({ year: parts.year, month: parts.month - 1 })
    },
    []
  )

  const handleGridKeyDown = (event: React.KeyboardEvent) => {
    const key = event.key
    let next: string | null = null

    if (key === 'ArrowLeft') next = shiftIsoByDays(focusedIso, -1)
    else if (key === 'ArrowRight') next = shiftIsoByDays(focusedIso, 1)
    else if (key === 'ArrowUp') next = shiftIsoByDays(focusedIso, -7)
    else if (key === 'ArrowDown') next = shiftIsoByDays(focusedIso, 7)
    else if (key === 'Home') next = shiftIsoByDays(focusedIso, -weekdayIndex(focusedIso, weekStart))
    else if (key === 'End') next = shiftIsoByDays(focusedIso, 6 - weekdayIndex(focusedIso, weekStart))
    else if (key === 'PageUp') next = event.shiftKey ? shiftIsoByYears(focusedIso, -1) : shiftIsoByMonths(focusedIso, -1)
    else if (key === 'PageDown') next = event.shiftKey ? shiftIsoByYears(focusedIso, 1) : shiftIsoByMonths(focusedIso, 1)
    else return

    event.preventDefault()
    if (next) moveFocus(next)
  }

  const goMonth = (delta: number) => setCursor((c) => shiftMonth(c.year, c.month, delta))
  const goYears = (delta: number) => setCursor((c) => ({ ...c, year: c.year + delta }))

  const monthNavDisabled = (delta: number) => {
    const { year, month } = shiftMonth(cursor.year, cursor.month, delta)
    return !monthIntersectsRange(year, month, min, max)
  }

  const iconButton = (
    label: string,
    glyph: string,
    onClick: () => void,
    disabled: boolean,
    id: string
  ) => (
    <button
      type="button"
      aria-label={label}
      title={label}
      data-testid={`${testId}-${id}`}
      disabled={disabled}
      onClick={onClick}
      style={{
        width: touch ? 40 : 32,
        height: touch ? 40 : 32,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        borderRadius: theme.borderRadius.sm,
        border: 'none',
        background: 'transparent',
        color: disabled ? theme.colors.text.muted : theme.colors.text.secondary,
        cursor: disabled ? 'default' : 'pointer',
        fontSize: theme.typography.fontSize.lg,
        lineHeight: 1,
      }}
    >
      {glyph}
    </button>
  )

  const headerButton = (label: string, onClick: () => void, id: string, ariaLabel: string) => (
    <button
      type="button"
      onClick={onClick}
      data-testid={`${testId}-${id}`}
      aria-label={ariaLabel}
      style={{
        display: 'inline-flex',
        alignItems: 'center',
        gap: theme.spacing.xs,
        padding: `6px ${theme.spacing.sm}`,
        borderRadius: theme.borderRadius.sm,
        border: 'none',
        background: 'transparent',
        color: theme.colors.text.primary,
        fontSize: theme.typography.fontSize.base,
        fontWeight: theme.typography.fontWeight.semibold,
        cursor: 'pointer',
      }}
    >
      {label}
      <span aria-hidden="true" style={{ fontSize: 10, color: theme.colors.text.secondary }}>
        ▾
      </span>
    </button>
  )

  const gridButtonStyle = (selected: boolean, disabled: boolean): React.CSSProperties => ({
    padding: `${theme.spacing.sm} 0`,
    minHeight: touch ? 44 : 36,
    borderRadius: theme.borderRadius.sm,
    border: 'none',
    background: selected ? theme.colors.semantic.primary : 'transparent',
    color: disabled
      ? theme.colors.text.muted
      : selected
        ? '#ffffff'
        : theme.colors.text.primary,
    fontSize: theme.typography.fontSize.base,
    cursor: disabled ? 'default' : 'pointer',
  })

  return (
    <div
      ref={panelRef}
      data-testid={testId}
      role="dialog"
      aria-modal={touch}
      aria-label={t('dateField.calendarLabel')}
      style={{
        background: theme.colors.bg.card,
        border: `1px solid ${theme.colors.border.light}`,
        borderRadius: touch ? `${theme.borderRadius.lg} ${theme.borderRadius.lg} 0 0` : theme.borderRadius.md,
        boxShadow: theme.shadows.dropdown,
        padding: theme.spacing.md,
        width: touch ? '100%' : 7 * cellSize + 2 * 12,
        boxSizing: 'border-box',
        color: theme.colors.text.primary,
      }}
    >
      {/* What the panel is showing, for a screen reader that cannot see the header. */}
      <div aria-live="polite" style={srOnly}>
        {view === 'day'
          ? `${monthsLong[cursor.month]} ${cursor.year}`
          : view === 'month'
            ? String(cursor.year)
            : `${yearPage.start} – ${yearPage.end}`}
      </div>

      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: theme.spacing.sm }}>
        {view === 'day' && (
          <>
            {iconButton(t('dateField.previousMonth'), '‹', () => goMonth(-1), monthNavDisabled(-1), 'prev')}
            <div style={{ display: 'flex', alignItems: 'center', gap: theme.spacing.xs }}>
              {headerButton(monthsLong[cursor.month], () => setView('month'), 'month-view-button', t('dateField.chooseMonth'))}
              {headerButton(String(cursor.year), () => setView('year'), 'year-view-button', t('dateField.chooseYear'))}
            </div>
            {iconButton(t('dateField.nextMonth'), '›', () => goMonth(1), monthNavDisabled(1), 'next')}
          </>
        )}

        {view === 'month' && (
          <>
            {iconButton(t('dateField.previousYear'), '‹', () => goYears(-1), !yearIntersectsRange(cursor.year - 1, min, max), 'prev')}
            {headerButton(String(cursor.year), () => setView('year'), 'year-view-button', t('dateField.chooseYear'))}
            {iconButton(t('dateField.nextYear'), '›', () => goYears(1), !yearIntersectsRange(cursor.year + 1, min, max), 'next')}
          </>
        )}

        {view === 'year' && (
          <>
            {iconButton(
              t('dateField.previousYears'),
              '‹',
              () => goYears(-YEAR_PAGE_SIZE),
              !rangeIntersects(getYearPage(cursor.year - YEAR_PAGE_SIZE), min, max),
              'prev'
            )}
            <span style={{ fontWeight: theme.typography.fontWeight.semibold }} data-testid={`${testId}-year-range`}>
              {yearPage.start} – {yearPage.end}
            </span>
            {iconButton(
              t('dateField.nextYears'),
              '›',
              () => goYears(YEAR_PAGE_SIZE),
              !rangeIntersects(getYearPage(cursor.year + YEAR_PAGE_SIZE), min, max),
              'next'
            )}
          </>
        )}
      </div>

      {view === 'day' && (
        <div ref={gridRef} role="grid" aria-label={`${monthsLong[cursor.month]} ${cursor.year}`} onKeyDown={handleGridKeyDown}>
          <div role="row" style={{ display: 'grid', gridTemplateColumns: 'repeat(7, 1fr)' }}>
            {weekdays.map((label) => (
              <div
                key={label}
                role="columnheader"
                aria-label={label}
                style={{
                  textAlign: 'center',
                  fontSize: theme.typography.fontSize.xs,
                  color: theme.colors.text.secondary,
                  padding: `${theme.spacing.xs} 0`,
                }}
              >
                {label.slice(0, 2)}
              </div>
            ))}
          </div>

          {weeks.map((week) => (
            <div key={week[0].iso} role="row" style={{ display: 'grid', gridTemplateColumns: 'repeat(7, 1fr)' }}>
              {week.map((day) => {
                const selected = day.iso === value
                return (
                  <button
                    key={day.iso}
                    type="button"
                    role="gridcell"
                    data-date-cell={day.iso}
                    data-autofocus={day.iso === focusedIso}
                    data-testid={`${testId}-day-${day.iso}`}
                    aria-label={fullDateLabel(day.iso, locale)}
                    aria-selected={selected}
                    aria-current={day.isToday ? 'date' : undefined}
                    aria-disabled={day.disabled}
                    disabled={day.disabled}
                    tabIndex={day.iso === focusedIso ? 0 : -1}
                    onFocus={() => setFocusedIso(day.iso)}
                    onClick={() => onSelect(day.iso)}
                    style={{
                      ...gridButtonStyle(selected, day.disabled),
                      minHeight: cellSize,
                      opacity: day.inMonth || day.disabled ? 1 : 0.45,
                      color: day.disabled
                        ? theme.colors.text.muted
                        : selected
                          ? '#ffffff'
                          : day.inMonth
                            ? theme.colors.text.primary
                            : theme.colors.text.secondary,
                      fontWeight: day.isToday ? theme.typography.fontWeight.bold : theme.typography.fontWeight.normal,
                      boxShadow: day.isToday && !selected ? `inset 0 0 0 1px ${theme.colors.semantic.primary}` : undefined,
                    }}
                  >
                    {day.day}
                  </button>
                )
              })}
            </div>
          ))}
        </div>
      )}

      {view === 'month' && (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: theme.spacing.xs }}>
          {monthsShort.map((label, month) => {
            const selected = splitIso(value)?.year === cursor.year && splitIso(value)?.month === month + 1
            const disabled = !monthIntersectsRange(cursor.year, month, min, max)
            return (
              <button
                key={label}
                type="button"
                data-testid={`${testId}-month-${month + 1}`}
                aria-label={monthsLong[month]}
                aria-pressed={selected}
                disabled={disabled}
                onClick={() => {
                  setCursor({ year: cursor.year, month })
                  setView('day')
                }}
                style={gridButtonStyle(selected, disabled)}
              >
                {label}
              </button>
            )
          })}
        </div>
      )}

      {view === 'year' && (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: theme.spacing.xs }}>
          {yearPage.years.map((year) => {
            const selected = splitIso(value)?.year === year
            const disabled = !yearIntersectsRange(year, min, max)
            return (
              <button
                key={year}
                type="button"
                data-testid={`${testId}-year-${year}`}
                data-autofocus={year === (splitIso(anchor)?.year ?? cursor.year)}
                aria-pressed={selected}
                disabled={disabled}
                onClick={() => {
                  setCursor((c) => ({ ...c, year }))
                  setView('month')
                }}
                style={gridButtonStyle(selected, disabled)}
              >
                {year}
              </button>
            )
          })}
        </div>
      )}

      <div
        style={{
          display: 'flex',
          justifyContent: 'space-between',
          alignItems: 'center',
          gap: theme.spacing.sm,
          marginTop: theme.spacing.sm,
          paddingTop: theme.spacing.sm,
          borderTop: `1px solid ${theme.colors.border.dark}`,
        }}
      >
        {showToday ? (
          <button
            type="button"
            data-testid={`${testId}-today`}
            disabled={!isIsoInRange(todayIso, min, max)}
            onClick={() => onSelect(todayIso)}
            style={footerButtonStyle(!isIsoInRange(todayIso, min, max), touch)}
          >
            {t('dateField.today')}
          </button>
        ) : (
          <span />
        )}
        <div style={{ display: 'flex', gap: theme.spacing.sm }}>
          {onClear && (
            <button type="button" data-testid={`${testId}-clear`} onClick={onClear} style={footerButtonStyle(false, touch)}>
              {t('dateField.clear')}
            </button>
          )}
          <button
            type="button"
            data-testid={`${testId}-close`}
            onClick={onClose}
            style={{ ...footerButtonStyle(false, touch), color: theme.colors.semantic.primary }}
          >
            {t('common.close')}
          </button>
        </div>
      </div>
    </div>
  )
}

function footerButtonStyle(disabled: boolean, touch: boolean): React.CSSProperties {
  return {
    padding: touch ? `${theme.spacing.md} ${theme.spacing.lg}` : `${theme.spacing.sm} ${theme.spacing.md}`,
    minHeight: touch ? 44 : undefined,
    background: 'transparent',
    border: 'none',
    borderRadius: theme.borderRadius.sm,
    color: disabled ? theme.colors.text.muted : theme.colors.text.secondary,
    fontSize: theme.typography.fontSize.sm,
    fontWeight: theme.typography.fontWeight.medium,
    cursor: disabled ? 'default' : 'pointer',
  }
}

/** Position of a date within its displayed week, honouring the locale's first day. */
function weekdayIndex(iso: string, weekStart: number): number {
  const parts = splitIso(iso)
  if (!parts) return 0
  const date = new Date(2000, 0, 1)
  date.setFullYear(parts.year, parts.month - 1, parts.day)
  return (date.getDay() - weekStart + 7) % 7
}

function fullDateLabel(iso: string, locale: string): string {
  const parts = splitIso(iso)
  if (!parts) return iso
  const date = new Date(2000, 0, 1)
  date.setFullYear(parts.year, parts.month - 1, parts.day)
  return new Intl.DateTimeFormat(locale, { dateStyle: 'full' }).format(date)
}

/** True when any day of the month is selectable, used to grey out navigation. */
function monthIntersectsRange(year: number, month: number, min?: string, max?: string): boolean {
  const first = makeIso(year, month + 1, 1)
  const lastDay = new Date(2000, 0, 1)
  lastDay.setFullYear(year, month + 1, 0)
  const last = makeIso(year, month + 1, lastDay.getDate())
  if (!first || !last) return false
  return (!max || compareIso(first, max) <= 0) && (!min || compareIso(last, min) >= 0)
}

function yearIntersectsRange(year: number, min?: string, max?: string): boolean {
  const first = makeIso(year, 1, 1)
  const last = makeIso(year, 12, 31)
  if (!first || !last) return false
  return (!max || compareIso(first, max) <= 0) && (!min || compareIso(last, min) >= 0)
}

function rangeIntersects(page: { start: number; end: number }, min?: string, max?: string): boolean {
  const first = makeIso(page.start, 1, 1)
  const last = makeIso(page.end, 12, 31)
  if (!first || !last) return false
  return (!max || compareIso(first, max) <= 0) && (!min || compareIso(last, min) >= 0)
}
