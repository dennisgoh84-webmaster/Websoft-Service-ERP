import { useEffect, useState } from 'react'
import {
  api,
  type Warehouse,
  type StockItemRow,
  type StockMovementRow,
  type StockValuationReport,
  type ReorderItem,
} from '../lib/api'
import { formatMoney as money, formatDate } from '../lib/format'

type Tab = 'valuation' | 'reorder' | 'movements'

export default function StockReportsPage() {
  const [tab, setTab] = useState<Tab>('valuation')
  const [error, setError] = useState<string | null>(null)

  return (
    <div style={{ padding: 24 }}>
      <h2>Stock Operation Reports</h2>
      {error && <p className="error">{error}</p>}
      <div style={{ display: 'flex', gap: 8, marginBottom: 16 }}>
        {(['valuation', 'reorder', 'movements'] as Tab[]).map((t) => (
          <button
            key={t}
            className={tab === t ? '' : 'secondary'}
            onClick={() => setTab(t)}
          >
            {t === 'valuation' ? 'Stock Valuation' : t === 'reorder' ? 'Reorder Alert' : 'Stock Movements'}
          </button>
        ))}
      </div>
      {tab === 'valuation' && <ValuationTab onError={setError} />}
      {tab === 'reorder' && <ReorderTab onError={setError} />}
      {tab === 'movements' && <MovementsTab onError={setError} />}
    </div>
  )
}

/* ── Valuation ──────────────────────────────────────────────────── */
function ValuationTab({ onError }: { onError: (e: string | null) => void }) {
  const [data, setData] = useState<StockValuationReport | null>(null)
  const [warehouses, setWarehouses] = useState<Warehouse[]>([])
  const [whFilter, setWhFilter] = useState('')

  function refresh() {
    api.stockValuationReport(whFilter || undefined).then(setData).catch((e) => onError(e.message))
  }
  useEffect(refresh, [whFilter])
  useEffect(() => { api.listWarehouses().then(setWarehouses).catch(() => {}) }, [])

  if (!data) return <p>Loading...</p>

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
            <tr>
              <th>Item Code</th><th>Item Name</th><th>Warehouse</th>
              <th style={{ textAlign: 'right' }}>Qty</th>
              <th style={{ textAlign: 'right' }}>Avg Cost</th>
              <th style={{ textAlign: 'right' }}>Total Value</th>
            </tr>
          </thead>
          <tbody>
            {data.items.map((r, i) => (
              <tr key={i}>
                <td>{r.item_code}</td><td>{r.item_name}</td><td>{r.warehouse_code}</td>
                <td style={{ textAlign: 'right' }}>{r.quantity}</td>
                <td style={{ textAlign: 'right' }}>{money(r.avg_cost)}</td>
                <td style={{ textAlign: 'right' }}>{money(r.total_value)}</td>
              </tr>
            ))}
            {!data.items.length && <tr><td colSpan={6} style={{ textAlign: 'center' }}>No stock</td></tr>}
          </tbody>
          {data.items.length > 0 && (
            <tfoot>
              <tr>
                <td colSpan={5} style={{ textAlign: 'right', fontWeight: 'bold' }}>Total</td>
                <td style={{ textAlign: 'right', fontWeight: 'bold' }}>{money(data.total_value)}</td>
              </tr>
            </tfoot>
          )}
        </table>
      </div>
    </>
  )
}

