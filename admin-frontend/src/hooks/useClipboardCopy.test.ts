// @vitest-environment jsdom
import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest'
import { act, renderHook } from '@testing-library/react'
import { useClipboardCopy } from './useClipboardCopy'

function stubClipboard(value: unknown) {
  Object.defineProperty(navigator, 'clipboard', {
    value,
    configurable: true,
    writable: true,
  })
}

describe('useClipboardCopy', () => {
  const originalClipboard = Object.getOwnPropertyDescriptor(navigator, 'clipboard')

  beforeEach(() => {
    stubClipboard(undefined)
  })

  afterEach(() => {
    if (originalClipboard) {
      Object.defineProperty(navigator, 'clipboard', originalClipboard)
    }
  })

  it('starts idle', () => {
    const { result } = renderHook(() => useClipboardCopy())
    expect(result.current.status).toBe('idle')
  })

  it('reports copied once the write resolves', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    stubClipboard({ writeText })

    const { result } = renderHook(() => useClipboardCopy())

    let copied: boolean | undefined
    await act(async () => {
      copied = await result.current.copy('s3cret')
    })

    expect(writeText).toHaveBeenCalledWith('s3cret')
    expect(copied).toBe(true)
    expect(result.current.status).toBe('copied')
  })

  it('reports failure when the write rejects, and never claims success', async () => {
    const writeText = vi.fn().mockRejectedValue(new Error('NotAllowedError'))
    stubClipboard({ writeText })

    const { result } = renderHook(() => useClipboardCopy())

    let copied: boolean | undefined
    await act(async () => {
      copied = await result.current.copy('s3cret')
    })

    expect(copied).toBe(false)
    expect(result.current.status).toBe('failed')
  })

  it('reports failure when the clipboard API is unavailable (non-secure origin)', async () => {
    stubClipboard(undefined)

    const { result } = renderHook(() => useClipboardCopy())

    let copied: boolean | undefined
    await act(async () => {
      copied = await result.current.copy('s3cret')
    })

    expect(copied).toBe(false)
    expect(result.current.status).toBe('failed')
  })

  it('reports failure when writeText is missing from the clipboard object', async () => {
    stubClipboard({})

    const { result } = renderHook(() => useClipboardCopy())

    await act(async () => {
      await result.current.copy('s3cret')
    })

    expect(result.current.status).toBe('failed')
  })

  it('resolves only after the write settles', async () => {
    let resolveWrite: (() => void) | undefined
    const writeText = vi.fn(
      () =>
        new Promise<void>((resolve) => {
          resolveWrite = resolve
        })
    )
    stubClipboard({ writeText })

    const { result } = renderHook(() => useClipboardCopy())

    let settled = false
    await act(async () => {
      const pending = result.current.copy('s3cret').then(() => {
        settled = true
      })

      // The write is still in flight — the caller must not have continued yet.
      await Promise.resolve()
      expect(settled).toBe(false)
      expect(result.current.status).toBe('idle')

      resolveWrite?.()
      await pending
    })

    expect(settled).toBe(true)
    expect(result.current.status).toBe('copied')
  })

  it('recovers to copied after a failed attempt', async () => {
    const writeText = vi
      .fn()
      .mockRejectedValueOnce(new Error('NotAllowedError'))
      .mockResolvedValueOnce(undefined)
    stubClipboard({ writeText })

    const { result } = renderHook(() => useClipboardCopy())

    await act(async () => {
      await result.current.copy('s3cret')
    })
    expect(result.current.status).toBe('failed')

    await act(async () => {
      await result.current.copy('s3cret')
    })
    expect(result.current.status).toBe('copied')
  })

  it('returns to idle on reset', async () => {
    stubClipboard({ writeText: vi.fn().mockResolvedValue(undefined) })

    const { result } = renderHook(() => useClipboardCopy())

    await act(async () => {
      await result.current.copy('s3cret')
    })
    expect(result.current.status).toBe('copied')

    act(() => {
      result.current.reset()
    })
    expect(result.current.status).toBe('idle')
  })
})
