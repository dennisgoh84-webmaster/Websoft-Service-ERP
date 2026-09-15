import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api, type DashboardSummary } from '../lib/api'
import { formatMoney as money } from '../lib/format'
import { useAuth } from '../lib/AuthContext'

function Stat({
  label,
  value,
  hint,
  to,
  small,
}: {
  label: string
  value: string | number
  hint?: string
  to?: string
  /** Currency/text values (e.g. "SGD 12345.67") need the smaller variant to
      fit a tile on one line -- see .stat-value-text in index.css. */
  small?: boolean
}) {
  const content = (
    <>
      <div className={`stat-value${small ? ' stat-value-text' : ''}`}>{value}</div>
      <div className="stat-label">{label}</div>
      {hint && <div className="muted" style={{ marginTop: 4 }}>{hint}</div>}
    </>
  )
  // The tile itself is the grid item either way (a linked tile is the
  // <a>, not a <div> nested inside one) -- a nested div only ever sizes
  // to its own content and never actually fills the grid cell, which is
  // why tiles used to come out different heights depending on whether
  // they linked anywhere or how much hint text they had.
  return to ? (
    <Link to={to} className="card stat-tile" style={{ textDecoration: 'none', color: 'inherit' }}>
      {content}
    </Link>
  ) : (
    <div className="card stat-tile">{content}</div>
  )
}

export default function DashboardPage() {
  const { activeCompany } = useAuth()
  const [summary, setSummary] = useState<DashboardSummary | null>(null)
  // Company Dashboard's own /dashboard/summary endpoint is not yet
  // converted to backend-php (a pre-existing, separate gap).
  const [summaryError, setSummaryError] = useState<string | null>(null)

  useEffect(() => {
    api
      .dashboardSummary()
      .then(setSummary)
      .catch((e) => setSummaryError(e instanceof Error ? e.message : 'Failed to load'))
  }, [])

  return (
    <div>
      <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
        {activeCompany?.logo && (
          <img
            src={activeCompany.logo}
            alt={`${activeCompany.name} logo`}
            style={{ height: 40, width: 'auto', maxWidth: 160, objectFit: 'contain' }}
          />
        )}
        <h1 style={{ margin: 0 }}>Company Dashboard</h1>
      </div>
      <p className="muted">
        Financial and operations summary for {activeCompany?.name ?? 'this company'} -- see
        docs/business-requirements.md for the full requirements list. Company details (address, GST no.,
        logo) live under Company Setup.
      </p>

      {summaryError && <p className="muted">Company Dashboard summary unavailable: {summaryError}</p>}
      {!summary && !summaryError && <p>Loading...</p>}
      {summary && <CompanyDashboardSummary summary={summary} />}
    </div>
  )
}

function CompanyDashboardSummary({ summary }: { summary: DashboardSummary }) {
  const netReceivable = summary.ar_outstanding_sgd - summary.ap_outstanding_sgd

  return (
    <>
      <h2>Financial Summary</h2>
      <div className="stat-grid">
        <Stat
          small
          label="AR outstanding"
          value={money(summary.ar_outstanding_sgd)}
          hint={`incl. ${money(summary.ar_overdue_sgd)} overdue`}
          to="/invoices"
        />
        <Stat
          small
          label="AP outstanding"
          value={money(summary.ap_outstanding_sgd)}
          hint={`incl. ${money(summary.ap_overdue_sgd)} overdue`}
          to="/accounts-payable"
        />
        <Stat
          small
          label="Net receivable position"
          value={money(netReceivable)}
          hint="AR outstanding less AP outstanding"
        />
        <Stat small label="Invoiced to date" value={money(summary.invoices_total_sgd)} to="/invoices" />
        <div className="card stat-tile">
          <span className={`badge ${summary.gl_is_balanced ? 'active' : 'exceeded'}`}>
            {summary.gl_is_balanced ? 'Balanced' : 'OUT OF BALANCE'}
          </span>
          <div className="stat-label" style={{ marginTop: 4 }}>
            GL Trial Balance
          </div>
        </div>
      </div>
      <p className="muted" style={{ marginTop: -4 }}>
        Full breakdowns: <Link to="/accounting-reports">Accounting Reports</Link> (AR/AP aging, trial
        balance).
      </p>

      <h2>Service Operations</h2>
      <div className="stat-grid">
        <Stat label="Active contracts" value={summary.active_contracts} to="/contracts?status=active" />
        <Stat
          label="Expiring soon"
          value={summary.contracts_expiring_soon}
          hint="within 30 days (SRV-014)"
          to="/contracts"
        />
        <Stat label="Open job orders" value={summary.open_job_orders} to="/job-orders?status=open" />
        <Stat
          label="Excess awaiting review"
          value={summary.excess_awaiting_review}
          to="/excess-review"
        />
        <Stat
          label="Late service records"
          value={summary.missing_service_records}
          hint="submitted >3 business days late (SRV-015)"
        />
        <Stat
          label="Approvals overdue"
          value={summary.service_record_approvals_overdue}
          hint={`of ${summary.service_records_awaiting_approval} awaiting approval; >1 week since submission (SRV-019)`}
          to="/service-record-approval"
        />
        <Stat label="Invoices issued" value={summary.invoices_count} to="/invoices" />
      </div>

      <div className="card">
        <h2>Contracted hours across all contracts</h2>
        <div className="progress-bar" style={{ height: 12 }}>
          <div
            style={{
              width: `${summary.total_contracted_hours ? (summary.total_consumed_hours / summary.total_contracted_hours) * 100 : 0}%`,
            }}
          />
        </div>
        <p>
          {summary.total_consumed_hours.toFixed(1)} used / {summary.total_contracted_hours.toFixed(1)} contracted --{' '}
          <strong>{summary.total_remaining_hours.toFixed(1)} hrs remaining</strong>
        </p>
      </div>
    </>
  )
}
