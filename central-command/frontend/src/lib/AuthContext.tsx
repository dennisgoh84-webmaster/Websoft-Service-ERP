import { createContext, useContext, useEffect, useState, type ReactNode } from 'react'
import { api, type AdminUser, type LoginResponse } from './api'

interface AuthCtx {
  user: AdminUser | null
  loading: boolean
  login: (username: string, password: string) => Promise<LoginResponse>
  verifyOtp: (otpSession: string, otpCode: string) => Promise<void>
  logout: () => void
}

const Ctx = createContext<AuthCtx>({
  user: null,
  loading: true,
  login: async () => ({ status: 'ok' as const }),
  verifyOtp: async () => {},
  logout: () => {},
})

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<AdminUser | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    const token = localStorage.getItem('cc_token')
    if (!token) {
      setLoading(false)
      return
    }
    api.me().then(setUser).catch(() => localStorage.removeItem('cc_token')).finally(() => setLoading(false))
  }, [])

  const login = async (username: string, password: string): Promise<LoginResponse> => {
    const res = await api.login(username, password)
    // If OTP required, return the response so the login page can show OTP form
    if (res.status === 'otp_required') {
      return res
    }
    // Direct login (shouldn't happen with current backend, but handle gracefully)
    if (res.access_token) {
      localStorage.setItem('cc_token', res.access_token)
      const me = await api.me()
      setUser(me)
    }
    return res
  }

  const verifyOtp = async (otpSession: string, otpCode: string) => {
    const res = await api.verifyOtp(otpSession, otpCode)
    localStorage.setItem('cc_token', res.access_token)
    const me = await api.me()
    setUser(me)
  }

  const logout = () => {
    localStorage.removeItem('cc_token')
    setUser(null)
  }

  return <Ctx.Provider value={{ user, loading, login, verifyOtp, logout }}>{children}</Ctx.Provider>
}

export const useAuth = () => useContext(Ctx)
