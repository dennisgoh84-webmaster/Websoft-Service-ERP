// Operations Reports -- filterable listing reports over Contracts, Job
// Orders and Service Records (module_key "operations_reports"). Every
// export is written to Event Logs (see app/routers/reports.py). Reports
// are picked from a card launcher grouped CONTRACTS / JOBS / USAGE
// (components/ReportLauncher.tsx, 2026-09-24); each then shows only its
// own filter panel and columns.
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import ExportControl from '../components/ExportControl'
import { ReportHeader, ReportLauncher, useSelectedReport, type ReportSection } from '../components/ReportLauncher'
import {
  api,
  downloadBlob,
  type Company,
  type ContractKind,
  type ContractReportRow,
  type ContractStatus,
  type CompanyIndividualProductUsageRow,
  type JobOrderReportRow,
  type JobOrderStatus,
  type OperationsFilterOptions,
  type ServiceRecordOutcome,
  type ServiceRecordReportRow,
  type ServiceRecordStatus,
  type SetupListItem,
} from '../lib/api'
import { useAuth } from '../lib/AuthContext'
import { FilterGrid, InternalCompaniesPicker, MultiPick, PeriodRange } from '../components/ReportFilters'
import { formatMoney as money, formatDate } from '../lib/format'

// NEW FEATURE (not a Python->PHP conversion -- see
// docs/backlog.md / docs/planned-work.md): "Service Contract
// Operation Report - Contract Expiry Listing, Contract due for
// renewal Listing" adds two report types onto this same page,
// following its existing report-type-selector pattern.
type ReportType =
  | 'contracts'
  | 'job-orders'
  | 'service-records'
  | 'customer-product-usage'
  | 'contract-expiry-listing'
  | 'contract-renewal-due-listing'

const SECTIONS: ReportSection<ReportType>[] = [
  {
    label: 'Contracts',
    reports: [
      {
        key: 'contracts',
        title: 'Service Contracts',
        summary: 'All service contracts, filtered however you need.',
        details:
          'Every service contract with its company / individual, kind, status, contracted / consumed / remaining amount, value, and start and end dates. Narrow it by status or kind, or use "Expiring within (days)" to find contracts ending soon.',
        filters: ['Internal Companies', 'Company / Individual', 'Status', 'Kind', 'Expiring within', 'Month from – to', 'Dates'],
      },
      {
        key: 'contract-expiry-listing',
        title: 'Contract Expiry Listing',
        summary: 'Contracts that end between two dates.',
        details: 'Every contract whose end date falls between the two dates you choose -- use it to plan renewals for a coming month or quarter.',
        filters: ['Internal Companies', 'Expiry from', 'Expiry to'],
      },
      {
        key: 'contract-renewal-due-listing',
        title: 'Contract due for Renewal Listing',
        summary: 'Contracts already inside their 30-day renewal window.',
        details: 'Contracts ending within the next 30 days -- the same window that flags a contract for renewal on its own page. Nothing to set: it always shows what needs action now.',
        filters: ['Internal Companies'],
      },
    ],
  },
  {
    label: 'Jobs',
    reports: [
      {
        key: 'job-orders',
        title: 'Job Orders',
        summary: 'Job orders by status, assignee and date.',
        details: 'Every job order with its subject, priority, status, assignee and due date. Set "Overdue" to "Overdue only" to see just the jobs that are past due and still open.',
        filters: ['Internal Companies', 'Company / Individual', 'Status', 'Assigned to', 'Overdue', 'Month from – to', 'Dates'],
      },
      {
        key: 'service-records',
        title: 'Service Records',
        summary: 'Work done on site, by status, outcome and staff.',
        details: 'Every service record with its work date, employee, hours, status, outcome and whether it was late -- use it to review what was done for a company / individual or by a technician over a period.',
        filters: ['Internal Companies', 'Company / Individual', 'Status', 'Outcome', 'Staff', 'Month from – to', 'Dates'],
      },
    ],
  },
  {
    label: 'Usage',
    reports: [
      {
        key: 'customer-product-usage',
        title: 'Company / Individual Product Usage',
        summary: 'Which companies / individuals use which products.',
        details: 'Each company / individual with the products they use, filterable by product and by industry -- useful for upgrade campaigns and support planning.',
        filters: ['Internal Companies', 'Company / Individual', 'Product', 'Industry'],
      },
    ],
  },
]

