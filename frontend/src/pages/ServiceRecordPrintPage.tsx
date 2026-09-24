// A printable Service Record form -- see JobOrderPrintPage.tsx for the
// customer-lookup pattern this follows. Same content as
// docx_forms.service_record_to_docx, which "Email" also converts to the
// PDF it attaches (2026-09-12).
import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import ExportControl from '../components/ExportControl'
import { api, downloadBlob, type CompanyIndividual, type JobOrder, type ServiceRecord } from '../lib/api'
import { useAuth } from '../lib/AuthContext'
import { formatDate } from '../lib/format'

export default function ServiceRecordPrintPage() {
  const { id } = useParams<{ id: string }>()
  const { activeCompany } = useAuth()
  const [record, setRecord] = useState<ServiceRecord | null>(null)
  const [jobOrder, setJobOrder] = useState<JobOrder | null>(null)
  const [customer, setCustomer] = useState<CompanyIndividual | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!id) return
    api.getServiceRecord(id).then((r) => {
      setRecord(r)
      api.getJobOrder(r.job_order_id).then((jo) => {
        setJobOrder(jo)
        api.getCompanyIndividual(jo.customer_id).then(setCustomer)
      })
    })
  }, [id])

  async function onExport(format: string) {
    if (!id || !record) return
    if (format === 'pdf') {
      window.print()
      return
    }
    downloadBlob(await api.exportServiceRecordDocx(id), `${record.service_record_number}.docx`)
  }

  if (!record || !jobOrder || !customer) return <p>Loading...</p>

  return (
    <div className="invoice-sheet">
      <div className="no-print" style={{ marginBottom: 16 }}>
        {error && <div className="error-banner" style={{ marginBottom: 8 }}>{error}</div>}
        <ExportControl
          formats={[
            { value: 'pdf', label: 'PDF (Print)' },
            { value: 'word', label: 'Word' },
          ]}
          onExport={onExport}
          onError={setError}
        />
      </div>

      <div className="form-header">
        <div>{activeCompany?.logo && <img src={activeCompany.logo} alt="" className="invoice-logo" />}</div>
        <div className="form-header-right">
          <div className="form-company-name">{activeCompany?.name}</div>
          {activeCompany?.address && <div>{activeCompany.address}</div>}
          {activeCompany?.phone && <div>Tel: {activeCompany.phone}</div>}
        </div>
      </div>

      <h2 style={{ marginTop: 8 }}>Service Record</h2>

      <div className="form-meta">
        <div>
          <div className="form-section-label">Company / Individual</div>
          <div className="form-customer-name">{customer.name}</div>
        </div>
        <div className="form-meta-right">
          <div className="form-meta-row">
            <span className="muted">Record No</span>
            <span>: {record.service_record_number}</span>
          </div>
          <div className="form-meta-row">
            <span className="muted">Job Order</span>
            <span>
              : {jobOrder.job_order_number} -- {jobOrder.subject}
            </span>
          </div>
          <div className="form-meta-row">
            <span className="muted">Work Date</span>
            <span>: {formatDate(record.work_date)}</span>
          </div>
          <div className="form-meta-row">
            <span className="muted">Status</span>
            <span>: {record.status}</span>
          </div>
        </div>
      </div>

      <table className="invoice-lines">
        <thead>
          <tr>
            <th>FIELD</th>
            <th>VALUE</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>Time logged (raw)</td>
            <td>{record.raw_minutes} min</td>
          </tr>
          <tr>
            <td>Time logged (rounded, SRV-007)</td>
            <td>{record.rounded_minutes} min</td>
          </tr>
          <tr>
            <td>Completion</td>
            <td>{record.completion_status === 'C' ? 'Completed' : 'Not yet completed -- another visit expected'}</td>
          </tr>
          <tr>
            <td>After hours / weekend / holiday</td>
            <td>{record.is_after_hours ? 'Yes' : 'No'}</td>
          </tr>
          {record.deducted_minutes !== null && (
            <tr>
              <td>Minutes deducted from contract</td>
              <td>{record.deducted_minutes} min</td>
            </tr>
          )}
        </tbody>
      </table>

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
    </div>
  )
}
