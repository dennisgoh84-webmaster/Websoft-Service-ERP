import { useEffect, useState, type FormEvent } from 'react'
import DateInput from '../components/DateInput'
import {
  api,
  type CompanyIndividual,
  type GINRow,
  type JobOrder,
  type StockItemRow,
  type Warehouse,
} from '../lib/api'
import { formatDate, formatMoney as money } from '../lib/format'

interface LineInput {
  stock_item_id: string
  quantity: string
  notes: string
}
const emptyLine = (): LineInput => ({ stock_item_id: '', quantity: '', notes: '' })

/**
 * Goods Issue Note: stock leaving a warehouse -- to a job order, a
 * customer, or internal use. Draft first; confirming deducts the stock at
 * the item's average cost and refuses the whole document if the warehouse
 * does not hold enough.
 */
export default function GoodsIssueNotePage() {
  const [rows, setRows] = useState<GINRow[]>([])
  const [warehouses, setWarehouses] = useState<Warehouse[]>([])
  const [items, setItems] = useState<StockItemRow[]>([])
  const [customers, setCustomers] = useState<CompanyIndividual[]>([])
  const [jobOrders, setJobOrders] = useState<JobOrder[]>([])
  const [error, setError] = useState<string | null>(null)
  const [creating, setCreating] = useState(false)

  const [warehouseId, setWarehouseId] = useState('')
  const [customerId, setCustomerId] = useState('')
  const [jobOrderId, setJobOrderId] = useState('')
  const [issueDate, setIssueDate] = useState('')
  const [reason, setReason] = useState('')
  const [notes, setNotes] = useState('')
  const [lines, setLines] = useState<LineInput[]>([emptyLine()])

  function refresh() {
    api.listGINs().then(setRows).catch((e) => setError(e.message))
  }
  useEffect(() => {
    refresh()
    api.listWarehouses().then(setWarehouses).catch(() => {})
    api.listStockItems().then(setItems).catch(() => {})
    api.listCompanyIndividuals().then(setCustomers).catch(() => {})
    api.listJobOrders().then(setJobOrders).catch(() => {})
  }, [])

  function updateLine(idx: number, field: keyof LineInput, val: string) {
    setLines((prev) => prev.map((l, i) => (i === idx ? { ...l, [field]: val } : l)))
  }

  async function onCreate(e: FormEvent) {
    e.preventDefault()
    setError(null)
    try {
      await api.createGIN({
        warehouse_id: warehouseId,
        customer_id: customerId || undefined,
        job_order_id: jobOrderId || undefined,
        issue_date: issueDate || undefined,
        reason: reason || undefined,
        notes: notes || undefined,
        lines: lines
          .filter((l) => l.stock_item_id)
          .map((l) => ({ stock_item_id: l.stock_item_id, quantity: parseInt(l.quantity) || 1, notes: l.notes || undefined })),
      })
      setCreating(false)
      setWarehouseId('')
      setCustomerId('')
      setJobOrderId('')
      setIssueDate('')
      setReason('')
      setNotes('')
      setLines([emptyLine()])
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed')
    }
  }

  async function onConfirm(id: string) {
    setError(null)
    try {
      await api.confirmGIN(id)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed')
    }
  }

  const itemMap = Object.fromEntries(items.map((i) => [i.id, i]))
  const whMap = Object.fromEntries(warehouses.map((w) => [w.id, w]))
  const customerMap = Object.fromEntries(customers.map((c) => [c.id, c]))
  const jobOrderMap = Object.fromEntries(jobOrders.map((j) => [j.id, j]))

  return (
    <div style={{ padding: 24 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h2>Goods Issue Note (GIN)</h2>
        {!creating && <button onClick={() => setCreating(true)}>+ New GIN</button>}
      </div>
      <p className="muted">
        Stock going out to a job order, a customer or internal use. Confirming deducts it from the warehouse at the item's
        average cost; a GIN asking for more than the warehouse holds is refused as a whole.
      </p>
      {error && <div className="error-banner">{error}</div>}

      {creating && (
        <form onSubmit={onCreate} style={{ border: '1px solid var(--border)', padding: 16, borderRadius: 8, marginBottom: 16 }}>
          <h3>New GIN</h3>
          <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', marginBottom: 12 }}>
            <div>
              <label>Warehouse *</label>
              <select value={warehouseId} onChange={(e) => setWarehouseId(e.target.value)} required>
                <option value="">Select...</option>
                {warehouses.map((w) => (
                  <option key={w.id} value={w.id}>
                    {w.code} – {w.name}
                  </option>
                ))}
              </select>
            </div>
            <div>
              <label>Issue date</label>
              <DateInput value={issueDate} onChange={(e) => setIssueDate(e.target.value)} />
            </div>
            <div>
              <label>Job order (optional)</label>
              <select value={jobOrderId} onChange={(e) => setJobOrderId(e.target.value)} style={{ width: 240 }}>
                <option value="">None</option>
                {jobOrders.map((j) => (
                  <option key={j.id} value={j.id}>
                    {j.job_order_number} – {j.subject}
                  </option>
                ))}
              </select>
            </div>
            <div>
              <label>Company / Individual (optional)</label>
              <select value={customerId} onChange={(e) => setCustomerId(e.target.value)} style={{ width: 240 }}>
                <option value="">None</option>
                {customers.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.name}
                  </option>
                ))}
              </select>
            </div>
            <div>
              <label>Reason</label>
              <input value={reason} onChange={(e) => setReason(e.target.value)} style={{ width: 200 }} />
            </div>
            <div>
              <label>Notes</label>
              <input value={notes} onChange={(e) => setNotes(e.target.value)} style={{ width: 200 }} />
            </div>
          </div>
          <h4>Lines</h4>
          {lines.map((ln, idx) => (
            <div key={idx} style={{ display: 'flex', gap: 8, marginBottom: 6, flexWrap: 'wrap', alignItems: 'center' }}>
              <select value={ln.stock_item_id} onChange={(e) => updateLine(idx, 'stock_item_id', e.target.value)} required style={{ width: 220 }}>
                <option value="">Select Item...</option>
                {items.map((i) => (
                  <option key={i.id} value={i.id}>
                    {i.code} – {i.name}
                  </option>
                ))}
              </select>
              <input
                placeholder="Qty"
                type="number"
                min="1"
                value={ln.quantity}
                onChange={(e) => updateLine(idx, 'quantity', e.target.value)}
                required
                style={{ width: 80 }}
              />
              <input placeholder="Line note" value={ln.notes} onChange={(e) => updateLine(idx, 'notes', e.target.value)} style={{ width: 180 }} />
              {lines.length > 1 && (
                <button type="button" className="secondary" onClick={() => setLines((prev) => prev.filter((_, i) => i !== idx))}>
                  ✕
                </button>
              )}
            </div>
          ))}
          <div style={{ display: 'flex', gap: 8, marginTop: 8 }}>
            <button type="button" className="secondary" onClick={() => setLines((prev) => [...prev, emptyLine()])}>
              + Add Line
            </button>
            <button type="submit">Create GIN</button>
            <button type="button" className="secondary" onClick={() => setCreating(false)}>
              Cancel
            </button>
          </div>
        </form>
      )}

      <div style={{ overflowX: 'auto' }}>
        <table>
          <thead>
            <tr>
              <th>GIN #</th>
              <th>Warehouse</th>
              <th>Date</th>
              <th>Issued to</th>
              <th>Reason</th>
              <th>Status</th>
              <th>Lines</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {rows.map((g) => (
              <tr key={g.id}>
                <td>
                  <strong>{g.gin_number}</strong>
                </td>
                <td>{whMap[g.warehouse_id]?.code || '—'}</td>
                <td>{formatDate(g.issue_date)}</td>
                <td>
                  {g.job_order_id && <div>{jobOrderMap[g.job_order_id]?.job_order_number ?? 'Job order'}</div>}
                  {g.customer_id && <div>{customerMap[g.customer_id]?.name ?? 'Company / Individual'}</div>}
                  {!g.job_order_id && !g.customer_id && 'Internal use'}
                </td>
                <td>{g.reason || '—'}</td>
                <td>
                  <span className={`badge badge-${g.status === 'confirmed' ? 'success' : g.status === 'draft' ? 'warning' : 'neutral'}`}>{g.status}</span>
                </td>
                <td>
                  {g.lines.map((ln, i) => (
                    <div key={i} style={{ fontSize: '0.85em' }}>
                      {itemMap[ln.stock_item_id]?.code || '?'}: {ln.quantity}
                      {ln.unit_cost !== null && <> × {money(ln.unit_cost)}</>}
                    </div>
                  ))}
                </td>
                <td>{g.status === 'draft' && <button onClick={() => onConfirm(g.id)}>Confirm</button>}</td>
              </tr>
            ))}
            {!rows.length && (
              <tr>
                <td colSpan={8} style={{ textAlign: 'center' }}>
                  No GINs yet
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  )
}
