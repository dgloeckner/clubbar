import { useCallback, useEffect, useState } from 'react'
import { useLocation } from 'react-router-dom'

import { getRegistrationReview } from '../api/generated/registration-review/registration-review'
import type { AdminRole } from '../api/generated/adminRole'
import { permitsPath } from '../utils/adminRoles'
import { useLatestRequest } from './useLatestRequest'

/**
 * How many submissions are waiting, for the nav badge (#782).
 *
 * ## Why it asks for one row
 *
 * The count lives in the pagination envelope, so `per_page=1` gets it for the
 * price of a single row. Asking for a page of twenty to count them would move
 * twenty people's names and masked IBANs into a layout component that has no
 * business holding any.
 *
 * ## Why it is silent about failure
 *
 * A badge is an invitation, not a fact the panel depends on. If the request
 * fails the count stays at zero and the section is still there to open — a
 * layout that surfaced an error banner because a decoration could not load
 * would be worse than the decoration missing.
 *
 * ## Roles
 *
 * Gated on the same `permitsPath` that decides whether the entry is rendered at
 * all. Without that a Getränkewart would fire a request on every navigation
 * that answers 403 by design, which is noise in their logs and ours.
 */
export function usePendingRegistrationCount(roles: AdminRole[] | undefined): number {
  const [count, setCount] = useState(0)
  const location = useLocation()
  const request = useLatestRequest()

  const permitted = permitsPath(roles ?? [], '/registrations')

  const refresh = useCallback(async () => {
    if (!permitted) {
      setCount(0)
      return
    }

    const signal = request.next()

    try {
      const page = await getRegistrationReview().listRegistrations(
        { per_page: 1 },
        { signal }
      )
      if (signal.aborted) return

      setCount(page.pagination?.total ?? 0)
    } catch {
      // Deliberately quiet — see the docblock.
      if (!signal.aborted) setCount(0)
    }
  }, [permitted, request])

  // Re-read on every navigation rather than on a timer: the number changes when
  // an admin approves or rejects something, and leaving the inbox is exactly
  // when they did. A poll would spend requests on the far more common case of
  // nobody touching the queue at all.
  useEffect(() => {
    void refresh()
  }, [refresh, location.pathname])

  return count
}
