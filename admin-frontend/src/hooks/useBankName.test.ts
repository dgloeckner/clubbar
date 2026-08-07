// @vitest-environment jsdom
import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest'
import { act, renderHook } from '@testing-library/react'
import { useBankName } from './useBankName'

const { getMock } = vi.hoisted(() => ({ getMock: vi.fn() }))

vi.mock('../api/client', () => ({
  default: { get: getMock },
}))

const VALID_DE_IBAN = 'DE89370400440532013000'

describe('useBankName', () => {
  beforeEach(() => {
    getMock.mockReset()
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('returns null for a non-German IBAN and never calls the backend', async () => {
    const { result } = renderHook(() => useBankName('FR7630006000011234567890189'))

    await act(async () => {
      await vi.advanceTimersByTimeAsync(500)
    })

    expect(result.current).toBeNull()
    expect(getMock).not.toHaveBeenCalled()
  })

  it('returns null for an invalid German IBAN (broken checksum) and never calls the backend', async () => {
    // Same digits as the valid fixture but with the last digit altered, breaking the checksum.
    const { result } = renderHook(() => useBankName('DE89370400440532013001'))

    await act(async () => {
      await vi.advanceTimersByTimeAsync(500)
    })

    expect(result.current).toBeNull()
    expect(getMock).not.toHaveBeenCalled()
  })

  it('resolves the bank name for a valid German IBAN after the debounce', async () => {
    getMock.mockResolvedValue({ data: { bank_name: 'Commerzbank' } })

    const { result } = renderHook(() => useBankName(VALID_DE_IBAN))

    // Not yet requested before the debounce window elapses.
    expect(getMock).not.toHaveBeenCalled()

    await act(async () => {
      await vi.advanceTimersByTimeAsync(400)
    })

    expect(getMock).toHaveBeenCalledTimes(1)
    expect(getMock).toHaveBeenCalledWith(
      '/admin/bank-lookup',
      expect.objectContaining({ params: { iban: VALID_DE_IBAN } })
    )
    expect(result.current).toBe('Commerzbank')
  })

  it('debounces rapid IBAN changes so only the final value triggers a lookup', async () => {
    getMock.mockResolvedValue({ data: { bank_name: 'Commerzbank' } })

    const { result, rerender } = renderHook(({ iban }) => useBankName(iban), {
      initialProps: { iban: 'DE89370400440532013000' },
    })

    // Change the IBAN before the debounce fires; this must cancel the pending lookup.
    await act(async () => {
      await vi.advanceTimersByTimeAsync(200)
    })
    rerender({ iban: 'DE02100100100006820101' })
    await act(async () => {
      await vi.advanceTimersByTimeAsync(400)
    })

    expect(getMock).toHaveBeenCalledTimes(1)
    expect(getMock).toHaveBeenCalledWith(
      '/admin/bank-lookup',
      expect.objectContaining({ params: { iban: 'DE02100100100006820101' } })
    )
    expect(result.current).toBe('Commerzbank')
  })
})
