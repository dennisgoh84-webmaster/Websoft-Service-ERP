import { useEffect, useState, type FormEvent } from 'react'
import {
  api,
  type Warehouse,
  type StockItemRow,
  type StockLevelRow,
} from '../lib/api'
import { formatMoney as money } from '../lib/format'

type Tab = 'items' | 'levels'

export default function StockMasterPage() {
  const [tab, setTab] = useState<Tab>('items')
  const [error, setError] = useState<string | null>(null)

  return (
    <div style={{ padding: 24 }}>
      <h2>Stock Master</h2>
      {error && <p className="error">{error}</p>}
      <div style={{ display: 'flex', gap: 8, marginBottom: 16 }}>
        {(['items', 'levels'] as Tab[]).map((t) => (
          <button
            key={t}
            className={tab === t ? '' : 'secondary'}
            onClick={() => setTab(t)}
          >
            {t === 'items' ? 'Stock Items' : 'Stock Levels'}
          </button>
        ))}
      </div>
      {tab === 'items' && <StockItemTab onError={setError} />}
      {tab === 'levels' && <StockLevelTab onError={setError} />}
    </div>
  )
}

/* ── Stock Items ────────────────────────────────────────────────── */
function StockItemTab({ onError }: { onError: (e: string | null) => void }) {
  const [rows, setRows] = useState<StockItemRow[]>([])
  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const [category, setCategory] = useState('')
  const [uom, setUom] = useState('PCS')
  const [reorder, setReorder] = useState('')

  function refresh() {
    api.listStockItems().then(setRows).catch((e) => onError(e.message))
  }
  useEffect(refresh, [])

  async function onCreate(e: FormEvent) {
    e.preventDefault()
    onError(null)
    try {
      await api.createStockItem({
        code, name,
        category: category || undefined,
        unit_of_measure: uom || 'PCS',
        reorder_level: reorder ? parseInt(reorder) : 0,
      })
      setCode(''); setName(''); setCategory(''); setUom('PCS'); setReorder('')
      refresh()
    } catch (err) { onError(err instanceof Error ? err.message : 'Failed') }
  }

  async function toggle(item: StockItemRow) {
    onError(null)
    try {
      await api.updateStockItem(item.id, { is_active: !item.is_active })
      refresh()
    } catch (err) { onError(err instanceof Error ? err.message : 'Failed') }
  }

  return (
    <>
      <form onSubmit={onCreate} style={{ display: 'flex', gap: 8, marginBottom: 16, flexWrap: 'wrap' }}>
        <input placeholder="Code" value={code} onChange={(e) => setCode(e.target.value)} required style={{ width: 100 }} />
        <input placeholder="Name" value={name} onChange={(e) => setName(e.target.value)} required style={{ width: 200 }} />
        <input placeholder="Category" value={category} onChange={(e) => setCategory(e.target.value)} style={{ width: 130 }} />
        <input placeholder="UoM" value={uom} onChange={(e) => setUom(e.target.value)} style={{ width: 80 }} />
        <input placeholder="Reorder Level" type="number" value={reorder} onChange={(e) => setReorder(e.target.value)} style={{ width: 110 }} />
        <button type="submit">Add Item</button>
      </form>
      <div style={{ overflowX: 'auto' }}>
        <table>
          <thead>
            <tr><th>Code</th><th>Name</th><th>Category</th><th>UoM</th><th>Reorder Level</th><th>Active</th><th></th></tr>
          </thead>
          <tbody>
            {rows.map((item) => (
              <tr key={item.id}>
                <td>{item.code}</td><td>{item.name}</td><td>{item.category || '—'}</td>
                <td>{item.unit_of_measure}</td><td>{item.reorder_level}</td>
                <td>{item.is_active ? '✅' : '❌'}</td>
                <td><button className="secondary" onClick={() => toggle(item)}>{item.is_active ? 'Deactivate' : 'Activate'}</button></td>
              </tr>
            ))}
            {!rows.length && <tr><td colSpan={7} style={{ textAlign: 'center' }}>No stock items yet</td></tr>}
          </tbody>
        </table>
      </div>
    </>
  )
}

/* ── Stock Levels ───────────────────────────────────────────────── */
function StockLevelTab({ onError }: { onError: (e: string | null) => void }) {
  const [rows, setRows] = useState<StockLevelRow[]>([])
  const [warehouses, setWarehouses] = useState<Warehouse[]>([])
  const [whFilter, setWhFilter] = useState('')

  function refresh() {
    api.listStockLevels(whFilter || undefined).then(setRows).catch((e) => onError(e.message))
  }
  useEffect(refresh, [whFilter])
  useEffect(() => { api.listWarehouses().then(setWarehouses).catch(() => {}) }, [])

  return (
    <>
      <div style={{ marginBottom: 12 }}>
        <label>Warehouse: </label>
        <select value={whFilter} onChange={(e) => setWhFilter(e.target.value)}>
          <option value="">All</option>
          {warehouses.map((w) => <option key={w.id} value={w.id}>{w.code} – {w.name}</option>)}
        </select>
      </div>
      <div style={{ overflowX: 'auto' }}>
        <table>
          <thead>
            <tr><th>Item Code</th><th>Item Name</th><th>Warehouse</th><th style={{ textAlign: 'right' }}>Qty</th><th style={{ textAlign: 'right' }}>Avg Cost</th><th style={{ textAlign: 'right' }}>Total Value</th></tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.id}>
                <td>{r.item_code}</td><td>{r.item_name}</td>
                <td>{r.warehouse_code} – {r.warehouse_name}</td>
                <td style={{ textAlign: 'right' }}>{r.quantity}</td>
                <td style={{ textAlign: 'right' }}>{money(r.avg_cost)}</td>
                <td style={{ textAlign: 'right' }}>{money(r.quantity * r.avg_cost)}</td>
              </tr>
            ))}
            {!rows.length && <tr><td colSpan={6} style={{ textAlign: 'center' }}>No stock levels</td></tr>}
          </tbody>
        </table>
      </div>
    </>
  )
}
