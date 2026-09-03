// @vitest-environment jsdom
import { describe, expect, it, vi } from 'vitest'
import { act, renderHook } from '@testing-library/react'
import { otpFromInput, useOtpAutoSubmit } from './useOtpAutoSubmit'

/** Render the hook over a mutable (code, ready) pair, the way a form does. */
function setup(initial: { code: string; ready?: boolean } = { code: '' }) {
  const submit = vi.fn()
  const view = renderHook(
    ({ code, ready }: { code: string; ready: boolean }) => useOtpAutoSubmit(code, submit, ready),
    { initialProps: { code: initial.code, ready: initial.ready ?? true } },
  )

  return { submit, ...view }
}

describe('useOtpAutoSubmit', () => {
  it('does not submit an incomplete code', () => {
    const { submit, rerender } = setup()

    for (const code of ['1', '12', '123', '1234', '12345']) {
      rerender({ code, ready: true })
    }

    expect(submit).not.toHaveBeenCalled()
  })

  it('submits once when the sixth digit lands', () => {
    const { submit, rerender } = setup()

    rerender({ code: '12345', ready: true })
    rerender({ code: '123456', ready: true })

    expect(submit).toHaveBeenCalledTimes(1)
  })

  it('submits nothing while readiness is withheld', () => {
    const { submit, rerender } = setup({ code: '', ready: false })

    rerender({ code: '123456', ready: false })

    expect(submit).not.toHaveBeenCalled()
  })

  // Readiness gates, it never triggers. In the step-up dialog readiness is
  // "a password has been typed", so firing on that edge would submit the form
  // on the first character of the password and spend the code on a 401.
  it('does not submit when readiness arrives after the code is already complete', () => {
    const { submit, rerender } = setup({ code: '', ready: false })

    rerender({ code: '123456', ready: false })
    rerender({ code: '123456', ready: true })

    expect(submit).not.toHaveBeenCalled()
  })

  it('submits on the next manual attempt once readiness arrives', () => {
    const { submit, result, rerender } = setup({ code: '', ready: false })

    rerender({ code: '123456', ready: false })
    rerender({ code: '123456', ready: true })

    act(() => result.current())

    expect(submit).toHaveBeenCalledTimes(1)
  })

  // The lockout-burning loop: readiness is derived from `loading`, so it flips
  // back to true the instant the request settles. Without the value guard the
  // effect would re-fire on an unchanged code until MFA_MAX_ATTEMPTS is spent.
  it('does not resubmit when readiness flips back with the code unchanged', () => {
    const { submit, rerender } = setup()

    rerender({ code: '123456', ready: true })
    rerender({ code: '123456', ready: false })
    rerender({ code: '123456', ready: true })

    expect(submit).toHaveBeenCalledTimes(1)
  })

  // Every E2E page object fills the code and then clicks the button; the click
  // must not spend a second attempt on a code the backend has already consumed.
  it('ignores a manual submit of a code it already attempted', () => {
    const { submit, result, rerender } = setup()

    rerender({ code: '123456', ready: true })
    expect(submit).toHaveBeenCalledTimes(1)

    act(() => result.current())

    expect(submit).toHaveBeenCalledTimes(1)
  })

  it('re-arms once the field is edited away from six digits', () => {
    const { submit, rerender } = setup()

    rerender({ code: '123456', ready: true })
    rerender({ code: '12345', ready: true })
    rerender({ code: '123456', ready: true })

    expect(submit).toHaveBeenCalledTimes(2)
  })

  it('submits a different code without an edit down to five digits', () => {
    const { submit, rerender } = setup()

    rerender({ code: '123456', ready: true })
    rerender({ code: '654321', ready: true })

    expect(submit).toHaveBeenCalledTimes(2)
  })

  // A paste or an OS one-time-code autofill lands all six digits in one change.
  it('submits a code that arrives in a single change', () => {
    const { submit, rerender } = setup()

    rerender({ code: '123456', ready: true })

    expect(submit).toHaveBeenCalledTimes(1)
  })

  it('calls the submit function the caller passed on the render that fired', () => {
    const first = vi.fn()
    const second = vi.fn()
    const { rerender } = renderHook(
      ({ code, submit }: { code: string; submit: () => void }) => useOtpAutoSubmit(code, submit, true),
      { initialProps: { code: '', submit: first } },
    )

    rerender({ code: '123456', submit: second })

    expect(first).not.toHaveBeenCalled()
    expect(second).toHaveBeenCalledTimes(1)
  })
})

describe('otpFromInput', () => {
  it('keeps a plain six-digit code as it is', () => {
    expect(otpFromInput('123456')).toBe('123456')
  })

  // Authenticator apps render the code split in two, and that is what lands on
  // the clipboard. A `maxLength` of 6 truncated this to "123 45".
  it.each([
    ['123 456', 'a space, as most authenticator apps show it'],
    ['123-456', 'a hyphen'],
    ['123456\n', 'a trailing newline from a terminal'],
    ['  123456  ', 'surrounding whitespace'],
  ])('recovers the code from %j (%s)', (pasted) => {
    expect(otpFromInput(pasted)).toBe('123456')
  })

  it('keeps only the first six digits of a longer paste', () => {
    expect(otpFromInput('12345678')).toBe('123456')
  })

  it('drops letters rather than counting them toward the six', () => {
    expect(otpFromInput('code: 123456')).toBe('123456')
  })

  it('returns an incomplete code unchanged, so nothing auto-submits', () => {
    expect(otpFromInput('12 34')).toBe('1234')
  })
})
