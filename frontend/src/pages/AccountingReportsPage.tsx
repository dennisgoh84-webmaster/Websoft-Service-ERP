// Accounting Reports -- a card launcher grouped AR / AP / BANK / GL / GST
// (Dennis, 2026-09-24; see components/ReportLauncher.tsx), each report
// then opening with only its own filters. Most of these are the same
// figures already shown inline elsewhere (Invoices' aging widget,
// Accounts Payable's aging widget, General Ledger's trial balance,
// Chart of Accounts, Bank Master File, Tax Types); this screen is the
// one-stop, filterable/exportable version. GST Return is the one
// genuinely new calculation -- see app/services/reports.py
// gst_return_data for what it does and does not do (read-only, no
// filing, no GL posting). Every export is written to Event Logs.
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import ExportControl from '../components/ExportControl'
import { ReportHeader, ReportLauncher, useSelectedReport, type ReportSection } from '../components/ReportLauncher'
import {
  api,
  downloadBlob,
  type Account,
  type AgingReport,
  type APAgingReport,
  type BankAccount,
  type CommissionReport,
  type GSTReturn,
  type SalesGPReport,
  type TaxCode,
  type TrialBalance,
} from '../lib/api'
import { isoToMonth, monthEndISO, monthStartISO } from '../lib/period'
import { formatMoney as money, formatDate } from '../lib/format'

type ReportType =
  | 'ar-aging'
  | 'ap-aging'
  | 'bank-accounts'
  | 'trial-balance'
  | 'account-ledger'
  | 'chart-of-accounts'
  | 'tax-types'
  | 'gst-return'
  | 'sales-gp'
  | 'commission'

// Sales Invoice Listing and Commission sit under AR (they are views of
// sales invoices and what has been collected on them); Chart of Accounts
// under GL and Tax Types under GST, next to the reports that use them.
const SECTIONS: ReportSection<ReportType>[] = [
  {
    label: 'AR',
    reports: [
      {
        key: 'ar-aging',
        title: 'AR Aging',
        summary: 'Who owes you money, and how overdue it is.',
        details:
          'Every unpaid sales invoice, totalled per company / individual and split by how many days past due it is -- current, 1-30, 31-60, 61-90 and over 90 days. Use it to plan collection follow-ups and for month-end review. Change "As at" to see what was outstanding on an earlier date.',
        filters: ['As at date'],
      },
      {
        key: 'sales-gp',
        title: 'Sales Invoice Listing (GP)',
        summary: 'Invoices with revenue, cost and gross profit.',
        details:
          'Every sales invoice issued in the chosen months, with its revenue, cost and gross profit in dollars and as a percentage -- shows which jobs and customers actually make money.',
        filters: ['Period (months)'],
      },
      {
        key: 'commission',
        title: 'Commission',
        summary: 'Commission earned per salesperson.',
        details:
          'Commission per salesperson per month, worked out only on the part of each invoice that a receipt has actually settled -- so unpaid invoices earn nothing yet. The commission rate itself is set on this report.',
        filters: ['Period (months)'],
      },
    ],
  },
  {
    label: 'AP',
    reports: [
      {
        key: 'ap-aging',
        title: 'AP Aging',
        summary: 'What you owe suppliers, and how overdue it is.',
        details:
          'Every unpaid supplier bill, totalled per supplier and split by how many days past due it is -- current, 1-30, 31-60, 61-90 and over 90 days. Use it to plan payment runs. Change "As at" to see what was owed on an earlier date.',
        filters: ['As at date'],
      },
    ],
  },
  {
    label: 'Bank',
    reports: [
      {
        key: 'bank-accounts',
        title: 'Bank Accounts Listing',
        summary: "Every bank account set up for this company.",
        details:
          'Each bank account with its bank, account name and number, currency and status. To see transactions and reconcile an account, open it from Bank Accounts.',
        filters: [],
      },
    ],
  },
  {
    label: 'GL',
    reports: [
      {
        key: 'trial-balance',
        title: 'Trial Balance',
        summary: 'Debit and credit balance of every account.',
        details:
          'The balance of every general ledger account as at the chosen date. Total debits must equal total credits; if they do not, something was posted unbalanced. The starting point for month-end and year-end checks.',
        filters: ['As at date'],
      },
      {
        key: 'account-ledger',
        title: 'Account Ledger',
        summary: 'Every posting to one account, with a running balance.',
        details:
          'Every posted debit and credit for a single account, with a running balance. It opens in GL Transactions, where you choose the account and the date range and can export the result.',
        filters: ['Account', 'Date range'],
      },
      {
        key: 'chart-of-accounts',
        title: 'Chart of Accounts Listing',
        summary: 'The full list of general ledger accounts.',
        details: 'Every account code with its name, type and status -- handy when checking which code a transaction should go to.',
        filters: [],
      },
    ],
  },
  {
    label: 'GST',
    reports: [
      {
        key: 'gst-return',
        title: 'GST Return',
        summary: 'The figures you need for your GST return.',
        details:
          'Output tax on sales invoices and input tax on supplier bills for the chosen months, totalled per tax code with the net amount and number of documents. Read-only: it does not file anything or post to the ledger.',
        filters: ['Period (months)'],
      },
      {
        key: 'tax-types',
        title: 'Tax Types Listing',
        summary: 'Every tax code and its rate.',
        details: 'Every tax code set up for this company with its rate and status -- the codes used on invoices and bills and summed in the GST Return.',
        filters: [],
      },
    ],
  },
]

