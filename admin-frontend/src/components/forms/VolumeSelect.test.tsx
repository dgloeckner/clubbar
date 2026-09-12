// @vitest-environment jsdom

/**
 * The size control: a list of the sizes a club pours, and a field for the ones
 * it does not (ADR-0056).
 *
 * The list is where almost every product's size comes from, and these tests
 * hold the two things the field beside it must not get wrong:
 *
 * - a size the list does not contain **opens the field, filled in** — the
 *   alternative is a `<select>` asked to show a value it has no option for,
 *   which renders blank and lets the next unrelated save clear the column;
 * - the field **cannot hold anything but whole millilitres**, which is what
 *   makes typing safe here after it was not safe in litres (#863).
 */

import { render, screen, cleanup, fireEvent } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { VolumeSelect } from './VolumeSelect'
import { VOLUME_PRESETS_ML } from '../../utils/volume'

afterEach(cleanup)

function renderControl(value: number | null, onChange = vi.fn()) {
  render(
    <VolumeSelect
      value={value}
      onChange={onChange}
      testId="volume"
      emptyLabel="No size"
      customLabel="Other size…"
      customFieldLabel="Size in millilitres"
    />,
  )

  return {
    onChange,
    select: screen.getByTestId('volume') as HTMLSelectElement,
    hidden: screen.getByTestId('volume-value') as HTMLInputElement,
    field: () => screen.queryByTestId('volume-custom') as HTMLInputElement | null,
  }
}

describe('the list', () => {
  it('offers no size, every preset, and the escape hatch last', () => {
    const { select } = renderControl(null)

    expect([...select.options].map((option) => option.value)).toEqual([
      '',
      ...VOLUME_PRESETS_ML.map(String),
      'custom',
    ])
    expect(select.value).toBe('')
  })

  it('sends the picked option on as whole millilitres', () => {
    const { select, onChange } = renderControl(null)

    fireEvent.change(select, { target: { value: '500' } })

    expect(onChange).toHaveBeenCalledWith(500)
  })

  it('says "no size" as an explicit null, never as 0', () => {
    // 0 would print as a size while meaning none, which is why the API refuses
    // it — and why clearing has to reach the column as null.
    const { select, onChange } = renderControl(500)

    fireEvent.change(select, { target: { value: '' } })

    expect(onChange).toHaveBeenCalledWith(null)
  })

  it('keeps the field shut for a size that is on the list', () => {
    const { field, select, hidden } = renderControl(500)

    expect(field()).toBeNull()
    expect(select.value).toBe('500')
    expect(hidden.value).toBe('500')
  })
})

describe('a size the list does not contain', () => {
  it('opens the field with the size in it, rather than showing no size at all', () => {
    // The failure this prevents: a product saved when sizes were typed holds
    // 700 ml, the select has no such option and falls back to the empty one,
    // and the next save of an unrelated field clears a column nobody touched.
    const { select, field, hidden } = renderControl(700)

    expect(select.value).toBe('custom')
    expect(field()!.value).toBe('700')
    expect(hidden.value).toBe('700')
  })

  it('can be replaced by a listed size, which shuts the field again', () => {
    const { select, onChange } = renderControl(700)

    fireEvent.change(select, { target: { value: '750' } })

    expect(onChange).toHaveBeenCalledWith(750)
  })
})

describe('the typed field', () => {
  it('opens on request, carrying whatever was already picked', () => {
    // Picking `500` and then asking to type `550` should start from `500`,
    // not from an empty field.
    const { select, field, onChange } = renderControl(500)

    fireEvent.change(select, { target: { value: 'custom' } })

    expect(field()!.value).toBe('500')
    // Opening the field is not a change of size.
    expect(onChange).not.toHaveBeenCalled()
  })

  it('stays open while it is still empty', () => {
    const { select, field, hidden } = renderControl(null)

    fireEvent.change(select, { target: { value: 'custom' } })

    expect(field()).not.toBeNull()
    expect(field()!.value).toBe('')
    expect(hidden.value).toBe('')
  })

  it('reads a typed size as whole millilitres', () => {
    const { select, field, onChange } = renderControl(null)
    fireEvent.change(select, { target: { value: 'custom' } })

    fireEvent.change(field()!, { target: { value: '1500' } })

    expect(onChange).toHaveBeenCalledWith(1500)
  })

  it('refuses a decimal separator instead of reporting an empty field', () => {
    // `<input type="number">` reports a comma as the empty string, which is
    // what made the litres field unusable in German (#863). A millilitre has no
    // separator at all, so the mask can simply drop it — `0,5` becomes `5`, and
    // the preview beside the field spells that out as `5 ml`.
    const { select, field, onChange } = renderControl(null)
    fireEvent.change(select, { target: { value: 'custom' } })

    fireEvent.change(field()!, { target: { value: '0,5' } })

    expect(onChange).toHaveBeenCalledWith(5)
  })

  it('drops anything that is not a digit', () => {
    const { select, field, onChange } = renderControl(null)
    fireEvent.change(select, { target: { value: 'custom' } })

    fireEvent.change(field()!, { target: { value: '7d0e0' } })

    expect(onChange).toHaveBeenCalledWith(700)
  })

  it('is a text field with a numeric keypad, not a number input', () => {
    const { select, field } = renderControl(null)
    fireEvent.change(select, { target: { value: 'custom' } })

    expect(field()!.getAttribute('type')).toBe('text')
    expect(field()!.getAttribute('inputmode')).toBe('numeric')
  })

  it('hands an out-of-range size to the page rather than swallowing it', () => {
    // The 1–10 000 ml range is a refusal the admin has to see beside the field.
    const { select, field, onChange } = renderControl(null)
    fireEvent.change(select, { target: { value: 'custom' } })

    fireEvent.change(field()!, { target: { value: '99999' } })

    expect(onChange).toHaveBeenCalledWith(99999)
  })

  it('carries the page’s refusal, so the invalid field is the one the admin is in', () => {
    render(
      <VolumeSelect
        value={99999}
        onChange={vi.fn()}
        testId="bad"
        emptyLabel="No size"
        customLabel="Other size…"
        customFieldLabel="Size in millilitres"
        invalid
      />,
    )

    expect(screen.getByTestId('bad-custom').getAttribute('aria-invalid')).toBe('true')
    expect(screen.getByTestId('bad').getAttribute('aria-invalid')).toBeNull()
  })
})
