import { useEffect, useState, type FormEvent } from 'react'
import {
  api,
  type GRNRow,
  type Warehouse,
  type StockItemRow,
} from '../lib/api'
import { formatMoney as money, formatDate } from '../lib/format'

interface LineInput { stock_item_id: string; quantity: string; unit_cost: string }
const emptyLine = (): LineInput => ({ stock_item_id: '', quantity: '', unit_cost: '' })

export default function GoodsReceiveNotePage() {
  const [rows, setRows] = useState<GRNRow[]>([])
  const [warehouses, setWarehouses] = useState<Warehouse[]>([])
  const [items, setItems] = useState<StockItemRow[]>([])
  const [error, setError] = useState<string | null>(null)
  const [creating, setCreating] = useState(false)

  const [warehouseId, setWarehouseId] = useState('')
  const [notes, setNotes] = useState('')
  const [lines, setLines] = useState<LineInput[]>([emptyLine()])

  function refresh() {
    api.listGRNs().then(setRows).catch((e) => setError(e.message))
  }
  useEffect(() => {
    refresh()
    api.listWarehouses().then(setWarehouses).catch(() => {})
    api.listStockItems().then(setItems).catch(() => {})
  }, [])

  function updateLine(idx: number, field: keyof LineInput, val: string) {
    setLines((prev) => prev.map((l, i) => i === idx ? { ...l, [field]: val } : l))
  }
  function addLine() { setLines((prev) => [...prev, emptyLine()]) }
  function removeLine(idx: number) { setLines((prev) => prev.filter((_, i) => i !== idx)) }

  async function onCreate(e: FormEvent) {
    e.preventDefault(); setError(null)
    try {
      await api.createGRN({
        warehouse_id: warehouseId,
        notes: notes || undefined,
        lines: lines.filter((l) => l.stock_item_id).map((l) => ({
          stock_item_id: l.stock_item_id,
          quantity: parseInt(l.quantity) || 1,
          unit_cost: parseFloat(l.unit_cost) || 0,
        })),
      })
      setCreating(false); setWarehouseId(''); setNotes(''); setLines([emptyLine()])
      refresh()
    } catch (err) { setError(err instanceof Error ? err.message : 'Failed') }
  }

  async function onConfirm(id: string) {
    setError(null)
    try { await api.confirmGRN(id); refresh() }
    catch (err) { setError(err instanceof Error ? err.message : 'Failed') }
  }

  const itemMap = Object.fromEntries(items.map((i) => [i.id, i]))
  const whMap = Object.fromEntries(warehouses.map((w) => [w.id, w]))

  return (
    <div style={{ padding: 24 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h2>Goods Receive Note (GRN)</h2>
        {!creating && <button onClick={() => setCreating(true)}>+ New GRN</button>}
      </div>
      {error && <p className="error">{error}</p>}

      {creating && (
        <form onSubmit={onCreate} style={{ border: '1px solid var(--border)', padding: 16, borderRadius: 8, marginBottom: 16 }}>
          <h3>New GRN</h3>
          <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', marginBottom: 12 }}>
            <div>
              <label>Warehouse *</label>
              <select value={warehouseId} onChange={(e) => setWarehouseId(e.target.value)} required>
                <option value="">Select...</option>
                {warehouses.map((w) => <option key={w.id} value={w.id}>{w.code} – {w.name}</option>)}
              </select>
            </div>
            <div>
              <label>Notes</label>
              <input value={notes} onChange={(e) => setNotes(e.target.value)} style={{ width: 250 }} />
            </div>
          </div>
          <h4>Lines</h4>
          {lines.map((ln, idx) => (
            <div key={idx} style={{ display: 'flex', gap: 8, marginBottom: 6, flexWrap: 'wrap', alignItems: 'center' }}>
              <select value={ln.stock_item_id} onChange={(e) => updateLine(idx, 'stock_item_id', e.target.value)} required style={{ width: 220 }}>
                <option value="">Select Item...</option>
                {items.map((i) => <option key={i.id} value={i.id}>{i.code} – {i.name}</option>)}
              </select>
              <input placeholder="Qty" type="number" min="1" value={ln.quantity} onChange={(e) => updateLine(idx, 'quantity', e.target.value)} required style={{ width: 80 }} />
              <input placeholder="Unit Cost" type="number" step="0.01" min="0" value={ln.unit_cost} onChange={(e) => updateLine(idx, 'unit_cost', e.target.value)} required style={{ width: 110 }} />
              {lines.length > 1 && <button type="button" className="secondary" onClick={() => removeLine(idx)}>✕</button>}
            </div>
          ))}
          <div style={{ display: 'flex', gap: 8, marginTop: 8 }}>
            <button type="button" className="secondary" onClick={addLine}>+ Add Line</button>
            <button type="submit">Create GRN</button>
            <button type="button" className="secondary" onClick={() => setCreating(false)}>Cancel</button>
          </div>
        </form>
      )}

      <div style={{ overflowX: 'auto' }}>
        <table>
          <thead>
            <tr><th>GRN #</th><th>Warehouse</th><th>Date</th><th>Status</th><th>Lines</th><th></th></tr>
          </thead>
          <tbody>
            {rows.map((g) => (
              <tr key={g.id}>
                <td><strong>{g.grn_number}</strong></td>
                <td>{whMap[g.warehouse_id]?.code || '—'}</td>
                <td>{formatDate(g.receive_date)}</td>
                <td><span className={`badge badge-${g.status === 'confirmed' ? 'success' : g.status === 'draft' ? 'warning' : 'neutral'}`}>{g.status}</span></td>
                <td>
                  {g.lines.map((ln, i) => (
                    <div key={i} style={{ fontSize: '0.85em' }}>
                      {itemMap[ln.stock_item_id]?.code || '?'}: {ln.quantity} × {money(ln.unit_cost)}
                    </div>
                  ))}
                </td>
                <td>
                  {g.status === 'draft' && <button onClick={() => onConfirm(g.id)}>Confirm</button>}
                </td>
              </tr>
            ))}
            {!rows.length && <tr><td colSpan={6} style={{ textAlign: 'center' }}>No GRNs yet</td></tr>}
          </tbody>
        </table>
      </div>
    </div>
  )
}
