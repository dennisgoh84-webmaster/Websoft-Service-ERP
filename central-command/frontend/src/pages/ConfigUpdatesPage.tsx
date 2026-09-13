import { useEffect, useState, type FormEvent } from 'react'
import { api, type ConfigUpdate, type ClientSummary } from '../lib/api'

export default function ConfigUpdatesPage() {
  const [updates, setUpdates] = useState<ConfigUpdate[]>([])
  const [clients, setClients] = useState<ClientSummary[]>([])
  const [showForm, setShowForm] = useState(false)
  const [form, setForm] = useState({ title: '', description: '', sql_statement: '' })
  const [error, setError] = useState('')
  const [pushMsg, setPushMsg] = useState('')

  const refresh = () => {
    api.listConfigUpdates().then(setUpdates)
    api.listClients().then(setClients)
  }
  useEffect(() => { refresh() }, [])

  const clientName = (id: string) => clients.find((c) => c.id === id)?.name ?? id.slice(0, 8)

  async function onCreate(e: FormEvent) {
    e.preventDefault()
    setError('')
    try {
      await api.createConfigUpdate(form)
      setShowForm(false)
      setForm({ title: '', description: '', sql_statement: '' })
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed')
    }
  }

  async function markReady(id: string) {
    await api.updateConfigUpdate(id, { status: 'ready' } as Partial<ConfigUpdate>)
    refresh()
  }

  async function pushAll(id: string) {
    setPushMsg('')
    try {
      const res = await api.pushConfigUpdate(id)
      setPushMsg(`Pushed to ${res.successes}/${res.total} clients`)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Push failed')
    }
  }

  const statusColor = (s: string) =>
    s === 'pushed' ? '#27ae60' : s === 'ready' ? '#3498db' : s === 'partial' ? '#e67e22' : '#95a5a6'

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h1 style={{ margin: 0, fontSize: 22 }}>Config Updates</h1>
        <button
          onClick={() => setShowForm(!showForm)}
          style={{ background: '#800020', color: '#fff', border: 'none', padding: '8px 16px', borderRadius: 6, cursor: 'pointer', fontWeight: 600 }}
        >
          {showForm ? 'Cancel' : '+ New Config Update'}
        </button>
      </div>

      {error && <div style={{ background: '#fdecea', color: '#c0392b', padding: '6px 12px', borderRadius: 4, fontSize: 13, marginBottom: 12 }}>{error}</div>}
      {pushMsg && <div style={{ background: '#eafaf1', color: '#27ae60', padding: '6px 12px', borderRadius: 4, fontSize: 13, marginBottom: 12 }}>{pushMsg}</div>}

      {showForm && (
        <div style={{ background: '#fff', padding: 20, borderRadius: 8, border: '1px solid #e0e0e0', marginBottom: 20 }}>
          <form onSubmit={onCreate}>
            <div style={{ marginBottom: 12 }}>
              <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 2 }}>Title</label>
              <input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} required style={{ width: '100%', padding: 6, border: '1px solid #ccc', borderRadius: 4, boxSizing: 'border-box' }} />
            </div>
            <div style={{ marginBottom: 12 }}>
              <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 2 }}>Description</label>
              <input value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} style={{ width: '100%', padding: 6, border: '1px solid #ccc', borderRadius: 4, boxSizing: 'border-box' }} />
            </div>
            <div style={{ marginBottom: 12 }}>
              <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 2 }}>SQL Statement</label>
              <textarea
                value={form.sql_statement}
                onChange={(e) => setForm({ ...form, sql_statement: e.target.value })}
                required
                rows={5}
                style={{ width: '100%', padding: 6, border: '1px solid #ccc', borderRadius: 4, fontFamily: 'monospace', fontSize: 12, boxSizing: 'border-box' }}
                placeholder="UPDATE tax_codes SET rate = 0.09 WHERE code = 'SR';"
              />
            </div>
            <button type="submit" style={{ background: '#800020', color: '#fff', border: 'none', padding: '8px 20px', borderRadius: 6, cursor: 'pointer', fontWeight: 600 }}>
              Create (Draft)
            </button>
          </form>
        </div>
      )}

      {updates.map((cu) => (
        <div key={cu.id} style={{ background: '#fff', borderRadius: 8, border: '1px solid #e0e0e0', marginBottom: 16, overflow: 'hidden' }}>
          <div style={{ padding: '14px 16px', borderBottom: '1px solid #f0f0f0', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <div>
              <h3 style={{ margin: 0, fontSize: 14 }}>{cu.title}</h3>
              {cu.description && <p style={{ margin: '2px 0 0', fontSize: 12, color: '#888' }}>{cu.description}</p>}
            </div>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <span style={{ display: 'inline-block', padding: '2px 10px', borderRadius: 4, fontSize: 11, fontWeight: 600, color: '#fff', background: statusColor(cu.status) }}>
                {cu.status}
              </span>
              {cu.status === 'draft' && (
                <button onClick={() => markReady(cu.id)} style={{ background: '#3498db', color: '#fff', border: 'none', padding: '4px 10px', borderRadius: 3, cursor: 'pointer', fontSize: 11 }}>
                  Mark Ready
                </button>
              )}
              {(cu.status === 'ready' || cu.status === 'partial') && (
                <button onClick={() => pushAll(cu.id)} style={{ background: '#27ae60', color: '#fff', border: 'none', padding: '4px 10px', borderRadius: 3, cursor: 'pointer', fontSize: 11 }}>
                  Push to All
                </button>
              )}
            </div>
          </div>
          <div style={{ padding: '10px 16px', background: '#f9f9f9' }}>
            <pre style={{ margin: 0, fontSize: 12, fontFamily: 'monospace', whiteSpace: 'pre-wrap', wordBreak: 'break-all' }}>{cu.sql_statement}</pre>
          </div>
          {cu.push_logs.length > 0 && (
            <div style={{ padding: '8px 16px', borderTop: '1px solid #f0f0f0' }}>
              <p style={{ margin: '0 0 4px', fontSize: 11, fontWeight: 600, color: '#888' }}>Push History</p>
              {cu.push_logs.map((log) => (
                <div key={log.id} style={{ fontSize: 11, display: 'flex', gap: 8, padding: '2px 0' }}>
                  <span style={{ color: log.success ? '#27ae60' : '#e74c3c' }}>{log.success ? '✓' : '✗'}</span>
                  <span>{clientName(log.client_id)}</span>
                  <span style={{ color: '#888' }}>{new Date(log.pushed_at).toLocaleString()}</span>
                  {log.error_message && <span style={{ color: '#e74c3c' }}>{log.error_message}</span>}
                </div>
              ))}
            </div>
          )}
        </div>
      ))}

      {updates.length === 0 && (
        <p style={{ color: '#888', fontSize: 13 }}>No config updates yet.</p>
      )}
    </div>
  )
}
