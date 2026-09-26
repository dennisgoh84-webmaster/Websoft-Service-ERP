// A printable Credit Note "form" -- see InvoicePrintPage.tsx for the
// pattern this follows. Only an issued credit note is a document; a
// pending, rejected or withdrawn one has no number and nothing to print.
import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import ExportControl from '../components/ExportControl'
import { api, downloadBlob, type CompanyIndividual, type CreditNote } from '../lib/api'
import { useAuth } from '../lib/AuthContext'
import { formatMoney as money, formatDate } from '../lib/format'

export default function CreditNotePrintPage() {
  const { id } = useParams<{ id: string }>()
  const { activeCompany } = useAuth()
  const [note, setNote] = useState<CreditNote | null>(null)
  const [customer, setCustomer] = useState<CompanyIndividual | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!id) return
    api
      .getCreditNote(id)
      .then((n) => {
        setNote(n)
        api.getCompanyIndividual(n.customer_id).then(setCustomer)
      })
      .catch((err) => setError(err instanceof Error ? err.message : 'Failed to load the credit note'))
  }, [id])

  async function onExport(format: string) {
    if (!id || !note) return
    if (format === 'pdf') {
      window.print()
      return
    }
    downloadBlob(await api.exportCreditNoteDocx(id), `${note.credit_note_number}.docx`)
  }

  if (error && !note) return <div className="error-banner">{error}</div>
  if (!note || !customer) return <p>Loading...</p>
  if (note.status !== 'issued') return <p className="muted">Only an issued credit note has a document to print.</p>

  const billTo = [customer.address_line1, customer.address_line2, customer.address_city, customer.address_country]
    .filter(Boolean)
    .join(', ')

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
          {activeCompany?.uen && <div>Business Reg# {activeCompany.uen}</div>}
          {activeCompany?.gst_registration_no && <div>GST Reg# {activeCompany.gst_registration_no}</div>}
        </div>
      </div>

      <h2 style={{ textAlign: 'center', letterSpacing: 2 }}>CREDIT NOTE</h2>

      <div className="form-meta">
        <div>
          <div className="form-section-label">Credit To</div>
          <div className="form-customer-name">{customer.name}</div>
          {billTo && <div>{billTo}</div>}
          {customer.uen && <div>UEN: {customer.uen}</div>}
        </div>
        <div className="form-meta-right">
          <div className="form-meta-row">
            <span className="muted">Credit Note No</span>
            <span>: {note.credit_note_number}</span>
          </div>
          <div className="form-meta-row">
            <span className="muted">Date</span>
            <span>: {formatDate(note.issued_at)}</span>
          </div>
          <div className="form-meta-row">
            <span className="muted">Against Tax Invoice</span>
            <span>: {note.invoice_number}</span>
          </div>
        </div>
      </div>

      <table className="invoice-lines">
        <thead>
          <tr>
            <th style={{ width: 30 }}>#</th>
            <th>REASON</th>
            <th style={{ textAlign: 'right' }}>AMOUNT ($)</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>1</td>
            <td>{note.reason}</td>
            <td className="invoice-amount-cell" style={{ textAlign: 'right' }}>
              {money(note.amount_sgd)}
            </td>
          </tr>
        </tbody>
      </table>

      <div className="totals-strip">
        <div className="totals-box">
          <div className="muted">Subtotal</div>
          <div className="totals-value">{money(note.amount_sgd)}</div>
        </div>
        <div className="totals-box">
          <div className="muted">
            Tax {note.gst_rate ?? 0}% ({note.tax_code})
          </div>
          <div className="totals-value">{money(note.gst_amount_sgd)}</div>
        </div>
        <div className="totals-box totals-box-grand">
          <div>Total Credit</div>
          <div className="totals-value">{money(note.total_amount_sgd)}</div>
        </div>
      </div>

      <div className="form-signature-row">
        <div />
        <div>
          Signature &amp; Company Stamp
          <div className="form-signature-line" />
        </div>
      </div>

      <p className="muted" style={{ marginTop: 24, fontSize: 12 }}>
        Computer generated and no signature is required.
      </p>
    </div>
  )
}
