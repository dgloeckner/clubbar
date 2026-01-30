/**
 * API Client Configuration & Interceptors
 * Handles all HTTP requests to the backend API
 *
 * Global loading state:
 * - Tracks pending requests with counter
 * - Emits loading state changes for UI
 * - Used by loading indicator and tests for deterministic wait behavior
 */

import axios, { AxiosInstance, AxiosError, AxiosResponse } from 'axios'
import { ApiResponse } from '../types'

// Global pending request counter
let pendingRequests = 0
const loadingStateCallbacks: Array<(isLoading: boolean) => void> = []

/**
 * Subscribe to loading state changes
 * Used by UI components to show/hide loading indicator
 */
export function onLoadingStateChange(callback: (isLoading: boolean) => void): () => void {
  loadingStateCallbacks.push(callback)
  // Return unsubscribe function
  return () => {
    const index = loadingStateCallbacks.indexOf(callback)
    if (index > -1) {
      loadingStateCallbacks.splice(index, 1)
    }
  }
}

/**
 * Notify all subscribers of loading state change
 */
function notifyLoadingState() {
  const isLoading = pendingRequests > 0
  loadingStateCallbacks.forEach(cb => cb(isLoading))
}

/**
 * Increment pending request counter and notify
 */
function incrementPending() {
  const wasLoading = pendingRequests > 0
  pendingRequests++
  if (!wasLoading) {
    notifyLoadingState()
  }
}

/**
 * Decrement pending request counter and notify
 */
function decrementPending() {
  pendingRequests = Math.max(0, pendingRequests - 1)
  notifyLoadingState()
}

// Create axios instance
const apiClient: AxiosInstance = axios.create({
  baseURL: '/api',
  headers: {
    'Content-Type': 'application/json',
  },
  withCredentials: true, // Enable cookies for session auth
})

/**
 * Request interceptor - Add auth token and track pending requests
 */
apiClient.interceptors.request.use(
  (config) => {
    const token = localStorage.getItem('auth_token')
    if (token) {
      config.headers.Authorization = `Bearer ${token}`
    }
    incrementPending()
    return config
  },
  (error) => {
    decrementPending()
    return Promise.reject(error)
  }
)

/**
 * Response interceptor - Handle errors globally and track pending requests
 */
apiClient.interceptors.response.use(
  (response) => {
    decrementPending()
    return response
  },
  (error: AxiosError) => {
    decrementPending()

    // Handle 401 Unauthorized - clear auth and redirect to login
    if (error.response?.status === 401) {
      // Clear all auth data
      localStorage.removeItem('auth_token')
      localStorage.removeItem('admin_id')
      localStorage.removeItem('email')
      localStorage.removeItem('display_name')
      localStorage.removeItem('locale')

      // Redirect to login
      if (window.location.pathname !== '/login') {
        window.location.href = '/login'
      }
    }

    // Handle 403 Forbidden
    if (error.response?.status === 403) {
      console.error('Access forbidden')
    }

    // Handle 500 Server Error
    if (error.response?.status === 500) {
      console.error('Server error:', error.response.data)
    }

    return Promise.reject(error)
  }
)

/**
 * Generic GET request wrapper
 */
export async function get<T>(
  url: string,
  config?: any
): Promise<ApiResponse<T>> {
  try {
    const response: AxiosResponse<ApiResponse<T>> = await apiClient.get(url, config)
    return response.data
  } catch (error) {
    console.error('GET error:', error)
    throw error
  }
}

/**
 * Generic POST request wrapper
 */
export async function post<T>(
  url: string,
  data?: any,
  config?: any
): Promise<ApiResponse<T>> {
  try {
    const response: AxiosResponse<ApiResponse<T>> = await apiClient.post(url, data, config)
    return response.data
  } catch (error) {
    console.error('POST error:', error)
    throw error
  }
}

/**
 * Generic PUT request wrapper
 */
export async function put<T>(
  url: string,
  data?: any,
  config?: any
): Promise<ApiResponse<T>> {
  try {
    const response: AxiosResponse<ApiResponse<T>> = await apiClient.put(url, data, config)
    return response.data
  } catch (error) {
    console.error('PUT error:', error)
    throw error
  }
}

/**
 * Generic PATCH request wrapper
 */
export async function patch<T>(
  url: string,
  data?: any,
  config?: any
): Promise<ApiResponse<T>> {
  try {
    const response: AxiosResponse<ApiResponse<T>> = await apiClient.patch(url, data, config)
    return response.data
  } catch (error) {
    console.error('PATCH error:', error)
    throw error
  }
}

/**
 * Generic DELETE request wrapper
 */
export async function del<T>(
  url: string,
  config?: any
): Promise<ApiResponse<T>> {
  try {
    const response: AxiosResponse<ApiResponse<T>> = await apiClient.delete(url, config)
    return response.data
  } catch (error) {
    console.error('DELETE error:', error)
    throw error
  }
}

export default apiClient
