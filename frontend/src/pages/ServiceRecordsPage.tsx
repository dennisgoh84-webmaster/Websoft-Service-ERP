import { Fragment, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { EmailIcon, PrintIcon, WhatsAppIcon } from '../components/DocActionIcons'
import DocumentAttachmentsPanel from '../components/DocumentAttachmentsPanel'
import ExportControl from '../components/ExportControl'
import SignaturePanel from '../components/SignaturePanel'
import { api, downloadBlob, type CompanyIndividual, type CurrentUser, type JobOrder, type ServiceRecord } from '../lib/api'

// wa.me needs digits only (country code + number, no "+", spaces or dashes).
function waNumber(phone: string): string {
  return phone.replace(/[^0-9]/g, '')
}

export default function ServiceRecordsPage() {
  const [records, setRecords] = useState<ServiceRecord[]>([])
  const [jobOrders, setJobOrders] = useState<JobOrder[]>([])
  const [customers, setCustomers] = useState<CompanyIndividual[]>([])
  const [users, setUsers] = useState<CurrentUser[]>([])
  const [error, setError] = useState<string | null>(null)
  const [message, setMessage] = useState<string | null>(null)
  const [busyId, setBusyId] = useState<string | null>(null)
  const [docPanelId, setDocPanelId] = useState<string | null>(null)

  const [filterEmployee, setFilterEmployee] = useState('')
  const [filterStatus, setFilterStatus] = useState('')

  function refresh() {
    api
      .listServiceRecords({ employee_user_id: filterEmployee || undefined, status: filterStatus || undefined })
      .then(setRecords)
      .catch((e) => setError(e.message))
    api.listJobOrders().then(setJobOrders).catch((e) => setError(e.message))
    api.listCompanyIndividuals().then(setCustomers).catch((e) => setError(e.message))
    api.listUsers().then(setUsers).catch((e) => setError(e.message))
  }

  useEffect(refresh, [filterEmployee, filterStatus])

  const userName = (uid: string) => users.find((u) => u.id === uid)?.full_name ?? uid.slice(0, 8)
  const jobOrderOf = (id: string) => jobOrders.find((j) => j.id === id)
  const jobOrderSubject = (id: string) => jobOrderOf(id)?.subject ?? id.slice(0, 8)
  const customerOf = (jobOrderId: string) => {
    const jo = jobOrderOf(jobOrderId)
    return jo ? customers.find((c) => c.id === jo.customer_id) : undefined
  }

  function resetFilters() {
    setFilterEmployee('')
    setFilterStatus('')
  }

  async function onExport(format: string) {
    setError(null)
    const filters = { employee_user_id: filterEmployee || undefined, status: filterStatus || undefined }
    if (format === 'csv') {
      downloadBlob(await api.exportServiceRecordsCsv(filters), 'service-records.csv')
    } else {
      downloadBlob(await api.exportServiceRecordsExcel(filters), 'service-records.xlsx')
    }
  }

  async function onEmail(r: ServiceRecord) {
    setError(null)
    setMessage(null)
    setBusyId(r.id)
    try {
      const result = await api.emailServiceRecord(r.id)
      setMessage(`${r.service_record_number} emailed to ${result.to}.`)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to email service record')
    } finally {
      setBusyId(null)
    }
  }

  function onWhatsApp(r: ServiceRecord) {
    setError(null)
    const customer = customerOf(r.job_order_id)
    if (!customer?.phone) {
      setError(`${customer?.name ?? 'This customer'} has no phone number on file -- add one on the Company/Individual page first.`)
      return
    }
    const text = `Service Record ${r.service_record_number} for ${jobOrderSubject(r.job_order_id)}, ${r.work_date}. PDF to follow.`
    window.open(`https://wa.me/${waNumber(customer.phone)}?text=${encodeURIComponent(text)}`, '_blank')
  }

  return (
    <div>
      <h1>Service Records</h1>
      <p className="muted">
        Time logged against Job Orders (SRV-007: rounds up to the nearest 15 min). To log a new one,
        open the Job Order it belongs to. To approve one and key in the deduction minutes, see{' '}
        <Link to="/service-record-approval">Service Record Approval</Link>.
      </p>
      {error && <div className="error-banner">{error}</div>}
      {message && (
        <p className="muted" style={{ marginBottom: 12 }}>
          {message}
        </p>
      )}

      <div className="card">
        <div className="filter-bar">
          <div className="form-row" style={{ margin: 0 }}>
            <label>Employee</label>
            <select value={filterEmployee} onChange={(e) => setFilterEmployee(e.target.value)}>
              <option value="">All</option>
              {users.map((u) => (
                <option key={u.id} value={u.id}>
                  {u.full_name}
                </option>
              ))}
            </select>
          </div>
          <div className="form-row" style={{ margin: 0 }}>
            <label>Status</label>
            <select value={filterStatus} onChange={(e) => setFilterStatus(e.target.value)}>
              <option value="">All</option>
              <option value="submitted">Submitted</option>
              <option value="approved">Approved</option>
            </select>
          </div>
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

        <h2>Records ({records.length})</h2>
        <table>
          <thead>
            <tr>
              <th>Number</th>
              <th>Job Order</th>
              <th>Employee</th>
              <th>Date</th>
              <th>Raw / Rounded / Deducted</th>
              <th>Completion</th>
              <th>Status</th>
              <th>Outcome</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {records.map((r) => {
              const customer = customerOf(r.job_order_id)
              return (
                <Fragment key={r.id}>
                <tr>
                  <td className="muted">{r.service_record_number}</td>
                  <td>
                    <Link to={`/job-orders/${r.job_order_id}`}>{jobOrderSubject(r.job_order_id)}</Link>
                  </td>
                  <td>{userName(r.employee_user_id)}</td>
                  <td>{r.work_date}</td>
                  <td>
                    {r.raw_minutes}m &rarr; {r.rounded_minutes}m
                    {r.deducted_minutes != null && <> &rarr; {r.deducted_minutes}m deducted</>}
                  </td>
                  <td>
                    {r.completion_status === 'C' ? 'Completed' : 'Uncompleted'}
                    {r.is_after_hours && (
                      <span className="badge exceeded" style={{ marginLeft: 6 }}>
                        after-hours
                      </span>
                    )}
                  </td>
                  <td>
                    {r.status}
                    {r.is_late && (
                      <span className="badge exceeded" style={{ marginLeft: 6 }}>
                        late
                      </span>
                    )}
                  </td>
                  <td>{r.outcome}</td>
                  <td>
                    <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', justifyContent: 'flex-end' }}>
                      <button
                        className="secondary icon-button"
                        title="Attachments & Signatures"
                        aria-label="Attachments & Signatures"
                        onClick={() => setDocPanelId(docPanelId === r.id ? null : r.id)}
                      >📎</button>
                      <Link to={`/service-records/${r.id}/print`} className="secondary icon-button" title="Print" aria-label="Print">
                        <PrintIcon />
                      </Link>
                      <button
                        className="secondary icon-button"
                        disabled={busyId === r.id || !customer?.billing_email}
                        title={customer?.billing_email ? 'Email' : 'Add an email on the Company/Individual page first'}
                        aria-label="Email"
                        onClick={() => onEmail(r)}
                      >
                        <EmailIcon />
                      </button>
                      <button
                        className="secondary icon-button"
                        disabled={busyId === r.id || !customer?.phone}
                        title={customer?.phone ? 'WhatsApp' : 'Add a phone number on the Company/Individual page first'}
                        aria-label="WhatsApp"
                        onClick={() => onWhatsApp(r)}
                      >
                        <WhatsAppIcon />
                      </button>
                    </div>
                  </td>
                </tr>
                {docPanelId === r.id && (
                  <tr>
                    <td colSpan={9} style={{ padding: 16, background: 'var(--bg-muted, #f9f9f9)' }}>
                      <DocumentAttachmentsPanel entityType="service_record" entityId={r.id} />
                      <SignaturePanel entityType="service_record" entityId={r.id} />
                    </td>
                  </tr>
                )}
                </Fragment>
              )
            })}
            {records.length === 0 && (
              <tr>
                <td colSpan={9} className="muted">
                  No Service Records match these filters.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  )
}
