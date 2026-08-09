// @vitest-environment jsdom
import { describe, expect, it, vi } from 'vitest'
import { act, renderHook, waitFor } from '@testing-library/react'
import { useExecutionDateInfo } from './useExecutionDateInfo'
import type { ExecutionDateInfo } from '../api/generated'
import i18n from '../i18n/config'

const getExecutionDateInfo = vi.hoisted(() => vi.fn())

vi.mock('../api/generated/settlements/settlements', () => ({
  getSettlements: () => ({ getExecutionDateInfo }),
}))

const sampleInfo: ExecutionDateInfo = {
  minimum_date: '2026-08-13',
} as ExecutionDateInfo

describe('useExecutionDateInfo', () => {
  it('loads execution date info when enabled', async () => {
    getExecutionDateInfo.mockReset().mockResolvedValue(sampleInfo)

    const { result } = renderHook(() => useExecutionDateInfo(true))

    expect(result.current.loading).toBe(true)

    await waitFor(() => expect(result.current.loading).toBe(false))

    expect(result.current.info).toEqual(sampleInfo)
    expect(result.current.error).toBeNull()
    expect(getExecutionDateInfo).toHaveBeenCalledTimes(1)
  })

  it('does not fetch when disabled', async () => {
    getExecutionDateInfo.mockReset().mockResolvedValue(sampleInfo)

    const { result } = renderHook(() => useExecutionDateInfo(false))

    expect(result.current.loading).toBe(false)
    expect(result.current.info).toBeNull()
    expect(getExecutionDateInfo).not.toHaveBeenCalled()
  })

  it('sets an error message when the request fails', async () => {
    getExecutionDateInfo.mockReset().mockRejectedValue(new Error('Network down'))

    const { result } = renderHook(() => useExecutionDateInfo(true))

    await waitFor(() => expect(result.current.loading).toBe(false))

    expect(result.current.info).toBeNull()
    expect(result.current.error).toBe('Network down')
  })

  it('falls back to a translated message for non-Error failures', async () => {
    getExecutionDateInfo.mockReset().mockRejectedValue('boom')

    const { result } = renderHook(() => useExecutionDateInfo(true))

    await waitFor(() => expect(result.current.loading).toBe(false))

    expect(result.current.error).toBe(i18n.t('newSettlement.errors.loadExecutionDate'))
    // The fallback is a locale string, not an English literal — a rejection
    // with no Error to quote must still read in the admin's own language.
    expect(result.current.error).not.toBe('newSettlement.errors.loadExecutionDate')
  })

  it('reload() triggers a new request and clears a previous error', async () => {
    getExecutionDateInfo
      .mockReset()
      .mockRejectedValueOnce(new Error('First failure'))
      .mockResolvedValueOnce(sampleInfo)

    const { result } = renderHook(() => useExecutionDateInfo(true))

    await waitFor(() => expect(result.current.loading).toBe(false))
    expect(result.current.error).toBe('First failure')
    expect(getExecutionDateInfo).toHaveBeenCalledTimes(1)

    act(() => {
      result.current.reload()
    })

    expect(result.current.loading).toBe(true)

    await waitFor(() => expect(result.current.loading).toBe(false))

    expect(getExecutionDateInfo).toHaveBeenCalledTimes(2)
    expect(result.current.error).toBeNull()
    expect(result.current.info).toEqual(sampleInfo)
  })
})
