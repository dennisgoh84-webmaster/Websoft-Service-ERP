import { Fragment, useEffect, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { EmailIcon, PrintIcon, WhatsAppIcon } from '../components/DocActionIcons'
import DocumentAttachmentsPanel from '../components/DocumentAttachmentsPanel'
import ExportControl from '../components/ExportControl'
import SignaturePanel from '../components/SignaturePanel'
import { api, downloadBlob, type BankAccount, type CompanyIndividual, type Invoice, type Payment } from '../lib/api'
import { formatMoney as money, formatDate, todayIso } from '../lib/format'
import DateInput from '../components/DateInput'
import CurrencyFields, { currencyPayload, type CurrencyValue } from '../components/CurrencyFields'
import OtherVoucherFields from '../components/OtherVoucherFields'
import { useOtherVoucherAccounts } from '../lib/otherVoucherAccounts'

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
  // A Company / Individual, or "Other" -- bank interest and the like,
  // against a GL account (#49 / 31.1: never keyed into the Bank Book).
  const [kind, setKind] = useState<'customer' | 'other'>('customer')
  const [glAccountId, setGlAccountId] = useState('')
  const [description, setDescription] = useState('')
  // Bank interest is an exempt supply (ES) for GST (Dennis, 2026-09-26).
  const [exempt, setExempt] = useState(false)
  const [customerId, setCustomerId] = useState('')
  const [paymentDate, setPaymentDate] = useState(todayIso())
  // Multi-currency: the customer's own currency, at the rate table's rate.
  const [cur, setCur] = useState<CurrencyValue>({ currency: 'SGD', rate: '' })
  const [amount, setAmount] = useState('')
  const [method, setMethod] = useState('bank_transfer')
  const [reference, setReference] = useState('')
  // ACC-001: every receipt names the bank account the money landed in.
  const [bankAccounts, setBankAccounts] = useState<BankAccount[]>([])
  const [bankAccountId, setBankAccountId] = useState('')
  const [saving, setSaving] = useState(false)

  // Allocation: which invoice a given unallocated payment settles
  const [allocFor, setAllocFor] = useState<Record<string, { invoiceId: string; amount: string }>>({})

  function refresh() {
    api.listPayments().then(setPayments).catch((e) => setError(e.message))
    api.listInvoices().then(setInvoices).catch((e) => setError(e.message))
    api.listCompanyIndividuals().then(setCustomers).catch((e) => setError(e.message))
    api.listBankAccounts().then((rows) => {
      setBankAccounts(rows)
      setBankAccountId((cur) => cur || rows[0]?.id || '')
    }).catch((e) => setError(e.message))
  }

  useEffect(refresh, [])

  const otherAccounts = useOtherVoucherAccounts(bankAccounts)
  const customerName = (id: string | null) => (id ? (customers.find((c) => c.id === id)?.name ?? id.slice(0, 8)) : '')
  const openInvoices = invoices.filter((i) => i.outstanding_sgd > 0)

  async function onRecordPayment(e: FormEvent) {
    e.preventDefault()
    setError(null)
    setMessage(null)
    setSaving(true)
    try {
      const rv = await api.recordPayment({
        ...(kind === 'other' ? { gl_account_id: glAccountId, notes: description, tax_code: exempt ? 'ES' : null } : { customer_id: customerId }),
        payment_date: paymentDate,
        amount: parseFloat(amount),
        ...currencyPayload(cur),
        bank_account_id: bankAccountId,
        method,
        reference: reference || undefined,
      })
      setAmount('')
      setReference('')
      setDescription('')
      setMessage(
        kind === 'other'
          ? `${rv.voucher_number} recorded and posted to ${rv.gl_account}. Press Bank below to put it in the Bank Book.`
          : 'Receipt recorded. Allocate it below to settle specific invoices (AR-001).',
      )
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
        { invoice_id: choice.invoiceId, amount: parseFloat(choice.amount) },
      ])
      setAllocFor((prev) => ({ ...prev, [payment.id]: { invoiceId: '', amount: '' } }))
      setMessage('Allocated.')
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to allocate payment')
    }
  }

  // GL posting + Bank step (ACC-001..004). Posting happens automatically
  // when the receipt is recorded; these are the explicit reversible actions.
  async function onBank(p: Payment) {
    setBusyId(p.id); setError(null); setMessage(null)
    try {
      const r = await api.bankReceipt(p.id)
      setMessage(`${p.voucher_number} entered in the bank book as ${r.transaction_number}.`)
      refresh()
    } catch (err) { setError(err instanceof Error ? err.message : 'Bank step failed') }
    finally { setBusyId(null) }
  }
  async function onUnbank(p: Payment) {
    const reason = window.prompt(`Unbank ${p.voucher_number} — void its bank book line ${p.bank_transaction_number ?? ''}?\n\nReason (required):`)
    if (!reason?.trim()) return
    setBusyId(p.id); setError(null); setMessage(null)
    try {
      await api.unbankReceipt(p.id, reason.trim())
      setMessage(`${p.voucher_number} removed from the bank book.`)
      refresh()
    } catch (err) { setError(err instanceof Error ? err.message : 'Unbank failed') }
    finally { setBusyId(null) }
  }
  async function onUngl(p: Payment) {
    const reason = window.prompt(`Reverse the GL posting of ${p.voucher_number}?\n\nA mirror-image voucher is posted; nothing is deleted.\nReason (required):`)
    if (!reason?.trim()) return
    setBusyId(p.id); setError(null); setMessage(null)
    try {
      const r = await api.unglReceipt(p.id, reason.trim())
      setMessage(`${p.voucher_number} reversed in the GL by ${r.reversal_voucher}.`)
      refresh()
    } catch (err) { setError(err instanceof Error ? err.message : 'UNGL failed') }
    finally { setBusyId(null) }
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
            <label>Received from</label>
            <select value={kind} onChange={(e) => setKind(e.target.value as 'customer' | 'other')}>
              <option value="customer">A Company / Individual (settles its invoices)</option>
              <option value="other">Other -- bank interest, refunds... (to an account)</option>
            </select>
          </div>
          {kind === 'customer' ? (
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
          ) : (
            <OtherVoucherFields
              accounts={otherAccounts}
              accountId={glAccountId}
              onAccountId={setGlAccountId}
              description={description}
              onDescription={setDescription}
              placeholder="e.g. DBS interest for September"
            />
          )}
          {kind === 'other' && (
            <div className="form-row">
              <label>
                <input type="checkbox" checked={exempt} onChange={(e) => setExempt(e.target.checked)} /> Exempt supply (ES) for GST
              </label>
              <span className="muted">Tick for bank interest: it then counts in the GST Calculation&rsquo;s exempt supplies box.</span>
            </div>
          )}
          <div className="form-row">
            <label>Payment date</label>
            <DateInput
              value={paymentDate}
              onChange={(e) => setPaymentDate(e.target.value)}
              required
            />
          </div>
          <CurrencyFields
            idPrefix="receipt"
            value={cur}
            onChange={setCur}
            date={paymentDate}
            partyCurrency={kind === 'other' ? null : (customers.find((c) => c.id === customerId)?.default_currency ?? null)}
          />
          <div className="form-row">
            <label>Amount received ({cur.currency})</label>
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
          <button type="submit" disabled={saving || (kind === 'customer' ? !customerId : !glAccountId || !description.trim())}>
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
              <th>Received from</th>
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
                  <td style={{ whiteSpace: 'nowrap' }}>{formatDate(p.payment_date)}</td>
                  <td>
                    {p.kind === 'other' ? (
                      <>
                        {p.notes}
                        <div className="muted small">Other: {p.gl_account}</div>
                      </>
                    ) : (
                      customerName(p.customer_id)
                    )}
                  </td>
                  <td>
                    {money(p.amount_sgd)}
                    {p.currency_code && p.currency_code !== 'SGD' && (
                      <div className="muted small">
                        {p.currency_code} {(p.amount_fx ?? 0).toFixed(2)} @ {p.exchange_rate}
                      </div>
                    )}
                  </td>
                  <td>
                    {p.unallocated_sgd > 0 ? (
                      <strong>{p.currency_code && p.currency_code !== 'SGD' ? `${p.currency_code} ${(p.unallocated_fx ?? 0).toFixed(2)}` : money(p.unallocated_sgd)}</strong>
                    ) : (
                      <span className="muted">{p.kind === 'other' ? 'to an account' : 'fully allocated'}</span>
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
                    <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', alignItems: 'center' }}>
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
                        <button className="secondary" disabled={busyId === p.id} onClick={() => onUnbank(p)} title="Void this receipt's bank book line (needs a reason)">Unbank</button>
                      ) : (
                        <button disabled={busyId === p.id || !p.bank_account_id} onClick={() => onBank(p)} title="Confirm the money reached the bank — writes the bank book line">Bank</button>
                      )}
                      {p.gl_status === 'posted' && (
                        <button className="secondary" disabled={busyId === p.id} onClick={() => onUngl(p)} title="Reverse the GL posting (needs a reason)">UNGL</button>
                      )}
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
