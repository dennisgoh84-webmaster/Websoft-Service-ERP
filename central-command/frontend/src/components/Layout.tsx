import { Link, useLocation } from 'react-router-dom'
import { useAuth } from '../lib/AuthContext'
import type { ReactNode } from 'react'

const NAV = [
  { to: '/', label: '📊 Dashboard' },
  { to: '/clients', label: '🏢 Clients' },
  { to: '/advertisements', label: '📢 Advertisements' },
  { to: '/licenses', label: '🔑 Licenses' },
  { to: '/config-updates', label: '⚙️ Config Updates' },
  { to: '/versions', label: '🔄 Version Control' },
  { to: '/staff', label: '👤 Staff' },
]

export default function Layout({ children }: { children: ReactNode }) {
  const { user, logout } = useAuth()
  const loc = useLocation()

  return (
    <div style={{ display: 'flex', minHeight: '100vh' }}>
      {/* Sidebar */}
      <nav
        style={{
          width: 220,
          background: '#1a1a2e',
          color: '#e0e0e0',
          padding: '20px 0',
          flexShrink: 0,
          display: 'flex',
          flexDirection: 'column',
        }}
      >
        <div style={{ padding: '0 16px 20px', borderBottom: '1px solid #333' }}>
          <h1 style={{ fontSize: 15, margin: 0, color: '#fff', fontWeight: 700 }}>
            🖥️ Central Command
          </h1>
          <p style={{ fontSize: 11, margin: '4px 0 0', color: '#888' }}>
            Web Master Consultancy
          </p>
        </div>

        <div style={{ flex: 1, padding: '12px 0' }}>
          {NAV.map((n) => {
            const active = n.to === '/' ? loc.pathname === '/' : loc.pathname.startsWith(n.to)
            return (
              <Link
                key={n.to}
                to={n.to}
                style={{
                  display: 'block',
                  padding: '8px 16px',
                  color: active ? '#fff' : '#aaa',
                  background: active ? '#800020' : 'transparent',
                  textDecoration: 'none',
                  fontSize: 13,
                  fontWeight: active ? 600 : 400,
                }}
              >
                {n.label}
              </Link>
            )
          })}
        </div>

        <div style={{ padding: '12px 16px', borderTop: '1px solid #333', fontSize: 12 }}>
          <p style={{ margin: 0, color: '#aaa' }}>{user?.full_name}</p>
          <button
            onClick={logout}
            style={{
              marginTop: 8,
              background: 'transparent',
              border: '1px solid #555',
              color: '#ccc',
              padding: '4px 10px',
              borderRadius: 4,
              cursor: 'pointer',
              fontSize: 11,
            }}
          >
            Sign out
          </button>
        </div>
      </nav>

      {/* Main content */}
      <main style={{ flex: 1, padding: '24px 32px', background: '#f5f5f5', overflow: 'auto' }}>
        {children}
      </main>
    </div>
  )
}
