// A printable Payment Voucher "form" -- see InvoicePrintPage.tsx for
// the pattern this follows.
import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import ExportControl from '../components/ExportControl'
import { api, downloadBlob, type CompanyIndividual, type SupplierPayment } from '../lib/api'
import { useAuth } from '../lib/AuthContext'
import { formatMoney as money, formatDate } from '../lib/format'

export default function PaymentVoucherPrintPage() {
  const { id } = useParams<{ id: string }>()
  const { activeCompany } = useAuth()
  const [payment, setPayment] = useState<SupplierPayment | null>(null)
  const [supplier, setSupplier] = useState<CompanyIndividual | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!id) return
    api.getSupplierPayment(id).then((p) => {
      setPayment(p)
      api.getCompanyIndividual(p.supplier_id).then(setSupplier)
    })
  }, [id])

  async function onExport(format: string) {
    if (!id || !payment) return
    if (format === 'pdf') {
      window.print()
      return
    }
    downloadBlob(await api.exportSupplierPaymentDocx(id), `${payment.voucher_number}.docx`)
  }

  if (!payment || !supplier) return <p>Loading...</p>

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
        </div>
      </div>

      <div className="form-meta">
        <div>
          <div className="form-section-label">Paid To</div>
          <div className="form-customer-name">{supplier.name}</div>
          {supplier.gst_registration_no && <div>GST Reg# {supplier.gst_registration_no}</div>}
        </div>
        <div className="form-meta-right">
          <div className="form-meta-row">
            <span className="muted">Voucher No</span>
            <span>: {payment.voucher_number}</span>
          </div>
          <div className="form-meta-row">
            <span className="muted">Date</span>
            <span>: {formatDate(payment.payment_date)}</span>
          </div>
          <div className="form-meta-row">
            <span className="muted">Method</span>
            <span>: {payment.method}</span>
          </div>
          {payment.reference && (
            <div className="form-meta-row">
              <span className="muted">Reference</span>
              <span>: {payment.reference}</span>
            </div>
          )}
        </div>
      </div>

      <div className="totals-strip">
        <div className="totals-box totals-box-grand">
          <div>Amount Paid</div>
          <div className="totals-value">{money(payment.amount_sgd)}</div>
        </div>
      </div>

      {payment.allocations.length > 0 && (
        <table className="invoice-lines">
          <thead>
            <tr>
              <th>Applied To</th>
              <th style={{ textAlign: 'right' }}>AMOUNT ($)</th>
            </tr>
          </thead>
          <tbody>
            {payment.allocations.map((a) => (
              <tr key={a.id}>
                <td>{a.bill_number ?? '-'}</td>
                <td className="invoice-amount-cell" style={{ textAlign: 'right' }}>
                  {money(a.amount_sgd)}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      {payment.unallocated_sgd > 0 && (
        <p className="muted" style={{ marginTop: 16 }}>
          {money(payment.unallocated_sgd)} unallocated.
        </p>
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
