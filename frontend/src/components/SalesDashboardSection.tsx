import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  api,
  downloadBlob,
  type SalesDashboardArRow,
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

  function refresh() {
    api.salesDashboardSummary().then(setSummary).catch((e) => setSummaryError(e instanceof Error ? e.message : 'Failed to load'))
    api.salesDashboardTopBillingCustomers().then(setTopCustomers).catch(() => setTopCustomers([]))
    api.salesDashboardBottomNonActiveCustomers().then(setBottomCustomers).catch(() => setBottomCustomers([]))
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
