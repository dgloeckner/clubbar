/**
 * API Client — Axios instance + custom orval mutator
 *
 * Cross-cutting concerns:
 * - CSRF token management (persisted to localStorage)
 * - Global loading state pub/sub (drives LoadingIndicator)
 * - 401 → redirect to /login
 * - File download helper
 *
 * NOTE: Bearer token auth is NOT used — the API uses cookie-based session auth.
 */

import axios from 'axios'
import type { AxiosRequestConfig } from 'axios'

// ─── CSRF ────────────────────────────────────────────────────────────────────

let csrfToken: string | null = localStorage.getItem('csrf_token')

export function setCsrfToken(token: string | null) {
  csrfToken = token
  if (token) {
    localStorage.setItem('csrf_token', token)
  } else {
    localStorage.removeItem('csrf_token')
  }
}

// ─── Loading state ───────────────────────────────────────────────────────────

let pendingRequests = 0
const loadingStateCallbacks: Array<(isLoading: boolean) => void> = []

export function onLoadingStateChange(callback: (isLoading: boolean) => void): () => void {
  loadingStateCallbacks.push(callback)
  return () => {
    const index = loadingStateCallbacks.indexOf(callback)
    if (index > -1) loadingStateCallbacks.splice(index, 1)
  }
}

function notifyLoadingState() {
  const isLoading = pendingRequests > 0
  loadingStateCallbacks.forEach(cb => cb(isLoading))
}

function incrementPending() {
  const wasLoading = pendingRequests > 0
  pendingRequests++
  if (!wasLoading) notifyLoadingState()
}

function decrementPending() {
  pendingRequests = Math.max(0, pendingRequests - 1)
  notifyLoadingState()
}

// ─── Axios instance ───────────────────────────────────────────────────────────

const axiosInstance = axios.create({
  baseURL: '/api',
  headers: { 'Content-Type': 'application/json' },
  withCredentials: true,
})

axiosInstance.interceptors.request.use(
  (config) => {
    if (csrfToken && config.method && !['get', 'head', 'options'].includes(config.method)) {
      config.headers['X-CSRF-Token'] = csrfToken
    }
    incrementPending()
    return config
  },
  (error) => {
    decrementPending()
    return Promise.reject(error)
  }
)

axiosInstance.interceptors.response.use(
  (response) => {
    decrementPending()
    return response
  },
  (error) => {
    decrementPending()
    if (error.response?.status === 401) {
      localStorage.removeItem('admin_id')
      localStorage.removeItem('email')
      localStorage.removeItem('display_name')
      localStorage.removeItem('locale')
      localStorage.removeItem('csrf_token')
      csrfToken = null
      if (window.location.pathname !== '/login') {
        window.location.href = '/login'
      }
    }
    if (error.response?.status === 403) {
      console.error('Access forbidden')
    }
    if (error.response?.status === 500) {
      console.error('Server error:', error.response.data)
    }
    return Promise.reject(error)
  }
)

// ─── orval mutator ────────────────────────────────────────────────────────────

export const customInstance = <T>(
  config: AxiosRequestConfig,
  options?: { signal?: AbortSignal }
): Promise<T> =>
  axiosInstance({ ...config, signal: options?.signal }).then(({ data }) => data)

// ─── File download ────────────────────────────────────────────────────────────

export async function downloadFile(url: string, fallbackFilename: string): Promise<void> {
  const response = await axiosInstance.get(url, { responseType: 'blob' })
  const contentDisposition = response.headers['content-disposition']
  let filename = fallbackFilename
  if (contentDisposition) {
    const match = contentDisposition.match(/filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/)
    if (match?.[1]) filename = match[1].replace(/['"]/g, '')
  }
  const blob = new Blob([response.data])
  const objectUrl = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = objectUrl
  a.download = filename
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(objectUrl)
}

export default axiosInstance
