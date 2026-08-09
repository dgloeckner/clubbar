import { useCallback, useEffect, useMemo, useState } from 'react'
import { getMembers } from '../api/generated/members/members'
import type { CollectionHold, CreditBalance, MandateMissing } from '../api/generated'
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
  noMandate: ExclusionStream<MandateMissing>
  /** True until every listing has settled, however each settles. */
  loading: boolean
  /** How many members the next run will leave out, across all three listings. */
  excludedCount: number
  /** Refetch all three — what the clear-hold mutation calls once it lands. */
  reload: () => void
}

const emptyStream = <T,>(): ExclusionStream<T> => ({ items: [], totalCents: 0, error: null })

/**
 * The three standing exclusion listings behind the Excluded from Collection
 * view: members in credit, members on hold, and members with no usable
 * mandate (#258).
 *
 * No endpoint uses the standard list envelope — each answers with an
 * unpaginated `{ items, total }` and takes no sort, filter or page — so
 * `useListQuery` does not apply here. What does apply is the rest of
 * `patterns/data-fetching.md`: each listing gets its own `useLatestRequest`
 * slot, because they are independent streams and one must never abort the
 * other, and every setter is guarded on `signal.aborted` rather than on the
 * abort error (a response that has already arrived resolves normally and
 * throws nothing).
 *
 * The three are fetched together and fail apart. A failure in one leaves the
 * others rendered, because a treasurer who can see two of the three is better
 * served than one shown a bare error page.
 */
export function useExcludedFromCollection(): UseExcludedFromCollectionResult {
  const [credit, setCredit] = useState<ExclusionStream<CreditBalance>>(emptyStream)
  const [holds, setHolds] = useState<ExclusionStream<CollectionHold>>(emptyStream)
  const [noMandate, setNoMandate] = useState<ExclusionStream<MandateMissing>>(emptyStream)
  const [loading, setLoading] = useState(true)
  const [attempt, setAttempt] = useState(0)

  const creditRequest = useLatestRequest()
  const holdsRequest = useLatestRequest()
  const noMandateRequest = useLatestRequest()

  const reload = useCallback(() => setAttempt((n) => n + 1), [])

  useEffect(() => {
    const creditSignal = creditRequest.next()
    const holdsSignal = holdsRequest.next()
    const noMandateSignal = noMandateRequest.next()
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

    const loadNoMandate = async () => {
      try {
        const response = await getMembers().listMembersWithoutMandate({ signal: noMandateSignal })
        if (noMandateSignal.aborted) return
        setNoMandate({
          items: response.items ?? [],
          totalCents: response.total_uncollectable_cents ?? 0,
          error: null,
        })
      } catch (err) {
        if (noMandateSignal.aborted) return
        setNoMandate({
          items: [],
          totalCents: 0,
          error: err instanceof Error ? err.message : i18n.t('excluded.errors.loadNoMandate'),
        })
      }
    }

    Promise.all([loadCredit(), loadHolds(), loadNoMandate()]).finally(() => {
      // A superseded set must not clear the spinner the newer one raised.
      if (creditSignal.aborted && holdsSignal.aborted && noMandateSignal.aborted) return
      setLoading(false)
    })

    return () => {
      creditRequest.abort()
      holdsRequest.abort()
      noMandateRequest.abort()
    }
  }, [attempt, creditRequest, holdsRequest, noMandateRequest])

  const excludedCount = credit.items.length + holds.items.length + noMandate.items.length

  return useMemo(
    () => ({ credit, holds, noMandate, loading, excludedCount, reload }),
    [credit, holds, noMandate, loading, excludedCount, reload]
  )
}
