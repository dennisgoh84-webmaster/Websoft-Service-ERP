// Accounting Reports -- a card launcher grouped AR / AP / BANK / GL / GST /
// SALES / SETUP (Dennis, 2026-09-24; see components/ReportLauncher.tsx),
// each report then opening with only its own filters: one or several
// Internal Companies (the user's own companies), one or several Company /
// Individual (one list -- a customer can also be a supplier), salesperson,
// and a month-to-month range, accounting period or exact dates. Export offers CSV, Excel and
// PDF (Print) -- the browser's print dialog, like the print forms. Most of these are the same
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
import { AsAtPicker, FilterGrid, InternalCompaniesPicker, MultiPick, PeriodRange } from '../components/ReportFilters'
import { useAuth } from '../lib/AuthContext'
import {
  api,
  downloadBlob,
  type Account,
  type AccountingReportFilters,
  type AgingReport,
  type APAgingReport,
  type BankAccount,
  type CommissionReport,
  type GSTReturn,
  type SalesGPReport,
  type ReportFilterOptions,
  type TaxCode,
  type TrialBalance,
} from '../lib/api'
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
        filters: ['Internal Companies', 'Company / Individual', 'Month end / As at'],
      },
    ],
  },
  {
    label: 'AP',
    reports: [
      {
        key: 'ap-aging',
        title: 'AP Aging',
        summary: 'What you owe, and how overdue it is.',
        details:
          'Every unpaid bill you have received, totalled per company / individual and split by how many days past due it is -- current, 1-30, 31-60, 61-90 and over 90 days. Use it to plan payment runs. Change "As at" to see what was owed on an earlier date.',
        filters: ['Internal Companies', 'Company / Individual', 'Month end / As at'],
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
        filters: ['Internal Companies', 'Month end / As at'],
      },
      {
        key: 'account-ledger',
        title: 'Account Ledger',
        summary: 'Every posting to one account, with a running balance.',
        details:
          'Every posted debit and credit for a single account, with a running balance. It opens in GL Transactions, where you choose the account and the date range and can export the result.',
        filters: ['Account', 'Date range'],
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
          'Output tax on sales invoices and input tax on bills received for the chosen period, totalled per tax code with the net amount and number of documents. Read-only: it does not file anything or post to the ledger.',
        filters: ['Internal Companies', 'Month from – to', 'Dates'],
      },
    ],
  },
  {
    label: 'Sales',
    reports: [
      {
        key: 'sales-gp',
        title: 'Sales Invoice Listing (GP)',
        summary: 'Invoices with revenue, cost and gross profit.',
        details:
          'Every sales invoice issued in the chosen period, with its revenue, cost and gross profit in dollars and as a percentage -- shows which jobs and companies / individuals actually make money.',
        filters: ['Internal Companies', 'Company / Individual', 'Month from – to', 'Dates'],
      },
      {
        key: 'commission',
        title: 'Commission',
        summary: 'Commission earned per salesperson.',
        details:
          'Commission per salesperson per month, worked out only on the part of each invoice that a receipt has actually settled -- so unpaid invoices earn nothing yet. The commission rate itself is set on this report. Part of Commission Management.',
        filters: ['Internal Companies', 'Salesperson', 'Month from – to', 'Dates'],
      },
    ],
  },
  {
    label: 'Setup',
    reports: [
      {
        key: 'chart-of-accounts',
        title: 'Chart of Accounts Listing',
        summary: 'The full list of general ledger accounts.',
        details: 'Every account code with its name, type and status -- handy when checking which code a transaction should go to.',
        filters: [],
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

function isoLocal(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
function firstOfMonth(): string {
  const d = new Date()
  return isoLocal(new Date(d.getFullYear(), d.getMonth(), 1))
}
function today(): string {
  return isoLocal(new Date())
}

const USES_COMPANIES: ReportType[] = ['ar-aging', 'ap-aging', 'trial-balance', 'gst-return', 'sales-gp', 'commission']
const USES_AS_AT: ReportType[] = ['ar-aging', 'ap-aging', 'trial-balance']
const USES_RANGE: ReportType[] = ['gst-return', 'sales-gp', 'commission']

export default function AccountingReportsPage() {
  const { user, moduleAccess } = useAuth()
  // Commission (its report and its rate) belongs to Commission
  // Management: with that module off the tile is not offered at all.
  const sections = moduleAccess.commission_management
    ? SECTIONS
    : SECTIONS.map((s) => ({ ...s, reports: s.reports.filter((r) => r.key !== 'commission') }))
  const [reportType, openReport] = useSelectedReport(sections)
  const [companyIds, setCompanyIds] = useState<string[]>(user?.company_id ? [user.company_id] : [])
  // One Company / Individual pick list for AR, AP and Sales GP alike.
  const [partyIds, setPartyIds] = useState<string[]>([])
  const [staffIds, setStaffIds] = useState<string[]>([])
  const [options, setOptions] = useState<ReportFilterOptions>({ company_individuals: [], sales_staff: [] })
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

  const multiCompany = companyIds.length > 1
  const filters: AccountingReportFilters = {
    company_ids: companyIds.join(','),
    customer_ids: partyIds.join(','),
    supplier_ids: partyIds.join(','),
    sales_staff_ids: staffIds.join(','),
  }
  const asAtFilters: AccountingReportFilters = { ...filters, as_at: asAt || undefined }
  const rangeFilters: AccountingReportFilters = { ...filters, period_start: periodStart, period_end: periodEnd }
  const filterKey = JSON.stringify({ filters, asAt, periodStart, periodEnd })

  const companyNames: string[] =
    (reportType === 'ar-aging' ? arAging?.companies : reportType === 'ap-aging' ? apAging?.companies : reportType === 'trial-balance' ? trialBalance?.companies
      : reportType === 'gst-return' ? gstReturn?.companies : reportType === 'sales-gp' ? salesGP?.companies : commission?.companies) ?? []
  const picked = (ids: string[], opts: { id: string; name: string }[], all: string) =>
    ids.length === 0 ? all : ids.map((id) => (id === 'unassigned' ? 'Unassigned' : opts.find((o) => o.id === id)?.name ?? id)).join(', ')
  const printSummary: string[] = []
  if (reportType && USES_COMPANIES.includes(reportType) && multiCompany) printSummary.push(`Internal Companies: ${companyNames.join(', ')}`)
  if (reportType === 'ar-aging' || reportType === 'ap-aging' || reportType === 'sales-gp') {
    printSummary.push(`Company / Individual: ${picked(partyIds, options.company_individuals, 'All')}`)
  }
  if (reportType === 'commission') printSummary.push(`Salesperson: ${picked(staffIds, options.sales_staff, 'All')}`)
  // The date the server actually used (its own "today" when none was picked), not the browser's clock.
  const resolvedAsAt = asAt || (reportType === 'ar-aging' ? arAging?.as_at : reportType === 'ap-aging' ? apAging?.as_at : trialBalance?.as_at) || ''
  if (reportType && USES_AS_AT.includes(reportType)) printSummary.push(`As at: ${resolvedAsAt ? formatDate(resolvedAsAt) : 'today'}`)
  if (reportType && USES_RANGE.includes(reportType)) printSummary.push(`Period: ${formatDate(periodStart)} to ${formatDate(periodEnd)}`)

  // Every report opens on the first internal company -- the one you are
  // signed in to (Dennis, 2026-09-25: "always default the first one").
  useEffect(() => {
    if (user?.company_id) setCompanyIds([user.company_id])
  }, [reportType, user?.company_id])

  // Company / Individual and salesperson choices follow the ticked internal
  // companies; stale picks are dropped.
  const companyKey = companyIds.join(',')
  useEffect(() => {
    if (!companyKey) return
    api.reportFilterOptions(companyKey).then((o) => {
      setOptions(o)
      const keep = (ids: string[], opts: { id: string }[]) => ids.filter((id) => id === 'unassigned' || opts.some((x) => x.id === id))
      setPartyIds((ids) => keep(ids, o.company_individuals))
      setStaffIds((ids) => keep(ids, o.sales_staff))
    }).catch(() => setOptions({ company_individuals: [], sales_staff: [] }))
  }, [companyKey])

  useEffect(() => {
    setError(null)
    if (reportType === 'ar-aging') {
      api.reportArAging(asAtFilters).then(setArAging).catch((e) => setError(e.message))
    } else if (reportType === 'ap-aging') {
      api.reportApAging(asAtFilters).then(setApAging).catch((e) => setError(e.message))
    } else if (reportType === 'trial-balance') {
      api.reportTrialBalance(asAtFilters).then(setTrialBalance).catch((e) => setError(e.message))
    } else if (reportType === 'bank-accounts') {
      api.listBankAccounts().then(setBankAccounts).catch((e) => setError(e.message))
    } else if (reportType === 'chart-of-accounts') {
      api.listAccounts().then(setAccounts).catch((e) => setError(e.message))
    } else if (reportType === 'tax-types') {
      api.listTaxCodes().then(setTaxCodes).catch((e) => setError(e.message))
    } else if (reportType === 'gst-return') {
      api.reportGstReturn(rangeFilters).then(setGstReturn).catch((e) => setError(e.message))
    } else if (reportType === 'sales-gp') {
      api.reportSalesGP(rangeFilters).then(setSalesGP).catch((e) => setError(e.message))
    } else if (reportType === 'commission') {
      api.reportCommission(rangeFilters).then((r) => {
        setCommission(r)
        setCommissionRateInput(String(r.rate_percent))
      }).catch((e) => setError(e.message))
    }
    // filterKey captures every filter value; the objects built from it are new each render.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [reportType, filterKey])

  async function onSaveCommissionRate() {
    setError(null)
    setSavingRate(true)
    try {
      await api.updateCommissionSettings(Number(commissionRateInput) || 0)
      const r = await api.reportCommission(rangeFilters)
      setCommission(r)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to save commission rate')
    } finally {
      setSavingRate(false)
    }
  }

  async function onExport(format: string) {
    setError(null)
    if (format === 'pdf') {
      window.print()
      return
    }
    const csv = format === 'csv'
    const ext = csv ? 'csv' : 'xlsx'
    if (reportType === 'ar-aging') {
      downloadBlob(csv ? await api.exportArAgingReportCsv(asAtFilters) : await api.exportArAgingReportExcel(asAtFilters), `ar-aging-report.${ext}`)
    } else if (reportType === 'ap-aging') {
      downloadBlob(csv ? await api.exportApAgingReportCsv(asAtFilters) : await api.exportApAgingReportExcel(asAtFilters), `ap-aging-report.${ext}`)
    } else if (reportType === 'trial-balance') {
      downloadBlob(csv ? await api.exportTrialBalanceReportCsv(asAtFilters) : await api.exportTrialBalanceReportExcel(asAtFilters), `trial-balance-report.${ext}`)
    } else if (reportType === 'bank-accounts') {
      downloadBlob(csv ? await api.exportBankAccountsCsv() : await api.exportBankAccountsExcel(), `bank-accounts.${ext}`)
    } else if (reportType === 'chart-of-accounts') {
      downloadBlob(csv ? await api.exportAccountsCsv() : await api.exportAccountsExcel(), `chart-of-accounts.${ext}`)
    } else if (reportType === 'tax-types') {
      downloadBlob(csv ? await api.exportTaxCodesCsv() : await api.exportTaxCodesExcel(), `tax-types.${ext}`)
    } else if (reportType === 'sales-gp') {
      downloadBlob(csv ? await api.exportSalesGPReportCsv(rangeFilters) : await api.exportSalesGPReportExcel(rangeFilters), `sales-gp-report.${ext}`)
    } else if (reportType === 'commission') {
      downloadBlob(csv ? await api.exportCommissionReportCsv(rangeFilters) : await api.exportCommissionReportExcel(rangeFilters), `commission-report.${ext}`)
    } else if (reportType === 'gst-return') {
      downloadBlob(csv ? await api.exportGstReturnCsv(rangeFilters) : await api.exportGstReturnExcel(rangeFilters), `gst-return.${ext}`)
    }
  }


  return (
    <div>
      <h1 className="no-print">Accounting Reports</h1>
      {!reportType ? (
        <>
          <p className="muted">
            Choose a report. Each one shows what it covers and which filters it takes. Every export is
            recorded in Event Logs.
          </p>
          <ReportLauncher sections={sections} onOpen={openReport} />
        </>
      ) : (
        <>
      <ReportHeader sections={sections} current={reportType} onBack={() => openReport(null)} printSummary={printSummary} />
      {error && <div className="error-banner">{error}</div>}

      <div className="card">
        <FilterGrid>
          {USES_COMPANIES.includes(reportType) && <InternalCompaniesPicker value={companyIds} onChange={setCompanyIds} />}
          {(reportType === 'ar-aging' || reportType === 'ap-aging' || reportType === 'sales-gp') && (
            <MultiPick
              label="Company / Individual"
              allLabel="All"
              options={options.company_individuals}
              value={partyIds}
              onChange={setPartyIds}
            />
          )}
          {reportType === 'commission' && (
            <MultiPick
              label="Salesperson"
              allLabel="All"
              options={[...options.sales_staff, { id: 'unassigned', name: 'Unassigned (no salesperson on the contract)' }]}
              value={staffIds}
              onChange={setStaffIds}
            />
          )}
          {USES_AS_AT.includes(reportType) && <AsAtPicker value={asAt} onChange={setAsAt} />}
          {USES_RANGE.includes(reportType) && (
            <PeriodRange from={periodStart} to={periodEnd} onChange={(f, t) => { setPeriodStart(f); setPeriodEnd(t) }} />
          )}
          {reportType !== 'account-ledger' && (
            <div className="report-filter-actions">
              <ExportControl
                formats={[
                  { value: 'csv', label: 'CSV' },
                  { value: 'excel', label: 'Excel' },
                  { value: 'pdf', label: 'PDF' },
                ]}
                onExport={onExport}
                onError={setError}
              />
            </div>
          )}
        </FilterGrid>

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
                    {multiCompany && <th>Internal Company</th>}
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
                    <tr key={`${r.company_id}-${r.customer_id}`}>
                      {multiCompany && <td>{r.company_name}</td>}
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
                      <td colSpan={multiCompany ? 8 : 7} className="muted">
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
                    {multiCompany && <th>Internal Company</th>}
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
                  {apAging.rows.map((r) => (
                    <tr key={`${r.company_id}-${r.supplier_id}`}>
                      {multiCompany && <td>{r.company_name}</td>}
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
                      <td colSpan={multiCompany ? 8 : 7} className="muted">
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
              Sales Invoice Listing (GP): {formatDate(salesGP.period_start)} to {formatDate(salesGP.period_end)}
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
                    {multiCompany && <th>Internal Company</th>}
                    <th>Invoice</th>
                    <th>Date</th>
                    <th>Company / Individual</th>
                    <th>Revenue</th>
                    <th>Cost</th>
                    <th>GP</th>
                    <th>GP%</th>
                  </tr>
                </thead>
                <tbody>
                  {salesGP.rows.map((r) => (
                    <tr key={r.invoice_id}>
                      {multiCompany && <td>{r.company_name}</td>}
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
                      <td colSpan={multiCompany ? 8 : 7} className="muted">
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
              Commission: {formatDate(commission.period_start)} to {formatDate(commission.period_end)}
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
                    {multiCompany && <th>Internal Company</th>}
                    <th>Month</th>
                    <th>Salesperson</th>
                    <th>Commission</th>
                  </tr>
                </thead>
                <tbody>
                  {commission.rows.map((r, i) => (
                    <tr key={`${r.company_id}-${r.month}-${r.sales_staff_id ?? 'none'}-${i}`}>
                      {multiCompany && <td>{r.company_name}</td>}
                      <td>{r.month}</td>
                      <td>{r.sales_staff_name}</td>
                      <td>{money(r.commission_sgd)}</td>
                    </tr>
                  ))}
                  {commission.rows.length === 0 && (
                    <tr>
                      <td colSpan={multiCompany ? 4 : 3} className="muted">
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
              GST Return: {formatDate(gstReturn.period_start)} to {formatDate(gstReturn.period_end)}
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
