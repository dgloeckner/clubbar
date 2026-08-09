import { useCallback, useEffect, useMemo, useState } from 'react'
import { getMembers } from '../api/generated/members/members'
import type { CollectionHold, CreditBalance } from '../api/generated'
import { useLatestRequest } from './useLatestRequest'
import i18n from '../i18n/config'

/** One exclusion listing: what it holds, what it sums to, and whether it loaded. */
export interface ExclusionStream<T> {
  items: T[]
  totalCents: number
  /**
   * Non-null when the request failed. Kept separate from an empty `items` so a
   * section can say "could not load" instead of "nobody is excluded" — the two
   * look identical otherwise, and reading a failure as good news is the bug
   * #132 closed everywhere else.
   */
  error: string | null
}

export interface UseExcludedFromCollectionResult {
  credit: ExclusionStream<CreditBalance>
  holds: ExclusionStream<CollectionHold>
  /** True until both listings have settled, however they settle. */
  loading: boolean
  /** How many members the next run will leave out, across both listings. */
  excludedCount: number
  /** Refetch both listings — what the clear-hold mutation calls once it lands. */
  reload: () => void
}

const emptyStream = <T,>(): ExclusionStream<T> => ({ items: [], totalCents: 0, error: null })

/**
 * The two standing exclusion listings behind the Excluded from Collection view.
 *
 * Neither endpoint uses the standard list envelope — both answer with an
 * unpaginated `{ items, total }` and take no sort, filter or page — so
 * `useListQuery` does not apply here. What does apply is the rest of
 * `patterns/data-fetching.md`: each listing gets its own `useLatestRequest`
 * slot, because they are independent streams and one must never abort the
 * other, and every setter is guarded on `signal.aborted` rather than on the
 * abort error (a response that has already arrived resolves normally and
 * throws nothing).
 *
 * The two are fetched together and fail apart. A failure in one leaves the
 * other rendered, because a treasurer who can see the holds is better served
 * than one shown a bare error page.
 */
export function useExcludedFromCollection(): UseExcludedFromCollectionResult {
  const [credit, setCredit] = useState<ExclusionStream<CreditBalance>>(emptyStream)
  const [holds, setHolds] = useState<ExclusionStream<CollectionHold>>(emptyStream)
  const [loading, setLoading] = useState(true)
  const [attempt, setAttempt] = useState(0)

  const creditRequest = useLatestRequest()
  const holdsRequest = useLatestRequest()

  const reload = useCallback(() => setAttempt((n) => n + 1), [])

  useEffect(() => {
    const creditSignal = creditRequest.next()
    const holdsSignal = holdsRequest.next()
    setLoading(true)

    const loadCredit = async () => {
      try {
        const response = await getMembers().listCreditBalances({ signal: creditSignal })
        if (creditSignal.aborted) return
        setCredit({
          items: response.items ?? [],
          totalCents: response.total_credit_cents ?? 0,
          error: null,
        })
      } catch (err) {
        if (creditSignal.aborted) return
        setCredit({
          items: [],
          totalCents: 0,
          error: err instanceof Error ? err.message : i18n.t('excluded.errors.loadCredit'),
        })
      }
    }

    const loadHolds = async () => {
      try {
        const response = await getMembers().listCollectionHolds({ signal: holdsSignal })
        if (holdsSignal.aborted) return
        setHolds({
          items: response.items ?? [],
          totalCents: response.total_held_cents ?? 0,
          error: null,
        })
      } catch (err) {
        if (holdsSignal.aborted) return
        setHolds({
          items: [],
          totalCents: 0,
          error: err instanceof Error ? err.message : i18n.t('excluded.errors.loadHolds'),
        })
      }
    }

    Promise.all([loadCredit(), loadHolds()]).finally(() => {
      // A superseded pair must not clear the spinner the newer one raised.
      if (creditSignal.aborted && holdsSignal.aborted) return
      setLoading(false)
    })

    return () => {
      creditRequest.abort()
      holdsRequest.abort()
    }
  }, [attempt, creditRequest, holdsRequest])

  const excludedCount = credit.items.length + holds.items.length

  return useMemo(
    () => ({ credit, holds, loading, excludedCount, reload }),
    [credit, holds, loading, excludedCount, reload]
  )
}
