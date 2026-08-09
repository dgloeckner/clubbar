// @vitest-environment jsdom
import { describe, expect, it, vi } from 'vitest'
import { act, renderHook, waitFor } from '@testing-library/react'
import { useExcludedFromCollection } from './useExcludedFromCollection'

const listCreditBalances = vi.hoisted(() => vi.fn())
const listCollectionHolds = vi.hoisted(() => vi.fn())

vi.mock('../api/generated/members/members', () => ({
  getMembers: () => ({ listCreditBalances, listCollectionHolds }),
}))

const creditResponse = {
  items: [
    { member_id: 'c1', first_name: 'Anneliese', last_name: 'Brandt', balance_cents: -2450 },
    { member_id: 'c2', first_name: 'Tobias', last_name: 'Kern', balance_cents: -1200 },
  ],
  total_credit_cents: -3650,
}

const holdsResponse = {
  items: [{ member_id: 'h1', first_name: 'Henrik', last_name: 'Lorenz', balance_cents: 3820 }],
  total_held_cents: 3820,
}

function mockBoth() {
  listCreditBalances.mockReset().mockResolvedValue(creditResponse)
  listCollectionHolds.mockReset().mockResolvedValue(holdsResponse)
}

describe('useExcludedFromCollection', () => {
  it('loads both listings and counts the members either one excludes', async () => {
    mockBoth()

    const { result } = renderHook(() => useExcludedFromCollection())
    expect(result.current.loading).toBe(true)

    await waitFor(() => expect(result.current.loading).toBe(false))

    expect(result.current.credit.items).toHaveLength(2)
    expect(result.current.credit.totalCents).toBe(-3650)
    expect(result.current.credit.error).toBeNull()

    expect(result.current.holds.items).toHaveLength(1)
    expect(result.current.holds.totalCents).toBe(3820)
    expect(result.current.holds.error).toBeNull()

    expect(result.current.excludedCount).toBe(3)
  })

  it('passes an abort signal to each listing, one slot per stream', async () => {
    mockBoth()

    const { result } = renderHook(() => useExcludedFromCollection())
    await waitFor(() => expect(result.current.loading).toBe(false))

    const creditSignal = listCreditBalances.mock.calls[0][0].signal as AbortSignal
    const holdsSignal = listCollectionHolds.mock.calls[0][0].signal as AbortSignal

    expect(creditSignal).toBeInstanceOf(AbortSignal)
    expect(holdsSignal).toBeInstanceOf(AbortSignal)
    // Independent streams: one must never abort the other.
    expect(creditSignal).not.toBe(holdsSignal)
  })

  it('reports a failed listing as an error, never as an empty one', async () => {
    // The distinction #132 exists for: "could not load" and "nobody is
    // excluded" render identically unless the hook keeps them apart.
    listCreditBalances.mockReset().mockRejectedValue(new Error('Network down'))
    listCollectionHolds.mockReset().mockResolvedValue(holdsResponse)

    const { result } = renderHook(() => useExcludedFromCollection())
    await waitFor(() => expect(result.current.loading).toBe(false))

    expect(result.current.credit.error).toBe('Network down')
    expect(result.current.credit.items).toEqual([])

    // The listing that did load is still rendered — one failure does not
    // blank the other half of the page.
    expect(result.current.holds.error).toBeNull()
    expect(result.current.holds.items).toHaveLength(1)
  })

  it('refetches both listings on reload', async () => {
    mockBoth()

    const { result } = renderHook(() => useExcludedFromCollection())
    await waitFor(() => expect(result.current.loading).toBe(false))

    expect(listCreditBalances).toHaveBeenCalledTimes(1)
    expect(listCollectionHolds).toHaveBeenCalledTimes(1)

    // What the clear-hold mutation calls once it lands: the row is gone from
    // one listing and the totals in both tiles have to follow.
    act(() => result.current.reload())
    await waitFor(() => expect(listCreditBalances).toHaveBeenCalledTimes(2))
    expect(listCollectionHolds).toHaveBeenCalledTimes(2)
  })

  it('aborts both in-flight listings on unmount', async () => {
    mockBoth()

    const { result, unmount } = renderHook(() => useExcludedFromCollection())
    await waitFor(() => expect(result.current.loading).toBe(false))

    const creditSignal = listCreditBalances.mock.calls[0][0].signal as AbortSignal
    const holdsSignal = listCollectionHolds.mock.calls[0][0].signal as AbortSignal
    expect(creditSignal.aborted).toBe(false)

    unmount()

    expect(creditSignal.aborted).toBe(true)
    expect(holdsSignal.aborted).toBe(true)
  })
})
