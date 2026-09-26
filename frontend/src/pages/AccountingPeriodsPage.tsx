// Accounting Periods — per-document-type, per-operation lock matrix.
//
// Each period carries a grid of locks: 5 document types × up to 6
// operations each. Individual cells can be toggled; "Close All" and
// "Open All (owner)" set every lock at once.
//
// GST F5 workflow (Dennis, 2026-09-26): once a month is keyed in and
// locked (Close All), "GST Calculation" sums it into the Form 5 boxes
// and keeps them with the documents behind them; the GST Return and its
// supporting listing (Accounting Reports) read only what was kept.
//
// Two pragmatic defaults still apply: a date with no period defined
// is unrestricted (opt-in protection), and "fiscal year" is whatever
// date range a period's rows say.
import { useEffect, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import {
  api,
  type AccountingPeriod,
  type GstReturnSaved,
  type PeriodDocType,
  type PeriodLock,
  type PeriodOperation,
} from '../lib/api'
import { formatDate, formatDateTime, formatMoney as money, ymdIso } from '../lib/format'

const MONTH_NAMES = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
]

function lastDayOfMonth(year: number, monthIndex: number) {
  return new Date(year, monthIndex + 1, 0).getDate()
}

// Document types and their valid operations (mirrors VALID_DOC_OPERATIONS
// in backend app/models/periods.py).
const DOC_TYPES: { key: PeriodDocType; label: string; ops: PeriodOperation[] }[] = [
  { key: 'sales_invoice', label: 'Sales Invoice', ops: ['update', 'reverse', 'gl', 'ungl'] },
  { key: 'receipt_voucher', label: 'Receipt Voucher', ops: ['update', 'reverse', 'bank', 'unbank', 'gl', 'ungl'] },
  { key: 'payment_voucher', label: 'Payment Voucher', ops: ['update', 'reverse', 'bank', 'unbank', 'gl', 'ungl'] },
  { key: 'purchase_bill', label: 'Purchase Bill', ops: ['update', 'reverse', 'gl', 'ungl'] },
  { key: 'journal_voucher', label: 'Journal Voucher', ops: ['update', 'reverse', 'gl', 'ungl'] },
]

const ALL_OPS: PeriodOperation[] = ['update', 'reverse', 'bank', 'unbank', 'gl', 'ungl']
const OP_LABELS: Record<PeriodOperation, string> = {
  update: 'Update',
  reverse: 'Reverse',
  bank: 'Bank',
  unbank: 'Unbank',
  gl: 'GL',
  ungl: 'UnGL',
}

function lockMap(locks: PeriodLock[]): Map<string, PeriodLock> {
  const m = new Map<string, PeriodLock>()
  for (const lk of locks) m.set(`${lk.doc_type}/${lk.operation}`, lk)
  return m
}

function periodLockSummary(locks: PeriodLock[]): 'open' | 'closed' | 'partial' {
  if (!locks.length) return 'open'
  const locked = locks.filter((l) => l.is_locked).length
  if (locked === 0) return 'open'
  if (locked === locks.length) return 'closed'
  return 'partial'
}

