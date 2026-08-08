// @vitest-environment jsdom
import { describe, expect, it } from 'vitest'
import { renderHook } from '@testing-library/react'
import { useLatestRequest } from './useLatestRequest'

describe('useLatestRequest', () => {
  it('aborts the previous request when a new one starts', () => {
    const { result } = renderHook(() => useLatestRequest())

    const first = result.current.next()
    expect(first.aborted).toBe(false)

    const second = result.current.next()

    expect(first.aborted).toBe(true)
    expect(second.aborted).toBe(false)
  })

  it('keeps a stable identity across re-renders so effects do not re-run', () => {
    const { result, rerender } = renderHook(() => useLatestRequest())

    const request = result.current
    rerender()

    expect(result.current).toBe(request)
    expect(result.current.next).toBe(request.next)
    expect(result.current.abort).toBe(request.abort)
  })

  it('aborts the in-flight request without starting a new one', () => {
    const { result } = renderHook(() => useLatestRequest())

    const signal = result.current.next()
    result.current.abort()

    expect(signal.aborted).toBe(true)
  })

  it('leaves a later request untouched when abort follows next', () => {
    const { result } = renderHook(() => useLatestRequest())

    result.current.next()
    result.current.abort()
    const current = result.current.next()

    expect(current.aborted).toBe(false)
  })

  it('lets only the newest request write when an older one resolves last', async () => {
    const { result } = renderHook(() => useLatestRequest())
    const rendered: string[] = []

    // A response that has already arrived when the abort lands resolves
    // normally — no error is thrown — so the flag is the only thing that keeps
    // the stale answer off the page.
    const load = async (value: string, delayMs: number, signal: AbortSignal) => {
      await new Promise((resolve) => setTimeout(resolve, delayMs))
      if (signal.aborted) return
      rendered.push(value)
    }

    const stale = load('an', 50, result.current.next())
    const current = load('', 0, result.current.next())

    await Promise.all([stale, current])

    expect(rendered).toEqual([''])
  })

  it('aborts the in-flight request on unmount', () => {
    const { result, unmount } = renderHook(() => useLatestRequest())

    const signal = result.current.next()
    unmount()

    expect(signal.aborted).toBe(true)
  })
})
