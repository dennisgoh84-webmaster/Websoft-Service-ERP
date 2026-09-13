import { useEffect, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { api, type ClientSummary } from '../lib/api'

export default function ClientsPage() {
  const [clients, setClients] = useState<ClientSummary[]>([])
  const [showForm, setShowForm] = useState(false)
  const [form, setForm] = useState({ name: '', code: '', db_host: '', db_port: 5432, db_name: '', db_username: '', db_password: '', db_use_tls: true })
  const [error, setError] = useState('')

  const refresh = () => api.listClients().then(setClients)
  useEffect(() => { refresh() }, [])

  async function onCreate(e: FormEvent) {
    e.preventDefault()
    setError('')
    try {
      await api.createClient(form)
      setShowForm(false)
      setForm({ name: '', code: '', db_host: '', db_port: 5432, db_name: '', db_username: '', db_password: '', db_use_tls: true })
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed')
    }
  }

  const statusColor = (s: string) =>
    s === 'active' ? '#27ae60' : s === 'suspended' ? '#e74c3c' : '#95a5a6'

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h1 style={{ margin: 0, fontSize: 22 }}>Client Instances</h1>
        <button
          onClick={() => setShowForm(!showForm)}
          style={{ background: '#800020', color: '#fff', border: 'none', padding: '8px 16px', borderRadius: 6, cursor: 'pointer', fontWeight: 600 }}
        >
          {showForm ? 'Cancel' : '+ Add Client'}
        </button>
      </div>

      {showForm && (
        <div style={{ background: '#fff', padding: 20, borderRadius: 8, border: '1px solid #e0e0e0', marginBottom: 20 }}>
          {error && <div style={{ background: '#fdecea', color: '#c0392b', padding: '6px 12px', borderRadius: 4, fontSize: 13, marginBottom: 12 }}>{error}</div>}
          <form onSubmit={onCreate} style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
            <div>
              <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 2 }}>Client Name</label>
              <input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required style={{ width: '100%', padding: 6, border: '1px solid #ccc', borderRadius: 4, boxSizing: 'border-box' }} />
            </div>
            <div>
              <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 2 }}>Code</label>
              <input value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} required style={{ width: '100%', padding: 6, border: '1px solid #ccc', borderRadius: 4, boxSizing: 'border-box' }} placeholder="e.g. ACME" />
            </div>
            <div>
              <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 2 }}>DB Host</label>
              <input value={form.db_host} onChange={(e) => setForm({ ...form, db_host: e.target.value })} required style={{ width: '100%', padding: 6, border: '1px solid #ccc', borderRadius: 4, boxSizing: 'border-box' }} />
            </div>
            <div>
              <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 2 }}>DB Port</label>
              <input type="number" value={form.db_port} onChange={(e) => setForm({ ...form, db_port: parseInt(e.target.value) })} style={{ width: '100%', padding: 6, border: '1px solid #ccc', borderRadius: 4, boxSizing: 'border-box' }} />
            </div>
            <div>
              <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 2 }}>DB Name</label>
              <input value={form.db_name} onChange={(e) => setForm({ ...form, db_name: e.target.value })} required style={{ width: '100%', padding: 6, border: '1px solid #ccc', borderRadius: 4, boxSizing: 'border-box' }} />
            </div>
            <div>
              <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 2 }}>DB Username</label>
              <input value={form.db_username} onChange={(e) => setForm({ ...form, db_username: e.target.value })} required style={{ width: '100%', padding: 6, border: '1px solid #ccc', borderRadius: 4, boxSizing: 'border-box' }} />
            </div>
            <div>
              <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 2 }}>DB Password</label>
              <input type="password" value={form.db_password} onChange={(e) => setForm({ ...form, db_password: e.target.value })} required style={{ width: '100%', padding: 6, border: '1px solid #ccc', borderRadius: 4, boxSizing: 'border-box' }} />
            </div>
            <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
              <input type="checkbox" checked={form.db_use_tls} onChange={(e) => setForm({ ...form, db_use_tls: e.target.checked })} />
              <label style={{ fontSize: 12, fontWeight: 600 }}>Use TLS</label>
            </div>
            <div style={{ gridColumn: '1 / -1' }}>
              <button type="submit" style={{ background: '#800020', color: '#fff', border: 'none', padding: '8px 20px', borderRadius: 6, cursor: 'pointer', fontWeight: 600 }}>
                Create Client
              </button>
            </div>
          </form>
        </div>
      )}

      <div style={{ background: '#fff', borderRadius: 8, border: '1px solid #e0e0e0', overflow: 'hidden' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
          <thead>
            <tr style={{ borderBottom: '2px solid #eee' }}>
              <th style={{ textAlign: 'left', padding: '10px 12px', color: '#888', fontWeight: 600, fontSize: 12 }}>Code</th>
              <th style={{ textAlign: 'left', padding: '10px 12px', color: '#888', fontWeight: 600, fontSize: 12 }}>Name</th>
              <th style={{ textAlign: 'left', padding: '10px 12px', color: '#888', fontWeight: 600, fontSize: 12 }}>Status</th>
              <th style={{ textAlign: 'left', padding: '10px 12px', color: '#888', fontWeight: 600, fontSize: 12 }}>Last Connected</th>
              <th style={{ textAlign: 'left', padding: '10px 12px', color: '#888', fontWeight: 600, fontSize: 12 }}>Alembic Head</th>
            </tr>
          </thead>
          <tbody>
            {clients.map((c) => (
              <tr key={c.id} style={{ borderBottom: '1px solid #f0f0f0' }}>
                <td style={{ padding: '8px 12px' }}>
                  <Link to={`/clients/${c.id}`} style={{ color: '#800020', fontWeight: 600 }}>{c.code}</Link>
                </td>
                <td style={{ padding: '8px 12px' }}>{c.name}</td>
                <td style={{ padding: '8px 12px' }}>
                  <span style={{ display: 'inline-block', padding: '1px 8px', borderRadius: 4, fontSize: 11, fontWeight: 600, color: '#fff', background: statusColor(c.status) }}>
                    {c.status}
                  </span>
                </td>
                <td style={{ padding: '8px 12px', color: '#888' }}>
                  {c.last_connected_at ? new Date(c.last_connected_at).toLocaleString() : '—'}
                </td>
                <td style={{ padding: '8px 12px', fontFamily: 'monospace', fontSize: 11, color: '#888' }}>
                  {c.last_known_alembic_head ?? '—'}
                </td>
              </tr>
            ))}
            {clients.length === 0 && (
              <tr>
                <td colSpan={5} style={{ padding: 20, textAlign: 'center', color: '#888' }}>No clients registered yet.</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  )
}
