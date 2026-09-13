import { useEffect, useState, type FormEvent } from 'react'
import { api, type Warehouse } from '../lib/api'

export default function WarehousesPage() {
  const [rows, setRows] = useState<Warehouse[]>([])
  const [error, setError] = useState<string | null>(null)
  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const [address, setAddress] = useState('')

  function refresh() {
    api.listWarehouses().then(setRows).catch((e) => setError(e.message))
  }
  useEffect(refresh, [])

  async function onCreate(e: FormEvent) {
    e.preventDefault()
    setError(null)
    try {
      await api.createWarehouse({ code, name, address: address || undefined })
      setCode(''); setName(''); setAddress('')
      refresh()
    } catch (err) { setError(err instanceof Error ? err.message : 'Failed') }
  }

  async function toggle(w: Warehouse) {
    setError(null)
    try {
      await api.updateWarehouse(w.id, { is_active: !w.is_active })
      refresh()
    } catch (err) { setError(err instanceof Error ? err.message : 'Failed') }
  }

  return (
    <div style={{ padding: 24 }}>
      <h2>Warehouses</h2>
      {error && <p className="error">{error}</p>}
      <form onSubmit={onCreate} style={{ display: 'flex', gap: 8, marginBottom: 16, flexWrap: 'wrap' }}>
        <input placeholder="Code" value={code} onChange={(e) => setCode(e.target.value)} required style={{ width: 100 }} />
        <input placeholder="Name" value={name} onChange={(e) => setName(e.target.value)} required style={{ width: 200 }} />
        <input placeholder="Address" value={address} onChange={(e) => setAddress(e.target.value)} style={{ width: 250 }} />
        <button type="submit">Add Warehouse</button>
      </form>
      <div style={{ overflowX: 'auto' }}>
        <table>
          <thead>
            <tr><th>Code</th><th>Name</th><th>Address</th><th>Active</th><th></th></tr>
          </thead>
          <tbody>
            {rows.map((w) => (
              <tr key={w.id}>
                <td>{w.code}</td><td>{w.name}</td><td>{w.address || '—'}</td>
                <td>{w.is_active ? '✅' : '❌'}</td>
                <td><button className="secondary" onClick={() => toggle(w)}>{w.is_active ? 'Deactivate' : 'Activate'}</button></td>
              </tr>
            ))}
            {!rows.length && <tr><td colSpan={5} style={{ textAlign: 'center' }}>No warehouses yet</td></tr>}
          </tbody>
        </table>
      </div>
    </div>
  )
}
