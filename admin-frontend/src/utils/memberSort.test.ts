import { describe, it, expect } from 'vitest'
import { ListMembersSortBy } from '../api/generated'
import { buildMemberSortBy } from './memberSort'

describe('buildMemberSortBy', () => {
  it('sends the Name column as the API name field, in both directions', () => {
    expect(buildMemberSortBy('last_name', 'asc')).toBe('name_asc')
    expect(buildMemberSortBy('last_name', 'desc')).toBe('name_desc')
  })

  // The #125 regression: every non-name key used to come out as
  // `created_at_desc`, so the header arrow and the row order disagreed.
  it('keeps the card UID sort, rather than collapsing it to a date sort', () => {
    expect(buildMemberSortBy('card_uid', 'asc')).toBe('card_uid_asc')
    expect(buildMemberSortBy('card_uid', 'desc')).toBe('card_uid_desc')
  })

  it('sorts oldest first when the date column asks for it', () => {
    expect(buildMemberSortBy('created_at', 'asc')).toBe('created_at_asc')
    expect(buildMemberSortBy('created_at', 'desc')).toBe('created_at_desc')
  })

  it('only produces values the generated API client accepts', () => {
    const allowed = Object.values(ListMembersSortBy)

    for (const key of ['last_name', 'card_uid', 'created_at'] as const) {
      for (const direction of ['asc', 'desc'] as const) {
        expect(allowed).toContain(buildMemberSortBy(key, direction))
      }
    }
  })
})