function firstOfMonth(): string {
  const d = new Date()
  return new Date(d.getFullYear(), d.getMonth(), 1).toISOString().slice(0, 10)
}
function today(): string {
  return new Date().toISOString().slice(0, 10)
}

export default function AccountingReportsPage() {
  const [reportType, openReport] = useSelectedReport(SECTIONS)
  const [asAt, setAsAt] = useState('')
  const [periodStart, setPeriodStart] = useState(firstOfMonth())
  const [periodEnd, setPeriodEnd] = useState(today())
  const [error, setError] = useState<string | null>(null)

  const [arAging, setArAging] = useState<AgingReport | null>(null)
  const [apAging, setApAging] = useState<APAgingReport | null>(null)
  const [trialBalance, setTrialBalance] = useState<TrialBalance | null>(null)
  const [bankAccounts, setBankAccounts] = useState<BankAccount[]>([])
  const [accounts, setAccounts] = useState<Account[]>([])
  const [taxCodes, setTaxCodes] = useState<TaxCode[]>([])
  const [gstReturn, setGstReturn] = useState<GSTReturn | null>(null)
  const [salesGP, setSalesGP] = useState<SalesGPReport | null>(null)
  const [commission, setCommission] = useState<CommissionReport | null>(null)
  const [commissionRateInput, setCommissionRateInput] = useState('')
  const [savingRate, setSavingRate] = useState(false)

  const usesDateRange = reportType === 'gst-return' || reportType === 'sales-gp' || reportType === 'commission'

  useEffect(() => {
    setError(null)
    const at = asAt || undefined
    if (reportType === 'ar-aging') {
      api.reportArAging(at).then(setArAging).catch((e) => setError(e.message))
    } else if (reportType === 'ap-aging') {
      api.reportApAging(at).then(setApAging).catch((e) => setError(e.message))
    } else if (reportType === 'trial-balance') {
      api.reportTrialBalance(at).then(setTrialBalance).catch((e) => setError(e.message))
    } else if (reportType === 'bank-accounts') {
      api.listBankAccounts().then(setBankAccounts).catch((e) => setError(e.message))
    } else if (reportType === 'chart-of-accounts') {
      api.listAccounts().then(setAccounts).catch((e) => setError(e.message))
    } else if (reportType === 'tax-types') {
      api.listTaxCodes().then(setTaxCodes).catch((e) => setError(e.message))
    } else if (reportType === 'gst-return') {
      api.reportGstReturn(periodStart, periodEnd).then(setGstReturn).catch((e) => setError(e.message))
    } else if (reportType === 'sales-gp') {
      api.reportSalesGP(periodStart, periodEnd).then(setSalesGP).catch((e) => setError(e.message))
    } else if (reportType === 'commission') {
      api.reportCommission(periodStart, periodEnd).then((r) => {
        setCommission(r)
        setCommissionRateInput(String(r.rate_percent))
      }).catch((e) => setError(e.message))
    }
  }, [reportType, asAt, periodStart, periodEnd])

  async function onSaveCommissionRate() {
    setError(null)
    setSavingRate(true)
    try {
      await api.updateCommissionSettings(Number(commissionRateInput) || 0)
      const r = await api.reportCommission(periodStart, periodEnd)
      setCommission(r)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to save commission rate')
    } finally {
      setSavingRate(false)
    }
  }

  async function onExport(format: string) {
    setError(null)
    const at = asAt || undefined
    const ext = format === 'csv' ? 'csv' : 'xlsx'
    if (reportType === 'ar-aging') {
      downloadBlob(format === 'csv' ? await api.exportArAgingReportCsv(at) : await api.exportArAgingReportExcel(at), `ar-aging-report.${ext}`)
    } else if (reportType === 'ap-aging') {
      downloadBlob(format === 'csv' ? await api.exportApAgingReportCsv(at) : await api.exportApAgingReportExcel(at), `ap-aging-report.${ext}`)
    } else if (reportType === 'trial-balance') {
      downloadBlob(format === 'csv' ? await api.exportTrialBalanceReportCsv(at) : await api.exportTrialBalanceReportExcel(at), `trial-balance-report.${ext}`)
    } else if (reportType === 'bank-accounts') {
      downloadBlob(format === 'csv' ? await api.exportBankAccountsCsv() : await api.exportBankAccountsExcel(), `bank-accounts.${ext}`)
    } else if (reportType === 'chart-of-accounts') {
      downloadBlob(format === 'csv' ? await api.exportAccountsCsv() : await api.exportAccountsExcel(), `chart-of-accounts.${ext}`)
    } else if (reportType === 'tax-types') {
      downloadBlob(format === 'csv' ? await api.exportTaxCodesCsv() : await api.exportTaxCodesExcel(), `tax-types.${ext}`)
    } else if (reportType === 'sales-gp') {
      downloadBlob(
        format === 'csv' ? await api.exportSalesGPReportCsv(periodStart, periodEnd) : await api.exportSalesGPReportExcel(periodStart, periodEnd),
        `sales-gp-report.${ext}`,
      )
    } else if (reportType === 'commission') {
      downloadBlob(
        format === 'csv' ? await api.exportCommissionReportCsv(periodStart, periodEnd) : await api.exportCommissionReportExcel(periodStart, periodEnd),
        `commission-report.${ext}`,
      )
    } else if (reportType === 'gst-return') {
      downloadBlob(
        format === 'csv' ? await api.exportGstReturnCsv(periodStart, periodEnd) : await api.exportGstReturnExcel(periodStart, periodEnd),
        `gst-return.${ext}`,
      )
    }
  }

  return (
    <div>
      <h1>Accounting Reports</h1>
      {!reportType ? (
        <>
          <p className="muted">
            Choose a report. Each one shows what it covers and which filters it takes. Every export is
            recorded in Event Logs.
          </p>
          <ReportLauncher sections={SECTIONS} onOpen={openReport} />
        </>
      ) : (
        <>
      <ReportHeader sections={SECTIONS} current={reportType} onBack={() => openReport(null)} />
      {error && <div className="error-banner">{error}</div>}

      <div className="card">
        <div className="filter-bar">
          {usesDateRange ? (
            <>
              <div className="form-row" style={{ margin: 0 }}>
                <label>Period from</label>
                <input
                  type="month"
                  value={isoToMonth(periodStart)}
                  onChange={(e) => setPeriodStart(monthStartISO(e.target.value))}
                />
              </div>
              <div className="form-row" style={{ margin: 0 }}>
                <label>Period to</label>
                <input
                  type="month"
                  value={isoToMonth(periodEnd)}
                  onChange={(e) => setPeriodEnd(monthEndISO(e.target.value))}
                />
              </div>
            </>
          ) : (
            (reportType === 'ar-aging' || reportType === 'ap-aging' || reportType === 'trial-balance') && (
              <>
                <div className="form-row" style={{ margin: 0 }}>
                  <label>As at</label>
                  <input type="date" value={asAt} onChange={(e) => setAsAt(e.target.value)} />
                </div>
                <button type="button" className="secondary" onClick={() => setAsAt('')}>
                  Reset to today
                </button>
              </>
            )
          )}
          {reportType !== 'account-ledger' && (
            <ExportControl
              formats={[
                { value: 'csv', label: 'CSV' },
                { value: 'excel', label: 'Excel' },
              ]}
              onExport={onExport}
              onError={setError}
            />
          )}
        </div>

        {reportType === 'ar-aging' && arAging && (
          <>
            <h2>AR Aging as at {formatDate(arAging.as_at)}</h2>
            <div className="stat-grid">
              <div className="card stat-tile">
                <div className="stat-value stat-value-text">{money(arAging.current)}</div>
                <div className="stat-label">Current / not yet due</div>
              </div>
              <div className="card stat-tile">
                <div className="stat-value stat-value-text">{money(arAging.days_1_30)}</div>
                <div className="stat-label">1-30 days</div>
              </div>
              <div className="card stat-tile">
                <div className="stat-value stat-value-text">{money(arAging.days_31_60)}</div>
                <div className="stat-label">31-60 days</div>
              </div>
              <div className="card stat-tile">
                <div className="stat-value stat-value-text">{money(arAging.days_61_90)}</div>
                <div className="stat-label">61-90 days</div>
              </div>
              <div className="card stat-tile">
                <div className="stat-value stat-value-text">{money(arAging.over_90)}</div>
                <div className="stat-label">Over 90 days</div>
              </div>
              <div className="card stat-tile">
                <div className="stat-value stat-value-text">{money(arAging.total)}</div>
                <div className="stat-label">Total outstanding (SGD)</div>
              </div>
            </div>
            <div className="report-table-wrap" style={{ overflowX: 'auto' }}>
              <table>
                <thead>
                  <tr>
                    <th>Company / Individual</th>
                    <th>Current</th>
                    <th>1-30</th>
                    <th>31-60</th>
                    <th>61-90</th>
                    <th>90+</th>
                    <th>Total</th>
                  </tr>
                </thead>
                <tbody>
                  {arAging.rows.map((r) => (
                    <tr key={r.customer_id}>
                      <td>{r.customer_name}</td>
                      <td>{money(r.current)}</td>
                      <td>{money(r.days_1_30)}</td>
                      <td>{money(r.days_31_60)}</td>
                      <td>{money(r.days_61_90)}</td>
                      <td>{money(r.over_90)}</td>
                      <td>
                        <strong>{money(r.total)}</strong>
                      </td>
                    </tr>
                  ))}
                  {arAging.rows.length === 0 && (
                    <tr>
                      <td colSpan={7} className="muted">
                        Nothing outstanding.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          </>
        )}

        {reportType === 'ap-aging' && apAging && (
          <>
            <h2>AP Aging as at {formatDate(apAging.as_at)}</h2>
            <div className="report-table-wrap" style={{ overflowX: 'auto' }}>
              <table>
                <thead>
                  <tr>
                    <th>Supplier</th>
                    <th>Current</th>
                    <th>1-30</th>
                    <th>31-60</th>
                    <th>61-90</th>
                    <th>90+</th>
                    <th>Total</th>
                  </tr>
                </thead>
                <tbody>
                  {apAging.rows.map((r) => (
                    <tr key={r.supplier_id}>
                      <td>{r.supplier_name}</td>
                      <td>{money(r.current)}</td>
                      <td>{money(r.days_1_30)}</td>
                      <td>{money(r.days_31_60)}</td>
                      <td>{money(r.days_61_90)}</td>
                      <td>{money(r.over_90)}</td>
                      <td>
                        <strong>{money(r.total)}</strong>
                      </td>
                    </tr>
                  ))}
                  {apAging.rows.length === 0 && (
                    <tr>
                      <td colSpan={7} className="muted">
                        Nothing owed.
                      </td>
                    </tr>
                  )}
                </tbody>
                {apAging.rows.length > 0 && (
                  <tfoot>
                    <tr>
                      <td>
                        <strong>Total</strong>
                      </td>
                      <td colSpan={5}></td>
                      <td>
                        <strong>{money(apAging.total)}</strong>
                      </td>
                    </tr>
                  </tfoot>
                )}
              </table>
            </div>
          </>
        )}

        {reportType === 'bank-accounts' && (
          <>
            <h2>Bank Accounts ({bankAccounts.length})</h2>
            <div className="report-table-wrap" style={{ overflowX: 'auto' }}>
              <table>
                <thead>
                  <tr>
                    <th>Bank</th>
                    <th>Account name</th>
                    <th>Account no.</th>
                    <th>Currency</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  {bankAccounts.map((b) => (
                    <tr key={b.id}>
                      <td>{b.bank_name}</td>
                      <td>{b.account_name}</td>
                      <td>{b.account_number}</td>
                      <td>{b.currency_code}</td>
                      <td>
                        <span className={`badge ${b.is_active ? 'active' : 'draft'}`}>{b.is_active ? 'Active' : 'Inactive'}</span>
                      </td>
                    </tr>
                  ))}
                  {bankAccounts.length === 0 && (
                    <tr>
                      <td colSpan={5} className="muted">
                        No bank accounts set up yet -- see Bank Master File.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          </>
        )}

        {reportType === 'trial-balance' && trialBalance && (
          <>
            <h2>
              Trial balance{' '}
              <span className={`badge ${trialBalance.is_balanced ? 'active' : 'exceeded'}`}>
                {trialBalance.is_balanced ? 'balanced' : 'OUT OF BALANCE'}
              </span>
            </h2>
            <div className="report-table-wrap" style={{ overflowX: 'auto' }}>
              <table>
                <thead>
                  <tr>
                    <th>Code</th>
                    <th>Account</th>
                    <th>Type</th>
                    <th>Debit</th>
                    <th>Credit</th>
                    <th>Balance</th>
                  </tr>
                </thead>
                <tbody>
                  {trialBalance.rows.map((r) => (
                    <tr key={r.account_id}>
                      <td>{r.code}</td>
                      <td>{r.name}</td>
                      <td>{r.account_type}</td>
                      <td>{money(r.debit_sgd)}</td>
                      <td>{money(r.credit_sgd)}</td>
                      <td>{money(r.balance_sgd)}</td>
                    </tr>
                  ))}
                  {trialBalance.rows.length === 0 && (
                    <tr>
                      <td colSpan={6} className="muted">
                        No posted journal entries yet.
                      </td>
                    </tr>
                  )}
                </tbody>
                {trialBalance.rows.length > 0 && (
                  <tfoot>
                    <tr>
                      <td colSpan={3}>
                        <strong>Total</strong>
                      </td>
                      <td>
                        <strong>{money(trialBalance.total_debit)}</strong>
                      </td>
                      <td>
                        <strong>{money(trialBalance.total_credit)}</strong>
                      </td>
                      <td></td>
                    </tr>
                  </tfoot>
                )}
              </table>
            </div>
          </>
        )}

        {reportType === 'account-ledger' && (
          <div className="card" style={{ textAlign: 'center', padding: 32 }}>
            <h2>Account Ledger</h2>
            <p className="muted">
              View every posted debit and credit for a single account with running balance,
              date filters, and CSV/Excel export.
            </p>
            <Link to="/gl-transactions">
              <button>Open GL Transactions →</button>
            </Link>
          </div>
        )}

        {reportType === 'chart-of-accounts' && (
          <>
            <h2>Chart of Accounts ({accounts.length})</h2>
            <div className="report-table-wrap" style={{ overflowX: 'auto' }}>
              <table>
                <thead>
                  <tr>
                    <th>Code</th>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  {accounts.map((a) => (
                    <tr key={a.id}>
                      <td>{a.code}</td>
                      <td>{a.name}</td>
                      <td>{a.account_type}</td>
                      <td>
                        <span className={`badge ${a.is_active ? 'active' : 'draft'}`}>{a.is_active ? 'Active' : 'Retired'}</span>
                      </td>
                    </tr>
                  ))}
                  {accounts.length === 0 && (
                    <tr>
                      <td colSpan={4} className="muted">
                        No accounts yet.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          </>
        )}

        {reportType === 'tax-types' && (
          <>
            <h2>Tax Types ({taxCodes.length})</h2>
            <div className="report-table-wrap" style={{ overflowX: 'auto' }}>
              <table>
                <thead>
                  <tr>
                    <th>Code</th>
                    <th>Name</th>
                    <th>Rate %</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  {taxCodes.map((t) => (
                    <tr key={t.id}>
                      <td>{t.code}</td>
                      <td>{t.name}</td>
                      <td>{t.rate_percent}%</td>
                      <td>
                        <span className={`badge ${t.is_active ? 'active' : 'draft'}`}>{t.is_active ? 'Active' : 'Retired'}</span>
                      </td>
                    </tr>
                  ))}
                  {taxCodes.length === 0 && (
                    <tr>
                      <td colSpan={4} className="muted">
                        No tax types yet.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          </>
        )}

        {reportType === 'sales-gp' && salesGP && (
          <>
            <h2>
              Sales Invoice Listing (GP): {salesGP.period_start} to {salesGP.period_end}
            </h2>
            <p className="muted">
              GP = revenue (net of GST) minus product cost. A row without a cost basis (no dot
              below) shows cost as $0.00 -- that's "unknown", not "free": either the invoice has no
              linked quotation (e.g. Excess Usage) or its quotation lines never had a cost entered.
            </p>
            <div className="stat-grid">
              <div className="card stat-tile">
                <div className="stat-value stat-value-text">{money(salesGP.total_revenue_sgd)}</div>
                <div className="stat-label">Total revenue</div>
              </div>
              <div className="card stat-tile">
                <div className="stat-value stat-value-text">{money(salesGP.total_cost_sgd)}</div>
                <div className="stat-label">Total cost</div>
              </div>
              <div className="card stat-tile">
                <div className="stat-value stat-value-text">{money(salesGP.total_gp_sgd)}</div>
                <div className="stat-label">Total GP</div>
              </div>
              <div className="card stat-tile">
                <div className="stat-value stat-value-text">{salesGP.total_gp_percent}%</div>
                <div className="stat-label">Overall GP%</div>
              </div>
            </div>
            <div className="report-table-wrap" style={{ overflowX: 'auto' }}>
              <table>
                <thead>
                  <tr>
                    <th>Invoice</th>
                    <th>Date</th>
                    <th>Customer</th>
                    <th>Revenue</th>
                    <th>Cost</th>
                    <th>GP</th>
                    <th>GP%</th>
                  </tr>
                </thead>
                <tbody>
                  {salesGP.rows.map((r) => (
                    <tr key={r.invoice_id}>
                      <td>{r.invoice_number}</td>
                      <td>{formatDate(r.issued_at)}</td>
                      <td>{r.customer_name}</td>
                      <td>{money(r.revenue_sgd)}</td>
                      <td>
                        {money(r.cost_sgd)}
                        {!r.has_cost_basis && (
                          <span className="muted" title="No known cost basis for this invoice">
                            {' '}
                            *
                          </span>
                        )}
                      </td>
                      <td>{money(r.gp_sgd)}</td>
                      <td>{r.gp_percent}%</td>
                    </tr>
                  ))}
                  {salesGP.rows.length === 0 && (
                    <tr>
                      <td colSpan={7} className="muted">
                        No invoices in this date range.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          </>
        )}

        {reportType === 'commission' && commission && (
          <>
            <h2>
              Commission: {commission.period_start} to {commission.period_end}
            </h2>
            <p className="muted">
              Formula (confirmed with Dennis, 2026-09-12): rate % of gross profit, applied to the
              portion of an invoice a receipt has actually settled -- grouped by the month the
              receipt was received, and credited to the invoice's own contract salesperson.
            </p>
            <div className="filter-bar">
              <div className="form-row" style={{ margin: 0 }}>
                <label>Commission rate %</label>
                <input
                  type="number"
                  min="0"
                  max="100"
                  step="0.01"
                  value={commissionRateInput}
                  onChange={(e) => setCommissionRateInput(e.target.value)}
                  style={{ width: 90 }}
                />
              </div>
              <button type="button" onClick={onSaveCommissionRate} disabled={savingRate}>
                {savingRate ? 'Saving...' : 'Save rate'}
              </button>
            </div>
            <div className="stat-grid">
              <div className="card stat-tile">
                <div className="stat-value stat-value-text">{commission.rate_percent}%</div>
                <div className="stat-label">Current rate</div>
              </div>
              <div className="card stat-tile">
                <div className="stat-value stat-value-text">{money(commission.total_commission_sgd)}</div>
                <div className="stat-label">Total commission</div>
              </div>
            </div>
            <div className="report-table-wrap" style={{ overflowX: 'auto' }}>
              <table>
                <thead>
                  <tr>
                    <th>Month</th>
                    <th>Salesperson</th>
                    <th>Commission</th>
                  </tr>
                </thead>
                <tbody>
                  {commission.rows.map((r, i) => (
                    <tr key={`${r.month}-${r.sales_staff_id ?? 'none'}-${i}`}>
                      <td>{r.month}</td>
                      <td>{r.sales_staff_name}</td>
                      <td>{money(r.commission_sgd)}</td>
                    </tr>
                  ))}
                  {commission.rows.length === 0 && (
                    <tr>
                      <td colSpan={3} className="muted">
                        No receipts applied to invoices in this date range.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          </>
        )}

        {reportType === 'gst-return' && gstReturn && (
          <>
            <h2>
              GST Return: {gstReturn.period_start} to {gstReturn.period_end}
            </h2>
            <p className="muted">
              Tax point = invoice date, output vs input tax only -- this does not file a return or
              post to the GL. Bad-debt relief on written-off invoices is a separate IRAS scheme not
              covered here.
            </p>
            <div className="stat-grid">
              <div className="card stat-tile">
                <div className="stat-value stat-value-text">{money(gstReturn.total_output_tax_sgd)}</div>
                <div className="stat-label">Output tax (sales)</div>
              </div>
              <div className="card stat-tile">
                <div className="stat-value stat-value-text">{money(gstReturn.total_input_tax_sgd)}</div>
                <div className="stat-label">Input tax (purchases)</div>
              </div>
              <div className="card stat-tile">
                <div className="stat-value stat-value-text">{money(gstReturn.net_gst_payable_sgd)}</div>
                <div className="stat-label">{gstReturn.net_gst_payable_sgd >= 0 ? 'Net GST payable' : 'Net GST reclaimable'}</div>
              </div>
            </div>
            <div className="report-table-wrap" style={{ overflowX: 'auto' }}>
              <table>
                <thead>
                  <tr>
                    <th>Direction</th>
                    <th>Tax code</th>
                    <th>Net (SGD)</th>
                    <th>Tax (SGD)</th>
                    <th>Documents</th>
                  </tr>
                </thead>
                <tbody>
                  {gstReturn.output_rows.map((r) => (
                    <tr key={`output-${r.tax_code}`}>
                      <td>Output</td>
                      <td>{r.tax_code}</td>
                      <td>{money(r.net_sgd)}</td>
                      <td>{money(r.tax_sgd)}</td>
                      <td>{r.document_count}</td>
                    </tr>
                  ))}
                  {gstReturn.input_rows.map((r) => (
                    <tr key={`input-${r.tax_code}`}>
                      <td>Input</td>
                      <td>{r.tax_code}</td>
                      <td>{money(r.net_sgd)}</td>
                      <td>{money(r.tax_sgd)}</td>
                      <td>{r.document_count}</td>
                    </tr>
                  ))}
                  {gstReturn.output_rows.length === 0 && gstReturn.input_rows.length === 0 && (
                    <tr>
                      <td colSpan={5} className="muted">
                        No invoices or bills in this date range.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          </>
        )}
      </div>
        </>
      )}
    </div>
  )
}
