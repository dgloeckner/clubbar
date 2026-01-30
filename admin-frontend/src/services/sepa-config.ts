/**
 * SEPA Configuration API Service
 * Handles SEPA configuration settings (creditor info, address, etc.)
 */

import { get, put } from './api'
import { SepaConfig, UpdateSepaConfigRequest, ApiResponse } from '../types'

/**
 * Get SEPA configuration
 * Returns null if not configured (404 response)
 */
export async function getSepaConfig(): Promise<SepaConfig | null> {
  try {
    const response = await get<SepaConfig>('/admin/sepa-config')

    // If response has data wrapper, unwrap it
    if (response.data) {
      return response.data
    }

    // Handle case where response itself is the config
    if (response && 'creditor_id' in response) {
      return response as unknown as SepaConfig
    }

    return null
  } catch (error: any) {
    // 404 means not configured yet
    if (error.response?.status === 404) {
      return null
    }
    throw error
  }
}

/**
 * Update SEPA configuration
 * Returns updated configuration
 */
export async function updateSepaConfig(data: UpdateSepaConfigRequest): Promise<SepaConfig> {
  const response = await put<SepaConfig>('/admin/sepa-config', data)

  // If response has data wrapper, unwrap it
  if (response.data) {
    return response.data
  }

  // Handle case where response itself is the config
  if (response && 'creditor_id' in response) {
    return response as unknown as SepaConfig
  }

  throw new Error('Invalid response format')
}
