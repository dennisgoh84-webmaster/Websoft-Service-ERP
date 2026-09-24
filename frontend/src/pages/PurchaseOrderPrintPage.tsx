// A printable Purchase Order form, matching Webmaster's own letterhead
// format -- see QuotationPrintPage.tsx for the pattern this follows.
// Same content as docx_forms.purchase_order_to_docx, which "Email PO"
// also converts to the PDF it attaches.
import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import ExportControl from '../components/ExportControl'
import { api, downloadBlob, type CompanyIndividual, type PurchaseOrder } from '../lib/api'
import { useAuth } from '../lib/AuthContext'
import { formatMoney as money, formatDate } from '../lib/format'

export default function PurchaseOrderPrintPage() {
  const { id } = useParams<{ id: string }>()
  const { activeCompany } = useAuth()
  const [po, setPo] = useState<PurchaseOrder | null>(null)
  const [supplier, setSupplier] = useState<CompanyIndividual | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!id) return
    api.getPurchaseOrder(id).then((p) => {
      setPo(p)
      api.getCompanyIndividual(p.supplier_id).then(setSupplier)
    })
  }, [id])

  async function onExport(format: string) {
    if (!id || !po) return
    if (format === 'pdf') {
      window.print()
      return
    }
    downloadBlob(await api.exportPurchaseOrderDocx(id), `${po.po_number}.docx`)
  }

  if (!po || !supplier) return <p>Loading...</p>

  const supplierAddress = [
    supplier.address_line1,
    supplier.address_line2,
    supplier.address_city,
    supplier.address_country,
  ]
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
          <div className="form-customer-name">{supplier.name}</div>
          {supplierAddress && <div>{supplierAddress}</div>}
          {supplier.billing_email && (
            <div className="form-meta-row">
              <span className="muted">Email</span>
              <span>: {supplier.billing_email}</span>
            </div>
          )}
          {supplier.phone && (
            <div className="form-meta-row">
              <span className="muted">Tel</span>
              <span>: {supplier.phone}</span>
            </div>
          )}
          {supplier.gst_registration_no && (
            <div className="form-meta-row">
              <span className="muted">GST Reg</span>
              <span>: {supplier.gst_registration_no}</span>
            </div>
          )}
        </div>
        <div className="form-meta-right">
          <div className="form-meta-row">
            <span className="muted">PO No</span>
            <span>: {po.po_number}</span>
          </div>
          <div className="form-meta-row">
            <span className="muted">Date</span>
            <span>: {formatDate(po.order_date)}</span>
          </div>
          <div className="form-meta-row">
            <span className="muted">Status</span>
            <span>: {po.status.replace('_', ' ')}</span>
          </div>
        </div>
      </div>

      <table className="invoice-lines">
        <thead>
          <tr>
            <th>DESCRIPTION</th>
            <th style={{ textAlign: 'right' }}>AMOUNT ($)</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>{po.description}</td>
            <td className="invoice-amount-cell" style={{ textAlign: 'right' }}>
              {money(po.amount_sgd)}
            </td>
          </tr>
        </tbody>
      </table>

      <div className="totals-strip">
        <div className="totals-box">
          <div className="muted">Subtotal</div>
          <div className="totals-value">{money(po.amount_sgd)}</div>
        </div>
        <div className="totals-box">
          <div className="muted">GST</div>
          <div className="totals-value">{money(po.gst_amount_sgd)}</div>
        </div>
        <div className="totals-box totals-box-grand">
          <div>Grand Total</div>
          <div className="totals-value">{money(po.total_amount_sgd)}</div>
        </div>
      </div>

      <div className="form-terms">
        <p>Please confirm receipt of this purchase order and quote the PO number above on your invoice.</p>
      </div>

      <div className="form-signature-row">
        <div />
        <div>
          Authorised by
          <div className="form-signature-line" />
        </div>
      </div>
    </div>
  )
}