export default function AccountingPeriodsPage() {
  const [periods, setPeriods] = useState<AccountingPeriod[]>([])
  const [error, setError] = useState<string | null>(null)
  const [message, setMessage] = useState<string | null>(null)
  const [expandedId, setExpandedId] = useState<string | null>(null)
  const [busy, setBusy] = useState<string | null>(null) // lock cell being toggled
  const [gstFor, setGstFor] = useState<string | null>(null) // period whose saved GST is shown
  const [gst, setGst] = useState<GstReturnSaved | null>(null)
  const [calculating, setCalculating] = useState<string | null>(null)

  const now = new Date()
  const [fiscalYear, setFiscalYear] = useState(now.getFullYear())
  const [month, setMonth] = useState(now.getMonth())
  const [creating, setCreating] = useState(false)

  function refresh() {
    api.listAccountingPeriods().then(setPeriods).catch((e) => setError(e.message))
  }

  useEffect(refresh, [])

  async function onCreatePeriod(e: FormEvent) {
    e.preventDefault()
    setError(null)
    setCreating(true)
    try {
      const start = ymdIso(fiscalYear, month, 1)
      const end = ymdIso(fiscalYear, month, lastDayOfMonth(fiscalYear, month))
      await api.createAccountingPeriod({
        fiscal_year: fiscalYear,
        name: `${MONTH_NAMES[month]} ${fiscalYear}`,
        period_start: start,
        period_end: end,
      })
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to create period')
    } finally {
      setCreating(false)
    }
  }

  async function onCalculateGst(period: AccountingPeriod) {
    if (period.gst && !window.confirm(`${period.name} already has GST Calculation v${period.gst.version}. Calculate again? The earlier one is kept as superseded.`)) return
    setError(null)
    setMessage(null)
    setCalculating(period.id)
    try {
      const r = await api.calculatePeriodGst(period.id)
      setMessage(`${period.name} — GST Calculation v${r.version} saved.`)
      setGst(r)
      setGstFor(period.id)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'GST Calculation failed')
    } finally {
      setCalculating(null)
    }
  }

  async function onSubmitGst(period: AccountingPeriod) {
    if (
      !window.confirm(
        `Mark ${period.name}'s GST return as submitted to IRAS?\n\nYour name and the time are recorded, and the month is then locked for good: no recalculation, no reopening.`,
      )
    )
      return
    setError(null)
    setMessage(null)
    try {
      const r = await api.submitPeriodGst(period.id)
      setMessage(`${period.name} — GST return marked submitted to IRAS; the month is now locked.`)
      setGst(r)
      setGstFor(period.id)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to mark as submitted')
    }
  }

  async function onShowGst(period: AccountingPeriod) {
    if (gstFor === period.id) {
      setGstFor(null)
      return
    }
    setError(null)
    try {
      const r = await api.periodGst(period.id)
      setGst(r.current)
      setGstFor(period.id)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load the GST Calculation')
    }
  }

  async function onCloseAll(period: AccountingPeriod) {
    setError(null)
    setMessage(null)
    try {
      await api.closeAccountingPeriod(period.id)
      setMessage(`${period.name} — all operations locked.`)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to close period')
    }
  }

  async function onOpenAll(period: AccountingPeriod) {
    setError(null)
    setMessage(null)
    try {
      await api.reopenAccountingPeriod(period.id)
      setMessage(`${period.name} — all operations unlocked.`)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to open period (owner only)')
    }
  }

  async function onToggleLock(period: AccountingPeriod, docType: PeriodDocType, op: PeriodOperation, locked: boolean) {
    const cellKey = `${period.id}/${docType}/${op}`
    setBusy(cellKey)
    setError(null)
    try {
      const updated = await api.togglePeriodLock(period.id, { doc_type: docType, operation: op, locked })
      setPeriods((prev) => prev.map((p) => (p.id === updated.id ? updated : p)))
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to toggle lock')
    } finally {
      setBusy(null)
    }
  }

  return (
    <div>
      <h1>GST and Account Period</h1>
      <p className="muted">
        Each period carries a lock matrix per document type and operation.
        Locking an operation prevents that action on documents dated within the period.
        Click a cell to toggle; use Close All / Open All for bulk changes.
        A date with no period defined is unrestricted — periods are opt-in protection.
      </p>
      <p className="muted">
        GST: once a month is fully keyed in, lock it (Close All), then press <strong>GST Calculation</strong>. It sums the
        period's sales invoices and booked supplier bills into the IRAS Form 5 boxes and keeps them, with every document
        behind them. The GST Return and GST Supporting Listing under Accounting Reports read only what is kept here.
      </p>
      {error && <div className="error-banner">{error}</div>}
      {message && (
        <p className="muted" style={{ marginBottom: 12 }}>
          {message}
        </p>
      )}

      <div className="card">
        <h2>Periods ({periods.length})</h2>
        <table>
          <thead>
            <tr>
              <th>Name</th>
              <th>Fiscal year</th>
              <th>Start</th>
              <th>End</th>
              <th>Status</th>
              <th>GST</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {periods.map((p) => {
              const summary = periodLockSummary(p.locks)
              const isExpanded = expandedId === p.id
              return (
                <>
                  <tr key={p.id}>
                    <td>
                      <button
                        className="link"
                        onClick={() => setExpandedId(isExpanded ? null : p.id)}
                        style={{ fontWeight: 500, cursor: 'pointer', background: 'none', border: 'none', padding: 0, color: 'inherit', textDecoration: 'underline', textUnderlineOffset: '2px' }}
                      >
                        {p.name}
                      </button>
                    </td>
                    <td>{p.fiscal_year}</td>
                    <td>{formatDate(p.period_start)}</td>
                    <td>{formatDate(p.period_end)}</td>
                    <td>
                      <span
                        className={`badge ${summary === 'open' ? 'active' : summary === 'closed' ? 'expired' : ''}`}
                        style={summary === 'partial' ? { background: '#e67e22', color: '#fff' } : undefined}
                      >
                        {summary === 'partial' ? 'Partial' : summary === 'open' ? 'Open' : 'Closed'}
                      </span>
                    </td>
                    <td>
                      {p.gst ? (
                        <button className="link" onClick={() => onShowGst(p)} style={{ background: 'none', border: 'none', padding: 0, color: 'inherit', textDecoration: 'underline', cursor: 'pointer' }}>
                          Net {money(p.gst.net_gst_sgd)} (v{p.gst.version})
                        </button>
                      ) : (
                        <span className="muted">Not calculated</span>
                      )}
                      {p.gst?.submitted_at && (
                        <div style={{ fontSize: '0.8em' }}>
                          <span className="badge active">Submitted to IRAS</span> {formatDateTime(p.gst.submitted_at)}
                          {p.gst.submitted_by_name ? ` by ${p.gst.submitted_by_name}` : ''} — locked
                        </div>
                      )}
                      {p.gst && summary !== 'closed' && (
                        <div className="muted" style={{ fontSize: '0.8em' }}>
                          Reopened since — lock and recalculate
                        </div>
                      )}
                    </td>
                    <td style={{ display: 'flex', gap: 6 }}>
                      {!p.gst?.submitted_at && (
                        <button
                          onClick={() => onCalculateGst(p)}
                          disabled={summary !== 'closed' || calculating === p.id}
                          title={summary !== 'closed' ? 'Lock the period (Close All) first' : 'Sum this period into the Form 5 boxes and keep them'}
                          style={{ fontSize: '0.85em' }}
                        >
                          {calculating === p.id ? 'Calculating...' : 'GST Calculation'}
                        </button>
                      )}
                      {p.gst && !p.gst.submitted_at && summary === 'closed' && (
                        <button className="secondary" onClick={() => onSubmitGst(p)} style={{ fontSize: '0.85em' }} title="Record that this return was submitted to IRAS; the month is then locked">
                          Submit to IRAS
                        </button>
                      )}
                      {summary !== 'closed' && (
                        <button className="secondary" onClick={() => onCloseAll(p)} style={{ fontSize: '0.85em' }}>
                          Close All
                        </button>
                      )}
                      {summary !== 'open' && !p.gst?.submitted_at && (
                        <button className="secondary" onClick={() => onOpenAll(p)} style={{ fontSize: '0.85em' }}>
                          Open All
                        </button>
                      )}
                    </td>
                  </tr>
                  {gstFor === p.id && (
                    <tr key={`${p.id}-gst`}>
                      <td colSpan={7} style={{ padding: '8px 12px' }}>
                        {gst ? <GstPanel gst={gst} /> : <p className="muted">No GST Calculation saved for this period yet.</p>}
                      </td>
                    </tr>
                  )}
                  {isExpanded && (
                    <tr key={`${p.id}-locks`}>
                      <td colSpan={7} style={{ padding: '8px 12px' }}>
                        <LockMatrix
                          period={p}
                          busy={busy}
                          onToggle={(dt, op, locked) => onToggleLock(p, dt, op, locked)}
                        />
                      </td>
                    </tr>
                  )}
                </>
              )
            })}
            {periods.length === 0 && (
              <tr>
                <td colSpan={7} className="muted">
                  No periods defined yet — postings are unrestricted until one exists.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      <div className="card">
        <h2>Add a period</h2>
        <form onSubmit={onCreatePeriod}>
          <div className="form-row">
            <label>Month</label>
            <select value={month} onChange={(e) => setMonth(Number(e.target.value))}>
              {MONTH_NAMES.map((m, i) => (
                <option key={m} value={i}>
                  {m}
                </option>
              ))}
            </select>
          </div>
          <div className="form-row">
            <label>Fiscal year</label>
            <input
              type="number"
              value={fiscalYear}
              onChange={(e) => setFiscalYear(Number(e.target.value))}
              style={{ width: 120 }}
            />
          </div>
          <button type="submit" disabled={creating}>
            {creating ? 'Adding...' : 'Add period'}
          </button>
        </form>
      </div>

      <p className="muted">
        Closing out a whole fiscal year (moving Revenue/Expense into Equity) has its own page:{' '}
        <Link to="/year-end-closing">Year-End Closing</Link>.
      </p>
    </div>
  )
}

// ── Lock matrix grid ──────────────────────────────────────────────

function LockMatrix({
  period,
  busy,
  onToggle,
}: {
  period: AccountingPeriod
  busy: string | null
  onToggle: (dt: PeriodDocType, op: PeriodOperation, locked: boolean) => void
}) {
  const locks = lockMap(period.locks)

  return (
    <div style={{ overflowX: 'auto' }}>
      <table style={{ fontSize: '0.85em', minWidth: 500 }}>
        <thead>
          <tr>
            <th style={{ textAlign: 'left' }}>Document Type</th>
            {ALL_OPS.map((op) => (
              <th key={op} style={{ textAlign: 'center', padding: '4px 8px' }}>
                {OP_LABELS[op]}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {DOC_TYPES.map((dt) => (
            <tr key={dt.key}>
              <td style={{ fontWeight: 500, whiteSpace: 'nowrap' }}>{dt.label}</td>
              {ALL_OPS.map((op) => {
                const valid = dt.ops.includes(op)
                if (!valid) {
                  return (
                    <td key={op} style={{ textAlign: 'center', color: '#ccc' }}>
                      —
                    </td>
                  )
                }
                const lk = locks.get(`${dt.key}/${op}`)
                const isLocked = lk?.is_locked ?? false
                const cellKey = `${period.id}/${dt.key}/${op}`
                const isBusy = busy === cellKey
                return (
                  <td key={op} style={{ textAlign: 'center' }}>
                    <button
                      onClick={() => onToggle(dt.key, op, !isLocked)}
                      disabled={isBusy}
                      title={isLocked ? `Unlock ${OP_LABELS[op]} for ${dt.label}` : `Lock ${OP_LABELS[op]} for ${dt.label}`}
                      style={{
                        cursor: isBusy ? 'wait' : 'pointer',
                        background: 'none',
                        border: 'none',
                        fontSize: '1.1em',
                        padding: '2px 6px',
                        opacity: isBusy ? 0.4 : 1,
                      }}
                    >
                      {isLocked ? '🔒' : '🔓'}
                    </button>
                  </td>
                )
              })}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

/** A saved GST Calculation: the Form 5 boxes, then every document behind them, as kept. */
function GstPanel({ gst }: { gst: GstReturnSaved }) {
  const [showLines, setShowLines] = useState(false)
  const BOX_LABEL: Record<string, string> = {
    '1': 'Box 1',
    '2': 'Box 2',
    '3': 'Box 3',
    out_of_scope: 'Out of scope',
    '5': 'Box 5',
    not_taxable: 'Not a taxable purchase',
    no_gst: 'No GST',
  }
  return (
    <div>
      <p className="muted" style={{ marginTop: 0 }}>
        GST Calculation v{gst.version} for {gst.period_name} ({formatDate(gst.period_start)} – {formatDate(gst.period_end)}), saved{' '}
        {formatDateTime(gst.calculated_at)}
        {gst.calculated_by_name ? ` by ${gst.calculated_by_name}` : ''} — {gst.output_document_count} sales and{' '}
        {gst.input_document_count} purchase documents.
        {gst.submitted_at && (
          <>
            {' '}
            <strong>
              Submitted to IRAS {formatDateTime(gst.submitted_at)}
              {gst.submitted_by_name ? ` by ${gst.submitted_by_name}` : ''} — locked.
            </strong>
          </>
        )}
      </p>
      <table>
        <thead>
          <tr>
            <th>Box</th>
            <th>Form 5</th>
            <th style={{ textAlign: 'right' }}>SGD</th>
          </tr>
        </thead>
        <tbody>
          {gst.boxes.map((b) => (
            <tr key={b.box}>
              <td>{b.box}</td>
              <td>{b.label}</td>
              <td style={{ textAlign: 'right' }}>{b.box === 8 || b.box === 6 || b.box === 7 ? <strong>{money(b.amount_sgd)}</strong> : money(b.amount_sgd)}</td>
            </tr>
          ))}
        </tbody>
      </table>
      <button type="button" className="secondary" onClick={() => setShowLines(!showLines)} style={{ marginTop: 8 }}>
        {showLines ? 'Hide documents' : `Show the ${gst.lines.length} documents behind it`}
      </button>
      {showLines && (
        <div style={{ overflowX: 'auto', marginTop: 8 }}>
          <table>
            <thead>
              <tr>
                <th>Type</th>
                <th>Document</th>
                <th>Date</th>
                <th>Company / Individual</th>
                <th>Tax code</th>
                <th>Counted in</th>
                <th style={{ textAlign: 'right' }}>Net</th>
                <th style={{ textAlign: 'right' }}>GST</th>
              </tr>
            </thead>
            <tbody>
              {gst.lines.map((l) => (
                <tr key={`${l.document_type}-${l.document_id}`}>
                  <td>{l.direction === 'output' ? 'Sales' : 'Purchase'}</td>
                  <td>{l.document_number}</td>
                  <td>{formatDate(l.document_date)}</td>
                  <td>{l.party_name ?? '—'}</td>
                  <td>{l.tax_code ?? '—'}</td>
                  <td>{BOX_LABEL[l.box] ?? l.box}</td>
                  <td style={{ textAlign: 'right' }}>{money(l.net_sgd)}</td>
                  <td style={{ textAlign: 'right' }}>{money(l.gst_sgd)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
