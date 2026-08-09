import { useCallback, useEffect, useState } from 'react'
import { getSettlements } from '../api/generated/settlements/settlements'
import type { ExecutionDateInfo } from '../api/generated'
import i18n from '../i18n/config'

interface UseExecutionDateInfoResult {
  info: ExecutionDateInfo | null
  loading: boolean
  error: string | null
  reload: () => void
}

/**
 * Fetches the earliest valid SEPA execution date from the backend.
 *
 * The rule — today + 7 calendar days, rolled to the next TARGET2 business day —
 * deliberately lives on the server only (ADR-0009). Computing it here as well
 * would mean maintaining the Easter calculation in two languages, so callers
 * must render `info.minimum_date` and block submission while it is unavailable
 * rather than falling back to a locally derived date.
 *
 * @param enabled Skip the request until the value is actually needed.
 */
export function useExecutionDateInfo(enabled: boolean = true): UseExecutionDateInfoResult {
  const [info, setInfo] = useState<ExecutionDateInfo | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [attempt, setAttempt] = useState(0)

  const reload = useCallback(() => setAttempt((n) => n + 1), [])

  useEffect(() => {
    if (!enabled) return

    let cancelled = false
    setLoading(true)
    setError(null)

    getSettlements()
      .getExecutionDateInfo()
      .then((result) => {
        if (!cancelled) setInfo(result)
      })
      .catch((err: unknown) => {
        if (!cancelled) {
          setInfo(null)
          setError(err instanceof Error ? err.message : i18n.t('newSettlement.errors.loadExecutionDate'))
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [enabled, attempt])

  return { info, loading, error, reload }
}
