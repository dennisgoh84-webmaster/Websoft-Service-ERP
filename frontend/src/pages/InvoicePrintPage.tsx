// A printable Tax Invoice "form" -- plain HTML/CSS, matching Webmaster's
// own Quotation letterhead format (reference: Quote_0160, shared
// 2026-09-10), rendered with live data and printed with the browser's
// own Print dialog (Save as PDF). No report designer, no separate
// tool: to change the layout, edit the JSX/CSS below exactly like any
// other page in this app.
import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import ExportControl from '../components/ExportControl'
import { api, downloadBlob, type CompanyIndividual, type Invoice } from '../lib/api'
import { useAuth } from '../lib/AuthContext'
import { formatMoney as money, formatDate } from '../lib/format'

export default function InvoicePrintPage() {
  const { id } = useParams<{ id: string }>()
  const { activeCompany } = useAuth()
  const [invoice, setInvoice] = useState<Invoice | null>(null)
  const [customer, setCustomer] = useState<CompanyIndividual | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!id) return
    api.getInvoice(id).then((inv) => {
      setInvoice(inv)
      api.getCompanyIndividual(inv.customer_id).then(setCustomer)
    })
  }, [id])

  async function onExport(format: string) {
    if (!id || !invoice) return
    if (format === 'pdf') {
      window.print()
      return
    }
    downloadBlob(await api.exportInvoiceDocx(id), `${invoice.invoice_number}.docx`)
  }

  if (!invoice || !customer) return <p>Loading...</p>

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
          {activeCompany?.website && <div className="form-website">{activeCompany.website}</div>}
          {activeCompany?.uen && <div>Business Reg# {activeCompany.uen}</div>}
          {activeCompany?.gst_registration_no && <div>GST Reg# {activeCompany.gst_registration_no}</div>}
        </div>
      </div>

      <div className="form-meta">
        <div>
          <div className="form-customer-name">{customer.name}</div>
          {billTo && <div>{billTo}</div>}
          {customer.contact_person && (
            <div className="form-meta-row">
              <span className="muted">Contact Person</span>
              <span>: {customer.contact_person}</span>
            </div>
          )}
          {customer.billing_email && (
            <div className="form-meta-row">
              <span className="muted">Contact Email</span>
              <span>: {customer.billing_email}</span>
            </div>
          )}
          {customer.phone && (
            <div className="form-meta-row">
              <span className="muted">Contact No</span>
              <span>: {customer.phone}</span>
            </div>
          )}
        </div>
        <div className="form-meta-right">
          <div className="form-meta-row">
            <span className="muted">Invoice No</span>
            <span>: {invoice.invoice_number}</span>
          </div>
          <div className="form-meta-row">
            <span className="muted">Date</span>
            <span>: {formatDate(invoice.issued_at)}</span>
          </div>
          {invoice.due_date && (
            <div className="form-meta-row">
              <span className="muted">Due Date</span>
              <span>: {formatDate(invoice.due_date)}</span>
            </div>
          )}
        </div>
      </div>

      <table className="invoice-lines">
        <thead>
          <tr>
            <th style={{ width: 30 }}>#</th>
            <th>DESCRIPTION</th>
            <th style={{ textAlign: 'right' }}>QTY</th>
            <th style={{ textAlign: 'right' }}>UNIT PRICE ($)</th>
            <th style={{ textAlign: 'right' }}>AMOUNT ($)</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>1</td>
            <td>{invoice.description}</td>
            <td style={{ textAlign: 'right' }}>1.00</td>
            <td style={{ textAlign: 'right' }}>{money(invoice.amount_sgd)}</td>
            <td className="invoice-amount-cell" style={{ textAlign: 'right' }}>
              {money(invoice.amount_sgd)}
            </td>
          </tr>
        </tbody>
      </table>

      <div className="totals-strip">
        <div className="totals-box">
          <div className="muted">Subtotal</div>
          <div className="totals-value">{money(invoice.amount_sgd)}</div>
        </div>
        <div className="totals-box">
          <div className="muted">
            Tax {invoice.gst_rate}% ({invoice.tax_code})
          </div>
          <div className="totals-value">{money(invoice.gst_amount_sgd)}</div>
        </div>
        <div className="totals-box totals-box-grand">
          <div>Grand Total</div>
          <div className="totals-value">{money(invoice.total_amount_sgd)}</div>
        </div>
      </div>

      {customer.terms_and_conditions && (
        <div className="form-terms">
          <div className="form-section-label">Terms &amp; Conditions</div>
          {customer.terms_and_conditions.split('\n').map((line, i) => (
            <p key={i}>{line}</p>
          ))}
        </div>
      )}

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
