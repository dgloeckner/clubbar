import type { ListMembersSortBy } from '../api/generated'

/** The columns the member list can be sorted by. */
export type MemberSortKey = 'last_name' | 'card_uid' | 'balance' | 'created_at'

/**
 * Translate the list's sort key and direction into the API's `sort_by` value.
 *
 * The Name column orders by the API's single `name` field (last name, then
 * first name); every other key is sent as-is. Nothing is rewritten on the way
 * out: the members list used to collapse every non-name sort to
 * `created_at_desc`, so clicking "Card-UID" put an ascending arrow on the
 * header while the rows stayed newest-first (#125).
 */
export function buildMemberSortBy(key: MemberSortKey, direction: 'asc' | 'desc'): ListMembersSortBy {
  const field = key === 'last_name' ? 'name' : key

  return `${field}_${direction}` as ListMembersSortBy
}
