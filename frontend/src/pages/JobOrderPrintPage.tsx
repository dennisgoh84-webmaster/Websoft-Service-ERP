// A printable Job Order "form" -- see ReceiptPrintPage.tsx for the
// pattern this follows. Confirmed 2026-09-11: "Job Order Listing Page
// to view current Jobs with the filter and printing of Job Orders" --
// the listing + filters already exist (JobOrdersPage.tsx); this adds
// the printing half, one Job Order per page like every other printed
// document in the app.
import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import ExportControl from '../components/ExportControl'
import { api, type Contract, type CurrentUser, type CompanyIndividual, type JobOrder, type ServiceRecord } from '../lib/api'
import { useAuth } from '../lib/AuthContext'
import { formatDate } from '../lib/format'

export default function JobOrderPrintPage() {
  const { id } = useParams<{ id: string }>()
  const { activeCompany } = useAuth()
  const [jobOrder, setJobOrder] = useState<JobOrder | null>(null)
  const [customer, setCustomer] = useState<CompanyIndividual | null>(null)
  const [contract, setContract] = useState<Contract | null>(null)
  const [records, setRecords] = useState<ServiceRecord[]>([])
  const [users, setUsers] = useState<CurrentUser[]>([])
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!id) return
    api.getJobOrder(id).then((jo) => {
      setJobOrder(jo)
      api.getCompanyIndividual(jo.customer_id).then(setCustomer)
      if (jo.contract_id) api.getContract(jo.contract_id).then(setContract)
    })
    api.listServiceRecords({ job_order_id: id }).then(setRecords)
    api.listUsers().then(setUsers)
  }, [id])

  const userName = (uid: string | null) => users.find((u) => u.id === uid)?.full_name ?? '-'

  async function onExport(format: string) {
    if (format === 'pdf') {
      window.print()
    }
  }

  if (!jobOrder || !customer) return <p>Loading...</p>

  const customerAddress = [customer.address_line1, customer.address_line2, customer.address_city, customer.address_country]
    .filter(Boolean)
    .join(', ')

  return (
    <div className="invoice-sheet">
      <div className="no-print" style={{ marginBottom: 16 }}>
        {error && <div className="error-banner" style={{ marginBottom: 8 }}>{error}</div>}
        <ExportControl formats={[{ value: 'pdf', label: 'PDF (Print)' }]} onExport={onExport} onError={setError} />
      </div>

      <div className="form-header">
        <div>{activeCompany?.logo && <img src={activeCompany.logo} alt="" className="invoice-logo" />}</div>
        <div className="form-header-right">
          <div className="form-company-name">{activeCompany?.name}</div>
          {activeCompany?.address && <div>{activeCompany.address}</div>}
          {activeCompany?.phone && <div>Tel: {activeCompany.phone}</div>}
          {activeCompany?.uen && <div>Business Reg# {activeCompany.uen}</div>}
        </div>
      </div>

      <h2 style={{ marginTop: 8 }}>
        Job Order {jobOrder.is_urgent && <span className="badge exceeded">URGENT</span>}
      </h2>

      <div className="form-meta">
        <div>
          <div className="form-section-label">Company / Individual</div>
          <div className="form-customer-name">{customer.name}</div>
          {customerAddress && <div>{customerAddress}</div>}
          {customer.uen && <div>UEN: {customer.uen}</div>}
        </div>
        <div className="form-meta-right">
          <div className="form-meta-row">
            <span className="muted">Job Order No</span>
            <span>: {jobOrder.job_order_number}</span>
          </div>
          <div className="form-meta-row">
            <span className="muted">Date</span>
            <span>: {formatDate(jobOrder.created_at)}</span>
          </div>
          <div className="form-meta-row">
            <span className="muted">Status</span>
            <span>: {jobOrder.status}</span>
          </div>
          <div className="form-meta-row">
            <span className="muted">Priority</span>
            <span>: {jobOrder.priority}</span>
          </div>
          {jobOrder.due_date && (
            <div className="form-meta-row">
              <span className="muted">Due</span>
              <span>: {formatDate(jobOrder.due_date)}</span>
            </div>
          )}
          <div className="form-meta-row">
            <span className="muted">Assigned to</span>
            <span>: {userName(jobOrder.assigned_to_user_id)}</span>
          </div>
          {contract && (
            <div className="form-meta-row">
              <span className="muted">Contract</span>
              <span>: {contract.status}</span>
            </div>
          )}
        </div>
      </div>

      <div className="form-section-label" style={{ marginTop: 12 }}>
        Subject
      </div>
      <p>{jobOrder.subject}</p>

      {records.length > 0 && (
        <>
          <div className="form-section-label" style={{ marginTop: 12 }}>
            Service Records
          </div>
          <table className="invoice-lines">
            <thead>
              <tr>
                <th>Employee</th>
                <th>Date</th>
                <th>Raw / Rounded / Deducted</th>
                <th>Completion</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              {records.map((r) => (
                <tr key={r.id}>
                  <td>{userName(r.employee_user_id)}</td>
                  <td>{formatDate(r.work_date)}</td>
                  <td>
                    {r.raw_minutes}m &rarr; {r.rounded_minutes}m
                    {r.deducted_minutes != null && <> &rarr; {r.deducted_minutes}m</>}
                  </td>
                  <td>{r.completion_status === 'C' ? 'Completed' : 'Uncompleted'}</td>
                  <td>{r.status}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </>
      )}

      <div className="form-signature-row">
        <div>
          CompanyIndividual Signature
          <div className="form-signature-line" />
        </div>
        <div>
          Signature &amp; Company Stamp
          <div className="form-signature-line" />
        </div>
      </div>

      <p className="muted" style={{ marginTop: 24, fontSize: 12 }}>
        Computer generated and no signature is required unless collected on-site.
      </p>
    </div>
  )
}
