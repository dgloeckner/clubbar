/**
 * useListQuery — the single list-state implementation shared by every list page.
 *
 * Every list page needs the same seven things: a page, a page size, a sort key
 * and direction, a filter set, a search box, a debounce on that search box, and
 * a fetch that publishes items plus a total. Six pages used to hand-roll all of
 * it, which is why the same three bugs kept being filed separately (#121):
 *
 * - a reload after a mutation dropped the filters, because the mutation handler
 *   re-fetched with a different parameter set than the loader effect,
 * - a slow response for an abandoned query overwrote a newer one, because
 *   nothing aborted the in-flight request,
 * - the desktop sort headers left `page` where it was while the mobile sort
 *   dropdown reset it, so sorting from a desktop table could land on a page the
 *   new ordering does not have.
 *
 * Each of those has exactly one home here: `reload()` re-runs the *current*
 * query, every run owns an AbortController, and every setter that changes what
 * the list contains funnels through a page reset.
 */

import { useCallback, useEffect, useRef, useState } from 'react'
import { useLatestRequest } from './useLatestRequest'
import i18n from '../i18n/config'

export type SortDirection = 'asc' | 'desc'

/** The full query, handed to the fetcher on every run. */
export interface ListQueryRequest<TFilters, TSortKey extends string> {
  page: number
  pageSize: number
  sortKey: TSortKey
  sortDirection: SortDirection
  search: string
  filters: TFilters
  /** Aborted when the query is superseded or the component unmounts. */
  signal: AbortSignal
}

/** What a fetcher returns: the page of items and the unpaginated total. */
export interface ListQueryPage<TItem> {
  items: TItem[]
  total: number
}

export interface UseListQueryOptions<TItem, TFilters, TSortKey extends string> {
  /**
   * Runs the query. Pass `request.signal` down to the API call so an abandoned
   * request is actually cancelled rather than merely ignored.
   *
   * Kept in a ref internally, so it does not need to be memoized by the caller.
   */
  fetcher: (request: ListQueryRequest<TFilters, TSortKey>) => Promise<ListQueryPage<TItem>>
  initialFilters: TFilters
  initialSortKey: TSortKey
  initialSortDirection?: SortDirection
  initialPageSize?: number
  /** Delay applied only while the search box is non-empty. */
  searchDebounceMs?: number
  /** Turns a rejected fetch into the message shown to the admin. */
  parseError?: (error: unknown) => string
}

export interface UseListQueryResult<TItem, TFilters, TSortKey extends string> {
  items: TItem[]
  total: number
  totalPages: number
  loading: boolean
  error: string | null
  /** Lets a page surface a mutation failure in the same banner as load failures. */
  setError: (message: string | null) => void

  page: number
  setPage: (page: number) => void
  pageSize: number
  setPageSize: (size: number) => void

  sortKey: TSortKey
  sortDirection: SortDirection
  /** `"<key>_<direction>"`, the value shape the mobile sort dropdown uses. */
  sortValue: string
  setSort: (key: TSortKey, direction: SortDirection) => void
  /** Same key flips the direction; a new key adopts `defaultDirection`. */
  toggleSort: (key: TSortKey, defaultDirection?: SortDirection) => void
  /** Accepts a `"<key>_<direction>"` value straight from the sort dropdown. */
  setSortValue: (value: string) => void

  search: string
  setSearch: (value: string) => void

  filters: TFilters
  setFilter: <K extends keyof TFilters>(key: K, value: TFilters[K]) => void
  setFilters: (patch: Partial<TFilters>) => void

  /**
   * Re-runs the current query — used after a create/update/delete.
   *
   * Issues the request immediately rather than scheduling one, and resolves
   * once the new page is in state, so `await reload()` in a mutation handler
   * leaves the list up to date. Deferring it to an effect instead let the
   * pending-request count reach zero between the mutation and the reload, and
   * anything watching the global loading indicator to know the list had
   * settled — the E2E suite does exactly that — saw a list that had not.
   */
  reload: () => Promise<void>
}

