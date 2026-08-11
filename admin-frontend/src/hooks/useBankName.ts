/**
 * useBankName — resolve IBAN → bank name via backend lookup.
 *
 * Debounces the request (400ms) and only queries for valid German IBANs (DE, ≥ 12 chars).
 * `isLoading` is true only while a lookup is pending (debounce or in-flight request), so
 * callers can tell "still searching" apart from "searched, found nothing" (both leave
 * bankName as null).
 */

import { useEffect, useState, useRef } from 'react'
import { validateIban } from '../utils/iban'
import axiosInstance from '../api/client'

export interface UseBankNameResult {
  bankName: string | null
  isLoading: boolean
}

export function useBankName(iban: string): UseBankNameResult {
  const [bankName, setBankName] = useState<string | null>(null)
  const [isLoading, setIsLoading] = useState(false)
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
      setIsLoading(false)
      return
    }

    setIsLoading(true)

    timerRef.current = setTimeout(() => {
      const controller = new AbortController()
      abortRef.current = controller

      // Send only the BLZ (positions 4-11 of a German IBAN), never the
      // full IBAN - query strings end up in access logs.
      axiosInstance
        .get('/admin/bank-lookup', {
          params: { blz: normalized.substring(4, 12) },
          signal: controller.signal,
        })
        .then((res: { data?: { bank_name?: string | null } }) => {
          setBankName(res.data?.bank_name ?? null)
          setIsLoading(false)
        })
        .catch((err: { name?: string }) => {
          if (err?.name !== 'CanceledError') {
            setBankName(null)
            setIsLoading(false)
          }
        })
    }, 400)

    return () => {
      if (timerRef.current) clearTimeout(timerRef.current)
      if (abortRef.current) abortRef.current.abort()
    }
  }, [iban])

  return { bankName, isLoading }
}
