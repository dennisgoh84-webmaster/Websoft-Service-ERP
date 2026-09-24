// Stock Operation Reports -- the same card launcher as Accounting and
// Operations Reports (components/ReportLauncher.tsx, 2026-09-24); each
// report keeps its own filters.
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
import { ReportHeader, ReportLauncher, useSelectedReport, type ReportSection } from '../components/ReportLauncher'

type ReportType = 'valuation' | 'reorder' | 'movements'

const SECTIONS: ReportSection<ReportType>[] = [
  {
    label: 'Inventory',
    reports: [
      {
        key: 'valuation',
        title: 'Stock Valuation',
        summary: 'What your stock on hand is worth.',
        details: 'Quantity on hand and value of every stock item, per warehouse or across all of them, with the grand total -- the figure to compare against the inventory account at month-end.',
        filters: ['Warehouse'],
      },
      {
        key: 'reorder',
        title: 'Reorder Alert',
        summary: 'Items at or below their reorder level.',
        details: 'Every item whose quantity on hand has fallen to or below its reorder level, with the shortfall -- the list to work from when raising purchase orders.',
        filters: [],
      },
    ],
  },
  {
    label: 'Movements',
    reports: [
      {
        key: 'movements',
        title: 'Stock Movements',
        summary: 'Every stock in, out and transfer.',
        details: 'Each stock movement with its date, item, warehouse, type, quantity, unit cost and source document -- use it to trace why an item\'s balance changed.',
        filters: ['Item', 'Warehouse'],
      },
    ],
  },
]

export default function StockReportsPage() {
  const [reportType, openReport] = useSelectedReport(SECTIONS)
  const [error, setError] = useState<string | null>(null)

  return (
    <div>
      <h1>Stock Operation Reports</h1>
      {!reportType ? (
        <>
          <p className="muted">Choose a report. Each one shows what it covers and which filters it takes.</p>
          <ReportLauncher sections={SECTIONS} onOpen={(key) => { setError(null); openReport(key) }} />
        </>
      ) : (
        <>
          <ReportHeader sections={SECTIONS} current={reportType} onBack={() => openReport(null)} />
          {error && <div className="error-banner">{error}</div>}
          <div className="card">
            {reportType === 'valuation' && <ValuationTab onError={setError} />}
            {reportType === 'reorder' && <ReorderTab onError={setError} />}
            {reportType === 'movements' && <MovementsTab onError={setError} />}
          </div>
        </>
      )}
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