export default function OperationsReportsPage() {
  const [reportType, openReport] = useSelectedReport(SECTIONS)
  const [contractExpiryFrom, setContractExpiryFrom] = useState('')
  const [contractExpiryTo, setContractExpiryTo] = useState('')
  const [expiryListingRows, setExpiryListingRows] = useState<ContractReportRow[]>([])
  const [renewalDueRows, setRenewalDueRows] = useState<ContractReportRow[]>([])
  const { user } = useAuth()
  // Internal Companies (2026-09-25): every report opens on the signed-in company.
  const [companyIds, setCompanyIds] = useState<string[]>(user?.company_id ? [user.company_id] : [])
  const [myCompanies, setMyCompanies] = useState<Company[]>([])
  const [options, setOptions] = useState<OperationsFilterOptions>({ company_individuals: [], staff: [], products: [] })
  const [industries, setIndustries] = useState<SetupListItem[]>([])
  const [error, setError] = useState<string | null>(null)

  // Shared-shape filters -- only the ones relevant to the selected
  // report type are actually sent (see the fetch effect below).
  const [customerIds, setCustomerIds] = useState<string[]>([])
  const [staffId, setStaffId] = useState('')
  const [startDate, setStartDate] = useState('')
  const [endDate, setEndDate] = useState('')

  const [contractStatus, setContractStatus] = useState<ContractStatus | ''>('')
  const [contractKind, setContractKind] = useState<ContractKind | ''>('')
  const [expiringWithinDays, setExpiringWithinDays] = useState('')

  const [jobOrderStatus, setJobOrderStatus] = useState<JobOrderStatus | ''>('')
  const [overdueOnly, setOverdueOnly] = useState(false)

  const [srStatus, setSrStatus] = useState<ServiceRecordStatus | ''>('')
  const [srOutcome, setSrOutcome] = useState<ServiceRecordOutcome | ''>('')

  const [productId, setProductId] = useState('')
  const [industryCode, setIndustryCode] = useState('')

  const [contracts, setContracts] = useState<ContractReportRow[]>([])
  const [jobOrders, setJobOrders] = useState<JobOrderReportRow[]>([])
  const [serviceRecords, setServiceRecords] = useState<ServiceRecordReportRow[]>([])
  const [productUsage, setProductUsage] = useState<CompanyIndividualProductUsageRow[]>([])

  const companyKey = companyIds.join(',')
  const multiCompany = companyIds.length > 1

  useEffect(() => {
    api.listMyCompanies().then(setMyCompanies).catch(() => setMyCompanies([]))
    api.listSetupItems({ list_type: 'industry' }).then(setIndustries).catch(() => setIndustries([]))
  }, [])

  useEffect(() => {
    if (user?.company_id) setCompanyIds([user.company_id])
  }, [reportType, user?.company_id])

  // Company / Individual, staff and product choices follow the ticked
  // internal companies; picks that no longer apply are dropped.
  useEffect(() => {
    if (!companyKey) return
    api.operationsFilterOptions(companyKey).then((o) => {
      setOptions(o)
      setCustomerIds((ids) => ids.filter((id) => o.company_individuals.some((c) => c.id === id)))
      setStaffId((id) => (o.staff.some((x) => x.id === id) ? id : ''))
      setProductId((id) => (o.products.some((x) => x.id === id) ? id : ''))
    }).catch(() => setOptions({ company_individuals: [], staff: [], products: [] }))
  }, [companyKey])

  function resetFilters() {
    if (user?.company_id) setCompanyIds([user.company_id])
    setCustomerIds([])
    setStaffId('')
    setStartDate('')
    setEndDate('')
    setContractStatus('')
    setContractKind('')
    setExpiringWithinDays('')
    setJobOrderStatus('')
    setOverdueOnly(false)
    setSrStatus('')
    setSrOutcome('')
    setProductId('')
    setIndustryCode('')
    setContractExpiryFrom('')
    setContractExpiryTo('')
  }

  useEffect(() => {
    setError(null)
    if (reportType === 'contracts') {
      api
        .reportContracts({
          status: contractStatus || undefined,
          contract_kind: contractKind || undefined,
          company_ids: companyKey || undefined,
        customer_ids: customerIds.join(',') || undefined,
          expiring_within_days: expiringWithinDays ? Number(expiringWithinDays) : undefined,
          start_date: startDate || undefined,
          end_date: endDate || undefined,
        })
        .then(setContracts)
        .catch((e) => setError(e.message))
    } else if (reportType === 'job-orders') {
      api
        .reportJobOrders({
          status: jobOrderStatus || undefined,
          company_ids: companyKey || undefined,
        customer_ids: customerIds.join(',') || undefined,
          assigned_to_user_id: staffId || undefined,
          overdue_only: overdueOnly || undefined,
          start_date: startDate || undefined,
          end_date: endDate || undefined,
        })
        .then(setJobOrders)
        .catch((e) => setError(e.message))
    } else if (reportType === 'service-records') {
      api
        .reportServiceRecords({
          status: srStatus || undefined,
          outcome: srOutcome || undefined,
          company_ids: companyKey || undefined,
        customer_ids: customerIds.join(',') || undefined,
          employee_user_id: staffId || undefined,
          start_date: startDate || undefined,
          end_date: endDate || undefined,
        })
        .then(setServiceRecords)
        .catch((e) => setError(e.message))
    } else if (reportType === 'customer-product-usage') {
      api
        .reportCompanyIndividualProductUsage({
          company_ids: companyKey || undefined,
        customer_ids: customerIds.join(',') || undefined,
          product_id: productId || undefined,
          industry_code: industryCode || undefined,
        })
        .then(setProductUsage)
        .catch((e) => setError(e.message))
    } else if (reportType === 'contract-expiry-listing') {
      api
        .reportContractExpiryListing({ company_ids: companyKey || undefined, expiry_from: contractExpiryFrom || undefined, expiry_to: contractExpiryTo || undefined })
        .then(setExpiryListingRows)
        .catch((e) => setError(e.message))
    } else if (reportType === 'contract-renewal-due-listing') {
      api
        .reportContractRenewalDueListing({ company_ids: companyKey || undefined })
        .then(setRenewalDueRows)
        .catch((e) => setError(e.message))
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [
    reportType, companyKey, customerIds, staffId, startDate, endDate, contractStatus, contractKind,
    expiringWithinDays, jobOrderStatus, overdueOnly, srStatus, srOutcome, productId, industryCode,
    contractExpiryFrom, contractExpiryTo,
  ])


  async function onExport(format: string) {
    setError(null)
    if (format === 'pdf') {
      window.print()
      return
    }
    if (reportType === 'contracts') {
      const filters = {
        status: contractStatus || undefined,
        contract_kind: contractKind || undefined,
        company_ids: companyKey || undefined,
        customer_ids: customerIds.join(',') || undefined,
        expiring_within_days: expiringWithinDays ? Number(expiringWithinDays) : undefined,
        start_date: startDate || undefined,
        end_date: endDate || undefined,
      }
      const blob = format === 'csv' ? await api.exportContractsReportCsv(filters) : await api.exportContractsReportExcel(filters)
      downloadBlob(blob, `contracts-report.${format === 'csv' ? 'csv' : 'xlsx'}`)
    } else if (reportType === 'job-orders') {
      const filters = {
        status: jobOrderStatus || undefined,
        company_ids: companyKey || undefined,
        customer_ids: customerIds.join(',') || undefined,
        assigned_to_user_id: staffId || undefined,
        overdue_only: overdueOnly || undefined,
        start_date: startDate || undefined,
        end_date: endDate || undefined,
      }
      const blob = format === 'csv' ? await api.exportJobOrdersReportCsv(filters) : await api.exportJobOrdersReportExcel(filters)
      downloadBlob(blob, `job-orders-report.${format === 'csv' ? 'csv' : 'xlsx'}`)
    } else if (reportType === 'service-records') {
      const filters = {
        status: srStatus || undefined,
        outcome: srOutcome || undefined,
        company_ids: companyKey || undefined,
        customer_ids: customerIds.join(',') || undefined,
        employee_user_id: staffId || undefined,
        start_date: startDate || undefined,
        end_date: endDate || undefined,
      }
      const blob =
        format === 'csv' ? await api.exportServiceRecordsReportCsv(filters) : await api.exportServiceRecordsReportExcel(filters)
      downloadBlob(blob, `service-records-report.${format === 'csv' ? 'csv' : 'xlsx'}`)
    } else if (reportType === 'customer-product-usage') {
      const filters = {
        company_ids: companyKey || undefined,
        customer_ids: customerIds.join(',') || undefined,
        product_id: productId || undefined,
        industry_code: industryCode || undefined,
      }
      const blob =
        format === 'csv'
          ? await api.exportCompanyIndividualProductUsageCsv(filters)
          : await api.exportCompanyIndividualProductUsageExcel(filters)
      downloadBlob(blob, `customer-product-usage.${format === 'csv' ? 'csv' : 'xlsx'}`)
    } else if (reportType === 'contract-expiry-listing') {
      const filters = { company_ids: companyKey || undefined, expiry_from: contractExpiryFrom || undefined, expiry_to: contractExpiryTo || undefined }
      const blob =
        format === 'csv'
          ? await api.exportContractExpiryListingCsv(filters)
          : await api.exportContractExpiryListingExcel(filters)
      downloadBlob(blob, `contract-expiry-listing.${format === 'csv' ? 'csv' : 'xlsx'}`)
    } else if (reportType === 'contract-renewal-due-listing') {
      const blob =
        format === 'csv'
          ? await api.exportContractRenewalDueListingCsv({ company_ids: companyKey || undefined })
          : await api.exportContractRenewalDueListingExcel({ company_ids: companyKey || undefined })
      downloadBlob(blob, `contract-renewal-due-listing.${format === 'csv' ? 'csv' : 'xlsx'}`)
    }
  }

  return (
    <div>
      <h1 className="no-print">Operations Reports</h1>
      {!reportType ? (
        <>
          <p className="muted">
            Choose a report. Each one shows what it covers and which filters it takes. Every export is
            recorded in Event Logs.
          </p>
          <ReportLauncher sections={SECTIONS} onOpen={(key) => { resetFilters(); openReport(key) }} />
        </>
      ) : (
        <>
      <ReportHeader
        sections={SECTIONS}
        current={reportType}
        onBack={() => openReport(null)}
        printSummary={[
          ...(multiCompany ? [`Internal Companies: ${companyIds.map((id) => myCompanies.find((c) => c.id === id)?.name ?? id).join(', ')}`] : []),
          ...(reportType === 'contract-expiry-listing' || reportType === 'contract-renewal-due-listing'
            ? []
            : [`Company / Individual: ${customerIds.length === 0 ? 'All' : customerIds.map((id) => options.company_individuals.find((c) => c.id === id)?.name ?? id).join(', ')}`]),
          ...(startDate || endDate ? [`Period: ${startDate ? formatDate(startDate) : '…'} to ${endDate ? formatDate(endDate) : '…'}`] : []),
          ...(reportType === 'contract-expiry-listing' && (contractExpiryFrom || contractExpiryTo)
            ? [`Expiry: ${contractExpiryFrom ? formatDate(contractExpiryFrom) : '…'} to ${contractExpiryTo ? formatDate(contractExpiryTo) : '…'}`]
            : []),
        ]}
      />
      {error && <div className="error-banner">{error}</div>}

      <div className="card">
        <FilterGrid>
          <InternalCompaniesPicker
            value={companyIds}
            onChange={setCompanyIds}
            action={
              <button type="button" className="secondary report-reset" onClick={resetFilters}>
                Reset filters
              </button>
            }
          />

          {reportType !== 'contract-expiry-listing' && reportType !== 'contract-renewal-due-listing' && (
            <MultiPick
              label="Company / Individual"
              allLabel="All"
              options={options.company_individuals}
              value={customerIds}
              onChange={setCustomerIds}
            />
          )}

          {reportType === 'contract-expiry-listing' && (
            <>
              <div className="form-row">
                <label>Expiry from</label>
                <input type="date" value={contractExpiryFrom} onChange={(e) => setContractExpiryFrom(e.target.value)} />
              </div>
              <div className="form-row">
                <label>Expiry to</label>
                <input type="date" value={contractExpiryTo} onChange={(e) => setContractExpiryTo(e.target.value)} />
              </div>
            </>
          )}

          {reportType === 'contract-renewal-due-listing' && (
            <p className="muted span-all" style={{ margin: 0 }}>
              Contracts within SRV-014's 30-day pre-expiry window (same window the contract detail
              page uses).
            </p>
          )}

          {reportType === 'contracts' && (
            <>
              <div className="form-row">
                <label>Status</label>
                <select value={contractStatus} onChange={(e) => setContractStatus(e.target.value as ContractStatus | '')}>
                  <option value="">All</option>
                  <option value="draft">Draft</option>
                  <option value="active">Active</option>
                  <option value="exceeded">Exceeded</option>
                  <option value="expired">Expired</option>
                  <option value="renewed">Renewed</option>
                </select>
              </div>
              <div className="form-row">
                <label>Kind</label>
                <select value={contractKind} onChange={(e) => setContractKind(e.target.value as ContractKind | '')}>
                  <option value="">All</option>
                  <option value="service_support">Service Support</option>
                  <option value="annual">Annual</option>
                </select>
              </div>
              <div className="form-row">
                <label>Expiring within (days)</label>
                <input
                  type="number"
                  min={0}
                  value={expiringWithinDays}
                  onChange={(e) => setExpiringWithinDays(e.target.value)}
                  placeholder="Any"
                />
              </div>
            </>
          )}

          {reportType === 'job-orders' && (
            <>
              <div className="form-row">
                <label>Status</label>
                <select value={jobOrderStatus} onChange={(e) => setJobOrderStatus(e.target.value as JobOrderStatus | '')}>
                  <option value="">All</option>
                  <option value="open">Open</option>
                  <option value="assigned">Assigned</option>
                  <option value="closed">Closed</option>
                  <option value="void">Void</option>
                </select>
              </div>
              <div className="form-row">
                <label>Assigned to</label>
                <select value={staffId} onChange={(e) => setStaffId(e.target.value)}>
                  <option value="">All</option>
                  {options.staff.map((s) => (
                    <option key={s.id} value={s.id}>
                      {s.name}
                    </option>
                  ))}
                </select>
              </div>
              <div className="form-row">
                <label>Overdue</label>
                <select value={overdueOnly ? 'overdue' : ''} onChange={(e) => setOverdueOnly(e.target.value === 'overdue')}>
                  <option value="">All</option>
                  <option value="overdue">Overdue only</option>
                </select>
              </div>
            </>
          )}

          {reportType === 'service-records' && (
            <>
              <div className="form-row">
                <label>Status</label>
                <select value={srStatus} onChange={(e) => setSrStatus(e.target.value as ServiceRecordStatus | '')}>
                  <option value="">All</option>
                  <option value="submitted">Submitted</option>
                  <option value="approved">Approved</option>
                </select>
              </div>
              <div className="form-row">
                <label>Outcome</label>
                <select value={srOutcome} onChange={(e) => setSrOutcome(e.target.value as ServiceRecordOutcome | '')}>
                  <option value="">All</option>
                  <option value="pending">Pending</option>
                  <option value="contract_deduction">Contract deduction</option>
                  <option value="excess_usage">Excess usage</option>
                  <option value="not_hour_metered">Not hour-metered (annual)</option>
                  <option value="billable">Billable (job order classified billable)</option>
                  <option value="non_billable">Non-billable (job order classified non-billable)</option>
                </select>
              </div>
              <div className="form-row">
                <label>Staff</label>
                <select value={staffId} onChange={(e) => setStaffId(e.target.value)}>
                  <option value="">All</option>
                  {options.staff.map((s) => (
                    <option key={s.id} value={s.id}>
                      {s.name}
                    </option>
                  ))}
                </select>
              </div>
            </>
          )}

          {reportType === 'customer-product-usage' && (
            <>
              <div className="form-row">
                <label>Product</label>
                <select value={productId} onChange={(e) => setProductId(e.target.value)}>
                  <option value="">All</option>
                  {options.products.map((p) => (
                    <option key={p.id} value={p.id}>
                      {p.name}
                    </option>
                  ))}
                </select>
              </div>
              <div className="form-row">
                <label>Industry</label>
                <select value={industryCode} onChange={(e) => setIndustryCode(e.target.value)}>
                  <option value="">All</option>
                  {industries.map((i) => (
                    <option key={i.code} value={i.code}>
                      {i.name}
                    </option>
                  ))}
                </select>
              </div>
            </>
          )}

          {reportType !== 'customer-product-usage' &&
            reportType !== 'contract-expiry-listing' &&
            reportType !== 'contract-renewal-due-listing' && (
            <PeriodRange from={startDate} to={endDate} onChange={(f, t) => { setStartDate(f); setEndDate(t) }} />
          )}

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
        </FilterGrid>

        <div className="report-table-wrap" style={{ overflowX: 'auto' }}>
          {reportType === 'contracts' && (
            <table>
              <thead>
                <tr>
                  {multiCompany && <th>Internal Company</th>}
                  <th>Company / Individual</th>
                  <th>Status</th>
                  <th>Kind</th>
                  <th>Contracted</th>
                  <th>Consumed</th>
                  <th>Remaining</th>
                  <th>Value (SGD)</th>
                  <th>Start</th>
                  <th>End</th>
                </tr>
              </thead>
              <tbody>
                {contracts.map((c) => (
                  <tr key={c.id}>
                    {multiCompany && <td>{c.company_name}</td>}
                    <td>{c.customer_name}</td>
                    <td>
                      <span className={`badge ${c.status}`}>{c.status}</span>
                    </td>
                    <td>{c.contract_kind === 'annual' ? 'Annual' : 'Service Support'}</td>
                    <td>{c.contracted_hours.toFixed(1)}</td>
                    <td>{c.consumed_hours.toFixed(1)}</td>
                    <td>{c.remaining_hours.toFixed(1)}</td>
                    <td>{money(c.contract_value_sgd)}</td>
                    <td>{formatDate(c.start_date)}</td>
                    <td>{formatDate(c.end_date)}</td>
                  </tr>
                ))}
                {contracts.length === 0 && (
                  <tr>
                    <td colSpan={multiCompany ? 10 : 9} className="muted">
                      No contracts match these filters.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          )}

          {reportType === 'job-orders' && (
            <table>
              <thead>
                <tr>
                  {multiCompany && <th>Internal Company</th>}
                  <th>Company / Individual</th>
                  <th>Subject</th>
                  <th>Priority</th>
                  <th>Status</th>
                  <th>Assigned to</th>
                  <th>Due date</th>
                  <th>Opened</th>
                </tr>
              </thead>
              <tbody>
                {jobOrders.map((o) => {
                  const overdue = !!o.due_date && o.due_date < new Date().toISOString().slice(0, 10) && o.status !== 'closed' && o.status !== 'void'
                  return (
                    <tr key={o.id}>
                      {multiCompany && <td>{o.company_name}</td>}
                      <td>{o.customer_name}</td>
                      <td>{o.subject}</td>
                      <td>{o.priority}</td>
                      <td>
                        <span className={`badge ${o.status === 'closed' ? 'active' : o.status === 'void' ? 'expired' : 'draft'}`}>
                          {o.status}
                        </span>
                      </td>
                      <td>{o.assigned_to_name ?? '-'}</td>
                      <td>
                        {o.due_date ? formatDate(o.due_date) : <span className="muted">-</span>}
                        {overdue && (
                          <span className="badge exceeded" style={{ marginLeft: 6 }}>
                            overdue
                          </span>
                        )}
                      </td>
                      <td>{formatDate(o.created_at)}</td>
                    </tr>
                  )
                })}
                {jobOrders.length === 0 && (
                  <tr>
                    <td colSpan={multiCompany ? 8 : 7} className="muted">
                      No job orders match these filters.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          )}

          {reportType === 'service-records' && (
            <table>
              <thead>
                <tr>
                  {multiCompany && <th>Internal Company</th>}
                  <th>Work date</th>
                  <th>Company / Individual</th>
                  <th>Employee</th>
                  <th>Hours</th>
                  <th>Status</th>
                  <th>Outcome</th>
                  <th>Late</th>
                </tr>
              </thead>
              <tbody>
                {serviceRecords.map((r) => (
                  <tr key={r.id}>
                    {multiCompany && <td>{r.company_name}</td>}
                    <td>{formatDate(r.work_date)}</td>
                    <td>{r.customer_name}</td>
                    <td>{r.employee_name}</td>
                    <td>{(r.rounded_minutes / 60).toFixed(2)}</td>
                    <td>{r.status}</td>
                    <td>{r.outcome.replace(/_/g, ' ')}</td>
                    <td>{r.is_late ? <span className="badge exceeded">late</span> : '-'}</td>
                  </tr>
                ))}
                {serviceRecords.length === 0 && (
                  <tr>
                    <td colSpan={multiCompany ? 8 : 7} className="muted">
                      No service records match these filters.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          )}

          {reportType === 'customer-product-usage' && (
            <table>
              <thead>
                <tr>
                  {multiCompany && <th>Internal Company</th>}
                  <th>Company / Individual</th>
                  <th>Industry</th>
                  <th>Product</th>
                  <th>Contract</th>
                  <th>Type</th>
                  <th>Status</th>
                  <th>Coverage</th>
                </tr>
              </thead>
              <tbody>
                {productUsage.map((row) => (
                  <tr key={`${row.contract_id}-${row.product_id}`}>
                    {multiCompany && <td>{row.company_name}</td>}
                    <td>{row.customer_name}</td>
                    <td className="muted">{row.industry_name || '-'}</td>
                    <td>{row.product_name}</td>
                    <td>
                      <Link to={`/contracts/${row.contract_id}`}>{row.contract_number}</Link>
                    </td>
                    <td className="muted">{row.contract_kind}</td>
                    <td>
                      <span className={`badge ${row.contract_status}`}>{row.contract_status}</span>
                    </td>
                    <td className="muted">
                      {formatDate(row.start_date)} &rarr; {formatDate(row.end_date)}
                    </td>
                  </tr>
                ))}
                {productUsage.length === 0 && (
                  <tr>
                    <td colSpan={multiCompany ? 8 : 7} className="muted">
                      No companies / individuals currently covered for these filters.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          )}

          {reportType === 'contract-expiry-listing' && (
            <table>
              <thead>
                <tr>
                  {multiCompany && <th>Internal Company</th>}
                  <th>Number</th>
                  <th>Company / Individual</th>
                  <th>Status</th>
                  <th>Remaining</th>
                  <th>Value (SGD)</th>
                  <th>End date</th>
                </tr>
              </thead>
              <tbody>
                {expiryListingRows.map((c) => (
                  <tr key={c.id}>
                    {multiCompany && <td>{c.company_name}</td>}
                    <td className="muted">{c.contract_number}</td>
                    <td>{c.customer_name}</td>
                    <td>
                      <span className={`badge ${c.status}`}>{c.status}</span>
                    </td>
                    <td>{c.remaining_hours.toFixed(1)}</td>
                    <td>{money(c.contract_value_sgd)}</td>
                    <td>{formatDate(c.end_date)}</td>
                  </tr>
                ))}
                {expiryListingRows.length === 0 && (
                  <tr>
                    <td colSpan={multiCompany ? 7 : 6} className="muted">
                      No contracts match these filters.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          )}

          {reportType === 'contract-renewal-due-listing' && (
            <table>
              <thead>
                <tr>
                  {multiCompany && <th>Internal Company</th>}
                  <th>Number</th>
                  <th>Company / Individual</th>
                  <th>Status</th>
                  <th>Remaining</th>
                  <th>End date</th>
                </tr>
              </thead>
              <tbody>
                {renewalDueRows.map((c) => (
                  <tr key={c.id}>
                    {multiCompany && <td>{c.company_name}</td>}
                    <td className="muted">{c.contract_number}</td>
                    <td>
                      <Link to={`/contracts/${c.id}`}>{c.customer_name}</Link>
                    </td>
                    <td>
                      <span className={`badge ${c.status}`}>{c.status}</span>
                    </td>
                    <td>{c.remaining_hours.toFixed(1)}</td>
                    <td>{formatDate(c.end_date)}</td>
                  </tr>
                ))}
                {renewalDueRows.length === 0 && (
                  <tr>
                    <td colSpan={multiCompany ? 6 : 5} className="muted">
                      No contracts within the SRV-014 30-day pre-expiry window.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          )}
        </div>
      </div>
        </>
      )}
    </div>
  )
}
