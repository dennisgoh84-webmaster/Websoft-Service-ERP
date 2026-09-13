import { useEffect, useState } from 'react'
import { api } from '../lib/api'
import type { ERPVersion, ClientVersionInfo, UpgradeLog } from '../lib/api'

export default function VersionControlPage() {
  const [versions, setVersions] = useState<ERPVersion[]>([])
  const [clientVersions, setClientVersions] = useState<ClientVersionInfo[]>([])
  const [upgradeLogs, setUpgradeLogs] = useState<UpgradeLog[]>([])
  const [tab, setTab] = useState<'versions' | 'clients' | 'logs'>('clients')
  const [showCreate, setShowCreate] = useState(false)
  const [form, setForm] = useState({ version_number: '', alembic_head: '', release_notes: '' })
  const [busy, setBusy] = useState(false)
  const [msg, setMsg] = useState('')

  const load = async () => {
    const [v, cv, ul] = await Promise.all([
      api.listVersions(),
      api.getClientVersions(),
      api.getUpgradeLogs(),
    ])
    setVersions(v)
    setClientVersions(cv)
    setUpgradeLogs(ul)
  }

  useEffect(() => { load() }, [])

  const handleCreate = async () => {
    if (!form.version_number || !form.alembic_head) return
    setBusy(true)
    try {
      await api.createVersion({
        version_number: form.version_number,
        alembic_head: form.alembic_head,
        release_notes: form.release_notes || undefined,
      })
      setShowCreate(false)
      setForm({ version_number: '', alembic_head: '', release_notes: '' })
      load()
    } catch (e: any) { setMsg(e.message) }
    setBusy(false)
  }

  const handleRelease = async (v: ERPVersion) => {
    if (!confirm(`Release v${v.version_number} and mark as latest?`)) return
    await api.updateVersion(v.id, { status: 'released', is_latest: true })
    load()
  }

  const handleUpgrade = async (cv: ClientVersionInfo) => {
    const latest = versions.find(v => v.is_latest && v.status === 'released')
    if (!latest) { setMsg('No latest version to upgrade to'); return }
    if (!confirm(`Upgrade ${cv.client_code} to v${latest.version_number}?`)) return
    setBusy(true)
    try {
      await api.upgradeClient(cv.client_id, latest.id)
      setMsg(`${cv.client_code} upgraded to v${latest.version_number}`)
      load()
    } catch (e: any) { setMsg(e.message) }
    setBusy(false)
  }

  const ts = (s: string | null) => s ? new Date(s).toLocaleString() : '—'

  const tabBtn = (key: typeof tab, label: string) => (
    <button
      key={key}
      onClick={() => setTab(key)}
      style={{
        padding: '6px 16px', border: 'none', cursor: 'pointer', fontSize: 13, fontWeight: tab === key ? 700 : 400,
        background: tab === key ? '#800020' : '#eee', color: tab === key ? '#fff' : '#333', borderRadius: 4,
      }}
    >{label}</button>
  )

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h1 style={{ fontSize: '1.3rem', margin: 0 }}>🔄 Version Control</h1>
        <button onClick={() => setShowCreate(true)} style={{ padding: '6px 14px', background: '#800020', color: '#fff', border: 'none', borderRadius: 4, cursor: 'pointer', fontSize: 13, fontWeight: 600 }}>+ New Version</button>
      </div>

      {msg && <div style={{ padding: 8, background: '#fef3cd', borderRadius: 4, marginBottom: 12, fontSize: 13 }}>{msg} <button onClick={() => setMsg('')} style={{ border: 'none', background: 'none', cursor: 'pointer' }}>✕</button></div>}

      <div style={{ display: 'flex', gap: 6, marginBottom: 16 }}>
        {tabBtn('clients', '📊 Client Versions')}
        {tabBtn('versions', '📦 Version Registry')}
        {tabBtn('logs', '📋 Upgrade History')}
      </div>

      {/* Create form */}
      {showCreate && (
        <div style={{ background: '#fff', border: '1px solid #ddd', borderRadius: 8, padding: 20, marginBottom: 16 }}>
          <h3 style={{ margin: '0 0 12px', fontSize: 14 }}>Register New ERP Version</h3>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10, marginBottom: 10 }}>
            <input placeholder="Version number (e.g. 1.2.0)" value={form.version_number} onChange={e => setForm({ ...form, version_number: e.target.value })} style={{ padding: 8, border: '1px solid #ddd', borderRadius: 4, fontSize: 13 }} />
            <input placeholder="Alembic head (e.g. c3d4e5f6g7h8)" value={form.alembic_head} onChange={e => setForm({ ...form, alembic_head: e.target.value })} style={{ padding: 8, border: '1px solid #ddd', borderRadius: 4, fontSize: 13 }} />
          </div>
          <textarea placeholder="Release notes (optional)" value={form.release_notes} onChange={e => setForm({ ...form, release_notes: e.target.value })} rows={3} style={{ width: '100%', padding: 8, border: '1px solid #ddd', borderRadius: 4, fontSize: 13, boxSizing: 'border-box' }} />
          <div style={{ marginTop: 10, display: 'flex', gap: 8 }}>
            <button onClick={handleCreate} disabled={busy} style={{ padding: '6px 16px', background: '#800020', color: '#fff', border: 'none', borderRadius: 4, cursor: 'pointer', fontSize: 13, fontWeight: 600 }}>Create</button>
            <button onClick={() => setShowCreate(false)} style={{ padding: '6px 16px', background: '#eee', border: 'none', borderRadius: 4, cursor: 'pointer', fontSize: 13 }}>Cancel</button>
          </div>
        </div>
      )}

      {/* Client Versions Tab */}
      {tab === 'clients' && (
        <div style={{ background: '#fff', border: '1px solid #ddd', borderRadius: 8, overflow: 'hidden' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
            <thead>
              <tr style={{ borderBottom: '2px solid #eee' }}>
                <th style={{ textAlign: 'left', padding: '10px 14px', color: '#888', fontWeight: 600, fontSize: 11, textTransform: 'uppercase' }}>Client</th>
                <th style={{ textAlign: 'left', padding: '10px 14px', color: '#888', fontWeight: 600, fontSize: 11, textTransform: 'uppercase' }}>Current Version</th>
                <th style={{ textAlign: 'left', padding: '10px 14px', color: '#888', fontWeight: 600, fontSize: 11, textTransform: 'uppercase' }}>Alembic Head</th>
                <th style={{ textAlign: 'left', padding: '10px 14px', color: '#888', fontWeight: 600, fontSize: 11, textTransform: 'uppercase' }}>Latest</th>
                <th style={{ textAlign: 'left', padding: '10px 14px', color: '#888', fontWeight: 600, fontSize: 11, textTransform: 'uppercase' }}>Status</th>
                <th style={{ textAlign: 'left', padding: '10px 14px', color: '#888', fontWeight: 600, fontSize: 11, textTransform: 'uppercase' }}>Action</th>
              </tr>
            </thead>
            <tbody>
              {clientVersions.map(cv => (
                <tr key={cv.client_id} style={{ borderBottom: '1px solid #f0f0f0' }}>
                  <td style={{ padding: '10px 14px' }}>
                    <strong style={{ color: '#800020' }}>{cv.client_code}</strong>
                    <span style={{ color: '#888', marginLeft: 8, fontSize: 12 }}>{cv.client_name}</span>
                  </td>
                  <td style={{ padding: '10px 14px', fontWeight: 600 }}>{cv.current_version || '—'}</td>
                  <td style={{ padding: '10px 14px', fontFamily: 'monospace', fontSize: 11, color: '#888' }}>{cv.current_alembic_head || '—'}</td>
                  <td style={{ padding: '10px 14px' }}>{cv.latest_version || '—'}</td>
                  <td style={{ padding: '10px 14px' }}>
                    {cv.is_up_to_date
                      ? <span style={{ background: '#27ae60', color: '#fff', padding: '2px 10px', borderRadius: 12, fontSize: 11, fontWeight: 600 }}>Up to date</span>
                      : <span style={{ background: '#e67e22', color: '#fff', padding: '2px 10px', borderRadius: 12, fontSize: 11, fontWeight: 600 }}>Update available</span>
                    }
                  </td>
                  <td style={{ padding: '10px 14px' }}>
                    {!cv.is_up_to_date && cv.status === 'active' && (
                      <button onClick={() => handleUpgrade(cv)} disabled={busy} style={{ padding: '4px 12px', background: '#27ae60', color: '#fff', border: 'none', borderRadius: 4, cursor: 'pointer', fontSize: 12, fontWeight: 600 }}>⬆ Upgrade</button>
                    )}
                  </td>
                </tr>
              ))}
              {clientVersions.length === 0 && (
                <tr><td colSpan={6} style={{ padding: 20, textAlign: 'center', color: '#888' }}>No clients registered</td></tr>
              )}
            </tbody>
          </table>
        </div>
      )}

      {/* Version Registry Tab */}
      {tab === 'versions' && (
        <div style={{ background: '#fff', border: '1px solid #ddd', borderRadius: 8, overflow: 'hidden' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
            <thead>
              <tr style={{ borderBottom: '2px solid #eee' }}>
                <th style={{ textAlign: 'left', padding: '10px 14px', color: '#888', fontWeight: 600, fontSize: 11, textTransform: 'uppercase' }}>Version</th>
                <th style={{ textAlign: 'left', padding: '10px 14px', color: '#888', fontWeight: 600, fontSize: 11, textTransform: 'uppercase' }}>Alembic Head</th>
                <th style={{ textAlign: 'left', padding: '10px 14px', color: '#888', fontWeight: 600, fontSize: 11, textTransform: 'uppercase' }}>Status</th>
                <th style={{ textAlign: 'left', padding: '10px 14px', color: '#888', fontWeight: 600, fontSize: 11, textTransform: 'uppercase' }}>Released</th>
                <th style={{ textAlign: 'left', padding: '10px 14px', color: '#888', fontWeight: 600, fontSize: 11, textTransform: 'uppercase' }}>Notes</th>
                <th style={{ textAlign: 'left', padding: '10px 14px', color: '#888', fontWeight: 600, fontSize: 11, textTransform: 'uppercase' }}>Actions</th>
              </tr>
            </thead>
            <tbody>
              {versions.map(v => (
                <tr key={v.id} style={{ borderBottom: '1px solid #f0f0f0' }}>
                  <td style={{ padding: '10px 14px', fontWeight: 700 }}>
                    v{v.version_number}
                    {v.is_latest && <span style={{ background: '#3498db', color: '#fff', padding: '1px 6px', borderRadius: 3, fontSize: 10, fontWeight: 600, marginLeft: 6 }}>LATEST</span>}
                  </td>
                  <td style={{ padding: '10px 14px', fontFamily: 'monospace', fontSize: 11, color: '#888' }}>{v.alembic_head}</td>
                  <td style={{ padding: '10px 14px' }}>
                    <span style={{
                      padding: '2px 8px', borderRadius: 3, fontSize: 11, fontWeight: 600, color: '#fff',
                      background: v.status === 'released' ? '#27ae60' : v.status === 'deprecated' ? '#e74c3c' : '#999',
                    }}>{v.status}</span>
                  </td>
                  <td style={{ padding: '10px 14px', color: '#888', fontSize: 12 }}>{ts(v.released_at)}</td>
                  <td style={{ padding: '10px 14px', fontSize: 12, maxWidth: 200, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{v.release_notes || '—'}</td>
                  <td style={{ padding: '10px 14px', display: 'flex', gap: 4 }}>
                    {v.status === 'draft' && (
                      <button onClick={() => handleRelease(v)} style={{ padding: '3px 10px', background: '#27ae60', color: '#fff', border: 'none', borderRadius: 3, cursor: 'pointer', fontSize: 11, fontWeight: 600 }}>Release</button>
                    )}
                    <button onClick={async () => { if (confirm('Delete this version?')) { await api.deleteVersion(v.id); load() } }} style={{ padding: '3px 10px', background: '#e74c3c', color: '#fff', border: 'none', borderRadius: 3, cursor: 'pointer', fontSize: 11, fontWeight: 600 }}>Delete</button>
                  </td>
                </tr>
              ))}
              {versions.length === 0 && (
                <tr><td colSpan={6} style={{ padding: 20, textAlign: 'center', color: '#888' }}>No versions registered yet</td></tr>
              )}
            </tbody>
          </table>
        </div>
      )}

      {/* Upgrade Logs Tab */}
      {tab === 'logs' && (
        <div style={{ background: '#fff', border: '1px solid #ddd', borderRadius: 8, overflow: 'hidden' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
            <thead>
              <tr style={{ borderBottom: '2px solid #eee' }}>
                <th style={{ textAlign: 'left', padding: '10px 14px', color: '#888', fontWeight: 600, fontSize: 11, textTransform: 'uppercase' }}>Client</th>
                <th style={{ textAlign: 'left', padding: '10px 14px', color: '#888', fontWeight: 600, fontSize: 11, textTransform: 'uppercase' }}>From</th>
                <th style={{ textAlign: 'left', padding: '10px 14px', color: '#888', fontWeight: 600, fontSize: 11, textTransform: 'uppercase' }}>To</th>
                <th style={{ textAlign: 'left', padding: '10px 14px', color: '#888', fontWeight: 600, fontSize: 11, textTransform: 'uppercase' }}>Status</th>
                <th style={{ textAlign: 'left', padding: '10px 14px', color: '#888', fontWeight: 600, fontSize: 11, textTransform: 'uppercase' }}>Time</th>
              </tr>
            </thead>
            <tbody>
              {upgradeLogs.map(l => (
                <tr key={l.id} style={{ borderBottom: '1px solid #f0f0f0' }}>
                  <td style={{ padding: '10px 14px', fontWeight: 500 }}>{l.client_id.slice(0, 8)}…</td>
                  <td style={{ padding: '10px 14px', fontFamily: 'monospace', fontSize: 11 }}>{l.from_version || '—'}</td>
                  <td style={{ padding: '10px 14px', fontWeight: 600 }}>v{l.to_version}</td>
                  <td style={{ padding: '10px 14px' }}>
                    {l.success
                      ? <span style={{ color: '#27ae60' }}>✓ OK</span>
                      : <span style={{ color: '#e74c3c' }}>✗ Failed</span>
                    }
                  </td>
                  <td style={{ padding: '10px 14px', color: '#888', fontSize: 12 }}>{ts(l.upgraded_at)}</td>
                </tr>
              ))}
              {upgradeLogs.length === 0 && (
                <tr><td colSpan={5} style={{ padding: 20, textAlign: 'center', color: '#888' }}>No upgrades yet</td></tr>
              )}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
