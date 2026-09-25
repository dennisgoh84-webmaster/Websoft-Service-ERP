// Accounting Periods — per-document-type, per-operation lock matrix.
//
// Each period carries a grid of locks: 5 document types × up to 6
// operations each. Individual cells can be toggled; "Close All" and
// "Open All (owner)" set every lock at once.
//
// Two pragmatic defaults still apply: a date with no period defined
// is unrestricted (opt-in protection), and "fiscal year" is whatever
// date range a period's rows say.
import { useEffect, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import {
  api,
  type AccountingPeriod,
  type PeriodDocType,
  type PeriodLock,
  type PeriodOperation,
} from '../lib/api'
import { formatDate } from '../lib/format'

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
      const start = new Date(fiscalYear, month, 1).toISOString().slice(0, 10)
      const end = new Date(fiscalYear, month, lastDayOfMonth(fiscalYear, month)).toISOString().slice(0, 10)
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
      <h1>Accounting Periods</h1>
      <p className="muted">
        Each period carries a lock matrix per document type and operation.
        Locking an operation prevents that action on documents dated within the period.
        Click a cell to toggle; use Close All / Open All for bulk changes.
        A date with no period defined is unrestricted — periods are opt-in protection.
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
                    <td style={{ display: 'flex', gap: 6 }}>
                      {summary !== 'closed' && (
                        <button className="secondary" onClick={() => onCloseAll(p)} style={{ fontSize: '0.85em' }}>
                          Close All
                        </button>
                      )}
                      {summary !== 'open' && (
                        <button className="secondary" onClick={() => onOpenAll(p)} style={{ fontSize: '0.85em' }}>
                          Open All
                        </button>
                      )}
                    </td>
                  </tr>
                  {isExpanded && (
                    <tr key={`${p.id}-locks`}>
                      <td colSpan={6} style={{ padding: '8px 12px' }}>
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
                <td colSpan={6} className="muted">
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
