import { useEffect, useState, type FormEvent } from 'react'
import { Link, useParams } from 'react-router-dom'
import {
  api,
  type StockItemRow,
  type StockLevelRow,
  type StockMovementRow,
  type Warehouse,
} from '../lib/api'
import { formatMoney as money } from '../lib/format'

export default function StockItemDetailPage() {
  const { id } = useParams<{ id: string }>()

  const [item, setItem] = useState<StockItemRow | null>(null)
  const [levels, setLevels] = useState<StockLevelRow[]>([])
  const [movements, setMovements] = useState<StockMovementRow[]>([])
  const [warehouses, setWarehouses] = useState<Warehouse[]>([])
  const [error, setError] = useState<string | null>(null)
  const [editing, setEditing] = useState(false)
  const [saving, setSaving] = useState(false)

  /* edit form state */
  const [editCode, setEditCode] = useState('')
  const [editName, setEditName] = useState('')
  const [editDesc, setEditDesc] = useState('')
  const [editCategory, setEditCategory] = useState('')
  const [editUom, setEditUom] = useState('')
  const [editReorder, setEditReorder] = useState('')

  function refresh() {
    if (!id) return
    api.getStockItem(id).then((d) => { setItem(d); populateForm(d) }).catch((e) => setError(e.message))
    api.listStockLevels().then((all) => setLevels(all.filter((l) => l.stock_item_id === id))).catch(() => {})
    api.listStockMovements({ stock_item_id: id, limit: 20 }).then(setMovements).catch(() => {})
    api.listWarehouses().then(setWarehouses).catch(() => {})
  }
  useEffect(refresh, [id])

  function populateForm(d: StockItemRow) {
    setEditCode(d.code)
    setEditName(d.name)
    setEditDesc(d.description || '')
    setEditCategory(d.category || '')
    setEditUom(d.unit_of_measure)
    setEditReorder(String(d.reorder_level))
  }

  async function onSave(e: FormEvent) {
    e.preventDefault(); setError(null); setSaving(true)
    try {
      const updated = await api.updateStockItem(id!, {
        code: editCode,
        name: editName,
        description: editDesc || undefined,
        category: editCategory || undefined,
        unit_of_measure: editUom || 'PCS',
        reorder_level: parseInt(editReorder) || 0,
      })
      setItem(updated)
      setEditing(false)
    } catch (err) { setError(err instanceof Error ? err.message : 'Failed') }
    finally { setSaving(false) }
  }

  async function toggleActive() {
    if (!item) return
    setError(null)
    try {
      const updated = await api.updateStockItem(item.id, { is_active: !item.is_active })
      setItem(updated)
    } catch (err) { setError(err instanceof Error ? err.message : 'Failed') }
  }

  const whMap = Object.fromEntries(warehouses.map((w) => [w.id, w]))

  if (!item) return <div style={{ padding: 24 }}><p>Loading...</p></div>

  /* total stock across all warehouses */
  const totalQty = levels.reduce((s, l) => s + l.quantity, 0)
  const totalValue = levels.reduce((s, l) => s + l.quantity * l.avg_cost, 0)

  return (
    <div style={{ padding: 24 }}>
      <div style={{ marginBottom: 16 }}>
        <Link to="/stock-master" style={{ fontSize: '0.9em' }}>← Back to Stock Master</Link>
      </div>

      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: 12, marginBottom: 16 }}>
        <div>
          <h2 style={{ margin: 0 }}>{item.code} — {item.name}</h2>
          <span className={`badge badge-${item.is_active ? 'success' : 'neutral'}`} style={{ marginTop: 4, display: 'inline-block' }}>
            {item.is_active ? 'Active' : 'Inactive'}
          </span>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          {!editing && <button onClick={() => setEditing(true)}>Edit</button>}
          <button className="secondary" onClick={toggleActive}>
            {item.is_active ? 'Deactivate' : 'Activate'}
          </button>
        </div>
      </div>

      {error && <p className="error">{error}</p>}

      {/* ── Details / Edit Form ──────────────────────────────────── */}
      {editing ? (
        <form onSubmit={onSave} style={{ border: '1px solid var(--border)', padding: 16, borderRadius: 8, marginBottom: 24 }}>
          <h3 style={{ marginTop: 0 }}>Edit Stock Item</h3>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))', gap: 12, marginBottom: 12 }}>
            <div><label>Code *</label><input value={editCode} onChange={(e) => setEditCode(e.target.value)} required /></div>
            <div><label>Name *</label><input value={editName} onChange={(e) => setEditName(e.target.value)} required /></div>
            <div><label>Category</label><input value={editCategory} onChange={(e) => setEditCategory(e.target.value)} /></div>
            <div><label>Unit of Measure</label><input value={editUom} onChange={(e) => setEditUom(e.target.value)} /></div>
            <div><label>Reorder Level</label><input type="number" value={editReorder} onChange={(e) => setEditReorder(e.target.value)} /></div>
          </div>
          <div>
            <label>Description</label>
            <textarea value={editDesc} onChange={(e) => setEditDesc(e.target.value)} rows={3} style={{ width: '100%' }} />
          </div>
          <div style={{ display: 'flex', gap: 8, marginTop: 12 }}>
            <button type="submit" disabled={saving}>{saving ? 'Saving...' : 'Save'}</button>
            <button type="button" className="secondary" onClick={() => { setEditing(false); populateForm(item) }}>Cancel</button>
          </div>
        </form>
      ) : (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))', gap: 12, marginBottom: 24, border: '1px solid var(--border)', padding: 16, borderRadius: 8 }}>
          <div><label style={{ fontSize: '0.8em', color: 'var(--muted)' }}>Code</label><div><strong>{item.code}</strong></div></div>
          <div><label style={{ fontSize: '0.8em', color: 'var(--muted)' }}>Name</label><div>{item.name}</div></div>
          <div><label style={{ fontSize: '0.8em', color: 'var(--muted)' }}>Category</label><div>{item.category || '—'}</div></div>
          <div><label style={{ fontSize: '0.8em', color: 'var(--muted)' }}>Unit of Measure</label><div>{item.unit_of_measure}</div></div>
          <div><label style={{ fontSize: '0.8em', color: 'var(--muted)' }}>Reorder Level</label><div>{item.reorder_level}</div></div>
          <div><label style={{ fontSize: '0.8em', color: 'var(--muted)' }}>Description</label><div>{item.description || '—'}</div></div>
        </div>
      )}

      {/* ── Summary Tiles ────────────────────────────────────────── */}
      <div style={{ display: 'flex', gap: 16, marginBottom: 24, flexWrap: 'wrap' }}>
        <div style={{ border: '1px solid var(--border)', borderRadius: 8, padding: '12px 20px', minWidth: 140, textAlign: 'center' }}>
          <div style={{ fontSize: '0.8em', color: 'var(--muted)' }}>Total Qty</div>
          <div style={{ fontSize: '1.6em', fontWeight: 'bold' }}>{totalQty}</div>
        </div>
        <div style={{ border: '1px solid var(--border)', borderRadius: 8, padding: '12px 20px', minWidth: 140, textAlign: 'center' }}>
          <div style={{ fontSize: '0.8em', color: 'var(--muted)' }}>Total Value</div>
          <div style={{ fontSize: '1.6em', fontWeight: 'bold' }}>{money(totalValue)}</div>
        </div>
        <div style={{ border: '1px solid var(--border)', borderRadius: 8, padding: '12px 20px', minWidth: 140, textAlign: 'center' }}>
          <div style={{ fontSize: '0.8em', color: 'var(--muted)' }}>Warehouses</div>
          <div style={{ fontSize: '1.6em', fontWeight: 'bold' }}>{levels.length}</div>
        </div>
      </div>

      {/* ── Stock Levels by Warehouse ────────────────────────────── */}
      <h3>Stock Levels by Warehouse</h3>
      <div style={{ overflowX: 'auto', marginBottom: 24 }}>
        <table>
          <thead>
            <tr>
              <th>Warehouse</th>
              <th style={{ textAlign: 'right' }}>Quantity</th>
              <th style={{ textAlign: 'right' }}>Avg Cost</th>
              <th style={{ textAlign: 'right' }}>Total Value</th>
            </tr>
          </thead>
          <tbody>
            {levels.map((l) => (
              <tr key={l.id}>
                <td>{l.warehouse_code} — {l.warehouse_name}</td>
                <td style={{ textAlign: 'right' }}>{l.quantity}</td>
                <td style={{ textAlign: 'right' }}>{money(l.avg_cost)}</td>
                <td style={{ textAlign: 'right' }}>{money(l.quantity * l.avg_cost)}</td>
              </tr>
            ))}
            {!levels.length && <tr><td colSpan={4} style={{ textAlign: 'center' }}>No stock in any warehouse</td></tr>}
          </tbody>
        </table>
      </div>

      {/* ── Recent Stock Movements ───────────────────────────────── */}
      <h3>Recent Movements</h3>
      <div style={{ overflowX: 'auto' }}>
        <table>
          <thead>
            <tr>
              <th>Date</th>
              <th>Warehouse</th>
              <th>Type</th>
              <th style={{ textAlign: 'right' }}>Qty</th>
              <th style={{ textAlign: 'right' }}>Unit Cost</th>
              <th style={{ textAlign: 'right' }}>Total</th>
              <th>Ref</th>
            </tr>
          </thead>
          <tbody>
            {movements.map((m) => (
              <tr key={m.id}>
                <td>{new Date(m.created_at).toLocaleDateString()}</td>
                <td>{whMap[m.warehouse_id]?.code || '?'}</td>
                <td>{m.movement_type.replace('_', ' ')}</td>
                <td style={{ textAlign: 'right', color: m.quantity >= 0 ? 'green' : 'red' }}>
                  {m.quantity > 0 ? '+' : ''}{m.quantity}
                </td>
                <td style={{ textAlign: 'right' }}>{money(m.unit_cost)}</td>
                <td style={{ textAlign: 'right' }}>{money(m.total_cost)}</td>
                <td style={{ fontSize: '0.85em' }}>{m.reference_type?.toUpperCase()}</td>
              </tr>
            ))}
            {!movements.length && <tr><td colSpan={7} style={{ textAlign: 'center' }}>No movements yet</td></tr>}
          </tbody>
        </table>
      </div>
    </div>
  )
}
