/**
 * Instance Config Context
 *
 * Fetches the deploying club's configured instance name once at app
 * bootstrap, from the public (no-auth) `GET /instance-config` endpoint
 * (ADR-0034). Mounted above both the login page and the authenticated app,
 * since the login screen itself needs to show the name before any session
 * exists.
 */

import { createContext, useContext, useState, useEffect, useCallback, ReactNode } from 'react'
import { getInstanceBranding } from '../api/generated/instance-branding/instance-branding'

// Rendered while the fetch is in flight and whenever it fails — never a blank
// string, which would otherwise flash on every page load.
const DEFAULT_INSTANCE_NAME = 'Club Bar'

interface InstanceConfigContextType {
  instanceName: string
  // True only until the initial fetch settles. A later refetch() (e.g. after
  // saving the Settings → Instance tab) does not flip this back to true —
  // the header/title keep showing the previous name until the new one lands.
  loading: boolean
  refetch: () => Promise<void>
}

const InstanceConfigContext = createContext<InstanceConfigContextType | undefined>(undefined)

interface InstanceConfigProviderProps {
  children: ReactNode
}

export function InstanceConfigProvider({ children }: InstanceConfigProviderProps) {
  const [instanceName, setInstanceName] = useState(DEFAULT_INSTANCE_NAME)
  const [loading, setLoading] = useState(true)

  const fetchInstanceConfig = useCallback(async () => {
    try {
      const result = await getInstanceBranding().getInstanceConfig()
      setInstanceName(result.instance_name?.trim() ? result.instance_name : DEFAULT_INSTANCE_NAME)
    } catch {
      // Public endpoint, called before any session exists — a network blip or
      // an unreachable backend must never block the login page from
      // rendering something coherent.
      setInstanceName(DEFAULT_INSTANCE_NAME)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    fetchInstanceConfig()
  }, [fetchInstanceConfig])

  const value: InstanceConfigContextType = {
    instanceName,
    loading,
    refetch: fetchInstanceConfig,
  }

  return <InstanceConfigContext.Provider value={value}>{children}</InstanceConfigContext.Provider>
}

export function useInstanceConfig(): InstanceConfigContextType {
  const context = useContext(InstanceConfigContext)
  if (!context) throw new Error('useInstanceConfig must be used within InstanceConfigProvider')
  return context
}
