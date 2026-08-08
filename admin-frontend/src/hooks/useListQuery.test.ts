// @vitest-environment jsdom
import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest'
import { act, renderHook } from '@testing-library/react'
import { useListQuery, splitSortValue, type ListQueryRequest } from './useListQuery'

interface Row {
  id: string
}

interface Filters {
  status: 'all' | 'active'
  categoryId: string | null
}

const defaultOptions = {
  initialFilters: { status: 'all', categoryId: null } as Filters,
  initialSortKey: 'created_at' as const,
  initialSortDirection: 'desc' as const,
  initialPageSize: 20,
}

/** Render the hook with a spy fetcher and wait past the (zero) initial debounce. */
async function renderListQuery(
  fetcher: (request: ListQueryRequest<Filters, string>) => Promise<{ items: Row[]; total: number }>,
  overrides: Partial<Parameters<typeof useListQuery<Row, Filters, string>>[0]> = {}
) {
  const view = renderHook(() => useListQuery<Row, Filters, string>({ ...defaultOptions, fetcher, ...overrides }))
  await act(async () => {
    await vi.advanceTimersByTimeAsync(0)
  })
  return view
}

describe('splitSortValue', () => {
  it('splits on the last underscore so multi-word keys survive', () => {
    expect(splitSortValue('created_at_desc')).toEqual({ key: 'created_at', direction: 'desc' })
    expect(splitSortValue('last_name_asc')).toEqual({ key: 'last_name', direction: 'asc' })
    expect(splitSortValue('name_asc')).toEqual({ key: 'name', direction: 'asc' })
  })

  it('treats a value without a direction suffix as a bare key', () => {
    expect(splitSortValue('name')).toEqual({ key: 'name', direction: null })
    expect(splitSortValue('created_at_sideways')).toEqual({ key: 'created_at_sideways', direction: null })
  })
})

