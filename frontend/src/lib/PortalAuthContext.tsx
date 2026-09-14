// Auth context for the Customer Helpdesk Portal (PORTAL-001..004,
// docs/customer-portal-design.md §7). A dedicated context and a
// dedicated localStorage key (websoft_portal_token, see portalApi.ts)
// -- kept entirely separate from src/lib/AuthContext.tsx's staff
// session so a browser can never mix the two up (e.g. a staff member
// previewing their own customer's portal in another tab), and so the
// backend's security boundary (app/core/deps.py get_current_portal_user,
// purpose="portal") has a matching boundary on this side too.
import { createContext, useContext, useEffect, useState, type ReactNode } from 'react'
import {
  clearPortalToken,
  getPortalToken,
  portalApi,
  setPortalToken,
  type PortalMe,
} from './portalApi'

interface PortalAuthState {
  portalUser: PortalMe | null
  loading: boolean
  /** Stores a portal_token (from verify-otp, or straight from login when
   * SMTP isn't configured) and loads the session. */
  completeLogin: (token: string) => Promise<void>
  logout: () => void
  /** Re-read /me -- e.g. right after change-password clears
   * must_change_password. */
  refresh: () => Promise<void>
}

const PortalAuthContext = createContext<PortalAuthState | undefined>(undefined)

export function PortalAuthProvider({ children }: { children: ReactNode }) {
  const [portalUser, setPortalUser] = useState<PortalMe | null>(null)
  const [loading, setLoading] = useState(true)

  async function loadSession() {
    setPortalUser(await portalApi.me())
  }

  useEffect(() => {
    if (!getPortalToken()) {
      setLoading(false)
      return
    }
    loadSession()
      .catch(() => clearPortalToken())
      .finally(() => setLoading(false))
  }, [])

  async function completeLogin(token: string) {
    setPortalToken(token)
    await loadSession()
  }

  function logout() {
    clearPortalToken()
    setPortalUser(null)
  }

  return (
    <PortalAuthContext.Provider
      value={{ portalUser, loading, completeLogin, logout, refresh: loadSession }}
    >
      {children}
    </PortalAuthContext.Provider>
  )
}

export function usePortalAuth(): PortalAuthState {
  const ctx = useContext(PortalAuthContext)
  if (!ctx) throw new Error('usePortalAuth must be used within PortalAuthProvider')
  return ctx
}