/* ── Reorder ────────────────────────────────────────────────────── */
function ReorderTab({ onError }: { onError: (e: string | null) => void }) {
  const [rows, setRows] = useState<ReorderItem[]>([])

  useEffect(() => {
    api.reorderReport().then(setRows).catch((e) => onError(e.message))
  }, [])

  return (
    <div style={{ overflowX: 'auto' }}>
      <p style={{ fontSize: '0.85em', color: 'var(--muted)', marginBottom: 12 }}>
        Items whose total stock across all warehouses is at or below their reorder level.
      </p>
      <table>
        <thead>
          <tr><th>Item Code</th><th>Item Name</th><th>UoM</th><th style={{ textAlign: 'right' }}>Reorder Level</th><th style={{ textAlign: 'right' }}>Current Stock</th><th style={{ textAlign: 'right' }}>Shortfall</th></tr>
        </thead>
        <tbody>
          {rows.map((r, i) => (
            <tr key={i}>
              <td>{r.item_code}</td><td>{r.item_name}</td><td>{r.unit_of_measure}</td>
              <td style={{ textAlign: 'right' }}>{r.reorder_level}</td>
              <td style={{ textAlign: 'right' }}>{r.current_stock}</td>
              <td style={{ textAlign: 'right', color: r.shortfall > 0 ? 'var(--danger, #c00)' : 'inherit' }}>{r.shortfall}</td>
            </tr>
          ))}
          {!rows.length && <tr><td colSpan={6} style={{ textAlign: 'center' }}>All items are above reorder level 👍</td></tr>}
        </tbody>
      </table>
    </div>
  )
}

/* ── Movements ──────────────────────────────────────────────────── */
function MovementsTab({ onError }: { onError: (e: string | null) => void }) {
  const [rows, setRows] = useState<StockMovementRow[]>([])
  const [items, setItems] = useState<StockItemRow[]>([])
  const [warehouses, setWarehouses] = useState<Warehouse[]>([])
  const [itemFilter, setItemFilter] = useState('')
  const [whFilter, setWhFilter] = useState('')

  function refresh() {
    api.listStockMovements({
      stock_item_id: itemFilter || undefined,
      warehouse_id: whFilter || undefined,
    }).then(setRows).catch((e) => onError(e.message))
  }
  useEffect(refresh, [itemFilter, whFilter])
  useEffect(() => {
    api.listStockItems().then(setItems).catch(() => {})
    api.listWarehouses().then(setWarehouses).catch(() => {})
  }, [])

  const itemMap = Object.fromEntries(items.map((i) => [i.id, i]))
  const whMap = Object.fromEntries(warehouses.map((w) => [w.id, w]))

  return (
    <>
      <div style={{ display: 'flex', gap: 12, marginBottom: 12, flexWrap: 'wrap' }}>
        <div>
          <label>Item: </label>
          <select value={itemFilter} onChange={(e) => setItemFilter(e.target.value)}>
            <option value="">All</option>
            {items.map((i) => <option key={i.id} value={i.id}>{i.code} – {i.name}</option>)}
          </select>
        </div>
        <div>
          <label>Warehouse: </label>
          <select value={whFilter} onChange={(e) => setWhFilter(e.target.value)}>
            <option value="">All</option>
            {warehouses.map((w) => <option key={w.id} value={w.id}>{w.code} – {w.name}</option>)}
          </select>
        </div>
      </div>
      <div style={{ overflowX: 'auto' }}>
        <table>
          <thead>
            <tr><th>Date</th><th>Item</th><th>Warehouse</th><th>Type</th><th style={{ textAlign: 'right' }}>Qty</th><th style={{ textAlign: 'right' }}>Unit Cost</th><th style={{ textAlign: 'right' }}>Total</th><th>Ref</th></tr>
          </thead>
          <tbody>
            {rows.map((m) => (
              <tr key={m.id}>
                <td>{formatDate(m.created_at)}</td>
                <td>{itemMap[m.stock_item_id]?.code || '?'}</td>
                <td>{whMap[m.warehouse_id]?.code || '?'}</td>
                <td>{m.movement_type.replace('_', ' ')}</td>
                <td style={{ textAlign: 'right', color: m.quantity >= 0 ? 'green' : 'red' }}>{m.quantity > 0 ? '+' : ''}{m.quantity}</td>
                <td style={{ textAlign: 'right' }}>{money(m.unit_cost)}</td>
                <td style={{ textAlign: 'right' }}>{money(m.total_cost)}</td>
                <td style={{ fontSize: '0.85em' }}>{m.reference_type?.toUpperCase()}</td>
              </tr>
            ))}
            {!rows.length && <tr><td colSpan={8} style={{ textAlign: 'center' }}>No movements</td></tr>}
          </tbody>
        </table>
      </div>
    </>
  )
}
