import { useEffect, useState, type FormEvent } from 'react'
import {
  api,
  type AdjustmentRow,
  type Warehouse,
  type StockItemRow,
} from '../lib/api'
import DateInput from '../components/DateInput'
import { formatDate, todayIso } from '../lib/format'

interface LineInput { stock_item_id: string; quantity_change: string; notes: string }
const emptyLine = (): LineInput => ({ stock_item_id: '', quantity_change: '', notes: '' })

export default function StockAdjustmentPage() {
  const [rows, setRows] = useState<AdjustmentRow[]>([])
  const [warehouses, setWarehouses] = useState<Warehouse[]>([])
  const [items, setItems] = useState<StockItemRow[]>([])
  const [error, setError] = useState<string | null>(null)
  const [creating, setCreating] = useState(false)

  const [warehouseId, setWarehouseId] = useState('')
  const [reason, setReason] = useState('')
  const [docDate, setDocDate] = useState(todayIso())
  const [lines, setLines] = useState<LineInput[]>([emptyLine()])

  function refresh() {
    api.listAdjustments().then(setRows).catch((e) => setError(e.message))
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
      await api.createAdjustment({
        adjustment_date: docDate || undefined,
        warehouse_id: warehouseId,
        reason: reason || undefined,
        lines: lines.filter((l) => l.stock_item_id).map((l) => ({
          stock_item_id: l.stock_item_id,
          quantity_change: parseInt(l.quantity_change) || 0,
          notes: l.notes || undefined,
        })),
      })
      setCreating(false); setWarehouseId(''); setReason(''); setLines([emptyLine()])
      refresh()
    } catch (err) { setError(err instanceof Error ? err.message : 'Failed') }
  }

  async function onAction(id: string, action: 'submit' | 'approve' | 'reject') {
    setError(null)
    try {
      if (action === 'submit') await api.submitAdjustment(id)
      else if (action === 'approve') await api.approveAdjustment(id)
      else await api.rejectAdjustment(id)
      refresh()
    } catch (err) { setError(err instanceof Error ? err.message : 'Failed') }
  }

  const itemMap = Object.fromEntries(items.map((i) => [i.id, i]))
  const whMap = Object.fromEntries(warehouses.map((w) => [w.id, w]))

  function statusBadge(s: string) {
    const cls = s === 'approved' ? 'success' : s === 'rejected' ? 'danger' : s === 'pending_approval' ? 'warning' : 'neutral'
    return <span className={`badge badge-${cls}`}>{s.replace('_', ' ')}</span>
  }

  return (
    <div style={{ padding: 24 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h2>Stock Adjustment</h2>
        {!creating && <button onClick={() => setCreating(true)}>+ New Adjustment</button>}
      </div>
      <p style={{ fontSize: '0.85em', color: 'var(--muted)', marginBottom: 12 }}>
        INV-001: Adjustments require manager approval before stock levels change.
      </p>
      {error && <p className="error">{error}</p>}

      {creating && (
        <form onSubmit={onCreate} style={{ border: '1px solid var(--border)', padding: 16, borderRadius: 8, marginBottom: 16 }}>
          <h3>New Adjustment</h3>
          <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', marginBottom: 12 }}>
            <div>
              <label>Warehouse *</label>
              <select value={warehouseId} onChange={(e) => setWarehouseId(e.target.value)} required>
                <option value="">Select...</option>
                {warehouses.map((w) => <option key={w.id} value={w.id}>{w.code} – {w.name}</option>)}
              </select>
            </div>
            <div>
              <label>Adjustment date</label>
              <DateInput value={docDate} onChange={(e) => setDocDate(e.target.value)} required />
            </div>
            <div>
              <label>Reason</label>
              <input value={reason} onChange={(e) => setReason(e.target.value)} style={{ width: 300 }} placeholder="e.g. Physical count variance" />
            </div>
          </div>
          <h4>Lines (positive = increase, negative = decrease)</h4>
          {lines.map((ln, idx) => (
            <div key={idx} style={{ display: 'flex', gap: 8, marginBottom: 6, flexWrap: 'wrap', alignItems: 'center' }}>
              <select value={ln.stock_item_id} onChange={(e) => updateLine(idx, 'stock_item_id', e.target.value)} required style={{ width: 220 }}>
                <option value="">Select Item...</option>
                {items.map((i) => <option key={i.id} value={i.id}>{i.code} – {i.name}</option>)}
              </select>
              <input placeholder="Qty Change" type="number" value={ln.quantity_change} onChange={(e) => updateLine(idx, 'quantity_change', e.target.value)} required style={{ width: 110 }} />
              <input placeholder="Notes" value={ln.notes} onChange={(e) => updateLine(idx, 'notes', e.target.value)} style={{ width: 180 }} />
              {lines.length > 1 && <button type="button" className="secondary" onClick={() => removeLine(idx)}>✕</button>}
            </div>
          ))}
          <div style={{ display: 'flex', gap: 8, marginTop: 8 }}>
            <button type="button" className="secondary" onClick={addLine}>+ Add Line</button>
            <button type="submit">Create</button>
            <button type="button" className="secondary" onClick={() => setCreating(false)}>Cancel</button>
          </div>
        </form>
      )}

      <div style={{ overflowX: 'auto' }}>
        <table>
          <thead>
            <tr><th>ADJ #</th><th>Warehouse</th><th>Date</th><th>Reason</th><th>Status</th><th>Lines</th><th>Actions</th></tr>
          </thead>
          <tbody>
            {rows.map((adj) => (
              <tr key={adj.id}>
                <td><strong>{adj.adj_number}</strong></td>
                <td>{whMap[adj.warehouse_id]?.code || '—'}</td>
                <td>{formatDate(adj.adjustment_date)}</td>
                <td>{adj.reason || '—'}</td>
                <td>{statusBadge(adj.status)}</td>
                <td>
                  {adj.lines.map((ln, i) => (
                    <div key={i} style={{ fontSize: '0.85em' }}>
                      {itemMap[ln.stock_item_id]?.code || '?'}: {ln.quantity_change > 0 ? '+' : ''}{ln.quantity_change}
                    </div>
                  ))}
                </td>
                <td style={{ display: 'flex', gap: 4, flexWrap: 'wrap' }}>
                  {adj.status === 'draft' && <button onClick={() => onAction(adj.id, 'submit')}>Submit</button>}
                  {adj.status === 'pending_approval' && (
                    <>
                      <button onClick={() => onAction(adj.id, 'approve')}>Approve</button>
                      <button className="secondary" onClick={() => onAction(adj.id, 'reject')}>Reject</button>
                    </>
                  )}
                </td>
              </tr>
            ))}
            {!rows.length && <tr><td colSpan={7} style={{ textAlign: 'center' }}>No adjustments yet</td></tr>}
          </tbody>
        </table>
      </div>
    </div>
  )
}