const DEFAULT_DEBOUNCE_MS = 500
const DEFAULT_PAGE_SIZE = 20

/**
 * Split a `"<key>_<direction>"` sort value on its *last* underscore, so keys
 * that themselves contain underscores (`created_at`, `last_name`) survive.
 * A value with no recognizable direction suffix is treated as a bare key.
 */
export function splitSortValue<TSortKey extends string>(
  value: string
): { key: TSortKey; direction: SortDirection | null } {
  const lastUnderscore = value.lastIndexOf('_')
  if (lastUnderscore === -1) return { key: value as TSortKey, direction: null }

  const direction = value.slice(lastUnderscore + 1)
  if (direction !== 'asc' && direction !== 'desc') {
    return { key: value as TSortKey, direction: null }
  }
  return { key: value.slice(0, lastUnderscore) as TSortKey, direction }
}

/** True for the rejection axios/fetch produce when a request is aborted. */
function isAbortError(error: unknown): boolean {
  if (typeof error !== 'object' || error === null) return false
  const { name, code } = error as { name?: string; code?: string }
  return name === 'AbortError' || name === 'CanceledError' || code === 'ERR_CANCELED'
}

/**
 * Last-resort wording when a page passes no `parseError` of its own. Read off
 * the shared i18n instance rather than `useTranslation`, because the message is
 * produced inside the fetch rejection handler, not during render.
 */
function defaultParseError(error: unknown): string {
  return error instanceof Error ? error.message : i18n.t('errors.loadData')
}

