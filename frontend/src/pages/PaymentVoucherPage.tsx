import { Fragment, useEffect, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { EmailIcon, PrintIcon, WhatsAppIcon } from '../components/DocActionIcons'
import DocumentAttachmentsPanel from '../components/DocumentAttachmentsPanel'
import ExportControl from '../components/ExportControl'
import SignaturePanel from '../components/SignaturePanel'
import { api, downloadBlob, type BankAccount, type CompanyIndividual, type SupplierInvoice, type SupplierPayment } from '../lib/api'
import DateInput from '../components/DateInput'
import { formatMoney as money, todayIso } from '../lib/format'

export default function PaymentVoucherPage() {
  const [suppliers, setSuppliers] = useState<CompanyIndividual[]>([])
  const [bills, setBills] = useState<SupplierInvoice[]>([])
  const [payments, setPayments] = useState<SupplierPayment[]>([])
  const [error, setError] = useState<string | null>(null)
  const [message, setMessage] = useState<string | null>(null)
  const [busyId, setBusyId] = useState<string | null>(null)
  const [docPanelId, setDocPanelId] = useState<string | null>(null)

  const [paySupplier, setPaySupplier] = useState('')
  const [payAmount, setPayAmount] = useState('')
  const [payRef, setPayRef] = useState('')
  const [payDate, setPayDate] = useState(todayIso())
  // ACC-001: every payment names the bank account the money left from.
  const [bankAccounts, setBankAccounts] = useState<BankAccount[]>([])
  const [bankAccountId, setBankAccountId] = useState('')

  const [allocFor, setAllocFor] = useState<Record<string, { billId: string; amount: string }>>({})

  function refresh() {
    api.listCompanyIndividuals({ is_supplier: true }).then(setSuppliers).catch((e) => setError(e.message))
    api.listBills().then(setBills).catch((e) => setError(e.message))
    api.listSupplierPayments().then(setPayments).catch((e) => setError(e.message))
    api.listBankAccounts().then((rows) => {
      setBankAccounts(rows)
      setBankAccountId((cur) => cur || rows[0]?.id || '')
    }).catch((e) => setError(e.message))
  }

  useEffect(refresh, [])

  const supplierName = (id: string) => suppliers.find((s) => s.id === id)?.name ?? id.slice(0, 8)
  const supplierOf = (id: string) => suppliers.find((s) => s.id === id)
  const openBills = bills.filter((b) => b.outstanding_sgd > 0)

  async function onRecordPayment(e: FormEvent) {
    e.preventDefault()
    setError(null)
    setMessage(null)
    try {
      await api.recordSupplierPayment({
        supplier_id: paySupplier,
        payment_date: payDate || todayIso(),
        amount_sgd: parseFloat(payAmount),
        bank_account_id: bankAccountId,
        reference: payRef || undefined,
      })
      setPayAmount('')
      setPayRef('')
      setMessage('Payment voucher recorded. Allocate it below to settle a bill.')
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to record payment')
    }
  }

  async function onExport(format: string) {
    setError(null)
    if (format === 'csv') {
      downloadBlob(await api.exportSupplierPaymentsCsv(), 'payment-vouchers.csv')
    } else {
      downloadBlob(await api.exportSupplierPaymentsExcel(), 'payment-vouchers.xlsx')
    }
  }

  async function onAllocate(payment: SupplierPayment) {
    const choice = allocFor[payment.id]
    if (!choice?.billId || !choice.amount) {
      setError('Pick a bill and an amount to allocate.')
      return
    }
    setError(null)
    try {
      await api.allocateSupplierPayment(payment.id, [
        { supplier_invoice_id: choice.billId, amount_sgd: parseFloat(choice.amount) },
      ])
      setAllocFor((prev) => ({ ...prev, [payment.id]: { billId: '', amount: '' } }))
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to allocate payment')
    }
  }

  // GL posting + Bank step (ACC-001..004). Posting happens automatically
  // when the voucher is recorded; these are the explicit reversible actions.
  async function onBank(p: SupplierPayment) {
    setBusyId(p.id); setError(null); setMessage(null)
    try {
      const r = await api.bankSupplierPayment(p.id)
      setMessage(`${p.voucher_number} entered in the bank book as ${r.transaction_number}.`)
      refresh()
    } catch (err) { setError(err instanceof Error ? err.message : 'Bank step failed') }
    finally { setBusyId(null) }
  }
  async function onUnbank(p: SupplierPayment) {
    const reason = window.prompt(`Unbank ${p.voucher_number} — void its bank book line ${p.bank_transaction_number ?? ''}?\n\nReason (required):`)
    if (!reason?.trim()) return
    setBusyId(p.id); setError(null); setMessage(null)
    try {
      await api.unbankSupplierPayment(p.id, reason.trim())
      setMessage(`${p.voucher_number} removed from the bank book.`)
      refresh()
    } catch (err) { setError(err instanceof Error ? err.message : 'Unbank failed') }
    finally { setBusyId(null) }
  }
  async function onUngl(p: SupplierPayment) {
    const reason = window.prompt(`Reverse the GL posting of ${p.voucher_number}?\n\nA mirror-image voucher is posted; nothing is deleted.\nReason (required):`)
    if (!reason?.trim()) return
    setBusyId(p.id); setError(null); setMessage(null)
    try {
      const r = await api.unglSupplierPayment(p.id, reason.trim())
      setMessage(`${p.voucher_number} reversed in the GL by ${r.reversal_voucher}.`)
      refresh()
    } catch (err) { setError(err instanceof Error ? err.message : 'UNGL failed') }
    finally { setBusyId(null) }
  }

  async function onEmail(p: SupplierPayment) {
    setError(null)
    setMessage(null)
    setBusyId(p.id)
    try {
      const result = await api.emailSupplierPayment(p.id)
      setMessage(`${p.voucher_number} emailed to ${result.to}.`)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to email payment voucher')
    } finally {
      setBusyId(null)
    }
  }

  function onWhatsApp(p: SupplierPayment) {
    setError(null)
    const supplier = supplierOf(p.supplier_id)
    if (!supplier?.phone) {
      setError(`${supplierName(p.supplier_id)} has no phone number on file -- add one on the Company/Individual page first.`)
      return
    }
    const text = `Payment Voucher ${p.voucher_number}, ${money(p.amount_sgd)}. PDF to follow.`
    window.open(`https://wa.me/${supplier.phone.replace(/[^0-9]/g, '')}?text=${encodeURIComponent(text)}`, '_blank')
  }

  return (
    <div>
      <h1>Payment Voucher</h1>
      <p className="muted">
        Money paid to suppliers. Allocation to a specific bill is a manual decision, same as
        Receipts on the customer side -- unallocated money sits against the supplier until then.
      </p>
      {error && <div className="error-banner">{error}</div>}
      {message && (
        <p className="muted" style={{ marginBottom: 12 }}>
          {message}
        </p>
      )}

      <div className="card">
        <div className="filter-bar">
          <h2 style={{ margin: 0 }}>Payment vouchers ({payments.length})</h2>
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
              <th>Supplier</th>
              <th>Amount</th>
              <th>Unallocated</th>
              <th>Allocate to bill</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {payments.map((p) => {
              const choice = allocFor[p.id] ?? { billId: '', amount: '' }
              const supplierBills = openBills.filter((b) => b.supplier_id === p.supplier_id)
              return (
                <Fragment key={p.id}>
                <tr>
                  <td>{p.voucher_number}</td>
                  <td>{supplierName(p.supplier_id)}</td>
                  <td>{money(p.amount_sgd)}</td>
                  <td>
                    {p.unallocated_sgd > 0 ? (
                      <strong>{money(p.unallocated_sgd)}</strong>
                    ) : (
                      <span className="muted">fully allocated</span>
                    )}
                    {p.allocations.length > 0 && (
                      <div className="muted">
                        {p.allocations.map((a) => `${a.bill_number}: ${money(a.amount_sgd)}`).join(', ')}
                      </div>
                    )}
                  </td>
                  <td>
                    {p.unallocated_sgd > 0 ? (
                      <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                        <select
                          value={choice.billId}
                          onChange={(e) =>
                            setAllocFor((prev) => ({ ...prev, [p.id]: { ...choice, billId: e.target.value } }))
                          }
                        >
                          <option value="">Bill...</option>
                          {supplierBills.map((b) => (
                            <option key={b.id} value={b.id}>
                              {b.bill_number} ({money(b.outstanding_sgd)} due)
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
                            setAllocFor((prev) => ({ ...prev, [p.id]: { ...choice, amount: e.target.value } }))
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
                      <span
                        className={`badge badge-${p.gl_status === 'posted' ? 'success' : p.gl_status === 'reversed' ? 'warning' : 'neutral'}`}
                        title={p.gl_voucher_number ? `GL voucher ${p.gl_voucher_number}` : 'Not posted to the General Ledger'}
                      >
                        GL {p.gl_status === 'posted' ? 'posted' : p.gl_status === 'reversed' ? 'reversed' : 'not posted'}
                      </span>
                      <span
                        className={`badge badge-${p.bank_status === 'banked' ? 'success' : 'neutral'}`}
                        title={p.bank_transaction_number ? `Bank book ${p.bank_transaction_number}` : 'Not yet confirmed in the bank book'}
                      >
                        {p.bank_status === 'banked' ? 'Banked' : 'Not banked'}
                      </span>
                      {p.bank_status === 'banked' ? (
                        <button className="secondary" disabled={busyId === p.id} onClick={() => onUnbank(p)} title="Void this payment's bank book line (needs a reason)">Unbank</button>
                      ) : (
                        <button disabled={busyId === p.id || !p.bank_account_id} onClick={() => onBank(p)} title="Confirm the money left the bank — writes the bank book line">Bank</button>
                      )}
                      {p.gl_status === 'posted' && (
                        <button className="secondary" disabled={busyId === p.id} onClick={() => onUngl(p)} title="Reverse the GL posting (needs a reason)">UNGL</button>
                      )}
                      <Link to={`/payment-voucher/${p.id}/print`} className="secondary icon-button" title="Print" aria-label="Print">
                        <PrintIcon />
                      </Link>
                      <button
                        className="secondary icon-button"
                        disabled={busyId === p.id || !supplierOf(p.supplier_id)?.billing_email}
                        title={supplierOf(p.supplier_id)?.billing_email ? 'Email' : 'Add an email on the Company/Individual page first'}
                        aria-label="Email"
                        onClick={() => onEmail(p)}
                      >
                        <EmailIcon />
                      </button>
                      <button
                        className="secondary icon-button"
                        disabled={busyId === p.id || !supplierOf(p.supplier_id)?.phone}
                        title={supplierOf(p.supplier_id)?.phone ? 'WhatsApp' : 'Add a phone number on the Company/Individual page first'}
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
                    <td colSpan={6} style={{ padding: 16, background: 'var(--bg-muted, #f9f9f9)' }}>
                      <DocumentAttachmentsPanel entityType="payment_voucher" entityId={p.id} />
                      <SignaturePanel entityType="payment_voucher" entityId={p.id} />
                    </td>
                  </tr>
                )}
                </Fragment>
              )
            })}
            {payments.length === 0 && (
              <tr>
                <td colSpan={6} className="muted">
                  No payment vouchers recorded yet.
                </td>
              </tr>
            )}
          </tbody>
        </table>
        <h2 style={{ marginTop: 18 }}>Record a payment voucher</h2>
        <form onSubmit={onRecordPayment}>
          <div className="form-row">
            <label>Supplier</label>
            <select value={paySupplier} onChange={(e) => setPaySupplier(e.target.value)} required>
              <option value="">Select...</option>
              {suppliers.map((s) => (
                <option key={s.id} value={s.id}>
                  {s.name}
                </option>
              ))}
            </select>
          </div>
          <div className="form-row">
            <label>Payment date</label>
            <DateInput value={payDate} onChange={(e) => setPayDate(e.target.value)} required />
          </div>
          <div className="form-row">
            <label>Amount (SGD)</label>
            <input
              type="number"
              min="0.01"
              step="0.01"
              value={payAmount}
              onChange={(e) => setPayAmount(e.target.value)}
              required
            />
          </div>
          <div className="form-row">
            <label>Bank account</label>
            <select value={bankAccountId} onChange={(e) => setBankAccountId(e.target.value)} required>
              {bankAccounts.length === 0 && <option value="">Add a bank account under Bank first</option>}
              {bankAccounts.map((b) => (
                <option key={b.id} value={b.id}>
                  {b.bank_name} — {b.account_number}
                </option>
              ))}
            </select>
          </div>
          <div className="form-row">
            <label>Reference</label>
            <input value={payRef} onChange={(e) => setPayRef(e.target.value)} />
          </div>
          <button type="submit" disabled={!paySupplier}>
            Record payment
          </button>
        </form>
      </div>
    </div>
  )
}
