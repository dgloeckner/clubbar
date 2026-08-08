/**
 * Helpers for the one list-response envelope the Admin API returns:
 * `{ data: [...], pagination: { page, per_page, total, total_pages } }`.
 */

/**
 * The largest `per_page` the API accepts. Asking for more is answered with
 * `400 invalid_request` rather than silently clamped, so screens that want
 * "everything" page through instead of guessing a big number.
 */
export const MAX_PER_PAGE = 100

/** Guard against a server that keeps claiming there is another page. */
const PAGE_LIMIT = 100

export interface PaginatedBody<T> {
  data?: T[]
  pagination?: {
    page?: number
    per_page?: number
    total?: number
    total_pages?: number
  }
}

/**
 * Collect every page of a list endpoint into one array.
 *
 * `fetchPage` is called with 1-indexed page numbers and must request
 * `MAX_PER_PAGE` rows; iteration stops at `total_pages`, at the first empty
 * page, or at PAGE_LIMIT pages, whichever comes first.
 */
export async function loadAllPages<T>(
  fetchPage: (page: number) => Promise<PaginatedBody<T>>,
): Promise<T[]> {
  const collected: T[] = []

  for (let page = 1; page <= PAGE_LIMIT; page++) {
    const body = await fetchPage(page)
    const rows = body.data ?? []

    collected.push(...rows)

    const totalPages = body.pagination?.total_pages
    if (rows.length === 0 || totalPages === undefined || page >= totalPages) {
      break
    }
  }

  return collected
}
