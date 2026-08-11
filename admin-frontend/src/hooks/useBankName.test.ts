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

  it('returns null and not loading for a non-German IBAN and never calls the backend', async () => {
    const { result } = renderHook(() => useBankName('FR7630006000011234567890189'))

    await act(async () => {
      await vi.advanceTimersByTimeAsync(500)
    })

    expect(result.current.bankName).toBeNull()
    expect(result.current.isLoading).toBe(false)
    expect(getMock).not.toHaveBeenCalled()
  })

  it('returns null and not loading for an invalid German IBAN (broken checksum) and never calls the backend', async () => {
    // Same digits as the valid fixture but with the last digit altered, breaking the checksum.
    const { result } = renderHook(() => useBankName('DE89370400440532013001'))

    await act(async () => {
      await vi.advanceTimersByTimeAsync(500)
    })

    expect(result.current.bankName).toBeNull()
    expect(result.current.isLoading).toBe(false)
    expect(getMock).not.toHaveBeenCalled()
  })

  it('resolves the bank name for a valid German IBAN after the debounce', async () => {
    getMock.mockResolvedValue({ data: { bank_name: 'Commerzbank' } })

    const { result } = renderHook(() => useBankName(VALID_DE_IBAN))

    // Not yet requested before the debounce window elapses, but already flagged as loading.
    expect(getMock).not.toHaveBeenCalled()
    expect(result.current.isLoading).toBe(true)

    await act(async () => {
      await vi.advanceTimersByTimeAsync(400)
    })

    expect(getMock).toHaveBeenCalledTimes(1)
    // Only the BLZ is sent - never the full IBAN (it would end up in access logs).
    expect(getMock).toHaveBeenCalledWith(
      '/admin/bank-lookup',
      expect.objectContaining({ params: { blz: '37040044' } })
    )
    expect(result.current.bankName).toBe('Commerzbank')
    expect(result.current.isLoading).toBe(false)
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
      expect.objectContaining({ params: { blz: '10010010' } })
    )
    expect(result.current.bankName).toBe('Commerzbank')
    expect(result.current.isLoading).toBe(false)
  })

  it('stops loading once the lookup resolves with no match, instead of showing "loading" forever', async () => {
    // Backend returns bank_name: null for a syntactically valid but unrecognized BLZ.
    getMock.mockResolvedValue({ data: { bank_name: null, bic: null } })

    const { result } = renderHook(() => useBankName(VALID_DE_IBAN))

    expect(result.current.isLoading).toBe(true)

    await act(async () => {
      await vi.advanceTimersByTimeAsync(400)
    })

    expect(getMock).toHaveBeenCalledTimes(1)
    expect(result.current.bankName).toBeNull()
    expect(result.current.isLoading).toBe(false)
  })
})
