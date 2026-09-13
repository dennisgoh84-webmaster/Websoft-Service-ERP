import { Fragment, useEffect, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { EmailIcon, PrintIcon, WhatsAppIcon } from '../components/DocActionIcons'
import DocumentAttachmentsPanel from '../components/DocumentAttachmentsPanel'
import ExportControl from '../components/ExportControl'
import SignaturePanel from '../components/SignaturePanel'
import { api, downloadBlob, type CompanyIndividual, type Invoice, type Payment } from '../lib/api'
import { formatMoney as money } from '../lib/format'

const METHODS = [
  { value: 'bank_transfer', label: 'Bank transfer' },
  { value: 'paynow', label: 'PayNow' },
  { value: 'cheque', label: 'Cheque' },
  { value: 'cash', label: 'Cash' },
  { value: 'credit_card', label: 'Credit card' },
  { value: 'other', label: 'Other' },
]

export default function ReceiptsPage() {
  const [customers, setCustomers] = useState<CompanyIndividual[]>([])
  const [invoices, setInvoices] = useState<Invoice[]>([])
  const [payments, setPayments] = useState<Payment[]>([])
  const [error, setError] = useState<string | null>(null)
  const [message, setMessage] = useState<string | null>(null)
  const [busyId, setBusyId] = useState<string | null>(null)
  const [docPanelId, setDocPanelId] = useState<string | null>(null)

  // Record payment form
  const [customerId, setCustomerId] = useState('')
  const [paymentDate, setPaymentDate] = useState(new Date().toISOString().slice(0, 10))
  const [amount, setAmount] = useState('')
  const [method, setMethod] = useState('bank_transfer')
  const [reference, setReference] = useState('')
  const [saving, setSaving] = useState(false)

  // Allocation: which invoice a given unallocated payment settles
  const [allocFor, setAllocFor] = useState<Record<string, { invoiceId: string; amount: string }>>({})

  function refresh() {
    api.listPayments().then(setPayments).catch((e) => setError(e.message))
    api.listInvoices().then(setInvoices).catch((e) => setError(e.message))
    api.listCompanyIndividuals().then(setCustomers).catch((e) => setError(e.message))
  }

  useEffect(refresh, [])

  const customerName = (id: string) => customers.find((c) => c.id === id)?.name ?? id.slice(0, 8)
  const openInvoices = invoices.filter((i) => i.outstanding_sgd > 0)

  async function onRecordPayment(e: FormEvent) {
    e.preventDefault()
    setError(null)
    setMessage(null)
    setSaving(true)
    try {
      await api.recordPayment({
        customer_id: customerId,
        payment_date: paymentDate,
        amount_sgd: parseFloat(amount),
        method,
        reference: reference || undefined,
      })
      setAmount('')
      setReference('')
      setMessage('Receipt recorded. Allocate it below to settle specific invoices (AR-001).')
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to record payment')
    } finally {
      setSaving(false)
    }
  }

  async function onExport(format: string) {
    setError(null)
    if (format === 'csv') {
      downloadBlob(await api.exportPaymentsCsv(), 'receipts.csv')
    } else {
      downloadBlob(await api.exportPaymentsExcel(), 'receipts.xlsx')
    }
  }

  async function onAllocate(payment: Payment) {
    const choice = allocFor[payment.id]
    if (!choice?.invoiceId || !choice.amount) {
      setError('Pick an invoice and an amount to allocate.')
      return
    }
    setError(null)
    setMessage(null)
    try {
      await api.allocatePayment(payment.id, [
        { invoice_id: choice.invoiceId, amount_sgd: parseFloat(choice.amount) },
      ])
      setAllocFor((prev) => ({ ...prev, [payment.id]: { invoiceId: '', amount: '' } }))
      setMessage('Allocated.')
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to allocate payment')
    }
  }

  async function onEmail(p: Payment) {
    setError(null)
    setMessage(null)
    setBusyId(p.id)
    try {
      const result = await api.emailReceipt(p.id)
      setMessage(`${p.voucher_number} emailed to ${result.to}.`)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to email receipt')
    } finally {
      setBusyId(null)
    }
  }

  function onWhatsApp(p: Payment) {
    setError(null)
    const customer = customers.find((c) => c.id === p.customer_id)
    if (!customer?.phone) {
      setError(`${customerName(p.customer_id)} has no phone number on file -- add one on the Company/Individual page first.`)
      return
    }
    const text = `Receipt ${p.voucher_number}, ${money(p.amount_sgd)}. PDF to follow.`
    window.open(`https://wa.me/${customer.phone.replace(/[^0-9]/g, '')}?text=${encodeURIComponent(text)}`, '_blank')
  }

  return (
    <div>
      <h1>Receipts</h1>
      <p className="muted">
        Money received from customers (Receipt Voucher). AR-001: allocation to specific invoices is
        always a manual decision by Finance from the remittance advice -- there is no automatic
        matching. Unallocated money sits on the customer's account until then.
      </p>
      {error && <div className="error-banner">{error}</div>}
      {message && (
        <p className="muted" style={{ marginBottom: 12 }}>
          {message}
        </p>
      )}

      <div className="card">
        <h2>Record a receipt</h2>
        <form onSubmit={onRecordPayment}>
          <div className="form-row">
            <label>Company / Individual</label>
            <select value={customerId} onChange={(e) => setCustomerId(e.target.value)} required>
              <option value="">Select...</option>
              {customers.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </select>
          </div>
          <div className="form-row">
            <label>Payment date</label>
            <input
              type="date"
              value={paymentDate}
              onChange={(e) => setPaymentDate(e.target.value)}
              required
            />
          </div>
          <div className="form-row">
            <label>Amount received (SGD)</label>
            <input
              type="number"
              min="0.01"
              step="0.01"
              value={amount}
              onChange={(e) => setAmount(e.target.value)}
              required
            />
          </div>
          <div className="form-row">
            <label>Method</label>
            <select value={method} onChange={(e) => setMethod(e.target.value)}>
              {METHODS.map((m) => (
                <option key={m.value} value={m.value}>
                  {m.label}
                </option>
              ))}
            </select>
          </div>
          <div className="form-row">
            <label>Bank / remittance reference</label>
            <input value={reference} onChange={(e) => setReference(e.target.value)} />
          </div>
          <button type="submit" disabled={saving || !customerId}>
            {saving ? 'Recording...' : 'Record receipt'}
          </button>
        </form>
      </div>

      <div className="card">
        <div className="filter-bar">
          <h2 style={{ margin: 0 }}>Receipts ({payments.length})</h2>
          <ExportControl
            formats={[
              { value: 'csv', label: 'CSV' },
              { value: 'excel', label: 'Excel' },
            ]}
            onExport={onExport}
            onError={setError}
          />
        </div>
        <table>
          <thead>
            <tr>
              <th>Voucher</th>
              <th>Date</th>
              <th>Company / Individual</th>
              <th>Amount</th>
              <th>Unallocated</th>
              <th>Reference</th>
              <th>Allocate to invoice</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {payments.map((p) => {
              const choice = allocFor[p.id] ?? { invoiceId: '', amount: '' }
              const customerInvoices = openInvoices.filter((i) => i.customer_id === p.customer_id)
              return (
                <Fragment key={p.id}>
                <tr>
                  <td style={{ whiteSpace: 'nowrap' }}>{p.voucher_number}</td>
                  <td style={{ whiteSpace: 'nowrap' }}>{p.payment_date}</td>
                  <td>{customerName(p.customer_id)}</td>
                  <td>{money(p.amount_sgd)}</td>
                  <td>
                    {p.unallocated_sgd > 0 ? (
                      <strong>{money(p.unallocated_sgd)}</strong>
                    ) : (
                      <span className="muted">fully allocated</span>
                    )}
                    {p.allocations.length > 0 && (
                      <div className="muted">
                        {p.allocations.map((a) => `${a.invoice_number}: ${money(a.amount_sgd)}`).join(', ')}
                      </div>
                    )}
                  </td>
                  <td className="muted">{p.reference ?? '-'}</td>
                  <td>
                    {p.unallocated_sgd > 0 ? (
                      <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                        <select
                          value={choice.invoiceId}
                          onChange={(e) =>
                            setAllocFor((prev) => ({
                              ...prev,
                              [p.id]: { ...choice, invoiceId: e.target.value },
                            }))
                          }
                        >
                          <option value="">Invoice...</option>
                          {customerInvoices.map((i) => (
                            <option key={i.id} value={i.id}>
                              {i.invoice_number} ({money(i.outstanding_sgd)} due)
                            </option>
                          ))}
                        </select>
                        <input
                          type="number"
                          min="0.01"
                          step="0.01"
                          placeholder="Amount"
                          style={{ width: 100 }}
                          value={choice.amount}
                          onChange={(e) =>
                            setAllocFor((prev) => ({
                              ...prev,
                              [p.id]: { ...choice, amount: e.target.value },
                            }))
                          }
                        />
                        <button onClick={() => onAllocate(p)}>Allocate</button>
                      </div>
                    ) : (
                      <span className="muted">-</span>
                    )}
                  </td>
                  <td>
                    <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                      <button
                        className="secondary icon-button"
                        title="Attachments & Signatures"
                        aria-label="Attachments & Signatures"
                        onClick={() => setDocPanelId(docPanelId === p.id ? null : p.id)}
                      >📎</button>
                      <Link to={`/receipts/${p.id}/print`} className="secondary icon-button" title="Print" aria-label="Print">
                        <PrintIcon />
                      </Link>
                      <button
                        className="secondary icon-button"
                        disabled={busyId === p.id || !customers.find((c) => c.id === p.customer_id)?.billing_email}
                        title={
                          customers.find((c) => c.id === p.customer_id)?.billing_email
                            ? 'Email'
                            : 'Add an email on the Company/Individual page first'
                        }
                        aria-label="Email"
                        onClick={() => onEmail(p)}
                      >
                        <EmailIcon />
                      </button>
                      <button
                        className="secondary icon-button"
                        disabled={busyId === p.id || !customers.find((c) => c.id === p.customer_id)?.phone}
                        title={
                          customers.find((c) => c.id === p.customer_id)?.phone
                            ? 'WhatsApp'
                            : 'Add a phone number on the Company/Individual page first'
                        }
                        aria-label="WhatsApp"
                        onClick={() => onWhatsApp(p)}
                      >
                        <WhatsAppIcon />
                      </button>
                    </div>
                  </td>
                </tr>
                {docPanelId === p.id && (
                  <tr>
                    <td colSpan={8} style={{ padding: 16, background: 'var(--bg-muted, #f9f9f9)' }}>
                      <DocumentAttachmentsPanel entityType="receipt_voucher" entityId={p.id} />
                      <SignaturePanel entityType="receipt_voucher" entityId={p.id} />
                    </td>
                  </tr>
                )}
                </Fragment>
              )
            })}
            {payments.length === 0 && (
              <tr>
                <td colSpan={8} className="muted">
                  No receipts recorded yet.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  )
}
