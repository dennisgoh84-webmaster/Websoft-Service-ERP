import { Fragment, useEffect, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import DocumentAttachmentsPanel from '../components/DocumentAttachmentsPanel'
import ExportControl from '../components/ExportControl'
import SignaturePanel from '../components/SignaturePanel'
import { api, downloadBlob, type Account, type APAgingReport, type CompanyIndividual, type PurchaseOrder, type SupplierInvoice, type TaxCode } from '../lib/api'
import DateInput from '../components/DateInput'
import CurrencyFields, { currencyPayload, type CurrencyValue } from '../components/CurrencyFields'
import { formatMoney as money, formatDate, todayIso } from '../lib/format'

const BILL_BADGE: Record<string, string> = {
  awaiting_match: 'draft',
  exception: 'exceeded',
  approved: 'active',
  partially_paid: 'exceeded',
  paid: 'active',
}

export default function AccountsPayablePage() {
  const [suppliers, setSuppliers] = useState<CompanyIndividual[]>([])
  const [pos, setPos] = useState<PurchaseOrder[]>([])
  const [bills, setBills] = useState<SupplierInvoice[]>([])
  const [aging, setAging] = useState<APAgingReport | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [message, setMessage] = useState<string | null>(null)
  const [docPanelId, setDocPanelId] = useState<string | null>(null)

  // New bill
  const [billSupplier, setBillSupplier] = useState('')
  const [billPo, setBillPo] = useState('')
  const [billDesc, setBillDesc] = useState('')
  const [billAmount, setBillAmount] = useState('')
  // GST is worked out from the bill's purchase tax code, as on a Sales
  // Invoice (Dennis, 2026-09-26) -- never keyed in.
  const [billTaxCode, setBillTaxCode] = useState('TX')
  const [purchaseCodes, setPurchaseCodes] = useState<TaxCode[]>([])
  // GL posting (ACC-001): optional expense account per bill; the posting
  // service defaults to 5000 Cost of services when left blank.
  const [expenseAccounts, setExpenseAccounts] = useState<Account[]>([])
  const [billExpenseAccountId, setBillExpenseAccountId] = useState('')
  const [busyBillId, setBusyBillId] = useState<string | null>(null)
  const [billRef, setBillRef] = useState('')
  // The supplier's own invoice date -- it decides the bill's GST period.
  const [billDate, setBillDate] = useState(todayIso())
  // Multi-currency: the supplier's own currency, at the rate table's rate.
  const [billCur, setBillCur] = useState<CurrencyValue>({ currency: 'SGD', rate: '' })

  // ACC-004: reverse a bill's GL posting. A mirror-image voucher is posted;
  // the bill and its original entry are untouched.
  async function onUnglBill(b: SupplierInvoice) {
    const reason = window.prompt(`Reverse the GL posting of ${b.bill_number}?\n\nA mirror-image voucher is posted; nothing is deleted.\nReason (required):`)
    if (!reason?.trim()) return
    setBusyBillId(b.id); setError(null); setMessage(null)
    try {
      const r = await api.unglBill(b.id, reason.trim())
      setMessage(`${b.bill_number} reversed in the GL by ${r.reversal_voucher}.`)
      refresh()
    } catch (err) { setError(err instanceof Error ? err.message : 'UNGL failed') }
    finally { setBusyBillId(null) }
  }

  async function onRematchBill(b: SupplierInvoice) {
    setBusyBillId(b.id); setError(null); setMessage(null)
    try {
      const r = await api.rematchBill(b.id)
      setMessage(r.status === 'exception' ? `${b.bill_number} is still an exception: ${r.match_note}` : `${b.bill_number} matched and approved for payment.`)
      refresh()
    } catch (err) { setError(err instanceof Error ? err.message : 'Match again failed') }
    finally { setBusyBillId(null) }
  }

  function refresh() {
    // 2026-09-12: a supplier is a Company/Individual record flagged
    // is_supplier=true -- managed on that page, just read here.
    api.listCompanyIndividuals({ is_supplier: true }).then(setSuppliers).catch((e) => setError(e.message))
    api.listPurchaseOrders().then(setPos).catch((e) => setError(e.message))
    api.listBills().then(setBills).catch((e) => setError(e.message))
    api.apAging().then(setAging).catch((e) => setError(e.message))
    // AccountType is sent as its lowercase value, not the enum name.
    api.listAccounts({ account_type: 'expense' }).then(setExpenseAccounts).catch((e) => setError(e.message))
    api.listTaxCodes(false, 'purchase').then(setPurchaseCodes).catch((e) => setError(e.message))
  }

  useEffect(refresh, [])

  const billRate = purchaseCodes.find((t) => t.code === billTaxCode)?.rate_percent ?? 0
  // Half-up to cents, the rounding the server applies.
  const gstPreview = Math.round((parseFloat(billAmount) || 0) * billRate) / 100
  const supplierName = (id: string) => suppliers.find((s) => s.id === id)?.name ?? id.slice(0, 8)

  async function onCreateBill(e: FormEvent) {
    e.preventDefault()
    setError(null)
    setMessage(null)
    try {
      const bill = await api.createBill({
        supplier_id: billSupplier,
        purchase_order_id: billPo || null,
        supplier_invoice_no: billRef || undefined,
        invoice_date: billDate || todayIso(),
        description: billDesc,
        amount: parseFloat(billAmount),
        ...currencyPayload(billCur),
        tax_code: billTaxCode,
        expense_account_id: billExpenseAccountId || null,
      })
      setBillDesc('')
      setBillAmount('')
      setBillRef('')
      setBillPo('')
      setMessage(`${bill.bill_number}: ${bill.match_note ?? ''}`)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to record bill')
    }
  }

  const poOptionsForSupplier = pos.filter((p) => p.supplier_id === billSupplier && p.status === 'approved')

  async function onExportAging(format: string) {
    setError(null)
    if (format === 'csv') {
      downloadBlob(await api.exportApAgingCsv(), 'ap-aging.csv')
    } else {
      downloadBlob(await api.exportApAgingExcel(), 'ap-aging.xlsx')
    }
  }

  async function onExportBills(format: string) {
    setError(null)
    if (format === 'csv') {
      downloadBlob(await api.exportBillsCsv(), 'bills.csv')
    } else {
      downloadBlob(await api.exportBillsExcel(), 'bills.xlsx')
    }
  }

  return (
    <div>
      <h1>Accounts Payable</h1>
      <p className="muted">
        Money owed to suppliers. PUR-001: a purchase order above the approval limit on the supplier's
        Company / Individual file needs the owner's approval -- with no limit set, every PO does. PUR-002:
        a bill is matched to its purchase order only (2-way, no goods receipt). PUR-003: a match
        auto-approves it for payment, and is paid on the billed amount even when that differs from the
        PO (e.g. more was delivered than ordered); only a bill from another supplier, or against an
        unapproved PO, is held as an exception.
      </p>
      {error && <div className="error-banner">{error}</div>}
      {message && (
        <p className="muted" style={{ marginBottom: 12 }}>
          {message}
        </p>
      )}

      {aging && (
        <div className="card">
          <div className="filter-bar">
            <h2 style={{ margin: 0 }}>AP aging as at {formatDate(aging.as_at)}</h2>
            <ExportControl
              formats={[
                { value: 'csv', label: 'CSV' },
                { value: 'excel', label: 'Excel' },
              ]}
              onExport={onExportAging}
              onError={setError}
            />
          </div>
          <table>
            <thead>
              <tr>
                <th>Supplier</th>
                <th>Current</th>
                <th>1-30</th>
                <th>31-60</th>
                <th>61-90</th>
                <th>90+</th>
                <th>Total</th>
              </tr>
            </thead>
            <tbody>
              {aging.rows.map((r) => (
                <tr key={r.supplier_id}>
                  <td>{r.supplier_name}</td>
                  <td>{money(r.current)}</td>
                  <td>{money(r.days_1_30)}</td>
                  <td>{money(r.days_31_60)}</td>
                  <td>{money(r.days_61_90)}</td>
                  <td>{money(r.over_90)}</td>
                  <td>
                    <strong>{money(r.total)}</strong>
                  </td>
                </tr>
              ))}
              {aging.rows.length === 0 && (
                <tr>
                  <td colSpan={7} className="muted">
                    Nothing outstanding.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      )}

      <div className="card">
        <div className="filter-bar">
          <h2 style={{ margin: 0 }}>Suppliers ({suppliers.length})</h2>
        </div>
        <p className="muted">
          A supplier is a <Link to="/company-individuals">Company / Individual</Link> record ticked "Is
          Supplier" there -- add or edit suppliers (including email and phone, used by Purchase
          Order's Email/WhatsApp) on that page, not here.
        </p>
        <table>
          <thead>
            <tr>
              <th>Name</th>
              <th>Email</th>
              <th>Phone</th>
              <th>Payment terms</th>
            </tr>
          </thead>
          <tbody>
            {suppliers.map((s) => (
              <tr key={s.id}>
                <td>{s.name}</td>
                <td>{s.billing_email ?? '-'}</td>
                <td>{s.phone ?? '-'}</td>
                <td>
                  {s.payment_terms_days === null ? (
                    <span className="muted">not agreed</span>
                  ) : (
                    `Net ${s.payment_terms_days} days`
                  )}
                </td>
              </tr>
            ))}
            {suppliers.length === 0 && (
              <tr>
                <td colSpan={4} className="muted">
                  No suppliers yet -- tick "Is Supplier" on a Company/Individual record.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      <p className="muted">
        Purchase orders are raised, approved, printed, emailed and imported to AP on the{' '}
        <Link to="/purchase-orders">Purchase Order</Link> page.
      </p>

      <div className="card">
        <div className="filter-bar">
          <h2 style={{ margin: 0 }}>Bills ({bills.length})</h2>
          <ExportControl
            formats={[
              { value: 'csv', label: 'CSV' },
              { value: 'excel', label: 'Excel' },
            ]}
            onExport={onExportBills}
            onError={setError}
          />
        </div>
        <div style={{ overflowX: 'auto' }}>
          <table>
            <thead>
              <tr>
                <th>Bill</th>
                <th>Supplier</th>
                <th>Description</th>
                <th>Total</th>
                <th>Outstanding</th>
                <th>Match</th>
                <th>Status</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {bills.map((b) => (
                <Fragment key={b.id}>
                <tr>
                  <td>{b.bill_number}</td>
                  <td>{supplierName(b.supplier_id)}</td>
                  <td>
                    {b.description}
                    {b.tax_code && <div className="muted">Tax code {b.tax_code}{b.gst_rate !== null ? ` (${b.gst_rate}%)` : ''}</div>}
                    {b.match_note && <div className="muted">{b.match_note}</div>}
                  </td>
                  <td>
                    {money(b.total_amount_sgd)}
                    {b.currency_code && b.currency_code !== 'SGD' && (
                      <div className="muted small">
                        {b.currency_code} {(b.total_amount_fx ?? 0).toFixed(2)} @ {b.exchange_rate}
                      </div>
                    )}
                  </td>
                  <td>
                    <strong>{money(b.outstanding_sgd)}</strong>
                  </td>
                  <td>{b.match_status.replace('_', ' ')}</td>
                  <td>
                    <span className={`badge ${BILL_BADGE[b.status] ?? 'draft'}`}>
                      {b.status.replace('_', ' ')}
                    </span>{' '}
                    <span
                      className={`badge badge-${b.gl_status === 'posted' ? 'success' : b.gl_status === 'reversed' ? 'warning' : 'neutral'}`}
                      title={b.gl_voucher_number ? `GL voucher ${b.gl_voucher_number}` : 'Posts to the GL when the bill reaches approved'}
                    >
                      GL {b.gl_status === 'posted' ? 'posted' : b.gl_status === 'reversed' ? 'reversed' : 'not posted'}
                    </span>
                    {b.gl_status === 'posted' && (
                      <>{' '}<button className="secondary" disabled={busyBillId === b.id} onClick={() => onUnglBill(b)} title="Reverse the GL posting (needs a reason)">UNGL</button></>
                    )}
                    {b.status === 'exception' && (
                      <>{' '}<button className="secondary" disabled={busyBillId === b.id} onClick={() => onRematchBill(b)} title="Check this bill against its purchase order again">Match again</button></>
                    )}
                  </td>
                  <td>
                    <button
                      className="secondary icon-button"
                      title="Attachments & Signatures"
                      aria-label="Attachments & Signatures"
                      onClick={() => setDocPanelId(docPanelId === b.id ? null : b.id)}
                    >📎</button>
                  </td>
                </tr>
                {docPanelId === b.id && (
                  <tr>
                    <td colSpan={8} style={{ padding: 16, background: 'var(--bg-muted, #f9f9f9)' }}>
                      <DocumentAttachmentsPanel entityType="supplier_invoice" entityId={b.id} />
                      <SignaturePanel entityType="supplier_invoice" entityId={b.id} />
                    </td>
                  </tr>
                )}
                </Fragment>
              ))}
              {bills.length === 0 && (
                <tr>
                  <td colSpan={8} className="muted">
                    No bills yet.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
        <h2 style={{ marginTop: 18 }}>Record a supplier bill</h2>
        <form onSubmit={onCreateBill}>
          <div className="form-row">
            <label>Supplier</label>
            <select
              value={billSupplier}
              onChange={(e) => {
                setBillSupplier(e.target.value)
                setBillPo('')
              }}
              required
            >
              <option value="">Select...</option>
              {suppliers.map((s) => (
                <option key={s.id} value={s.id}>
                  {s.name}
                </option>
              ))}
            </select>
          </div>
          <div className="form-row">
            <label>Purchase order (optional -- enables 2-way matching)</label>
            <select value={billPo} onChange={(e) => setBillPo(e.target.value)}>
              <option value="">No PO</option>
              {poOptionsForSupplier.map((po) => (
                <option key={po.id} value={po.id}>
                  {po.po_number} ({money(po.total_amount_sgd)})
                </option>
              ))}
            </select>
          </div>
          <div className="form-row">
            <label>Supplier's invoice number</label>
            <input value={billRef} onChange={(e) => setBillRef(e.target.value)} />
          </div>
          <div className="form-row">
            <label>Supplier's invoice date</label>
            <DateInput value={billDate} onChange={(e) => setBillDate(e.target.value)} required />
          </div>
          <CurrencyFields idPrefix="bill" value={billCur} onChange={setBillCur} date={billDate} partyCurrency={suppliers.find((x) => x.id === billSupplier)?.default_currency ?? null} />
          <div className="form-row">
            <label>Expense account (optional — defaults to 5000 Cost of services)</label>
            <select value={billExpenseAccountId} onChange={(e) => setBillExpenseAccountId(e.target.value)}>
              <option value="">5000 Cost of services (default)</option>
              {expenseAccounts.map((a) => (
                <option key={a.id} value={a.id}>
                  {a.code} {a.name}
                </option>
              ))}
            </select>
          </div>
          <div className="form-row">
            <label>Description</label>
            <input value={billDesc} onChange={(e) => setBillDesc(e.target.value)} required />
          </div>
          <div className="form-row">
            <label>Amount, net of GST ({billCur.currency})</label>
            <input
              type="number"
              min="0.01"
              step="0.01"
              value={billAmount}
              onChange={(e) => setBillAmount(e.target.value)}
              required
            />
          </div>
          <div className="form-row">
            <label>Tax code</label>
            <select value={billTaxCode} onChange={(e) => setBillTaxCode(e.target.value)} required>
              {purchaseCodes.map((t) => (
                <option key={t.id} value={t.code}>
                  {t.code} — {t.name} ({t.rate_percent}%)
                </option>
              ))}
            </select>
            {billAmount !== '' && (
              <p className="muted" style={{ margin: '4px 0 0' }}>
                GST {money(gstPreview)} · total {money((parseFloat(billAmount) || 0) + gstPreview)} — worked out from the tax code, as on a
                Sales Invoice.
              </p>
            )}
          </div>
          <button type="submit" disabled={!billSupplier}>
            Record bill
          </button>
        </form>
      </div>

      <p className="muted">
        Payments to suppliers are recorded and allocated on the <Link to="/payment-voucher">Payment Voucher</Link> page.
      </p>
    </div>
  )
}
