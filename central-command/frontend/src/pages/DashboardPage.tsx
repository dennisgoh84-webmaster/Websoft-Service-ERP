import { useEffect, useState } from 'react'
import { api, type DashboardStats } from '../lib/api'

const cardStyle: React.CSSProperties = {
  background: '#fff',
  borderRadius: 8,
  padding: '16px 20px',
  border: '1px solid #e0e0e0',
}

export default function DashboardPage() {
  const [stats, setStats] = useState<DashboardStats | null>(null)

  useEffect(() => {
    api.getDashboard().then(setStats)
  }, [])

  if (!stats) return <p>Loading dashboard...</p>

  return (
    <div>
      <h1 style={{ margin: '0 0 20px', fontSize: 22 }}>Dashboard</h1>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 16, marginBottom: 24 }}>
        <div style={cardStyle}>
          <p style={{ color: '#888', fontSize: 12, margin: 0, textTransform: 'uppercase' }}>Total Clients</p>
          <p style={{ fontSize: 28, fontWeight: 700, margin: '4px 0 0', color: '#333' }}>{stats.total_clients}</p>
        </div>
        <div style={cardStyle}>
          <p style={{ color: '#888', fontSize: 12, margin: 0, textTransform: 'uppercase' }}>Active</p>
          <p style={{ fontSize: 28, fontWeight: 700, margin: '4px 0 0', color: '#27ae60' }}>{stats.active_clients}</p>
        </div>
        <div style={cardStyle}>
          <p style={{ color: '#888', fontSize: 12, margin: 0, textTransform: 'uppercase' }}>Suspended</p>
          <p style={{ fontSize: 28, fontWeight: 700, margin: '4px 0 0', color: '#e74c3c' }}>{stats.suspended_clients}</p>
        </div>
        <div style={cardStyle}>
          <p style={{ color: '#888', fontSize: 12, margin: 0, textTransform: 'uppercase' }}>Active Ads</p>
          <p style={{ fontSize: 28, fontWeight: 700, margin: '4px 0 0', color: '#3498db' }}>{stats.active_ads}</p>
        </div>
        <div style={cardStyle}>
          <p style={{ color: '#888', fontSize: 12, margin: 0, textTransform: 'uppercase' }}>Config Updates</p>
          <p style={{ fontSize: 28, fontWeight: 700, margin: '4px 0 0', color: '#8e44ad' }}>{stats.total_config_updates}</p>
        </div>
        <div style={cardStyle}>
          <p style={{ color: '#888', fontSize: 12, margin: 0, textTransform: 'uppercase' }}>Pending Pushes</p>
          <p style={{ fontSize: 28, fontWeight: 700, margin: '4px 0 0', color: '#e67e22' }}>{stats.pending_pushes}</p>
        </div>
      </div>

      <div style={cardStyle}>
        <h2 style={{ fontSize: 16, margin: '0 0 12px' }}>Recent Push Activity</h2>
        {stats.recent_pushes.length === 0 ? (
          <p style={{ color: '#888', fontSize: 13 }}>No push activity yet.</p>
        ) : (
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
            <thead>
              <tr style={{ borderBottom: '2px solid #eee' }}>
                <th style={{ textAlign: 'left', padding: '6px 8px', color: '#888', fontWeight: 600 }}>Type</th>
                <th style={{ textAlign: 'left', padding: '6px 8px', color: '#888', fontWeight: 600 }}>Detail</th>
                <th style={{ textAlign: 'left', padding: '6px 8px', color: '#888', fontWeight: 600 }}>Status</th>
                <th style={{ textAlign: 'left', padding: '6px 8px', color: '#888', fontWeight: 600 }}>Time</th>
              </tr>
            </thead>
            <tbody>
              {stats.recent_pushes.map((p) => (
                <tr key={p.id} style={{ borderBottom: '1px solid #f0f0f0' }}>
                  <td style={{ padding: '6px 8px' }}>
                    <span
                      style={{
                        display: 'inline-block',
                        padding: '1px 6px',
                        borderRadius: 3,
                        fontSize: 11,
                        fontWeight: 600,
                        color: '#fff',
                        background:
                          p.push_type === 'advertisement' ? '#3498db'
                          : p.push_type === 'license' ? '#e67e22'
                          : p.push_type === 'video' ? '#8e44ad'
                          : '#27ae60',
                      }}
                    >
                      {p.push_type}
                    </span>
                  </td>
                  <td style={{ padding: '6px 8px' }}>{p.detail}</td>
                  <td style={{ padding: '6px 8px', color: p.success ? '#27ae60' : '#e74c3c' }}>
                    {p.success ? '✓ OK' : '✗ Failed'}
                  </td>
                  <td style={{ padding: '6px 8px', color: '#888' }}>
                    {new Date(p.pushed_at).toLocaleString()}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  )
}
