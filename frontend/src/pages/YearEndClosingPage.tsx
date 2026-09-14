// Year-End (Fiscal Year) Closing -- split out from Accounting Periods
// into its own page/nav entry (2026-09-11) since it's a distinct,
// owner-only, once-a-year action rather than everyday period
// maintenance. See app/services/periods.py for the closing rules this
// form is driven by (every period in the fiscal year must already be
// closed, one balanced journal entry per close, reversible afterwards
// like any posted voucher).
import { useEffect, useState, type FormEvent } from 'react'
import { api, type Account, type FiscalYearClosure } from '../lib/api'
import { formatDateTime } from '../lib/format'

export default function YearEndClosingPage() {
  const [closures, setClosures] = useState<FiscalYearClosure[]>([])
  const [equityAccounts, setEquityAccounts] = useState<Account[]>([])
  const [error, setError] = useState<string | null>(null)
  const [message, setMessage] = useState<string | null>(null)

  const now = new Date()
  const [closeYear, setCloseYear] = useState(now.getFullYear())
  const [retainedEarningsId, setRetainedEarningsId] = useState('')
  const [closingYear, setClosingYear] = useState(false)

  function refresh() {
    api.listFiscalYearClosures().then(setClosures).catch(() => setClosures([]))
    api.listAccounts({ account_type: 'equity' }).then(setEquityAccounts).catch(() => setEquityAccounts([]))
  }

  useEffect(refresh, [])

  async function onCloseFiscalYear(e: FormEvent) {
    e.preventDefault()
    if (!retainedEarningsId) {
      setError('Choose which Equity account receives the closing balance.')
      return
    }
    if (
      !window.confirm(
        `Close fiscal year ${closeYear}? This posts one journal entry moving every Revenue/Expense ` +
          'account\'s balance for the year into the chosen Equity account. It can only be undone by ' +
          'reversing that journal entry afterwards.',
      )
    ) {
      return
    }
    setError(null)
    setMessage(null)
    setClosingYear(true)
    try {
      await api.closeFiscalYear({ fiscal_year: closeYear, retained_earnings_account_id: retainedEarningsId })
      setMessage(`Fiscal year ${closeYear} closed.`)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to close fiscal year')
    } finally {
      setClosingYear(false)
    }
  }

  const closedFiscalYears = new Set(closures.map((c) => c.fiscal_year))

  return (
    <div>
      <h1>Year-End Closing</h1>
      <p className="muted">
        Owner only. Requires every period in the chosen fiscal year to already be closed (see
        Accounting Periods, under GST and Account Period). Posts one balanced journal entry moving
        each Revenue/Expense account's movement for the year into the Equity account you choose;
        reversible afterwards the same way any posted voucher is corrected (General Ledger &rarr;
        Reverse).
      </p>
      {error && <div className="error-banner">{error}</div>}
      {message && (
        <p className="muted" style={{ marginBottom: 12 }}>
          {message}
        </p>
      )}

      <div className="card">
        <h2>Close a fiscal year</h2>
        <form onSubmit={onCloseFiscalYear}>
          <div className="form-row">
            <label>Fiscal year</label>
            <input
              type="number"
              value={closeYear}
              onChange={(e) => setCloseYear(Number(e.target.value))}
              style={{ width: 120 }}
            />
          </div>
          <div className="form-row">
            <label>Retained Earnings account (Equity)</label>
            <select value={retainedEarningsId} onChange={(e) => setRetainedEarningsId(e.target.value)} required>
              <option value="">Select an Equity account</option>
              {equityAccounts.map((a) => (
                <option key={a.id} value={a.id}>
                  {a.code} {a.name}
                </option>
              ))}
            </select>
          </div>
          <button type="submit" disabled={closingYear || closedFiscalYears.has(closeYear)}>
            {closedFiscalYears.has(closeYear)
              ? `FY${closeYear} already closed`
              : closingYear
                ? 'Closing...'
                : `Close fiscal year ${closeYear}`}
          </button>
        </form>
      </div>

      <div className="card">
        <h2>Closed fiscal years</h2>
        <table>
          <thead>
            <tr>
              <th>Fiscal year</th>
              <th>Closed at</th>
            </tr>
          </thead>
          <tbody>
            {closures.map((c) => (
              <tr key={c.id}>
                <td>{c.fiscal_year}</td>
                <td>{formatDateTime(c.closed_at)}</td>
              </tr>
            ))}
            {closures.length === 0 && (
              <tr>
                <td colSpan={2} className="muted">
                  No fiscal years closed yet.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  )
}
