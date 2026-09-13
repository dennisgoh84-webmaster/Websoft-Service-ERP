import { useEffect, useState } from 'react'
import { api, type ClientSummary, type ClientModule } from '../lib/api'

export default function LicensesPage() {
  const [clients, setClients] = useState<ClientSummary[]>([])
  const [selectedClient, setSelectedClient] = useState<string>('')
  const [modules, setModules] = useState<ClientModule[]>([])
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [msg, setMsg] = useState('')
  const [toggling, setToggling] = useState<string | null>(null)

  // License limit state
  const [editingLimit, setEditingLimit] = useState(false)
  const [limitValue, setLimitValue] = useState('')
  const [savingLimit, setSavingLimit] = useState(false)

  const reloadClients = async () => {
    const c = await api.listClients()
    setClients(c)
    return c
  }

  useEffect(() => { reloadClients() }, [])

  const selectedClientObj = clients.find(c => c.id === selectedClient)

  async function loadModules(clientId: string) {
    setSelectedClient(clientId)
    setModules([])
    setError('')
    setMsg('')
    setEditingLimit(false)
    if (!clientId) return
    setLoading(true)
    try {
      const mods = await api.getClientModules(clientId)
      setModules(mods)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load')
    } finally {
      setLoading(false)
    }
  }

  async function toggleModule(m: ClientModule) {
    if (!selectedClient) return
    setToggling(m.module_key + m.company_id)
    setError('')
    try {
      await api.setModuleLicense(selectedClient, m.company_id, {
        module_key: m.module_key,
        enabled: !m.enabled,
      })
      const mods = await api.getClientModules(selectedClient)
      setModules(mods)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed')
    } finally {
      setToggling(null)
    }
  }

  async function saveLicenseLimit() {
    if (!selectedClient) return
    setSavingLimit(true)
    setError('')
    try {
      const val = limitValue.trim() === '' ? null : parseInt(limitValue, 10)
      if (val !== null && (isNaN(val) || val < 1)) {
        setError('License count must be a positive number or empty for unlimited')
        setSavingLimit(false)
        return
      }
      await api.updateLicenseLimit(selectedClient, val)
      await reloadClients()
      setEditingLimit(false)
      setMsg(`License limit ${val ? `set to ${val}` : 'set to unlimited'}`)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to update')
    } finally {
      setSavingLimit(false)
    }
  }

  async function pushLicenseLimit() {
    if (!selectedClient) return
    setSavingLimit(true)
    setError('')
    try {
      await api.pushLicenseLimit(selectedClient)
      setMsg('License limit pushed to client database')
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Push failed')
    } finally {
      setSavingLimit(false)
    }
  }

  // Group by company
  const byCompany: Record<string, { name: string; modules: ClientModule[] }> = {}
  for (const m of modules) {
    if (!byCompany[m.company_id]) {
      byCompany[m.company_id] = { name: m.company_name, modules: [] }
    }
    byCompany[m.company_id].modules.push(m)
  }

  return (
    <div>
      <h1 style={{ margin: '0 0 16px', fontSize: 22 }}>License Management</h1>

      <div style={{ marginBottom: 20 }}>
        <label style={{ fontSize: 13, fontWeight: 600, marginRight: 8 }}>Select Client:</label>
        <select
          value={selectedClient}
          onChange={(e) => loadModules(e.target.value)}
          style={{ padding: '6px 12px', border: '1px solid #ccc', borderRadius: 4, fontSize: 13, minWidth: 200 }}
        >
          <option value="">— Choose —</option>
          {clients.map((c) => (
            <option key={c.id} value={c.id}>{c.name} ({c.code})</option>
          ))}
        </select>
      </div>

      {error && <div style={{ background: '#fdecea', color: '#c0392b', padding: '6px 12px', borderRadius: 4, fontSize: 13, marginBottom: 12 }}>{error} <button onClick={() => setError('')} style={{ border: 'none', background: 'none', cursor: 'pointer' }}>✕</button></div>}
      {msg && <div style={{ background: '#eafaf1', color: '#27ae60', padding: '6px 12px', borderRadius: 4, fontSize: 13, marginBottom: 12 }}>{msg} <button onClick={() => setMsg('')} style={{ border: 'none', background: 'none', cursor: 'pointer' }}>✕</button></div>}

      {/* License Limit Panel */}
      {selectedClient && selectedClientObj && (
        <div style={{ background: '#fff', borderRadius: 8, border: '1px solid #e0e0e0', padding: '14px 16px', marginBottom: 20 }}>
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 10 }}>
            <div>
              <span style={{ fontSize: 13, fontWeight: 600, marginRight: 8 }}>🔐 Max Concurrent Logins:</span>
              {!editingLimit ? (
                <>
                  <span style={{
                    display: 'inline-block',
                    padding: '2px 10px',
                    borderRadius: 12,
                    fontSize: 12,
                    fontWeight: 600,
                    color: '#fff',
                    background: selectedClientObj.max_licenses ? '#3498db' : '#27ae60',
                  }}>
                    {selectedClientObj.max_licenses ? `${selectedClientObj.max_licenses} users` : 'Unlimited'}
                  </span>
                </>
              ) : (
                <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
                  <input
                    type="number"
                    min={1}
                    placeholder="Empty = unlimited"
                    value={limitValue}
                    onChange={e => setLimitValue(e.target.value)}
                    style={{ width: 120, padding: '4px 8px', border: '1px solid #ccc', borderRadius: 4, fontSize: 12 }}
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
                    setLimitValue(selectedClientObj.max_licenses ? String(selectedClientObj.max_licenses) : '')
                    setEditingLimit(true)
                  }}
                  style={{ padding: '4px 12px', background: '#3498db', color: '#fff', border: 'none', borderRadius: 4, cursor: 'pointer', fontSize: 11, fontWeight: 600 }}
                >
                  ✏️ Edit
                </button>
              )}
              <button
                onClick={pushLicenseLimit}
                disabled={savingLimit}
                style={{ padding: '4px 12px', background: '#800020', color: '#fff', border: 'none', borderRadius: 4, cursor: 'pointer', fontSize: 11, fontWeight: 600 }}
              >
                {savingLimit ? '...' : '⬆ Push to Client'}
              </button>
            </div>
          </div>
          <p style={{ margin: '6px 0 0', fontSize: 11, color: '#888' }}>
            Controls how many users can be logged in simultaneously. Leave empty for no limit. Push to apply to client's database.
          </p>
        </div>
      )}

      {loading && <p style={{ color: '#888' }}>Loading modules from client database...</p>}

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

      {selectedClient && !loading && modules.length === 0 && (
        <p style={{ color: '#888', fontSize: 13 }}>No modules found in client database.</p>
      )}
    </div>
  )
}
