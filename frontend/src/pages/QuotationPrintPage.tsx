// A printable Quotation "form", matching Webmaster's own letterhead
// format -- see InvoicePrintPage.tsx for the pattern this follows.
import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import ExportControl from '../components/ExportControl'
import { api, downloadBlob, type CompanyIndividual, type Quotation } from '../lib/api'
import { useAuth } from '../lib/AuthContext'
import { formatMoney as money, formatDate } from '../lib/format'

export default function QuotationPrintPage() {
  const { id } = useParams<{ id: string }>()
  const { activeCompany } = useAuth()
  const [quotation, setQuotation] = useState<Quotation | null>(null)
  const [customer, setCustomer] = useState<CompanyIndividual | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!id) return
    api.getQuotation(id).then((q) => {
      setQuotation(q)
      api.getCompanyIndividual(q.customer_id).then(setCustomer)
    })
  }, [id])

  async function onExport(format: string) {
    if (!id || !quotation) return
    if (format === 'pdf') {
      window.print()
      return
    }
    downloadBlob(await api.exportQuotationDocx(id), `${quotation.quotation_number}.docx`)
  }

  if (!quotation || !customer) return <p>Loading...</p>

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
            <span className="muted">Quotation No</span>
            <span>: {quotation.quotation_number}</span>
          </div>
          <div className="form-meta-row">
            <span className="muted">Date</span>
            <span>: {formatDate(quotation.quotation_date)}</span>
          </div>
          {quotation.valid_until && (
            <div className="form-meta-row">
              <span className="muted">Valid Until</span>
              <span>: {quotation.valid_until}</span>
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
          {quotation.lines.map((l, i) => (
            <tr key={l.id}>
              <td>{i + 1}</td>
              <td>
                {l.description}
                {l.unit_of_measure && <div className="muted">{l.unit_of_measure}</div>}
              </td>
              <td style={{ textAlign: 'right' }}>{l.quantity.toFixed(2)}</td>
              <td style={{ textAlign: 'right' }}>{money(l.unit_price_sgd)}</td>
              <td className="invoice-amount-cell" style={{ textAlign: 'right' }}>
                {money(l.line_total_sgd)}
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      <div className="totals-strip">
        <div className="totals-box">
          <div className="muted">Subtotal</div>
          <div className="totals-value">{money(quotation.amount_sgd)}</div>
        </div>
        <div className="totals-box">
          <div className="muted">
            Tax {quotation.gst_rate}% ({quotation.tax_code})
          </div>
          <div className="totals-value">{money(quotation.gst_amount_sgd)}</div>
        </div>
        <div className="totals-box totals-box-grand">
          <div>Grand Total</div>
          <div className="totals-value">{money(quotation.total_amount_sgd)}</div>
        </div>
      </div>

      {quotation.notes && (
        <div className="form-terms">
          <div className="form-section-label">Notes</div>
          {quotation.notes.split('\n').map((line, i) => (
            <p key={i}>{line}</p>
          ))}
        </div>
      )}

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
