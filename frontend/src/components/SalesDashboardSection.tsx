import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  api,
  downloadBlob,
  type ProspectStatus,
  type SalesDashboardArRow,
  type SalespersonCard,
  type SalesDashboardBottomCustomerRow,
  type SalesDashboardSummary,
  type SalesDashboardTopCustomerRow,
} from '../lib/api'
import { formatMoney as money, formatDate } from '../lib/format'
import ExportControl from './ExportControl'

/**
 * NEW FEATURE (not a Python->PHP conversion -- see
 * docs/backlog.md / docs/planned-work.md): "Sales Dashboard - Display
 * below Company Dashboard". Rendered independently of the Company
 * Dashboard's own summary state -- see DashboardPage.tsx's note on why
 * (the Company Dashboard's /dashboard/summary endpoint is not yet
 * converted to backend-php, a pre-existing, separate gap).
 *
 * "This Financial Year" follows Company Setup's financial-year start
 * month (settled 2026-09-15); the two Quotation tiles count real
 * statuses since the same day (BILL-006 approval step). See
 * App\Services\SalesDashboardService's docblock in backend-php.
 */
export default function SalesDashboardSection() {
  const [summary, setSummary] = useState<SalesDashboardSummary | null>(null)
  const [summaryError, setSummaryError] = useState<string | null>(null)
  const [topCustomers, setTopCustomers] = useState<SalesDashboardTopCustomerRow[]>([])
  const [bottomCustomers, setBottomCustomers] = useState<SalesDashboardBottomCustomerRow[]>([])
  const [drillDownBucket, setDrillDownBucket] = useState<string | null>(null)
  const [drillDownRows, setDrillDownRows] = useState<SalesDashboardArRow[]>([])
  const [error, setError] = useState<string | null>(null)
  const [people, setPeople] = useState<{ sees_all: boolean; month_label: string; cards: SalespersonCard[] } | null>(null)

  function refresh() {
    api.salesDashboardSummary().then(setSummary).catch((e) => setSummaryError(e instanceof Error ? e.message : 'Failed to load'))
    api.salesDashboardTopBillingCustomers().then(setTopCustomers).catch(() => setTopCustomers([]))
    api.salesDashboardBottomNonActiveCustomers().then(setBottomCustomers).catch(() => setBottomCustomers([]))
    api.salesDashboardSalespeople().then(setPeople).catch(() => setPeople(null))
  }

  useEffect(refresh, [])

  async function onDrillDown(bucket: string) {
    setError(null)
    if (drillDownBucket === bucket) {
      setDrillDownBucket(null)
      return
    }
    try {
      const rows = await api.salesDashboardArBreakdown(bucket)
      setDrillDownRows(rows)
      setDrillDownBucket(bucket)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load breakdown')
    }
  }

  const bucketLabel: Record<string, string> = {
    total: 'Total AR Outstanding',
    '31_60': 'AR Outstanding -- 2 months (31-60 days overdue)',
    '61_90': 'AR Outstanding -- 3 months (61-90 days overdue)',
  }

  return (
    <div>
      <h2>Sales Dashboard</h2>
      <p className="muted">
        {summary
          ? summary.financial_year_is_calendar_year
            ? `Financial year FY${summary.financial_year} (the calendar year).`
            : `Financial year FY${summary.financial_year}, as set in Company Setup.`
          : 'Financial year as set in Company Setup.'}{' '}
        Click a tile to see its breakdown.
      </p>
      {error && <div className="error-banner">{error}</div>}
      {summaryError && <p className="muted">Sales Dashboard summary unavailable: {summaryError}</p>}

      {summary && (
        <div className="stat-grid">
          <Link to="/operations-reports?report=contract-renewal-due-listing" className="card stat-tile" style={{ textDecoration: 'none', color: 'inherit' }}>
            <div className="stat-value">{summary.contracts_due_for_renewal}</div>
            <div className="stat-label">Contracts Due for Renewal</div>
          </Link>
          <div className="card stat-tile" style={{ cursor: 'pointer' }} onClick={() => onDrillDown('total')}>
            <div className="stat-value stat-value-text">{money(summary.ar_outstanding_total_sgd)}</div>
            <div className="stat-label">Total AR Outstanding</div>
          </div>
          <div className="card stat-tile" style={{ cursor: 'pointer' }} onClick={() => onDrillDown('31_60')}>
            <div className="stat-value stat-value-text">{money(summary.ar_outstanding_2_months_sgd)}</div>
            <div className="stat-label">AR Outstanding -- 2 months</div>
          </div>
          <div className="card stat-tile" style={{ cursor: 'pointer' }} onClick={() => onDrillDown('61_90')}>
            <div className="stat-value stat-value-text">{money(summary.ar_outstanding_3_months_sgd)}</div>
            <div className="stat-label">AR Outstanding -- 3 months</div>
          </div>
          <Link to="/quotations?status=pending_approval" className="card stat-tile" style={{ textDecoration: 'none', color: 'inherit' }}>
            <div className="stat-value">{summary.quotations_pending_approval.count}</div>
            <div className="stat-label">Quotations Pending Approval</div>
            <div className="muted" style={{ marginTop: 4, fontSize: 12 }}>
              Awaiting the Sales Manager (BILL-006)
            </div>
          </Link>
          <Link to="/quotations?status=sent" className="card stat-tile" style={{ textDecoration: 'none', color: 'inherit' }}>
            <div className="stat-value">{summary.quotations_pending_confirmation.count}</div>
            <div className="stat-label">Quotations Pending Confirmation by Client</div>
            <div className="muted" style={{ marginTop: 4, fontSize: 12 }}>
              Sent, not yet accepted or rejected
            </div>
          </Link>
        </div>
      )}

      {drillDownBucket && (
        <div className="card">
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <h2>{bucketLabel[drillDownBucket]} -- breakdown</h2>
            <ExportControl
              formats={[{ value: 'csv', label: 'CSV' }, { value: 'excel', label: 'Excel' }]}
              onExport={async (format) => {
                const blob =
                  format === 'csv'
                    ? await api.exportSalesDashboardArBreakdownCsv(drillDownBucket)
                    : await api.exportSalesDashboardArBreakdownExcel(drillDownBucket)
                downloadBlob(blob, `ar-outstanding-breakdown.${format === 'csv' ? 'csv' : 'xlsx'}`)
              }}
              onError={setError}
            />
          </div>
          <table>
            <thead>
              <tr>
                <th>Invoice</th>
                <th>Company / Individual</th>
                <th>Due date</th>
                <th>Outstanding (SGD)</th>
              </tr>
            </thead>
            <tbody>
              {drillDownRows.map((r) => (
                <tr key={r.invoice_id}>
                  <td>{r.invoice_number}</td>
                  <td>{r.customer_name}</td>
                  <td className="muted">{formatDate(r.due_date)}</td>
                  <td>{money(r.outstanding_sgd)}</td>
                </tr>
              ))}
              {drillDownRows.length === 0 && (
                <tr>
                  <td colSpan={4} className="muted">
                    Nothing outstanding in this bucket.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      )}

      {people && people.cards.length > 0 && (
        <div className="card">
          <h2 style={{ marginTop: 0 }}>{people.sees_all ? 'By salesperson' : 'My figures'}</h2>
          <p className="muted" style={{ marginTop: 0 }}>
            Prospects by stage as they stand today; quoted, billed and paid for {people.month_label} and for FY
            {summary?.financial_year ?? ''} to date. Work counts on its prospect's salesperson.
          </p>
          <div className="sp-grid">
            {people.cards.map((c) => (
              <SalespersonCardView key={c.salesperson_user_id ?? c.kind} card={c} monthLabel={people.month_label} year={summary?.financial_year} />
            ))}
          </div>
        </div>
      )}

      <div className="card">
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <h2>Top 10 Sales Billing Customer ({summary?.financial_year ?? new Date().getFullYear()})</h2>
          <ExportControl
            formats={[{ value: 'csv', label: 'CSV' }, { value: 'excel', label: 'Excel' }]}
            onExport={async (format) => {
              const blob =
                format === 'csv'
                  ? await api.exportSalesDashboardTopBillingCustomersCsv()
                  : await api.exportSalesDashboardTopBillingCustomersExcel()
              downloadBlob(blob, `top-billing-customers.${format === 'csv' ? 'csv' : 'xlsx'}`)
            }}
            onError={setError}
          />
        </div>
        <p className="muted" style={{ marginTop: -6 }}>
          Ranked by net-of-GST invoiced revenue for the financial year.
        </p>
        <table>
          <thead>
            <tr>
              <th>Company / Individual</th>
              <th>Invoices</th>
              <th>Net revenue (SGD, excl. GST)</th>
            </tr>
          </thead>
          <tbody>
            {topCustomers.map((c) => (
              <tr key={c.customer_id}>
                <td>
                  <Link to={`/company-individuals/${c.customer_id}`}>{c.customer_name}</Link>
                </td>
                <td className="muted">{c.invoice_count}</td>
                <td>{money(c.net_revenue_sgd)}</td>
              </tr>
            ))}
            {topCustomers.length === 0 && (
              <tr>
                <td colSpan={3} className="muted">
                  No invoices this financial year yet.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      <div className="card">
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <h2>Bottom 10 Non-Active Customer Listing ({summary?.financial_year ?? new Date().getFullYear()})</h2>
          <ExportControl
            formats={[{ value: 'csv', label: 'CSV' }, { value: 'excel', label: 'Excel' }]}
            onExport={async (format) => {
              const blob =
                format === 'csv'
                  ? await api.exportSalesDashboardBottomNonActiveCustomersCsv()
                  : await api.exportSalesDashboardBottomNonActiveCustomersExcel()
              downloadBlob(blob, `bottom-non-active-customers.${format === 'csv' ? 'csv' : 'xlsx'}`)
            }}
            onError={setError}
          />
        </div>
        <p className="muted" style={{ marginTop: -6 }}>
          Customers with zero invoices this financial year.
        </p>
        <table>
          <thead>
            <tr>
              <th>Company / Individual</th>
            </tr>
          </thead>
          <tbody>
            {bottomCustomers.map((c) => (
              <tr key={c.customer_id}>
                <td>
                  <Link to={`/company-individuals/${c.customer_id}`}>{c.customer_name}</Link>
                </td>
              </tr>
            ))}
            {bottomCustomers.length === 0 && (
              <tr>
                <td className="muted">Every customer has at least one invoice this financial year.</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  )
}

const STAGES: { key: ProspectStatus; label: string }[] = [
  { key: 'new', label: 'New' },
  { key: 'qualified', label: 'Qualified' },
  { key: 'proposal', label: 'Proposal' },
  { key: 'negotiation', label: 'Negotiation' },
  { key: 'won', label: 'Won' },
  { key: 'lost', label: 'Lost' },
]

const ROLE_LABEL: Record<string, string> = {
  sales_manager: 'Sales Manager',
  sales_supervisor: 'Sales Supervisor',
  sales_staff: 'Sales Staff',
  owner: 'Owner',
}

function initials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean)
  return ((parts[0]?.[0] ?? '') + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase() || '?'
}

/** Compact money for the big figures: $12.3k, $1.2M; the exact amount is in the tooltip. */
function compact(n: number): string {
  if (Math.abs(n) >= 1_000_000) return `$${(n / 1_000_000).toFixed(1).replace(/\.0$/, '')}M`
  if (Math.abs(n) >= 10_000) return `$${(n / 1_000).toFixed(1).replace(/\.0$/, '')}k`
  return `$${Math.round(n).toLocaleString('en-SG')}`
}

function SalespersonCardView({ card: c, monthLabel, year }: { card: SalespersonCard; monthLabel: string; year?: number }) {
  const prospectsLink = c.salesperson_user_id ? `/prospects?salesperson_user_id=${c.salesperson_user_id}` : '/prospects'
  const stages = STAGES.map((st) => ({ ...st, n: c.prospects_by_stage[st.key] ?? 0 }))
  const total = stages.reduce((sum, st) => sum + st.n, 0)
  const won = c.prospects_by_stage.won ?? 0
  const lost = c.prospects_by_stage.lost ?? 0
  const winRate = won + lost > 0 ? Math.round((won / (won + lost)) * 100) : null
  const collected = c.billed_sgd > 0 ? Math.min(100, Math.round((c.paid_sgd / c.billed_sgd) * 100)) : null
  const person = c.kind === 'salesperson'
  const figures: { label: string; month: number; year: number }[] = [
    { label: 'Quoted', month: c.quoted_month_sgd, year: c.quoted_sgd },
    { label: 'Billed', month: c.billed_month_sgd, year: c.billed_sgd },
    { label: 'Paid', month: c.paid_month_sgd, year: c.paid_sgd },
  ]
  return (
    <article className={`sp-card${person ? '' : ' sp-card--other'}`} data-testid="salesperson-card">
      <header className="sp-head">
        <span className="sp-avatar" aria-hidden="true">
          {person ? initials(c.name) : c.kind === 'no_prospect' ? '—' : '?'}
        </span>
        <div className="sp-who">
          <strong className="sp-name">{c.name}</strong>
          <span className="sp-role">
            {person ? ROLE_LABEL[c.role ?? ''] ?? 'Salesperson' : c.kind === 'no_prospect' ? 'Quotations and invoices with no prospect' : 'Prospects with no salesperson'}
          </span>
        </div>
        {winRate !== null && (
          <span className={`sp-pill ${winRate >= 50 ? 'sp-pill--good' : ''}`} title={`${won} won, ${lost} lost`}>
            {winRate}% win
          </span>
        )}
      </header>

      {c.kind !== 'no_prospect' && (
        <section className="sp-pipeline" aria-label="Prospects by stage">
          <div className="sp-bar" role="img" aria-label={stages.map((st) => `${st.label} ${st.n}`).join(', ')}>
            {total === 0 ? (
              <span className="sp-bar-empty" />
            ) : (
              stages
                .filter((st) => st.n > 0)
                .map((st) => <span key={st.key} className={`sp-seg sp-seg--${st.key}`} style={{ flexGrow: st.n }} title={`${st.label}: ${st.n}`} />)
            )}
          </div>
          <ul className="sp-legend">
            {stages.map((st) => (
              <li key={st.key} className={st.n ? '' : 'is-zero'}>
                <i className={`sp-dot sp-seg--${st.key}`} />
                {st.label} <b>{st.n}</b>
              </li>
            ))}
          </ul>
        </section>
      )}

      <section className="sp-figures">
        {figures.map((f) => (
          <div key={f.label} className="sp-figure">
            <span className="sp-figure-label">{f.label}</span>
            <span className="sp-figure-month" title={`${monthLabel}: ${money(f.month)}`}>
              {compact(f.month)}
            </span>
            <span className="sp-figure-year" title={`FY${year ?? ''} to date: ${money(f.year)}`}>
              FY {compact(f.year)}
            </span>
          </div>
        ))}
      </section>
      <p className="sp-period">
        {monthLabel} · FY{year ?? ''} to date underneath
      </p>

      {collected !== null && (
        <section className="sp-collect" aria-label="Collected">
          <div className="sp-collect-top">
            <span>Collected this FY</span>
            <b>{collected}%</b>
          </div>
          <div className="sp-meter">
            <span style={{ width: `${collected}%` }} />
          </div>
        </section>
      )}

      {c.kind !== 'no_prospect' && (
        <footer className="sp-foot">
          <Link to={prospectsLink}>
            {c.open_prospects} open prospect{c.open_prospects === 1 ? '' : 's'} →
          </Link>
        </footer>
      )}
    </article>
  )
}