describe('useListQuery', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('fetches once on mount with the initial query and publishes items and total', async () => {
    const fetcher = vi.fn().mockResolvedValue({ items: [{ id: 'a' }], total: 42 })
    const { result } = await renderListQuery(fetcher)

    expect(fetcher).toHaveBeenCalledTimes(1)
    expect(fetcher.mock.calls[0][0]).toMatchObject({
      page: 1,
      pageSize: 20,
      sortKey: 'created_at',
      sortDirection: 'desc',
      search: '',
      filters: { status: 'all', categoryId: null },
    })
    expect(result.current.items).toEqual([{ id: 'a' }])
    expect(result.current.total).toBe(42)
    expect(result.current.totalPages).toBe(3)
    expect(result.current.loading).toBe(false)
    expect(result.current.error).toBeNull()
  })

  it('debounces search but not other query changes', async () => {
    const fetcher = vi.fn().mockResolvedValue({ items: [], total: 0 })
    const { result } = await renderListQuery(fetcher)
    fetcher.mockClear()

    act(() => {
      result.current.setSearch('an')
    })
    await act(async () => {
      await vi.advanceTimersByTimeAsync(499)
    })
    expect(fetcher).not.toHaveBeenCalled()

    await act(async () => {
      await vi.advanceTimersByTimeAsync(1)
    })
    expect(fetcher).toHaveBeenCalledTimes(1)
    expect(fetcher.mock.calls[0][0]).toMatchObject({ search: 'an' })

    // A filter change with an empty search box must not wait.
    act(() => {
      result.current.setSearch('')
    })
    await act(async () => {
      await vi.advanceTimersByTimeAsync(0)
    })
    expect(fetcher).toHaveBeenCalledTimes(2)
  })

  it('collapses a burst of keystrokes into a single request', async () => {
    const fetcher = vi.fn().mockResolvedValue({ items: [], total: 0 })
    const { result } = await renderListQuery(fetcher)
    fetcher.mockClear()

    for (const value of ['a', 'an', 'ann']) {
      act(() => {
        result.current.setSearch(value)
      })
      await act(async () => {
        await vi.advanceTimersByTimeAsync(100)
      })
    }
    await act(async () => {
      await vi.advanceTimersByTimeAsync(500)
    })

    expect(fetcher).toHaveBeenCalledTimes(1)
    expect(fetcher.mock.calls[0][0]).toMatchObject({ search: 'ann' })
  })

  it('aborts the superseded request and ignores its late result', async () => {
    const signals: AbortSignal[] = []
    let resolveFirst: ((page: { items: Row[]; total: number }) => void) | undefined

    const fetcher = vi.fn().mockImplementation((request: ListQueryRequest<Filters, string>) => {
      signals.push(request.signal)
      if (signals.length === 1) {
        return new Promise<{ items: Row[]; total: number }>((resolve) => {
          resolveFirst = resolve
        })
      }
      return Promise.resolve({ items: [{ id: 'fresh' }], total: 1 })
    })

    const { result } = renderHook(() => useListQuery<Row, Filters, string>({ ...defaultOptions, fetcher }))
    await act(async () => {
      await vi.advanceTimersByTimeAsync(0)
    })

    act(() => {
      result.current.setFilter('status', 'active')
    })
    await act(async () => {
      await vi.advanceTimersByTimeAsync(0)
    })

    expect(signals[0].aborted).toBe(true)
    expect(signals[1].aborted).toBe(false)
    expect(result.current.items).toEqual([{ id: 'fresh' }])

    // The abandoned request answering late must not overwrite the fresh page.
    await act(async () => {
      resolveFirst?.({ items: [{ id: 'stale' }], total: 999 })
      await vi.advanceTimersByTimeAsync(0)
    })
    expect(result.current.items).toEqual([{ id: 'fresh' }])
    expect(result.current.total).toBe(1)
  })

  it('does not report an aborted request as an error', async () => {
    const fetcher = vi.fn().mockImplementation((request: ListQueryRequest<Filters, string>) =>
      request.filters.status === 'all'
        ? Promise.reject(Object.assign(new Error('canceled'), { code: 'ERR_CANCELED' }))
        : Promise.resolve({ items: [], total: 0 })
    )
    const { result } = await renderListQuery(fetcher)

    expect(result.current.error).toBeNull()
  })

  it.each([
    ['search', (r: ReturnType<typeof useListQuery<Row, Filters, string>>) => r.setSearch('x')],
    ['filter', (r: ReturnType<typeof useListQuery<Row, Filters, string>>) => r.setFilter('status', 'active')],
    ['sort', (r: ReturnType<typeof useListQuery<Row, Filters, string>>) => r.setSort('name', 'asc')],
    ['toggled sort', (r: ReturnType<typeof useListQuery<Row, Filters, string>>) => r.toggleSort('name')],
    ['sort dropdown value', (r: ReturnType<typeof useListQuery<Row, Filters, string>>) => r.setSortValue('name_asc')],
    ['page size', (r: ReturnType<typeof useListQuery<Row, Filters, string>>) => r.setPageSize(50)],
  ])('resets to page 1 when the %s changes', async (_label, change) => {
    const fetcher = vi.fn().mockResolvedValue({ items: [], total: 500 })
    const { result } = await renderListQuery(fetcher)

    act(() => {
      result.current.setPage(5)
    })
    await act(async () => {
      await vi.advanceTimersByTimeAsync(0)
    })
    expect(result.current.page).toBe(5)

    act(() => {
      change(result.current)
    })
    expect(result.current.page).toBe(1)
  })

  it('flips the direction when the same sort key is toggled again', async () => {
    const fetcher = vi.fn().mockResolvedValue({ items: [], total: 0 })
    const { result } = await renderListQuery(fetcher)

    act(() => {
      result.current.toggleSort('name', 'asc')
    })
    expect(result.current.sortKey).toBe('name')
    expect(result.current.sortDirection).toBe('asc')
    expect(result.current.sortValue).toBe('name_asc')

    act(() => {
      result.current.toggleSort('name', 'asc')
    })
    expect(result.current.sortDirection).toBe('desc')
  })

  it('keeps the current direction when a dropdown value carries no suffix', async () => {
    const fetcher = vi.fn().mockResolvedValue({ items: [], total: 0 })
    const { result } = await renderListQuery(fetcher)

    act(() => {
      result.current.setSortValue('name')
    })
    expect(result.current.sortKey).toBe('name')
    expect(result.current.sortDirection).toBe('desc')
  })

  it('reload re-runs the current query, filters and page included', async () => {
    const fetcher = vi.fn().mockResolvedValue({ items: [], total: 500 })
    const { result } = await renderListQuery(fetcher)

    act(() => {
      result.current.setFilter('categoryId', 'cat-1')
    })
    await act(async () => {
      await vi.advanceTimersByTimeAsync(0)
    })
    act(() => {
      result.current.setPage(3)
    })
    await act(async () => {
      await vi.advanceTimersByTimeAsync(0)
    })
    fetcher.mockClear()

    act(() => {
      result.current.reload()
    })
    await act(async () => {
      await vi.advanceTimersByTimeAsync(0)
    })

    expect(fetcher).toHaveBeenCalledTimes(1)
    expect(fetcher.mock.calls[0][0]).toMatchObject({
      page: 3,
      filters: { status: 'all', categoryId: 'cat-1' },
    })
  })

  it('clamps to the last existing page when a reload shrinks the list', async () => {
    let total = 41
    const fetcher = vi.fn().mockImplementation(() => Promise.resolve({ items: [], total }))
    const { result } = await renderListQuery(fetcher)

    act(() => {
      result.current.setPage(3)
    })
    await act(async () => {
      await vi.advanceTimersByTimeAsync(0)
    })
    expect(result.current.page).toBe(3)

    // The last item on page 3 is deleted; the reload finds only two pages left.
    total = 40
    act(() => {
      result.current.reload()
    })
    await act(async () => {
      await vi.advanceTimersByTimeAsync(0)
    })
    // One more tick for the clamp effect's re-query to settle.
    await act(async () => {
      await vi.advanceTimersByTimeAsync(0)
    })

    expect(result.current.page).toBe(2)
    expect(fetcher.mock.calls[fetcher.mock.calls.length - 1][0]).toMatchObject({ page: 2 })
  })

  it('clamps to page 1 when the list becomes empty', async () => {
    let total = 21
    const fetcher = vi.fn().mockImplementation(() => Promise.resolve({ items: [], total }))
    const { result } = await renderListQuery(fetcher)

    act(() => {
      result.current.setPage(2)
    })
    await act(async () => {
      await vi.advanceTimersByTimeAsync(0)
    })

    total = 0
    act(() => {
      result.current.reload()
    })
    await act(async () => {
      await vi.advanceTimersByTimeAsync(0)
    })
    await act(async () => {
      await vi.advanceTimersByTimeAsync(0)
    })

    expect(result.current.page).toBe(1)
  })

  it('surfaces a failed load through parseError and clears the list', async () => {
    const fetcher = vi.fn().mockRejectedValue(new Error('boom'))
    const { result } = await renderListQuery(fetcher, { parseError: (err) => `mapped: ${(err as Error).message}` })

    expect(result.current.error).toBe('mapped: boom')
    expect(result.current.items).toEqual([])
    expect(result.current.total).toBe(0)
    expect(result.current.loading).toBe(false)
  })

  it('falls back to the error message when no parseError is supplied', async () => {
    const { result } = await renderListQuery(vi.fn().mockRejectedValue(new Error('network down')))
    expect(result.current.error).toBe('network down')

    const { result: nonError } = await renderListQuery(vi.fn().mockRejectedValue('not an Error'))
    expect(nonError.current.error).toBe('Failed to load data')
  })

  it('does not fetch again when a filter is re-selected with its current value', async () => {
    const fetcher = vi.fn().mockResolvedValue({ items: [], total: 0 })
    const { result } = await renderListQuery(fetcher)
    fetcher.mockClear()

    act(() => {
      result.current.setFilter('status', 'all')
    })
    await act(async () => {
      await vi.advanceTimersByTimeAsync(0)
    })

    expect(fetcher).not.toHaveBeenCalled()
  })

  it('applies a multi-field filter patch in a single request', async () => {
    const fetcher = vi.fn().mockResolvedValue({ items: [], total: 0 })
    const { result } = await renderListQuery(fetcher)
    fetcher.mockClear()

    act(() => {
      result.current.setFilters({ status: 'active', categoryId: 'cat-9' })
    })
    await act(async () => {
      await vi.advanceTimersByTimeAsync(0)
    })

    expect(fetcher).toHaveBeenCalledTimes(1)
    expect(fetcher.mock.calls[0][0]).toMatchObject({ filters: { status: 'active', categoryId: 'cat-9' } })
  })

  it('never sets a page below 1', async () => {
    const fetcher = vi.fn().mockResolvedValue({ items: [], total: 100 })
    const { result } = await renderListQuery(fetcher)

    act(() => {
      result.current.setPage(0)
    })
    expect(result.current.page).toBe(1)
  })

  it('lets a page publish a mutation failure through the same error banner', async () => {
    const fetcher = vi.fn().mockResolvedValue({ items: [], total: 0 })
    const { result } = await renderListQuery(fetcher)

    act(() => {
      result.current.setError('Failed to delete product')
    })
    expect(result.current.error).toBe('Failed to delete product')
  })

  it('aborts the in-flight request on unmount', async () => {
    const signals: AbortSignal[] = []
    const fetcher = vi.fn().mockImplementation((request: ListQueryRequest<Filters, string>) => {
      signals.push(request.signal)
      return new Promise<{ items: Row[]; total: number }>(() => {})
    })

    const { unmount } = renderHook(() => useListQuery<Row, Filters, string>({ ...defaultOptions, fetcher }))
    await act(async () => {
      await vi.advanceTimersByTimeAsync(0)
    })

    unmount()
    expect(signals[0].aborted).toBe(true)
  })
})
