/**
 * GL Transaction Ledger — account-level drill-down.
 *
 * Shows every posted debit/credit against a selected account, with a
 * running balance, date filters, and CSV/Excel export. This is the
 * classic "account ledger" view accountants use to trace where every
 * number on the trial balance came from.
 *
 * Reachable from the nav ("GL Transactions") or from the trial balance
 * by clicking an account row.
 */
import { useEffect, useState } from 'react'
import { useSearchParams, Link } from 'react-router-dom'
import ExportControl from '../components/ExportControl'
import {
  api,
  downloadBlob,
  type Account,
  type GLTransactions,
} from '../lib/api'
import { formatMoney as money } from '../lib/format'

const VOUCHER_TYPE_LABELS: Record<string, string> = {
  journal: 'JV',
  receipt: 'RV',
  payment: 'PV',
  sales_invoice: 'SI',
  purchase_invoice: 'PI',
}

export default function GLTransactionsPage() {
  const [searchParams, setSearchParams] = useSearchParams()

  const [accounts, setAccounts] = useState<Account[]>([])
  const [accountId, setAccountId] = useState(searchParams.get('account') ?? '')
  const [dateFrom, setDateFrom] = useState(searchParams.get('from') ?? '')
  const [dateTo, setDateTo] = useState(searchParams.get('to') ?? '')
  const [data, setData] = useState<GLTransactions | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)

  useEffect(() => {
    api.listAccounts().then(setAccounts).catch(() => {})
  }, [])

  // Auto-load when arriving with ?account= from the trial balance link
  useEffect(() => {
    if (accountId) {
      loadTransactions(accountId, dateFrom, dateTo)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  function loadTransactions(acctId: string, from: string, to: string) {
    if (!acctId) return
    setError(null)
    setLoading(true)

    const filters: { date_from?: string; date_to?: string } = {}
    if (from) filters.date_from = from
    if (to) filters.date_to = to

    api
      .glTransactions(acctId, filters)
      .then((result) => {
        setData(result)
        // Sync URL params
        const params: Record<string, string> = { account: acctId }
        if (from) params.from = from
        if (to) params.to = to
        setSearchParams(params, { replace: true })
      })
      .catch((err) => setError(err instanceof Error ? err.message : 'Failed to load'))
      .finally(() => setLoading(false))
  }

  function onSubmit(e: React.FormEvent) {
    e.preventDefault()
    loadTransactions(accountId, dateFrom, dateTo)
  }

  async function onExport(format: string) {
    if (!accountId) return
    setError(null)
    const filters: { date_from?: string; date_to?: string } = {}
    if (dateFrom) filters.date_from = dateFrom
    if (dateTo) filters.date_to = dateTo

    try {
      if (format === 'csv') {
        downloadBlob(await api.exportGlTransactionsCsv(accountId, filters), `gl-${data?.account_code ?? 'transactions'}.csv`)
      } else {
        downloadBlob(await api.exportGlTransactionsExcel(accountId, filters), `gl-${data?.account_code ?? 'transactions'}.xlsx`)
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Export failed')
    }
  }

  return (
    <div>
      <h1>GL Transactions</h1>
      <p className="muted">
        Account-level ledger: every posted debit and credit for a selected
        account, with running balance. Select an account and optional date
        range below, or click an account on the{' '}
        <Link to="/general-ledger">trial balance</Link> to jump here.
      </p>

      {error && <div className="error-banner">{error}</div>}

      <div className="card">
        <form
          onSubmit={onSubmit}
          style={{ display: 'flex', gap: 12, flexWrap: 'wrap', alignItems: 'flex-end' }}
        >
          <div className="form-row" style={{ margin: 0, flex: '1 1 260px' }}>
            <label>Account</label>
            <select
              value={accountId}
              onChange={(e) => setAccountId(e.target.value)}
              required
              style={{ minWidth: 220 }}
            >
              <option value="">Select account...</option>
              {accounts.map((a) => (
                <option key={a.id} value={a.id}>
                  {a.code} {a.name}
                </option>
              ))}
            </select>
          </div>
          <div className="form-row" style={{ margin: 0 }}>
            <label>From</label>
            <input
              type="date"
              value={dateFrom}
              onChange={(e) => setDateFrom(e.target.value)}
            />
          </div>
          <div className="form-row" style={{ margin: 0 }}>
            <label>To</label>
            <input
              type="date"
              value={dateTo}
              onChange={(e) => setDateTo(e.target.value)}
            />
          </div>
          <button type="submit" disabled={loading || !accountId}>
            {loading ? 'Loading...' : 'Load'}
          </button>
        </form>
      </div>

      {data && (
        <div className="card">
          <div className="filter-bar">
            <div>
              <h2 style={{ margin: 0 }}>
                {data.account_code} {data.account_name}
              </h2>
              <span className="muted" style={{ fontSize: '0.85em' }}>
                {data.account_type} · {data.rows.length} transaction{data.rows.length !== 1 ? 's' : ''}
              </span>
            </div>
            <ExportControl
              formats={[
                { value: 'csv', label: 'CSV' },
                { value: 'excel', label: 'Excel' },
              ]}
              onExport={onExport}
              onError={setError}
            />
          </div>

          {/* Summary tiles */}
          <div className="stat-grid" style={{ marginTop: 12, marginBottom: 12 }}>
            <div className="card stat-tile">
              <div className="stat-value stat-value-text">{money(data.total_debit)}</div>
              <div className="stat-label">Total Debit</div>
            </div>
            <div className="card stat-tile">
              <div className="stat-value stat-value-text">{money(data.total_credit)}</div>
              <div className="stat-label">Total Credit</div>
            </div>
            <div className="card stat-tile">
              <div
                className="stat-value stat-value-text"
                style={{ color: data.closing_balance < 0 ? '#c0392b' : undefined }}
              >
                {money(data.closing_balance)}
              </div>
              <div className="stat-label">Closing Balance</div>
            </div>
          </div>

          <div style={{ overflowX: 'auto' }}>
            <table>
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Voucher</th>
                  <th>Type</th>
                  <th>Narration</th>
                  <th>Description</th>
                  <th style={{ textAlign: 'right' }}>Debit</th>
                  <th style={{ textAlign: 'right' }}>Credit</th>
                  <th style={{ textAlign: 'right' }}>Balance</th>
                </tr>
              </thead>
              <tbody>
                {data.rows.map((r) => (
                  <tr key={r.line_id}>
                    <td>{r.entry_date}</td>
                    <td>
                      <span className="muted">{r.voucher_number}</span>
                    </td>
                    <td>
                      <span className="badge muted">
                        {VOUCHER_TYPE_LABELS[r.voucher_type] ?? r.voucher_type}
                      </span>
                    </td>
                    <td>{r.narration}</td>
                    <td className="muted">{r.line_description ?? ''}</td>
                    <td style={{ textAlign: 'right' }}>
                      {r.debit_sgd > 0 ? money(r.debit_sgd) : ''}
                    </td>
                    <td style={{ textAlign: 'right' }}>
                      {r.credit_sgd > 0 ? money(r.credit_sgd) : ''}
                    </td>
                    <td
                      style={{
                        textAlign: 'right',
                        fontVariantNumeric: 'tabular-nums',
                        color: r.balance_sgd < 0 ? '#c0392b' : undefined,
                      }}
                    >
                      {money(r.balance_sgd)}
                    </td>
                  </tr>
                ))}
                {data.rows.length === 0 && (
                  <tr>
                    <td colSpan={8} className="muted">
                      No posted transactions for this account in the selected period.
                    </td>
                  </tr>
                )}
              </tbody>
              {data.rows.length > 0 && (
                <tfoot>
                  <tr>
                    <td colSpan={5}>
                      <strong>Totals</strong>
                    </td>
                    <td style={{ textAlign: 'right' }}>
                      <strong>{money(data.total_debit)}</strong>
                    </td>
                    <td style={{ textAlign: 'right' }}>
                      <strong>{money(data.total_credit)}</strong>
                    </td>
                    <td
                      style={{
                        textAlign: 'right',
                        fontVariantNumeric: 'tabular-nums',
                        color: data.closing_balance < 0 ? '#c0392b' : undefined,
                      }}
                    >
                      <strong>{money(data.closing_balance)}</strong>
                    </td>
                  </tr>
                </tfoot>
              )}
            </table>
          </div>
        </div>
      )}
    </div>
  )
}