export function useListQuery<TItem, TFilters extends object, TSortKey extends string>(
  options: UseListQueryOptions<TItem, TFilters, TSortKey>
): UseListQueryResult<TItem, TFilters, TSortKey> {
  const {
    initialFilters,
    initialSortKey,
    initialSortDirection = 'desc',
    initialPageSize = DEFAULT_PAGE_SIZE,
    searchDebounceMs = DEFAULT_DEBOUNCE_MS,
  } = options

  // The fetcher and the error mapper are read through refs so a page can pass
  // inline closures without re-running the query on every render.
  const fetcherRef = useRef(options.fetcher)
  fetcherRef.current = options.fetcher
  const parseErrorRef = useRef(options.parseError ?? defaultParseError)
  parseErrorRef.current = options.parseError ?? defaultParseError

  const [items, setItems] = useState<TItem[]>([])
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const [page, setPageState] = useState(1)
  const [pageSize, setPageSizeState] = useState(initialPageSize)
  const [sort, setSortState] = useState<{ key: TSortKey; direction: SortDirection }>({
    key: initialSortKey,
    direction: initialSortDirection,
  })
  const [search, setSearchState] = useState('')
  const [filters, setFiltersState] = useState<TFilters>(initialFilters)

  // The live query, readable by `runQuery` without making it a dependency of
  // anything — `runQuery` has to stay identity-stable so the effect below is
  // driven by the query alone.
  const queryRef = useRef({ page, pageSize, sort, search, filters })
  queryRef.current = { page, pageSize, sort, search, filters }

  // One abort slot for the list stream, shared with every other page that has
  // to stop rendering the answer to a question it no longer asks (#96).
  const request = useLatestRequest()

  // The single fetch path. The loader effect and every explicit reload call
  // this, so there is no second implementation that could drift from it.
  const runQuery = useCallback(async () => {
    // Whatever was in flight is now answering a question nobody is asking.
    const signal = request.next()

    const { page, pageSize, sort, search, filters } = queryRef.current
    setLoading(true)
    try {
      const result = await fetcherRef.current({
        page,
        pageSize,
        sortKey: sort.key,
        sortDirection: sort.direction,
        search,
        filters,
        signal,
      })
      // A superseded query must not publish its result over the newer one.
      // The flag, not a thrown error, is the reliable signal: a response that
      // had already arrived when the abort landed resolves normally (#96).
      if (signal.aborted) return
      setItems(result.items)
      setTotal(result.total)
      setError(null)
    } catch (err) {
      if (signal.aborted || isAbortError(err)) return
      setItems([])
      setTotal(0)
      setError(parseErrorRef.current(err))
    } finally {
      // Whoever aborts owns the spinner: a superseded request must not clear
      // the one the request that replaced it just raised.
      if (!signal.aborted) setLoading(false)
    }
  }, [request])

  useEffect(() => {
    // Typing in the search box fires a request per keystroke without this. The
    // abort lives in the cleanup rather than inside the timer, so a query typed
    // and then cleared is cancelled the moment it stops being the current one —
    // waiting for the new timer to fire is too late (#96).
    const timer = setTimeout(runQuery, search ? searchDebounceMs : 0)

    return () => {
      clearTimeout(timer)
      request.abort()
    }
  }, [page, pageSize, sort, search, filters, searchDebounceMs, runQuery, request])

  const totalPages = pageSize > 0 ? Math.ceil(total / pageSize) : 0

  // Deleting the last item on the last page leaves the admin on an out-of-range
  // page — and the pagination control hides itself when the list is empty, so
  // there is no way back. Clamp instead, which re-runs the query on the page
  // that now exists.
  useEffect(() => {
    if (loading || error !== null) return
    const lastPage = Math.max(1, totalPages)
    if (page > lastPage) setPageState(lastPage)
  }, [loading, error, page, totalPages])

  const setPage = useCallback((next: number) => {
    setPageState(Math.max(1, Math.floor(next)))
  }, [])

  // Everything below changes *what* the list contains, so page 1 is the only
  // page guaranteed to exist afterwards. Resetting here is what makes the
  // desktop sort headers and the mobile sort dropdown agree.
  const setPageSize = useCallback((size: number) => {
    setPageSizeState(size)
    setPageState(1)
  }, [])

  const setSort = useCallback((key: TSortKey, direction: SortDirection) => {
    setSortState({ key, direction })
    setPageState(1)
  }, [])

  const toggleSort = useCallback((key: TSortKey, defaultDirection: SortDirection = 'desc') => {
    setSortState((prev) =>
      prev.key === key
        ? { key, direction: prev.direction === 'asc' ? 'desc' : 'asc' }
        : { key, direction: defaultDirection }
    )
    setPageState(1)
  }, [])

  const setSortValue = useCallback((value: string) => {
    const { key, direction } = splitSortValue<TSortKey>(value)
    setSortState((prev) => ({ key, direction: direction ?? prev.direction }))
    setPageState(1)
  }, [])

  const setSearch = useCallback((value: string) => {
    setSearchState(value)
    setPageState(1)
  }, [])

  const setFilter = useCallback(<K extends keyof TFilters>(key: K, value: TFilters[K]) => {
    // Keeping the previous object identity when nothing changed avoids a
    // redundant round-trip when an already-selected filter pill is clicked.
    setFiltersState((prev) => (Object.is(prev[key], value) ? prev : { ...prev, [key]: value }))
    setPageState(1)
  }, [])

  const setFilters = useCallback((patch: Partial<TFilters>) => {
    setFiltersState((prev) => ({ ...prev, ...patch }))
    setPageState(1)
  }, [])

  // Not debounced and not deferred: a reload follows a mutation the admin has
  // already committed, so it neither waits on the search box nor on a render.
  const reload = useCallback(() => runQuery(), [runQuery])

  return {
    items,
    total,
    totalPages,
    loading,
    error,
    setError,
    page,
    setPage,
    pageSize,
    setPageSize,
    sortKey: sort.key,
    sortDirection: sort.direction,
    sortValue: `${sort.key}_${sort.direction}`,
    setSort,
    toggleSort,
    setSortValue,
    search,
    setSearch,
    filters,
    setFilter,
    setFilters,
    reload,
  }
}
