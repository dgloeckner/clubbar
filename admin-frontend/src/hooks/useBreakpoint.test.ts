// @vitest-environment jsdom
import { describe, expect, it } from 'vitest'
import { act, renderHook } from '@testing-library/react'
import { useBreakpoint } from './useBreakpoint'

function resizeTo(width: number) {
  act(() => {
    window.innerWidth = width
    window.dispatchEvent(new Event('resize'))
  })
}

describe('useBreakpoint', () => {
  it.each([
    [320, 'smallMobile'],
    [480, 'smallMobile'],
    [481, 'mobile'],
    [768, 'mobile'],
    [769, 'tablet'],
    [1500, 'tablet'],
    [1501, 'desktop'],
    [1920, 'desktop'],
  ] as const)('reports %ipx as %s', (width, expected) => {
    resizeTo(width)
    const { result } = renderHook(() => useBreakpoint())
    expect(result.current).toBe(expected)
  })

  it('updates when the window is resized', () => {
    resizeTo(1920)
    const { result } = renderHook(() => useBreakpoint())
    expect(result.current).toBe('desktop')

    resizeTo(400)
    expect(result.current).toBe('smallMobile')

    resizeTo(800)
    expect(result.current).toBe('tablet')
  })
})
