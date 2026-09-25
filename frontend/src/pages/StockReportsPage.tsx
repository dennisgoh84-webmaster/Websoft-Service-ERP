// Stock Operation Reports -- the same card launcher as Accounting and
// Operations Reports (components/ReportLauncher.tsx, 2026-09-24); each
// report keeps its own filters.
import { useEffect, useState } from 'react'
import {
  api,
  type Company,
  type StockFilterOptions,
  type StockMovementRow,
  type StockValuationReport,
  type ReorderItem,
} from '../lib/api'
import { useAuth } from '../lib/AuthContext'
import { formatMoney as money, formatDate } from '../lib/format'
import ExportControl from '../components/ExportControl'
import { FilterGrid, InternalCompaniesPicker } from '../components/ReportFilters'
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
        filters: ['Internal Companies', 'Warehouse'],
      },
      {
        key: 'reorder',
        title: 'Reorder Alert',
        summary: 'Items at or below their reorder level.',
        details: 'Every item whose quantity on hand has fallen to or below its reorder level, with the shortfall -- the list to work from when raising purchase orders.',
        filters: ['Internal Companies'],
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
        filters: ['Internal Companies', 'Item', 'Warehouse'],
      },
    ],
  },
]

export default function StockReportsPage() {
  const [reportType, openReport] = useSelectedReport(SECTIONS)
  const { user } = useAuth()
  const [error, setError] = useState<string | null>(null)
  // Internal Companies (2026-09-25): every report opens on the signed-in company.
  const [companyIds, setCompanyIds] = useState<string[]>(user?.company_id ? [user.company_id] : [])
  const [myCompanies, setMyCompanies] = useState<Company[]>([])
  const [options, setOptions] = useState<StockFilterOptions>({ warehouses: [], items: [] })
  const [warehouseId, setWarehouseId] = useState('')
  const [itemId, setItemId] = useState('')
  const companyKey = companyIds.join(',')
  const multiCompany = companyIds.length > 1

  useEffect(() => {
    api.listMyCompanies().then(setMyCompanies).catch(() => setMyCompanies([]))
  }, [])

  useEffect(() => {
    if (user?.company_id) setCompanyIds([user.company_id])
    setWarehouseId('')
    setItemId('')
  }, [reportType, user?.company_id])

  // Warehouse and item choices follow the ticked internal companies.
  useEffect(() => {
    if (!companyKey) return
    api.stockReportFilterOptions(companyKey).then((o) => {
      setOptions(o)
      setWarehouseId((id) => (o.warehouses.some((w) => w.id === id) ? id : ''))
      setItemId((id) => (o.items.some((i) => i.id === id) ? id : ''))
    }).catch(() => setOptions({ warehouses: [], items: [] }))
  }, [companyKey])

  const pickedName = (id: string, list: { id: string; name: string }[]) => (id ? list.find((x) => x.id === id)?.name ?? id : 'All')
  const printSummary = [
    ...(multiCompany ? [`Internal Companies: ${companyIds.map((id) => myCompanies.find((c) => c.id === id)?.name ?? id).join(', ')}`] : []),
    ...(reportType === 'movements' ? [`Item: ${pickedName(itemId, options.items)}`] : []),
    ...(reportType === 'valuation' || reportType === 'movements' ? [`Warehouse: ${pickedName(warehouseId, options.warehouses)}`] : []),
  ]

  return (
    <div>
      <h1 className="no-print">Stock Operation Reports</h1>
      {!reportType ? (
        <>
          <p className="muted">Choose a report. Each one shows what it covers and which filters it takes.</p>
          <ReportLauncher sections={SECTIONS} onOpen={(key) => { setError(null); openReport(key) }} />
        </>
      ) : (
        <>
          <ReportHeader sections={SECTIONS} current={reportType} onBack={() => openReport(null)} printSummary={printSummary} />
          {error && <div className="error-banner">{error}</div>}
          <div className="card">
            <FilterGrid>
              <InternalCompaniesPicker value={companyIds} onChange={setCompanyIds} />
              {reportType === 'movements' && (
                <div className="form-row">
                  <label>Item</label>
                  <select value={itemId} onChange={(e) => setItemId(e.target.value)}>
                    <option value="">All</option>
                    {options.items.map((i) => <option key={i.id} value={i.id}>{i.name}</option>)}
                  </select>
                </div>
              )}
              {(reportType === 'valuation' || reportType === 'movements') && (
                <div className="form-row">
                  <label>Warehouse</label>
                  <select value={warehouseId} onChange={(e) => setWarehouseId(e.target.value)}>
                    <option value="">All</option>
                    {options.warehouses.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}
                  </select>
                </div>
              )}
              <div className="report-filter-actions">
                <ExportControl formats={[{ value: 'pdf', label: 'PDF' }]} onExport={async () => window.print()} onError={setError} />
              </div>
            </FilterGrid>
            {reportType === 'valuation' && <ValuationTab companyKey={companyKey} multiCompany={multiCompany} warehouseId={warehouseId} onError={setError} />}
            {reportType === 'reorder' && <ReorderTab companyKey={companyKey} multiCompany={multiCompany} onError={setError} />}
            {reportType === 'movements' && (
              <MovementsTab companyKey={companyKey} multiCompany={multiCompany} warehouseId={warehouseId} itemId={itemId} onError={setError} />
            )}
          </div>
        </>
      )}
    </div>
  )
}

type TabProps = { companyKey: string; multiCompany: boolean; onError: (e: string | null) => void }
const right = { textAlign: 'right' as const }

