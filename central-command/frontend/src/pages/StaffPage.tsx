import { useEffect, useState } from 'react'
import { api } from '../lib/api'
import type { AdminUser, ClientSummary, SupportLogin } from '../lib/api'

const ROLE_LABELS: Record<string, string> = {
  super_admin: 'Super Admin',
  admin: 'Admin',
  support_engineer: 'Support Engineer',
  viewer: 'Viewer',
}
const ROLE_COLORS: Record<string, string> = {
  super_admin: '#800020',
  admin: '#3498db',
  support_engineer: '#27ae60',
  viewer: '#999',
}

export default function StaffPage() {
  const [staff, setStaff] = useState<AdminUser[]>([])
  const [clients, setClients] = useState<ClientSummary[]>([])
  const [supportLogins, setSupportLogins] = useState<SupportLogin[]>([])
  const [tab, setTab] = useState<'staff' | 'support'>('staff')
  const [showCreate, setShowCreate] = useState(false)
  const [showPush, setShowPush] = useState(false)
  const [form, setForm] = useState({ username: '', full_name: '', email: '', password: '', role: 'admin' })
  const [pushForm, setPushForm] = useState({ client_id: '', admin_user_id: '', login_email: '', login_password: '', reason: '' })
  const [busy, setBusy] = useState(false)
  const [msg, setMsg] = useState('')

  const load = async () => {
    const [s, c, sl] = await Promise.all([
      api.listStaff(),
      api.listClients(),
      api.listSupportLogins(),
    ])
    setStaff(s)
    setClients(c)
    setSupportLogins(sl)
  }

  useEffect(() => { load() }, [])

  const handleCreate = async () => {
    if (!form.username || !form.full_name || !form.password) return
    setBusy(true)
    try {
      await api.createStaff(form)
      setShowCreate(false)
      setForm({ username: '', full_name: '', email: '', password: '', role: 'admin' })
      setMsg('Staff created')
      load()
    } catch (e: any) { setMsg(e.message) }
    setBusy(false)
  }

  const handleToggleActive = async (s: AdminUser) => {
    await api.updateStaff(s.id, { is_active: !s.is_active })
    load()
  }

  const handlePushLogin = async () => {
    if (!pushForm.client_id || !pushForm.admin_user_id || !pushForm.login_email || !pushForm.login_password) return
    setBusy(true)
    try {
      const r = await api.pushSupportLogin(pushForm)
      setMsg(`Support login '${r.login_email}' pushed to ${r.client}`)
      setShowPush(false)
      setPushForm({ client_id: '', admin_user_id: '', login_email: '', login_password: '', reason: '' })
      load()
    } catch (e: any) { setMsg(e.message) }
    setBusy(false)
  }

  const handleRevoke = async (sl: SupportLogin) => {
    if (!confirm(`Revoke support login '${sl.login_email}'?`)) return
    try {
      await api.revokeSupportLogin(sl.id)
      setMsg(`Revoked '${sl.login_email}'`)
      load()
    } catch (e: any) { setMsg(e.message) }
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

  // Find names for IDs
  const staffName = (id: string) => staff.find(s => s.id === id)?.full_name || id.slice(0, 8)
  const clientName = (id: string) => clients.find(c => c.id === id)?.code || id.slice(0, 8)

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h1 style={{ fontSize: '1.3rem', margin: 0 }}>👤 Staff Management</h1>
        <div style={{ display: 'flex', gap: 6 }}>
          {tab === 'support' && (
            <button onClick={() => setShowPush(true)} style={{ padding: '6px 14px', background: '#27ae60', color: '#fff', border: 'none', borderRadius: 4, cursor: 'pointer', fontSize: 13, fontWeight: 600 }}>🔑 Push Support Login</button>
          )}
          {tab === 'staff' && (
            <button onClick={() => setShowCreate(true)} style={{ padding: '6px 14px', background: '#800020', color: '#fff', border: 'none', borderRadius: 4, cursor: 'pointer', fontSize: 13, fontWeight: 600 }}>+ Add Staff</button>
          )}
        </div>
      </div>

      {msg && <div style={{ padding: 8, background: '#fef3cd', borderRadius: 4, marginBottom: 12, fontSize: 13 }}>{msg} <button onClick={() => setMsg('')} style={{ border: 'none', background: 'none', cursor: 'pointer' }}>✕</button></div>}

      <div style={{ display: 'flex', gap: 6, marginBottom: 16 }}>
        {tabBtn('staff', '👥 CC Staff')}
        {tabBtn('support', '🔑 Support Logins')}
      </div>

      {/* Create Staff */}
      {showCreate && (
        <div style={{ background: '#fff', border: '1px solid #ddd', borderRadius: 8, padding: 20, marginBottom: 16 }}>
          <h3 style={{ margin: '0 0 12px', fontSize: 14 }}>Add New Staff Member</h3>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10, marginBottom: 10 }}>
            <input placeholder="Username" value={form.username} onChange={e => setForm({ ...form, username: e.target.value })} style={{ padding: 8, border: '1px solid #ddd', borderRadius: 4, fontSize: 13 }} />
            <input placeholder="Full Name" value={form.full_name} onChange={e => setForm({ ...form, full_name: e.target.value })} style={{ padding: 8, border: '1px solid #ddd', borderRadius: 4, fontSize: 13 }} />
            <input placeholder="Email (optional)" value={form.email} onChange={e => setForm({ ...form, email: e.target.value })} style={{ padding: 8, border: '1px solid #ddd', borderRadius: 4, fontSize: 13 }} />
            <input type="password" placeholder="Password" value={form.password} onChange={e => setForm({ ...form, password: e.target.value })} style={{ padding: 8, border: '1px solid #ddd', borderRadius: 4, fontSize: 13 }} />
          </div>
          <select value={form.role} onChange={e => setForm({ ...form, role: e.target.value })} style={{ padding: 8, border: '1px solid #ddd', borderRadius: 4, fontSize: 13, marginBottom: 10 }}>
            <option value="admin">Admin</option>
            <option value="support_engineer">Support Engineer</option>
            <option value="viewer">Viewer</option>
            <option value="super_admin">Super Admin</option>
          </select>
          <div style={{ display: 'flex', gap: 8 }}>
            <button onClick={handleCreate} disabled={busy} style={{ padding: '6px 16px', background: '#800020', color: '#fff', border: 'none', borderRadius: 4, cursor: 'pointer', fontSize: 13, fontWeight: 600 }}>Create</button>
            <button onClick={() => setShowCreate(false)} style={{ padding: '6px 16px', background: '#eee', border: 'none', borderRadius: 4, cursor: 'pointer', fontSize: 13 }}>Cancel</button>
          </div>
        </div>
      )}

      {/* Push Support Login */}
      {showPush && (
        <div style={{ background: '#fff', border: '1px solid #ddd', borderRadius: 8, padding: 20, marginBottom: 16 }}>
          <h3 style={{ margin: '0 0 12px', fontSize: 14 }}>Push Support Login to Client</h3>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10, marginBottom: 10 }}>
            <select value={pushForm.client_id} onChange={e => setPushForm({ ...pushForm, client_id: e.target.value })} style={{ padding: 8, border: '1px solid #ddd', borderRadius: 4, fontSize: 13 }}>
              <option value="">— Select Client —</option>
              {clients.filter(c => c.status === 'active').map(c => (
                <option key={c.id} value={c.id}>{c.code} — {c.name}</option>
              ))}
            </select>
            <select value={pushForm.admin_user_id} onChange={e => setPushForm({ ...pushForm, admin_user_id: e.target.value })} style={{ padding: 8, border: '1px solid #ddd', borderRadius: 4, fontSize: 13 }}>
              <option value="">— Select Staff —</option>
              {staff.filter(s => s.is_active).map(s => (
                <option key={s.id} value={s.id}>{s.full_name} ({s.role})</option>
              ))}
            </select>
            <input placeholder="Login Email (in client ERP)" value={pushForm.login_email} onChange={e => setPushForm({ ...pushForm, login_email: e.target.value })} style={{ padding: 8, border: '1px solid #ddd', borderRadius: 4, fontSize: 13 }} />
            <input type="password" placeholder="Login Password" value={pushForm.login_password} onChange={e => setPushForm({ ...pushForm, login_password: e.target.value })} style={{ padding: 8, border: '1px solid #ddd', borderRadius: 4, fontSize: 13 }} />
          </div>
          <input placeholder="Reason (optional)" value={pushForm.reason} onChange={e => setPushForm({ ...pushForm, reason: e.target.value })} style={{ width: '100%', padding: 8, border: '1px solid #ddd', borderRadius: 4, fontSize: 13, marginBottom: 10, boxSizing: 'border-box' }} />
          <div style={{ display: 'flex', gap: 8 }}>
            <button onClick={handlePushLogin} disabled={busy} style={{ padding: '6px 16px', background: '#27ae60', color: '#fff', border: 'none', borderRadius: 4, cursor: 'pointer', fontSize: 13, fontWeight: 600 }}>Push Login</button>
            <button onClick={() => setShowPush(false)} style={{ padding: '6px 16px', background: '#eee', border: 'none', borderRadius: 4, cursor: 'pointer', fontSize: 13 }}>Cancel</button>
          </div>
        </div>
      )}

      {/* Staff Tab */}
      {tab === 'staff' && (
        <div style={{ background: '#fff', border: '1px solid #ddd', borderRadius: 8, overflow: 'hidden' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
            <thead>
              <tr style={{ borderBottom: '2px solid #eee' }}>
                <th style={{ textAlign: 'left', padding: '10px 14px', color: '#888', fontWeight: 600, fontSize: 11, textTransform: 'uppercase' }}>Username</th>
                <th style={{ textAlign: 'left', padding: '10px 14px', color: '#888', fontWeight: 600, fontSize: 11, textTransform: 'uppercase' }}>Full Name</th>
                <th style={{ textAlign: 'left', padding: '10px 14px', color: '#888', fontWeight: 600, fontSize: 11, textTransform: 'uppercase' }}>Email</th>
                <th style={{ textAlign: 'left', padding: '10px 14px', color: '#888', fontWeight: 600, fontSize: 11, textTransform: 'uppercase' }}>Role</th>
                <th style={{ textAlign: 'left', padding: '10px 14px', color: '#888', fontWeight: 600, fontSize: 11, textTransform: 'uppercase' }}>Status</th>
                <th style={{ textAlign: 'left', padding: '10px 14px', color: '#888', fontWeight: 600, fontSize: 11, textTransform: 'uppercase' }}>Actions</th>
              </tr>
            </thead>
            <tbody>
              {staff.map(s => (
                <tr key={s.id} style={{ borderBottom: '1px solid #f0f0f0' }}>
                  <td style={{ padding: '10px 14px', fontWeight: 600 }}>{s.username}</td>
                  <td style={{ padding: '10px 14px' }}>{s.full_name}</td>
                  <td style={{ padding: '10px 14px', color: '#888', fontSize: 12 }}>{s.email || '—'}</td>
                  <td style={{ padding: '10px 14px' }}>
                    <span style={{ background: ROLE_COLORS[s.role] || '#999', color: '#fff', padding: '2px 8px', borderRadius: 3, fontSize: 11, fontWeight: 600 }}>
                      {ROLE_LABELS[s.role] || s.role}
                    </span>
                  </td>
                  <td style={{ padding: '10px 14px' }}>
                    {s.is_active
                      ? <span style={{ color: '#27ae60', fontWeight: 600 }}>Active</span>
                      : <span style={{ color: '#e74c3c', fontWeight: 600 }}>Disabled</span>
                    }
                  </td>
                  <td style={{ padding: '10px 14px' }}>
                    <button onClick={() => handleToggleActive(s)} style={{ padding: '3px 10px', background: s.is_active ? '#e74c3c' : '#27ae60', color: '#fff', border: 'none', borderRadius: 3, cursor: 'pointer', fontSize: 11, fontWeight: 600 }}>
                      {s.is_active ? 'Disable' : 'Enable'}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {/* Support Logins Tab */}
      {tab === 'support' && (
        <div style={{ background: '#fff', border: '1px solid #ddd', borderRadius: 8, overflow: 'hidden' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
            <thead>
              <tr style={{ borderBottom: '2px solid #eee' }}>
                <th style={{ textAlign: 'left', padding: '10px 14px', color: '#888', fontWeight: 600, fontSize: 11, textTransform: 'uppercase' }}>Staff</th>
                <th style={{ textAlign: 'left', padding: '10px 14px', color: '#888', fontWeight: 600, fontSize: 11, textTransform: 'uppercase' }}>Client</th>
                <th style={{ textAlign: 'left', padding: '10px 14px', color: '#888', fontWeight: 600, fontSize: 11, textTransform: 'uppercase' }}>Login Email</th>
                <th style={{ textAlign: 'left', padding: '10px 14px', color: '#888', fontWeight: 600, fontSize: 11, textTransform: 'uppercase' }}>Status</th>
                <th style={{ textAlign: 'left', padding: '10px 14px', color: '#888', fontWeight: 600, fontSize: 11, textTransform: 'uppercase' }}>Pushed</th>
                <th style={{ textAlign: 'left', padding: '10px 14px', color: '#888', fontWeight: 600, fontSize: 11, textTransform: 'uppercase' }}>Reason</th>
                <th style={{ textAlign: 'left', padding: '10px 14px', color: '#888', fontWeight: 600, fontSize: 11, textTransform: 'uppercase' }}>Action</th>
              </tr>
            </thead>
            <tbody>
              {supportLogins.map(sl => (
                <tr key={sl.id} style={{ borderBottom: '1px solid #f0f0f0' }}>
                  <td style={{ padding: '10px 14px', fontWeight: 500 }}>{staffName(sl.admin_user_id)}</td>
                  <td style={{ padding: '10px 14px' }}>
                    <span style={{ background: '#800020', color: '#fff', padding: '1px 8px', borderRadius: 3, fontSize: 11, fontWeight: 600 }}>{clientName(sl.client_id)}</span>
                  </td>
                  <td style={{ padding: '10px 14px', fontFamily: 'monospace', fontSize: 12 }}>{sl.login_email}</td>
                  <td style={{ padding: '10px 14px' }}>
                    {sl.status === 'active'
                      ? <span style={{ background: '#27ae60', color: '#fff', padding: '2px 8px', borderRadius: 12, fontSize: 11, fontWeight: 600 }}>Active</span>
                      : <span style={{ background: '#e74c3c', color: '#fff', padding: '2px 8px', borderRadius: 12, fontSize: 11, fontWeight: 600 }}>Revoked</span>
                    }
                  </td>
                  <td style={{ padding: '10px 14px', color: '#888', fontSize: 12 }}>{ts(sl.pushed_at)}</td>
                  <td style={{ padding: '10px 14px', fontSize: 12 }}>{sl.reason || '—'}</td>
                  <td style={{ padding: '10px 14px' }}>
                    {sl.status === 'active' && (
                      <button onClick={() => handleRevoke(sl)} style={{ padding: '3px 10px', background: '#e74c3c', color: '#fff', border: 'none', borderRadius: 3, cursor: 'pointer', fontSize: 11, fontWeight: 600 }}>Revoke</button>
                    )}
                  </td>
                </tr>
              ))}
              {supportLogins.length === 0 && (
                <tr><td colSpan={7} style={{ padding: 20, textAlign: 'center', color: '#888' }}>No support logins pushed yet</td></tr>
              )}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
