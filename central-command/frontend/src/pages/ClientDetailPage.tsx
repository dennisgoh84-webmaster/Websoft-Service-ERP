import { useEffect, useState } from 'react'
import { useParams, Link } from 'react-router-dom'
import { api, type Client, type ClientSummary, type ConnectionTestResult, type ClientModule } from '../lib/api'

type Tab = 'details' | 'modules' | 'licenses'

export default function ClientDetailPage() {
  const { id } = useParams<{ id: string }>()
  const [client, setClient] = useState<Client | null>(null)
  const [clientSummary, setClientSummary] = useState<ClientSummary | null>(null)
  const [testResult, setTestResult] = useState<ConnectionTestResult | null>(null)
  const [testing, setTesting] = useState(false)
  const [error, setError] = useState('')
  const [tab, setTab] = useState<Tab>('details')

  // Module state
  const [modules, setModules] = useState<ClientModule[]>([])
  const [modulesLoading, setModulesLoading] = useState(false)
  const [modulesLoaded, setModulesLoaded] = useState(false)
  const [toggling, setToggling] = useState<string | null>(null)
  const [moduleMsg, setModuleMsg] = useState('')

  // License limit state
  const [editingLimit, setEditingLimit] = useState(false)
  const [limitValue, setLimitValue] = useState('')
  const [savingLimit, setSavingLimit] = useState(false)
  const [licenseMsg, setLicenseMsg] = useState('')

  useEffect(() => {
    if (id) {
      api.getClient(id).then(setClient)
      // Also load summary for max_licenses
      api.listClients().then((clients) => {
        const found = clients.find(c => c.id === id)
        if (found) setClientSummary(found)
      })
    }
  }, [id])

  async function onTest() {
    if (!id) return
    setTesting(true)
    setTestResult(null)
    setError('')
    try {
      const res = await api.testConnection(id)
      setTestResult(res)
      api.getClient(id).then(setClient)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Test failed')
    } finally {
      setTesting(false)
    }
  }

  async function onDelete() {
    if (!id || !window.confirm('Delete this client? This cannot be undone.')) return
    await api.deleteClient(id)
    window.location.href = '/clients'
  }

  async function onSuspend() {
    if (!id) return
    await api.updateClient(id, { status: 'suspended' } as Partial<Client>)
    api.getClient(id).then(setClient)
  }

  async function onActivate() {
    if (!id) return
    await api.updateClient(id, { status: 'active' } as Partial<Client>)
    api.getClient(id).then(setClient)
  }

  // ── Module functions ─────────────────────────────────────────────
  async function loadModules() {
    if (!id) return
    setModulesLoading(true)
    setError('')
    setModuleMsg('')
    try {
      const mods = await api.getClientModules(id)
      setModules(mods)
      setModulesLoaded(true)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load modules')
    } finally {
      setModulesLoading(false)
    }
  }

  async function toggleModule(m: ClientModule) {
    if (!id) return
    setToggling(m.module_key + m.company_id)
    setError('')
    try {
      await api.setModuleLicense(id, m.company_id, {
        module_key: m.module_key,
        enabled: !m.enabled,
      })
      const mods = await api.getClientModules(id)
      setModules(mods)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed')
    } finally {
      setToggling(null)
    }
  }

  // ── License limit functions ──────────────────────────────────────
  async function saveLicenseLimit() {
    if (!id) return
    setSavingLimit(true)
    setError('')
    try {
      const val = limitValue.trim() === '' ? null : parseInt(limitValue, 10)
      if (val !== null && (isNaN(val) || val < 1)) {
        setError('License count must be a positive number or empty for unlimited')
        setSavingLimit(false)
        return
      }
      await api.updateLicenseLimit(id, val)
      // Refresh summary
      const clients = await api.listClients()
      const found = clients.find(c => c.id === id)
      if (found) setClientSummary(found)
      setEditingLimit(false)
      setLicenseMsg(`License limit ${val ? `set to ${val}` : 'set to unlimited'}`)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to update')
    } finally {
      setSavingLimit(false)
    }
  }

  async function pushLicenseLimit() {
    if (!id) return
    setSavingLimit(true)
    setError('')
    try {
      await api.pushLicenseLimit(id)
      setLicenseMsg('License limit pushed to client database')
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Push failed')
    } finally {
      setSavingLimit(false)
    }
  }

  // Switch tab and lazy-load modules
  function switchTab(t: Tab) {
    setTab(t)
    setError('')
    if (t === 'modules' && !modulesLoaded) {
      loadModules()
    }
  }

  if (!client) return <p>Loading...</p>

  const statusColor = client.status === 'active' ? '#27ae60' : client.status === 'suspended' ? '#e74c3c' : '#95a5a6'

  // Group modules by company
  const byCompany: Record<string, { name: string; modules: ClientModule[] }> = {}
  for (const m of modules) {
    if (!byCompany[m.company_id]) {
      byCompany[m.company_id] = { name: m.company_name, modules: [] }
    }
    byCompany[m.company_id].modules.push(m)
  }

  const tabStyle = (t: Tab) => ({
    padding: '8px 20px',
    border: 'none',
    borderBottom: tab === t ? '3px solid #800020' : '3px solid transparent',
    background: 'none',
    cursor: 'pointer',
    fontSize: 13,
    fontWeight: tab === t ? 600 : 400,
    color: tab === t ? '#800020' : '#666',
  })

  return (
    <div>
      <Link to="/clients" style={{ color: '#800020', fontSize: 13 }}>← Back to Clients</Link>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginTop: 12 }}>
        <div>
          <h1 style={{ margin: '0 0 4px', fontSize: 22 }}>{client.name}</h1>
          <p style={{ margin: 0, color: '#888', fontSize: 13 }}>
            Code: <strong>{client.code}</strong>
            <span style={{ marginLeft: 12, display: 'inline-block', padding: '1px 8px', borderRadius: 4, fontSize: 11, fontWeight: 600, color: '#fff', background: statusColor }}>
              {client.status}
            </span>
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          {client.status === 'active' && (
            <button onClick={onSuspend} style={{ background: '#e74c3c', color: '#fff', border: 'none', padding: '6px 14px', borderRadius: 4, cursor: 'pointer', fontSize: 12 }}>
              Suspend
            </button>
          )}
          {client.status === 'suspended' && (
            <button onClick={onActivate} style={{ background: '#27ae60', color: '#fff', border: 'none', padding: '6px 14px', borderRadius: 4, cursor: 'pointer', fontSize: 12 }}>
              Activate
            </button>
          )}
          <button onClick={onDelete} style={{ background: '#ccc', color: '#333', border: 'none', padding: '6px 14px', borderRadius: 4, cursor: 'pointer', fontSize: 12 }}>
            Delete
          </button>
        </div>
      </div>

      {/* Tab bar */}
      <div style={{ display: 'flex', borderBottom: '1px solid #ddd', marginTop: 20, marginBottom: 20 }}>
        <button onClick={() => switchTab('details')} style={tabStyle('details')}>📋 Details</button>
        <button onClick={() => switchTab('modules')} style={tabStyle('modules')}>🧩 Client Modules</button>
        <button onClick={() => switchTab('licenses')} style={tabStyle('licenses')}>🔑 Client Licenses</button>
      </div>

      {error && <div style={{ background: '#fdecea', color: '#c0392b', padding: '6px 12px', borderRadius: 4, fontSize: 13, marginBottom: 12 }}>{error} <button onClick={() => setError('')} style={{ border: 'none', background: 'none', cursor: 'pointer' }}>✕</button></div>}

      {/* ── Details Tab ──────────────────────────────────────────── */}
      {tab === 'details' && (
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 20 }}>
          {/* Connection Details */}
          <div style={{ background: '#fff', borderRadius: 8, padding: 20, border: '1px solid #e0e0e0' }}>
            <h2 style={{ fontSize: 15, margin: '0 0 12px', color: '#800020' }}>Connection Details</h2>
            <table style={{ fontSize: 13 }}>
              <tbody>
                <tr><td style={{ color: '#888', paddingRight: 16, paddingBottom: 6 }}>Host</td><td style={{ paddingBottom: 6 }}>{client.db_host}</td></tr>
                <tr><td style={{ color: '#888', paddingRight: 16, paddingBottom: 6 }}>Port</td><td style={{ paddingBottom: 6 }}>{client.db_port}</td></tr>
                <tr><td style={{ color: '#888', paddingRight: 16, paddingBottom: 6 }}>Database</td><td style={{ paddingBottom: 6 }}>{client.db_name}</td></tr>
                <tr><td style={{ color: '#888', paddingRight: 16, paddingBottom: 6 }}>Username</td><td style={{ paddingBottom: 6 }}>{client.db_username}</td></tr>
                <tr><td style={{ color: '#888', paddingRight: 16, paddingBottom: 6 }}>TLS</td><td style={{ paddingBottom: 6 }}>{client.db_use_tls ? 'Yes' : 'No'}</td></tr>
              </tbody>
            </table>

            <button
              onClick={onTest}
              disabled={testing}
              style={{ marginTop: 12, background: '#3498db', color: '#fff', border: 'none', padding: '6px 14px', borderRadius: 4, cursor: 'pointer', fontSize: 12 }}
            >
              {testing ? 'Testing...' : 'Test Connection'}
            </button>

            {testResult && (
              <div style={{ marginTop: 12, padding: 12, borderRadius: 6, background: testResult.success ? '#eafaf1' : '#fdecea', border: `1px solid ${testResult.success ? '#27ae60' : '#e74c3c'}`, fontSize: 12 }}>
                <strong>{testResult.success ? '✅ Connected' : '❌ Failed'}</strong>
                <p style={{ margin: '4px 0 0' }}>{testResult.message}</p>
                {testResult.alembic_head && <p style={{ margin: '2px 0 0' }}>Alembic head: <code>{testResult.alembic_head}</code></p>}
                {testResult.companies && (
                  <div style={{ marginTop: 6 }}>
                    <strong>Companies ({testResult.companies.length}):</strong>
                    <ul style={{ margin: '4px 0 0', paddingLeft: 16 }}>
                      {testResult.companies.map((c) => (
                        <li key={c.id}>{c.name} {c.registration_number && `(${c.registration_number})`}</li>
                      ))}
                    </ul>
                  </div>
                )}
              </div>
            )}
          </div>

          {/* Info */}
          <div style={{ background: '#fff', borderRadius: 8, padding: 20, border: '1px solid #e0e0e0' }}>
            <h2 style={{ fontSize: 15, margin: '0 0 12px', color: '#800020' }}>Information</h2>
            <table style={{ fontSize: 13 }}>
              <tbody>
                <tr><td style={{ color: '#888', paddingRight: 16, paddingBottom: 6 }}>Last Connected</td><td style={{ paddingBottom: 6 }}>{client.last_connected_at ? new Date(client.last_connected_at).toLocaleString() : '—'}</td></tr>
                <tr><td style={{ color: '#888', paddingRight: 16, paddingBottom: 6 }}>Alembic Head</td><td style={{ paddingBottom: 6, fontFamily: 'monospace', fontSize: 11 }}>{client.last_known_alembic_head ?? '—'}</td></tr>
                <tr><td style={{ color: '#888', paddingRight: 16, paddingBottom: 6 }}>Created</td><td style={{ paddingBottom: 6 }}>{new Date(client.created_at).toLocaleString()}</td></tr>
                <tr><td style={{ color: '#888', paddingRight: 16, paddingBottom: 6 }}>Updated</td><td style={{ paddingBottom: 6 }}>{new Date(client.updated_at).toLocaleString()}</td></tr>
              </tbody>
            </table>
            {client.notes && (
              <div style={{ marginTop: 12, padding: 10, background: '#f9f9f9', borderRadius: 4, fontSize: 12, color: '#555' }}>
                <strong>Notes:</strong> {client.notes}
              </div>
            )}
          </div>
        </div>
      )}

      {/* ── Client Modules Tab ───────────────────────────────────── */}
      {tab === 'modules' && (
        <div>
          {moduleMsg && <div style={{ background: '#eafaf1', color: '#27ae60', padding: '6px 12px', borderRadius: 4, fontSize: 13, marginBottom: 12 }}>{moduleMsg} <button onClick={() => setModuleMsg('')} style={{ border: 'none', background: 'none', cursor: 'pointer' }}>✕</button></div>}

          {modulesLoading && <p style={{ color: '#888' }}>Loading modules from client database...</p>}

          {!modulesLoading && modulesLoaded && modules.length === 0 && (
            <p style={{ color: '#888', fontSize: 13 }}>No modules found in client database. Test the connection first to ensure connectivity.</p>
          )}

          {Object.entries(byCompany).map(([companyId, { name, modules: mods }]) => (
            <div key={companyId} style={{ background: '#fff', borderRadius: 8, border: '1px solid #e0e0e0', marginBottom: 20, overflow: 'hidden' }}>
              <div style={{ background: '#f9f9f9', padding: '10px 16px', borderBottom: '1px solid #e0e0e0' }}>
                <h2 style={{ margin: 0, fontSize: 14, color: '#333' }}>🏢 {name}</h2>
              </div>
              <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
                <thead>
                  <tr style={{ borderBottom: '2px solid #eee' }}>
                    <th style={{ textAlign: 'left', padding: '8px 12px', color: '#888', fontWeight: 600, fontSize: 12 }}>Module</th>
                    <th style={{ textAlign: 'left', padding: '8px 12px', color: '#888', fontWeight: 600, fontSize: 12 }}>Key</th>
                    <th style={{ textAlign: 'left', padding: '8px 12px', color: '#888', fontWeight: 600, fontSize: 12 }}>Built</th>
                    <th style={{ textAlign: 'left', padding: '8px 12px', color: '#888', fontWeight: 600, fontSize: 12 }}>License</th>
                    <th style={{ textAlign: 'left', padding: '8px 12px', color: '#888', fontWeight: 600, fontSize: 12 }}>Status</th>
                    <th style={{ textAlign: 'left', padding: '8px 12px', color: '#888', fontWeight: 600, fontSize: 12 }}>Action</th>
                  </tr>
                </thead>
                <tbody>
                  {mods.map((m) => (
                    <tr key={m.module_key} style={{ borderBottom: '1px solid #f0f0f0' }}>
                      <td style={{ padding: '8px 12px', fontWeight: 500 }}>{m.module_name}</td>
                      <td style={{ padding: '8px 12px', fontFamily: 'monospace', fontSize: 11 }}>{m.module_key}</td>
                      <td style={{ padding: '8px 12px' }}>
                        <span style={{ color: m.is_built ? '#27ae60' : '#888' }}>{m.is_built ? '✓' : '—'}</span>
                      </td>
                      <td style={{ padding: '8px 12px' }}>
                        <span style={{ display: 'inline-block', padding: '1px 6px', borderRadius: 3, fontSize: 11, background: '#f0f0f0' }}>
                          {m.license_type}
                        </span>
                      </td>
                      <td style={{ padding: '8px 12px' }}>
                        <span style={{
                          display: 'inline-block',
                          padding: '2px 10px',
                          borderRadius: 12,
                          fontSize: 11,
                          fontWeight: 600,
                          color: '#fff',
                          background: m.enabled ? '#27ae60' : '#e74c3c',
                        }}>
                          {m.enabled ? 'ENABLED' : 'DISABLED'}
                        </span>
                      </td>
                      <td style={{ padding: '8px 12px' }}>
                        <button
                          onClick={() => toggleModule(m)}
                          disabled={toggling === m.module_key + m.company_id}
                          style={{
                            background: m.enabled ? '#e74c3c' : '#27ae60',
                            color: '#fff',
                            border: 'none',
                            padding: '3px 10px',
                            borderRadius: 3,
                            cursor: 'pointer',
                            fontSize: 11,
                          }}
                        >
                          {toggling === m.module_key + m.company_id
                            ? '...'
                            : m.enabled ? 'Disable' : 'Enable'}
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ))}

          {modulesLoaded && modules.length > 0 && (
            <button
              onClick={loadModules}
              style={{ background: '#3498db', color: '#fff', border: 'none', padding: '6px 14px', borderRadius: 4, cursor: 'pointer', fontSize: 12 }}
            >
              🔄 Refresh Modules
            </button>
          )}
        </div>
      )}

      {/* ── Client Licenses Tab ──────────────────────────────────── */}
      {tab === 'licenses' && clientSummary && (
        <div>
          {licenseMsg && <div style={{ background: '#eafaf1', color: '#27ae60', padding: '6px 12px', borderRadius: 4, fontSize: 13, marginBottom: 12 }}>{licenseMsg} <button onClick={() => setLicenseMsg('')} style={{ border: 'none', background: 'none', cursor: 'pointer' }}>✕</button></div>}

          <div style={{ background: '#fff', borderRadius: 8, border: '1px solid #e0e0e0', padding: '20px 24px' }}>
            <h2 style={{ fontSize: 15, margin: '0 0 16px', color: '#800020' }}>🔐 Max Concurrent Logins</h2>

            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 10 }}>
              <div style={{ fontSize: 13 }}>
                <span style={{ fontWeight: 600, marginRight: 8 }}>Current Limit:</span>
                {!editingLimit ? (
                  <span style={{
                    display: 'inline-block',
                    padding: '2px 10px',
                    borderRadius: 12,
                    fontSize: 12,
                    fontWeight: 600,
                    color: '#fff',
                    background: clientSummary.max_licenses ? '#3498db' : '#27ae60',
                  }}>
                    {clientSummary.max_licenses ? `${clientSummary.max_licenses} users` : 'Unlimited'}
                  </span>
                ) : (
                  <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
                    <input
                      type="number"
                      min={1}
                      placeholder="Empty = unlimited"
                      value={limitValue}
                      onChange={e => setLimitValue(e.target.value)}
                      style={{ width: 140, padding: '4px 8px', border: '1px solid #ccc', borderRadius: 4, fontSize: 12 }}
                    />
                    <button
                      onClick={saveLicenseLimit}
                      disabled={savingLimit}
                      style={{ padding: '4px 10px', background: '#27ae60', color: '#fff', border: 'none', borderRadius: 4, cursor: 'pointer', fontSize: 11, fontWeight: 600 }}
                    >
                      {savingLimit ? '...' : 'Save'}
                    </button>
                    <button
                      onClick={() => setEditingLimit(false)}
                      style={{ padding: '4px 10px', background: '#eee', border: 'none', borderRadius: 4, cursor: 'pointer', fontSize: 11 }}
                    >
                      Cancel
                    </button>
                  </span>
                )}
              </div>
              <div style={{ display: 'flex', gap: 6 }}>
                {!editingLimit && (
                  <button
                    onClick={() => {
                      setLimitValue(clientSummary.max_licenses ? String(clientSummary.max_licenses) : '')
                      setEditingLimit(true)
                    }}
                    style={{ padding: '5px 14px', background: '#3498db', color: '#fff', border: 'none', borderRadius: 4, cursor: 'pointer', fontSize: 12, fontWeight: 600 }}
                  >
                    ✏️ Edit
                  </button>
                )}
                <button
                  onClick={pushLicenseLimit}
                  disabled={savingLimit}
                  style={{ padding: '5px 14px', background: '#800020', color: '#fff', border: 'none', borderRadius: 4, cursor: 'pointer', fontSize: 12, fontWeight: 600 }}
                >
                  {savingLimit ? '...' : '⬆ Push to Client'}
                </button>
              </div>
            </div>
            <p style={{ margin: '10px 0 0', fontSize: 12, color: '#888' }}>
              Controls how many users can be logged in simultaneously. Leave empty for no limit. Push to apply to client's database.
            </p>
          </div>
        </div>
      )}
    </div>
  )
}
