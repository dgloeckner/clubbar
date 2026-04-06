/**
 * useBankName — resolve IBAN → bank name via backend lookup.
 *
 * Debounces the request (400ms) and only queries for valid German IBANs (DE, ≥ 12 chars).
 * Returns the bank name string or null while loading / for non-German IBANs.
 */

import { useEffect, useState, useRef } from 'react'
import { validateIban } from '../utils/iban'
import axiosInstance from '../api/client'

export function useBankName(iban: string): string | null {
  const [bankName, setBankName] = useState<string | null>(null)
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const abortRef = useRef<AbortController | null>(null)

  useEffect(() => {
    // Clear previous timer
    if (timerRef.current) clearTimeout(timerRef.current)
    // Abort previous request
    if (abortRef.current) abortRef.current.abort()

    const normalized = iban.replace(/\s/g, '').toUpperCase()

    // Only look up valid German IBANs
    if (!normalized.startsWith('DE') || normalized.length < 12 || !validateIban(normalized)) {
      setBankName(null)
      return
    }

    timerRef.current = setTimeout(() => {
      const controller = new AbortController()
      abortRef.current = controller

      axiosInstance
        .get('/admin/bank-lookup', {
          params: { iban: normalized },
          signal: controller.signal,
        })
        .then((res: { data?: { bank_name?: string | null } }) => {
          setBankName(res.data?.bank_name ?? null)
        })
        .catch((err: { name?: string }) => {
          if (err?.name !== 'CanceledError') {
            setBankName(null)
          }
        })
    }, 400)

    return () => {
      if (timerRef.current) clearTimeout(timerRef.current)
      if (abortRef.current) abortRef.current.abort()
    }
  }, [iban])

  return bankName
}
