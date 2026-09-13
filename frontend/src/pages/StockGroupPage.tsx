import { useEffect, useState, type FormEvent } from 'react'
import { api, type StockSetupRow } from '../lib/api'

export default function StockGroupPage() {
  const [rows, setRows] = useState<StockSetupRow[]>([])
  const [error, setError] = useState<string | null>(null)
  const [code, setCode] = useState('')
  const [name, setName] = useState('')

  /* inline-edit state */
  const [editId, setEditId] = useState<string | null>(null)
  const [editCode, setEditCode] = useState('')
  const [editName, setEditName] = useState('')

  function refresh() {
    api.listStockGroups().then(setRows).catch((e) => setError(e.message))
  }
  useEffect(refresh, [])

  async function onCreate(e: FormEvent) {
    e.preventDefault(); setError(null)
    try {
      await api.createStockGroup({ code, name })
      setCode(''); setName('')
      refresh()
    } catch (err) { setError(err instanceof Error ? err.message : 'Failed') }
  }

  async function toggle(row: StockSetupRow) {
    setError(null)
    try { await api.toggleStockGroup(row.id); refresh() }
    catch (err) { setError(err instanceof Error ? err.message : 'Failed') }
  }

  function startEdit(row: StockSetupRow) {
    setEditId(row.id)
    setEditCode(row.code || '')
    setEditName(row.name)
  }

  async function saveEdit() {
    if (!editId) return; setError(null)
    try {
      await api.updateStockGroup(editId, { code: editCode, name: editName })
      setEditId(null)
      refresh()
    } catch (err) { setError(err instanceof Error ? err.message : 'Failed') }
  }

  return (
    <div style={{ padding: 24 }}>
      <h2>Stock Groups</h2>
      {error && <p className="error">{error}</p>}

      <form onSubmit={onCreate} style={{ display: 'flex', gap: 8, marginBottom: 16, flexWrap: 'wrap' }}>
        <input placeholder="Code" value={code} onChange={(e) => setCode(e.target.value)} required style={{ width: 120 }} />
        <input placeholder="Name" value={name} onChange={(e) => setName(e.target.value)} required style={{ width: 250 }} />
        <button type="submit">Add Group</button>
      </form>

      <div style={{ overflowX: 'auto' }}>
        <table>
          <thead>
            <tr><th>Code</th><th>Name</th><th>Active</th><th></th></tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.id}>
                {editId === r.id ? (
                  <>
                    <td><input value={editCode} onChange={(e) => setEditCode(e.target.value)} style={{ width: 100 }} /></td>
                    <td><input value={editName} onChange={(e) => setEditName(e.target.value)} style={{ width: 200 }} /></td>
                    <td>{r.is_active ? '✅' : '❌'}</td>
                    <td style={{ display: 'flex', gap: 4 }}>
                      <button onClick={saveEdit}>Save</button>
                      <button className="secondary" onClick={() => setEditId(null)}>Cancel</button>
                    </td>
                  </>
                ) : (
                  <>
                    <td>{r.code}</td>
                    <td>{r.name}</td>
                    <td>{r.is_active ? '✅' : '❌'}</td>
                    <td style={{ display: 'flex', gap: 4 }}>
                      <button className="secondary" onClick={() => startEdit(r)}>Edit</button>
                      <button className="secondary" onClick={() => toggle(r)}>{r.is_active ? 'Deactivate' : 'Activate'}</button>
                    </td>
                  </>
                )}
              </tr>
            ))}
            {!rows.length && <tr><td colSpan={4} style={{ textAlign: 'center' }}>No groups yet</td></tr>}
          </tbody>
        </table>
      </div>
    </div>
  )
}
