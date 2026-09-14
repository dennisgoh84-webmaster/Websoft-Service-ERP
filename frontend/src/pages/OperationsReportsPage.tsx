// Operations Reports -- filterable listing reports over Contracts, Job
// Orders and Service Records (module_key "operations_reports"). Every
// export is written to Event Logs (see app/routers/reports.py). Same
// dynamic-filter + one-Export-button pattern as Invoices; a report type
// selector switches which filter panel and columns show.
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import ExportControl from '../components/ExportControl'
import {
  api,
  downloadBlob,
  type Contract,
  type ContractKind,
  type ContractStatus,
  type CompanyIndividual,
  type CompanyIndividualProductUsageRow,
  type JobOrder,
  type JobOrderStatus,
  type Product,
  type ServiceRecord,
  type ServiceRecordOutcome,
  type ServiceRecordStatus,
  type SetupListItem,
  type StaffUser,
} from '../lib/api'
import { isoToMonth, monthEndISO, monthStartISO } from '../lib/period'
import { formatMoney as money, formatDate } from '../lib/format'

type ReportType = 'contracts' | 'job-orders' | 'service-records' | 'customer-product-usage'

export default function OperationsReportsPage() {
  const [reportType, setReportType] = useState<ReportType>('contracts')
  const [customers, setCustomers] = useState<CompanyIndividual[]>([])
  const [staff, setStaff] = useState<StaffUser[]>([])
  const [products, setProducts] = useState<Product[]>([])
  const [industries, setIndustries] = useState<SetupListItem[]>([])
  const [error, setError] = useState<string | null>(null)

  // Shared-shape filters -- only the ones relevant to the selected
  // report type are actually sent (see the fetch effect below).
  const [customerId, setCustomerId] = useState('')
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

  const [contracts, setContracts] = useState<Contract[]>([])
  const [jobOrders, setJobOrders] = useState<JobOrder[]>([])
  const [serviceRecords, setServiceRecords] = useState<ServiceRecord[]>([])
  const [productUsage, setProductUsage] = useState<CompanyIndividualProductUsageRow[]>([])

  // All job orders, unfiltered -- used only to resolve a service record's
  // customer via its job order (Service Records has no customer_id of
  // its own), independent of whichever report is currently selected.
  const [allJobOrders, setAllJobOrders] = useState<JobOrder[]>([])

  useEffect(() => {
    api.listCompanyIndividuals().then(setCustomers).catch(() => setCustomers([]))
    api.listStaff().then(setStaff).catch(() => setStaff([]))
    api.listJobOrders().then(setAllJobOrders).catch(() => setAllJobOrders([]))
    api.listCatalog().then(setProducts).catch(() => setProducts([]))
    api.listSetupItems({ list_type: 'industry' }).then(setIndustries).catch(() => setIndustries([]))
  }, [])

  function resetFilters() {
    setCustomerId('')
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
  }

  useEffect(() => {
    setError(null)
    if (reportType === 'contracts') {
      api
        .reportContracts({
          status: contractStatus || undefined,
          contract_kind: contractKind || undefined,
          customer_id: customerId || undefined,
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
          customer_id: customerId || undefined,
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
          customer_id: customerId || undefined,
          employee_user_id: staffId || undefined,
          start_date: startDate || undefined,
          end_date: endDate || undefined,
        })
        .then(setServiceRecords)
        .catch((e) => setError(e.message))
    } else {
      api
        .reportCompanyIndividualProductUsage({
          customer_id: customerId || undefined,
          product_id: productId || undefined,
          industry_code: industryCode || undefined,
        })
        .then(setProductUsage)
        .catch((e) => setError(e.message))
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [
    reportType, customerId, staffId, startDate, endDate, contractStatus, contractKind,
    expiringWithinDays, jobOrderStatus, overdueOnly, srStatus, srOutcome, productId, industryCode,
  ])

  const customerName = (id: string) => customers.find((c) => c.id === id)?.name ?? id.slice(0, 8)
  const staffName = (id: string | null) => (id ? staff.find((s) => s.id === id)?.full_name ?? id.slice(0, 8) : '-')
  const jobOrderById = new Map(allJobOrders.map((o) => [o.id, o]))

  async function onExport(format: string) {
    setError(null)
    if (reportType === 'contracts') {
      const filters = {
        status: contractStatus || undefined,
        contract_kind: contractKind || undefined,
        customer_id: customerId || undefined,
        expiring_within_days: expiringWithinDays ? Number(expiringWithinDays) : undefined,
        start_date: startDate || undefined,
        end_date: endDate || undefined,
      }
      const blob = format === 'csv' ? await api.exportContractsReportCsv(filters) : await api.exportContractsReportExcel(filters)
      downloadBlob(blob, `contracts-report.${format === 'csv' ? 'csv' : 'xlsx'}`)
    } else if (reportType === 'job-orders') {
      const filters = {
        status: jobOrderStatus || undefined,
        customer_id: customerId || undefined,
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
        customer_id: customerId || undefined,
        employee_user_id: staffId || undefined,
        start_date: startDate || undefined,
        end_date: endDate || undefined,
      }
      const blob =
        format === 'csv' ? await api.exportServiceRecordsReportCsv(filters) : await api.exportServiceRecordsReportExcel(filters)
      downloadBlob(blob, `service-records-report.${format === 'csv' ? 'csv' : 'xlsx'}`)
    } else {
      const filters = {
        customer_id: customerId || undefined,
        product_id: productId || undefined,
        industry_code: industryCode || undefined,
      }
      const blob =
        format === 'csv'
          ? await api.exportCompanyIndividualProductUsageCsv(filters)
          : await api.exportCompanyIndividualProductUsageExcel(filters)
      downloadBlob(blob, `customer-product-usage.${format === 'csv' ? 'csv' : 'xlsx'}`)
    }
  }

  return (
    <div>
      <h1>Operations Reports</h1>
      <p className="muted">
        Filterable reports over Contracts, Job Orders and Service Records. Every export is recorded
        in Event Logs.
      </p>
      {error && <div className="error-banner">{error}</div>}

      <div className="card">
        <div className="filter-bar">
          <div className="form-row" style={{ margin: 0 }}>
            <label>Report</label>
            <select value={reportType} onChange={(e) => { setReportType(e.target.value as ReportType); resetFilters() }}>
              <option value="contracts">Service Contracts</option>
              <option value="job-orders">Job Orders</option>
              <option value="service-records">Service Records</option>
              <option value="customer-product-usage">CompanyIndividual Product Usage</option>
            </select>
          </div>

          <div className="form-row" style={{ margin: 0 }}>
            <label>Company / Individual</label>
            <select value={customerId} onChange={(e) => setCustomerId(e.target.value)}>
              <option value="">All</option>
              {customers.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </select>
          </div>

          {reportType === 'contracts' && (
            <>
              <div className="form-row" style={{ margin: 0 }}>
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
              <div className="form-row" style={{ margin: 0 }}>
                <label>Kind</label>
                <select value={contractKind} onChange={(e) => setContractKind(e.target.value as ContractKind | '')}>
                  <option value="">All</option>
                  <option value="service_support">Service Support</option>
                  <option value="annual">Annual</option>
                </select>
              </div>
              <div className="form-row" style={{ margin: 0 }}>
                <label>Expiring within (days)</label>
                <input
                  type="number"
                  min={0}
                  style={{ width: 90 }}
                  value={expiringWithinDays}
                  onChange={(e) => setExpiringWithinDays(e.target.value)}
                  placeholder="Any"
                />
              </div>
            </>
          )}

          {reportType === 'job-orders' && (
            <>
              <div className="form-row" style={{ margin: 0 }}>
                <label>Status</label>
                <select value={jobOrderStatus} onChange={(e) => setJobOrderStatus(e.target.value as JobOrderStatus | '')}>
                  <option value="">All</option>
                  <option value="open">Open</option>
                  <option value="assigned">Assigned</option>
                  <option value="closed">Closed</option>
                  <option value="void">Void</option>
                </select>
              </div>
              <div className="form-row" style={{ margin: 0 }}>
                <label>Assigned to</label>
                <select value={staffId} onChange={(e) => setStaffId(e.target.value)}>
                  <option value="">All</option>
                  {staff.map((s) => (
                    <option key={s.id} value={s.id}>
                      {s.full_name}
                    </option>
                  ))}
                </select>
              </div>
              <div className="form-row" style={{ margin: 0 }}>
                <label>&nbsp;</label>
                <label style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                  <input type="checkbox" checked={overdueOnly} onChange={(e) => setOverdueOnly(e.target.checked)} />
                  Overdue only
                </label>
              </div>
            </>
          )}

          {reportType === 'service-records' && (
            <>
              <div className="form-row" style={{ margin: 0 }}>
                <label>Status</label>
                <select value={srStatus} onChange={(e) => setSrStatus(e.target.value as ServiceRecordStatus | '')}>
                  <option value="">All</option>
                  <option value="submitted">Submitted</option>
                  <option value="approved">Approved</option>
                </select>
              </div>
              <div className="form-row" style={{ margin: 0 }}>
                <label>Outcome</label>
                <select value={srOutcome} onChange={(e) => setSrOutcome(e.target.value as ServiceRecordOutcome | '')}>
                  <option value="">All</option>
                  <option value="pending">Pending</option>
                  <option value="contract_deduction">Contract deduction</option>
                  <option value="excess_usage">Excess usage</option>
                  <option value="not_hour_metered">Not hour-metered (annual)</option>
                </select>
              </div>
              <div className="form-row" style={{ margin: 0 }}>
                <label>Staff</label>
                <select value={staffId} onChange={(e) => setStaffId(e.target.value)}>
                  <option value="">All</option>
                  {staff.map((s) => (
                    <option key={s.id} value={s.id}>
                      {s.full_name}
                    </option>
                  ))}
                </select>
              </div>
            </>
          )}

          {reportType === 'customer-product-usage' && (
            <>
              <div className="form-row" style={{ margin: 0 }}>
                <label>Product</label>
                <select value={productId} onChange={(e) => setProductId(e.target.value)}>
                  <option value="">All</option>
                  {products.map((p) => (
                    <option key={p.id} value={p.id}>
                      {p.name}
                    </option>
                  ))}
                </select>
              </div>
              <div className="form-row" style={{ margin: 0 }}>
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

          {reportType !== 'customer-product-usage' && (
            <>
              <div className="form-row" style={{ margin: 0 }}>
                <label>Period from</label>
                <input
                  type="month"
                  value={isoToMonth(startDate)}
                  onChange={(e) => setStartDate(monthStartISO(e.target.value))}
                />
              </div>
              <div className="form-row" style={{ margin: 0 }}>
                <label>Period to</label>
                <input
                  type="month"
                  value={isoToMonth(endDate)}
                  onChange={(e) => setEndDate(monthEndISO(e.target.value))}
                />
              </div>
            </>
          )}

          <button type="button" className="secondary" onClick={resetFilters}>
            Reset filters
          </button>

          <ExportControl
            formats={[
              { value: 'csv', label: 'CSV' },
              { value: 'excel', label: 'Excel' },
            ]}
            onExport={onExport}
            onError={setError}
          />
        </div>

        <div className="report-table-wrap" style={{ overflowX: 'auto' }}>
          {reportType === 'contracts' && (
            <table>
              <thead>
                <tr>
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
                    <td>{customerName(c.customer_id)}</td>
                    <td>
                      <span className={`badge ${c.status}`}>{c.status}</span>
                    </td>
                    <td>{c.contract_kind === 'annual' ? 'Annual' : 'Service Support'}</td>
                    <td>{c.contracted_hours.toFixed(1)}</td>
                    <td>{c.consumed_hours.toFixed(1)}</td>
                    <td>{c.remaining_hours.toFixed(1)}</td>
                    <td>{money(c.contract_value_sgd)}</td>
                    <td>{c.start_date}</td>
                    <td>{c.end_date}</td>
                  </tr>
                ))}
                {contracts.length === 0 && (
                  <tr>
                    <td colSpan={9} className="muted">
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
                      <td>{customerName(o.customer_id)}</td>
                      <td>{o.subject}</td>
                      <td>{o.priority}</td>
                      <td>
                        <span className={`badge ${o.status === 'closed' ? 'active' : o.status === 'void' ? 'expired' : 'draft'}`}>
                          {o.status}
                        </span>
                      </td>
                      <td>{staffName(o.assigned_to_user_id)}</td>
                      <td>
                        {o.due_date ?? <span className="muted">-</span>}
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
                    <td colSpan={7} className="muted">
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
                    <td>{r.work_date}</td>
                    <td>{customerName(jobOrderById.get(r.job_order_id)?.customer_id ?? '')}</td>
                    <td>{staffName(r.employee_user_id)}</td>
                    <td>{(r.rounded_minutes / 60).toFixed(2)}</td>
                    <td>{r.status}</td>
                    <td>{r.outcome.replace(/_/g, ' ')}</td>
                    <td>{r.is_late ? <span className="badge exceeded">late</span> : '-'}</td>
                  </tr>
                ))}
                {serviceRecords.length === 0 && (
                  <tr>
                    <td colSpan={7} className="muted">
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
                      {row.start_date} &rarr; {row.end_date}
                    </td>
                  </tr>
                ))}
                {productUsage.length === 0 && (
                  <tr>
                    <td colSpan={7} className="muted">
                      No customers currently covered for these filters.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          )}
        </div>
      </div>
    </div>
  )
}
