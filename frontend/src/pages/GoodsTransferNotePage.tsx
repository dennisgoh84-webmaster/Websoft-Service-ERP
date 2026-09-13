import { useEffect, useState, type FormEvent } from 'react'
import {
  api,
  type GTNRow,
  type Warehouse,
  type StockItemRow,
} from '../lib/api'

interface LineInput { stock_item_id: string; quantity: string; notes: string }
const emptyLine = (): LineInput => ({ stock_item_id: '', quantity: '', notes: '' })

export default function GoodsTransferNotePage() {
  const [rows, setRows] = useState<GTNRow[]>([])
  const [warehouses, setWarehouses] = useState<Warehouse[]>([])
  const [items, setItems] = useState<StockItemRow[]>([])
  const [error, setError] = useState<string | null>(null)
  const [creating, setCreating] = useState(false)

  const [fromWhId, setFromWhId] = useState('')
  const [toWhId, setToWhId] = useState('')
  const [notes, setNotes] = useState('')
  const [lines, setLines] = useState<LineInput[]>([emptyLine()])

  function refresh() {
    api.listGTNs().then(setRows).catch((e) => setError(e.message))
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
      await api.createGTN({
        from_warehouse_id: fromWhId,
        to_warehouse_id: toWhId,
        notes: notes || undefined,
        lines: lines.filter((l) => l.stock_item_id).map((l) => ({
          stock_item_id: l.stock_item_id,
          quantity: parseInt(l.quantity) || 1,
          notes: l.notes || undefined,
        })),
      })
      setCreating(false); setFromWhId(''); setToWhId(''); setNotes(''); setLines([emptyLine()])
      refresh()
    } catch (err) { setError(err instanceof Error ? err.message : 'Failed') }
  }

  async function onConfirm(id: string) {
    setError(null)
    try { await api.confirmGTN(id); refresh() }
    catch (err) { setError(err instanceof Error ? err.message : 'Failed') }
  }

  const itemMap = Object.fromEntries(items.map((i) => [i.id, i]))
  const whMap = Object.fromEntries(warehouses.map((w) => [w.id, w]))

  return (
    <div style={{ padding: 24 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h2>Goods Transfer Note (GTN)</h2>
        {!creating && <button onClick={() => setCreating(true)}>+ New GTN</button>}
      </div>
      {error && <p className="error">{error}</p>}

      {creating && (
        <form onSubmit={onCreate} style={{ border: '1px solid var(--border)', padding: 16, borderRadius: 8, marginBottom: 16 }}>
          <h3>New GTN</h3>
          <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', marginBottom: 12 }}>
            <div>
              <label>From Warehouse *</label>
              <select value={fromWhId} onChange={(e) => setFromWhId(e.target.value)} required>
                <option value="">Select...</option>
                {warehouses.map((w) => <option key={w.id} value={w.id}>{w.code} – {w.name}</option>)}
              </select>
            </div>
            <div>
              <label>To Warehouse *</label>
              <select value={toWhId} onChange={(e) => setToWhId(e.target.value)} required>
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
              <input placeholder="Notes" value={ln.notes} onChange={(e) => updateLine(idx, 'notes', e.target.value)} style={{ width: 150 }} />
              {lines.length > 1 && <button type="button" className="secondary" onClick={() => removeLine(idx)}>✕</button>}
            </div>
          ))}
          <div style={{ display: 'flex', gap: 8, marginTop: 8 }}>
            <button type="button" className="secondary" onClick={addLine}>+ Add Line</button>
            <button type="submit">Create GTN</button>
            <button type="button" className="secondary" onClick={() => setCreating(false)}>Cancel</button>
          </div>
        </form>
      )}

      <div style={{ overflowX: 'auto' }}>
        <table>
          <thead>
            <tr><th>GTN #</th><th>From</th><th>To</th><th>Date</th><th>Status</th><th>Lines</th><th></th></tr>
          </thead>
          <tbody>
            {rows.map((g) => (
              <tr key={g.id}>
                <td><strong>{g.gtn_number}</strong></td>
                <td>{whMap[g.from_warehouse_id]?.code || '—'}</td>
                <td>{whMap[g.to_warehouse_id]?.code || '—'}</td>
                <td>{new Date(g.transfer_date).toLocaleDateString()}</td>
                <td><span className={`badge badge-${g.status === 'confirmed' ? 'success' : g.status === 'draft' ? 'warning' : 'neutral'}`}>{g.status}</span></td>
                <td>
                  {g.lines.map((ln, i) => (
                    <div key={i} style={{ fontSize: '0.85em' }}>
                      {itemMap[ln.stock_item_id]?.code || '?'}: qty {ln.quantity}
                    </div>
                  ))}
                </td>
                <td>
                  {g.status === 'draft' && <button onClick={() => onConfirm(g.id)}>Confirm</button>}
                </td>
              </tr>
            ))}
            {!rows.length && <tr><td colSpan={7} style={{ textAlign: 'center' }}>No GTNs yet</td></tr>}
          </tbody>
        </table>
      </div>
    </div>
  )
}
