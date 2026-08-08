import { describe, expect, it, vi } from 'vitest'
import { MAX_PER_PAGE, loadAllPages, type PaginatedBody } from './pagination'

const page = <T,>(data: T[], total_pages: number, pageNo: number): PaginatedBody<T> => ({
  data,
  pagination: { page: pageNo, per_page: MAX_PER_PAGE, total: data.length * total_pages, total_pages },
})

describe('MAX_PER_PAGE', () => {
  it('matches the cap the API enforces', () => {
    expect(MAX_PER_PAGE).toBe(100)
  })
})

describe('loadAllPages', () => {
  it('returns a single page without asking for a second', async () => {
    const fetchPage = vi.fn(async () => page(['a', 'b'], 1, 1))

    expect(await loadAllPages(fetchPage)).toEqual(['a', 'b'])
    expect(fetchPage).toHaveBeenCalledTimes(1)
    expect(fetchPage).toHaveBeenCalledWith(1)
  })

  it('concatenates every page in order', async () => {
    const pages = [page(['a'], 3, 1), page(['b'], 3, 2), page(['c'], 3, 3)]
    const fetchPage = vi.fn(async (n: number) => pages[n - 1])

    expect(await loadAllPages(fetchPage)).toEqual(['a', 'b', 'c'])
    expect(fetchPage.mock.calls.map(([n]) => n)).toEqual([1, 2, 3])
  })

  it('returns an empty array when the list is empty', async () => {
    expect(await loadAllPages(async () => page([], 0, 1))).toEqual([])
  })

  it('tolerates a response with no data key', async () => {
    expect(await loadAllPages(async () => ({}))).toEqual([])
  })

  it('stops after one page when the server omits total_pages', async () => {
    const fetchPage = vi.fn(async () => ({ data: ['a'] }))

    expect(await loadAllPages(fetchPage)).toEqual(['a'])
    expect(fetchPage).toHaveBeenCalledTimes(1)
  })

  it('stops on an empty page even if more are claimed', async () => {
    const fetchPage = vi.fn(async (n: number) => (n === 1 ? page(['a'], 99, 1) : page([], 99, n)))

    expect(await loadAllPages(fetchPage)).toEqual(['a'])
    expect(fetchPage).toHaveBeenCalledTimes(2)
  })

  it('gives up rather than looping forever on a server that always claims more', async () => {
    const fetchPage = vi.fn(async (n: number) => page(['x'], 10_000, n))

    const result = await loadAllPages(fetchPage)

    expect(result).toHaveLength(100)
    expect(fetchPage).toHaveBeenCalledTimes(100)
  })

  it('propagates a failure instead of returning a partial list', async () => {
    const fetchPage = async (n: number) => {
      if (n === 2) throw new Error('boom')
      return page(['a'], 3, n)
    }

    await expect(loadAllPages(fetchPage)).rejects.toThrow('boom')
  })
})
