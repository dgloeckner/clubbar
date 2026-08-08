import { createContext, useContext, useEffect, useState, ReactNode } from 'react'
import { onLoadingStateChange } from '../api/client'

/**
 * Global loading state.
 *
 * The API client already counts in-flight requests and publishes the result;
 * this provider is the single subscriber that turns those notifications into
 * React state for `LoadingIndicator`. Before #122 nothing bridged the two, so
 * the counter ran with no effect while each page pushed the indicator around by
 * hand — and the pages that forgot to (Categories, Products, Journal, Reports,
 * Settlements) simply never showed it.
 *
 * There is deliberately no setter: a page that wants the bar should make a
 * request, and every request already shows it.
 */
interface LoadingContextType {
  isLoading: boolean
}

const LoadingContext = createContext<LoadingContextType | undefined>(undefined)

export function LoadingProvider({ children }: { children: ReactNode }) {
  const [isLoading, setIsLoading] = useState(false)

  useEffect(() => onLoadingStateChange(setIsLoading), [])

  return <LoadingContext.Provider value={{ isLoading }}>{children}</LoadingContext.Provider>
}

export function useLoading(): LoadingContextType {
  const context = useContext(LoadingContext)
  if (!context) {
    throw new Error('useLoading must be used within LoadingProvider')
  }
  return context
}