/* ── Valuation ──────────────────────────────────────────────────── */
function ValuationTab({ companyKey, multiCompany, warehouseId, onError }: TabProps & { warehouseId: string }) {
  const [data, setData] = useState<StockValuationReport | null>(null)

  useEffect(() => {
    if (!companyKey) return
    onError(null)
    api.stockValuationReport(warehouseId || undefined, companyKey).then(setData).catch((e) => onError(e.message))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [companyKey, warehouseId])

  if (!data) return <p>Loading...</p>
  const cols = multiCompany ? 7 : 6

  return (
    <div className="report-table-wrap" style={{ overflowX: 'auto' }}>
      <table>
        <thead>
          <tr>
            {multiCompany && <th>Internal Company</th>}
            <th>Item Code</th><th>Item Name</th><th>Warehouse</th>
            <th style={right}>Qty</th>
            <th style={right}>Avg Cost</th>
            <th style={right}>Total Value</th>
          </tr>
        </thead>
        <tbody>
          {data.items.map((r, i) => (
            <tr key={i}>
              {multiCompany && <td>{r.company_name}</td>}
              <td>{r.item_code}</td><td>{r.item_name}</td><td>{r.warehouse_code}</td>
              <td style={right}>{r.quantity}</td>
              <td style={right}>{money(r.avg_cost)}</td>
              <td style={right}>{money(r.total_value)}</td>
            </tr>
          ))}
          {!data.items.length && <tr><td colSpan={cols} style={{ textAlign: 'center' }}>No stock</td></tr>}
        </tbody>
        {data.items.length > 0 && (
          <tfoot>
            <tr>
              <td colSpan={cols - 1} style={{ textAlign: 'right', fontWeight: 'bold' }}>Total</td>
              <td style={{ textAlign: 'right', fontWeight: 'bold' }}>{money(data.total_value)}</td>
            </tr>
          </tfoot>
        )}
      </table>
    </div>
  )
}

/* ── Reorder ────────────────────────────────────────────────────── */
function ReorderTab({ companyKey, multiCompany, onError }: TabProps) {
  const [rows, setRows] = useState<ReorderItem[]>([])

  useEffect(() => {
    if (!companyKey) return
    onError(null)
    api.reorderReport(companyKey).then(setRows).catch((e) => onError(e.message))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [companyKey])

  return (
    <div className="report-table-wrap" style={{ overflowX: 'auto' }}>
      <p className="muted" style={{ fontSize: '0.85em', marginTop: 0 }}>
        Items whose total stock across all warehouses is at or below their reorder level.
      </p>
      <table>
        <thead>
          <tr>
            {multiCompany && <th>Internal Company</th>}
            <th>Item Code</th><th>Item Name</th><th>UoM</th><th style={right}>Reorder Level</th><th style={right}>Current Stock</th><th style={right}>Shortfall</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((r, i) => (
            <tr key={i}>
              {multiCompany && <td>{r.company_name}</td>}
              <td>{r.item_code}</td><td>{r.item_name}</td><td>{r.unit_of_measure}</td>
              <td style={right}>{r.reorder_level}</td>
              <td style={right}>{r.current_stock}</td>
              <td style={{ textAlign: 'right', color: r.shortfall > 0 ? 'var(--danger, #c00)' : 'inherit' }}>{r.shortfall}</td>
            </tr>
          ))}
          {!rows.length && <tr><td colSpan={multiCompany ? 7 : 6} style={{ textAlign: 'center' }}>All items are above reorder level 👍</td></tr>}
        </tbody>
      </table>
    </div>
  )
}

/* ── Movements ──────────────────────────────────────────────────── */
function MovementsTab({ companyKey, multiCompany, warehouseId, itemId, onError }: TabProps & { warehouseId: string; itemId: string }) {
  const [rows, setRows] = useState<StockMovementRow[]>([])

  useEffect(() => {
    if (!companyKey) return
    onError(null)
    api.listStockMovements({
      company_ids: companyKey,
      stock_item_id: itemId || undefined,
      warehouse_id: warehouseId || undefined,
    }).then(setRows).catch((e) => onError(e.message))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [companyKey, itemId, warehouseId])

  return (
    <div className="report-table-wrap" style={{ overflowX: 'auto' }}>
      <table>
        <thead>
          <tr>
            {multiCompany && <th>Internal Company</th>}
            <th>Date</th><th>Item</th><th>Warehouse</th><th>Type</th><th style={right}>Qty</th><th style={right}>Unit Cost</th><th style={right}>Total</th><th>Ref</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((m) => (
            <tr key={m.id}>
              {multiCompany && <td>{m.company_name}</td>}
              <td>{formatDate(m.created_at)}</td>
              <td>{m.item_code || '?'}</td>
              <td>{m.warehouse_code || '?'}</td>
              <td>{m.movement_type.replace('_', ' ')}</td>
              <td style={{ textAlign: 'right', color: m.quantity >= 0 ? 'green' : 'red' }}>{m.quantity > 0 ? '+' : ''}{m.quantity}</td>
              <td style={right}>{money(m.unit_cost)}</td>
              <td style={right}>{money(m.total_cost)}</td>
              <td style={{ fontSize: '0.85em' }}>{m.reference_type?.toUpperCase()}</td>
            </tr>
          ))}
          {!rows.length && <tr><td colSpan={multiCompany ? 9 : 8} style={{ textAlign: 'center' }}>No movements</td></tr>}
        </tbody>
      </table>
    </div>
  )
}
