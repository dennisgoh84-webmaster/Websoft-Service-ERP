// Credit Notes (BILL-003) -- raised from a row on the Sales Invoice page,
// approved here. Approving one issues it: it takes a CN number, posts the
// invoice's entry in reverse to the General Ledger and comes off what the
// invoice still owes. Who may approve (Finance, the Sales Manager or the
// owner within the customer's limit; the owner above it) is the server's
// call -- this page only shows the buttons it says the viewer may use.
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import ExportControl from '../components/ExportControl'
import { PrintIcon } from '../components/DocActionIcons'
import { api, downloadBlob, type BankAccount, type CreditNote, type Invoice } from '../lib/api'
import DateInput from '../components/DateInput'
import { formatMoney as money, formatDate, todayIso } from '../lib/format'

const STATUS_LABELS: Record<CreditNote['status'], string> = {
  pending_approval: 'Pending approval',
  issued: 'Issued',
  rejected: 'Rejected',
  withdrawn: 'Withdrawn',
}
const STATUS_BADGE: Record<CreditNote['status'], string> = {
  pending_approval: 'draft',
  issued: 'active',
  rejected: 'expired',
  withdrawn: 'expired',
}

export default function CreditNotesPage() {
  const [notes, setNotes] = useState<CreditNote[]>([])
  const [status, setStatus] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [message, setMessage] = useState<string | null>(null)
  const [busyId, setBusyId] = useState<string | null>(null)
  const [openId, setOpenId] = useState<string | null>(null)

  function refresh() {
    api
      .listCreditNotes({ status: status || undefined })
      .then(setNotes)
      .catch((err) => setError(err instanceof Error ? err.message : 'Failed to load credit notes'))
  }

  useEffect(refresh, [status])

  async function act(note: CreditNote, run: () => Promise<CreditNote>, done: (n: CreditNote) => string) {
    setError(null)
    setMessage(null)
    setBusyId(note.id)
    try {
      setMessage(done(await run()))
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed')
    } finally {
      setBusyId(null)
    }
  }

  function onApprove(note: CreditNote) {
    if (!window.confirm(`Approve and issue this ${money(note.total_amount_sgd)} credit note on ${note.invoice_number}? It posts to the General Ledger.`)) return
    act(
      note,
      () => api.approveCreditNote(note.id),
      (n) => `${n.credit_note_number} issued -- ${money(n.total_amount_sgd)} off ${n.invoice_number}, posted as ${n.gl_voucher_number ?? n.credit_note_number}.`,
    )
  }

  function onReject(note: CreditNote) {
    const reason = window.prompt(`Why is the credit note on ${note.invoice_number} rejected?`)
    if (!reason || !reason.trim()) return
    act(note, () => api.rejectCreditNote(note.id, reason.trim()), (n) => `Credit note on ${n.invoice_number} rejected. It is kept, and changes nothing.`)
  }

  function onWithdraw(note: CreditNote) {
    if (!window.confirm(`Withdraw the credit note on ${note.invoice_number}?`)) return
    act(note, () => api.withdrawCreditNote(note.id), (n) => `Credit note on ${n.invoice_number} withdrawn. It is kept, and changes nothing.`)
  }

  async function onExport(format: string) {
    const ext = format === 'excel' ? 'xlsx' : 'csv'
    downloadBlob(await api.exportCreditNotes({ status: status || undefined }, ext), `credit-notes.${ext}`)
  }

  const pending = notes.filter((n) => n.status === 'pending_approval')
  const issuedTotal = notes.filter((n) => n.status === 'issued').reduce((sum, n) => sum + n.total_amount_sgd, 0)

  return (
    <div>
      <h1>Credit Notes</h1>
      <p className="muted">
        Raise a credit note from its invoice on the <Link to="/invoices">Sales Invoice</Link> page. Finance, the Sales Manager or the
        owner approve it within the Company / Individual&apos;s credit note limit; above that limit, or while none is set, only the owner
        does (BILL-003). Approving issues it: it is numbered, its GST and ledger entry are reversed, and it comes off what the invoice owes.
      </p>
      {error && <div className="error-banner">{error}</div>}
      {message && (
        <p className="muted" style={{ marginBottom: 12 }}>
          {message}
        </p>
      )}

      <div className="card">
        <div className="filter-bar">
          <label>
            Status{' '}
            <select value={status} onChange={(e) => setStatus(e.target.value)}>
              <option value="">All</option>
              {Object.entries(STATUS_LABELS).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </select>
          </label>
          <ExportControl
            formats={[
              { value: 'csv', label: 'CSV' },
              { value: 'excel', label: 'Excel' },
            ]}
            onExport={onExport}
            onError={setError}
          />
        </div>

        <h2>Credit notes ({notes.length})</h2>
        <p className="muted">
          {pending.length} waiting for approval &middot; {money(issuedTotal)} issued
        </p>
        <div style={{ overflowX: 'auto' }}>
          <table>
            <thead>
              <tr>
                <th>CN no.</th>
                <th>Invoice</th>
                <th>Company / Individual</th>
                <th>Reason</th>
                <th>Net</th>
                <th>GST</th>
                <th>Total</th>
                <th>Status</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {notes.length === 0 && (
                <tr>
                  <td colSpan={9} className="muted">
                    No credit notes.
                  </td>
                </tr>
              )}
              {notes.map((n) => (
                <tr key={n.id}>
                  <td style={{ whiteSpace: 'nowrap' }}>
                    {n.credit_note_number ?? '-'}
                    <div className="muted">{formatDate(n.issued_at ?? n.raised_at)}</div>
                  </td>
                  <td style={{ whiteSpace: 'nowrap' }}>{n.invoice_number}</td>
                  <td>{n.customer_name}</td>
                  <td>
                    {n.reason}
                    <div className="muted">Raised by {n.raised_by ?? '-'}</div>
                    {n.decision_note && <div className="muted">Rejected: {n.decision_note}</div>}
                  </td>
                  <td>{money(n.amount_sgd)}</td>
                  <td>
                    {money(n.gst_amount_sgd)}
                    <div className="muted">
                      {n.tax_code} {n.gst_rate ?? 0}%
                    </div>
                  </td>
                  <td>
                    {money(n.total_amount_sgd)}
                    {n.currency_code && n.currency_code !== 'SGD' && (
                      <div className="muted small">
                        {n.currency_code} {(n.total_amount_fx ?? 0).toFixed(2)}
                      </div>
                    )}
                    {(n.unapplied_sgd ?? 0) > 0 && (
                      <div>
                        <span className="badge badge-warning">On account {money(n.unapplied_sgd ?? 0)}</span>
                      </div>
                    )}
                  </td>
                  <td>
                    <span className={`badge ${STATUS_BADGE[n.status]}`}>{STATUS_LABELS[n.status]}</span>
                    {n.status === 'pending_approval' && (
                      <div className="muted">
                        {n.needs_owner
                          ? n.credit_note_limit_sgd === null
                            ? 'Owner approves (no limit set)'
                            : `Owner approves (above ${money(n.credit_note_limit_sgd)})`
                          : `Within ${money(n.credit_note_limit_sgd ?? 0)} limit`}
                      </div>
                    )}
                    {n.status === 'issued' && <div className="muted">GL {n.gl_voucher_number ?? 'not posted'}</div>}
                    {n.decided_by && n.status !== 'pending_approval' && <div className="muted">by {n.decided_by}</div>}
                  </td>
                  <td style={{ whiteSpace: 'nowrap' }}>
                    {n.can_approve && (
                      <>
                        <button onClick={() => onApprove(n)} disabled={busyId === n.id}>
                          Approve
                        </button>{' '}
                        <button className="secondary" onClick={() => onReject(n)} disabled={busyId === n.id}>
                          Reject
                        </button>{' '}
                      </>
                    )}
                    {n.status === 'pending_approval' && (
                      <button className="secondary" onClick={() => onWithdraw(n)} disabled={busyId === n.id}>
                        Withdraw
                      </button>
                    )}
                    {n.status === 'issued' && (
                      <Link to={`/credit-notes/${n.id}/print`} className="secondary icon-button" title="Print" aria-label="Print">
                        <PrintIcon />
                      </Link>
                    )}
                    {n.status === 'issued' && (n.unapplied_sgd ?? 0) > 0 && (
                      <button className="secondary" onClick={() => setOpenId(openId === n.id ? null : n.id)}>
                        Use credit
                      </button>
                    )}
                  </td>
                </tr>
              ))}
              {notes
                .filter((n) => n.id === openId && (n.unapplied_sgd ?? 0) > 0)
                .map((n) => (
                  <tr key={`${n.id}-credit`}>
                    <td colSpan={9}>
                      <CreditOnAccount
                        note={n}
                        onDone={(msg) => {
                          // Stays open while credit is left (part used); it
                          // drops away by itself once all of it is used.
                          setMessage(msg)
                          refresh()
                        }}
                        onError={setError}
                      />
                    </td>
                  </tr>
                ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  )
}

/**
 * Credit left on the customer's account -- a paid invoice credited
 * (2026-09-26): Finance sets it against another of their invoices, or
 * refunds it with a Payment Voucher.
 */
function CreditOnAccount({ note, onDone, onError }: { note: CreditNote; onDone: (msg: string) => void; onError: (e: string) => void }) {
  const code = note.currency_code ?? 'SGD'
  const left = note.unapplied_fx ?? note.unapplied_sgd ?? 0
  const [invoices, setInvoices] = useState<Invoice[]>([])
  const [banks, setBanks] = useState<BankAccount[]>([])
  const [invoiceId, setInvoiceId] = useState('')
  const [amount, setAmount] = useState(String(left.toFixed(2)))
  const [bankId, setBankId] = useState('')
  const [date, setDate] = useState(todayIso())
  const [busy, setBusy] = useState(false)

  // After part of it is used, the box offers what is left.
  useEffect(() => setAmount(String(left.toFixed(2))), [left])

  useEffect(() => {
    api
      .listInvoices({ customer_id: note.customer_id })
      .then((rows) => setInvoices(rows.filter((i) => i.outstanding_sgd > 0 && (i.currency_code ?? 'SGD') === code)))
      .catch(() => setInvoices([]))
    api
      .listBankAccounts()
      .then((rows) => {
        const usable = rows.filter((b) => b.currency_code === 'SGD' || b.currency_code === code)
        setBanks(usable)
        setBankId(usable[0]?.id ?? '')
      })
      .catch(() => setBanks([]))
  }, [note.customer_id, code])

  async function run(fn: () => Promise<string>) {
    setBusy(true)
    try {
      onDone(await fn())
    } catch (err) {
      onError(err instanceof Error ? err.message : 'Failed')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div style={{ display: 'flex', gap: 24, flexWrap: 'wrap', padding: '8px 0' }} data-testid="credit-on-account">
      <div>
        <strong>
          {code} {left.toFixed(2)} of {note.credit_note_number} is on {note.customer_name}&rsquo;s account
        </strong>
        <div className="form-row">
          <label htmlFor={`cn-apply-invoice-${note.id}`}>Set against invoice</label>
          <select id={`cn-apply-invoice-${note.id}`} value={invoiceId} onChange={(e) => setInvoiceId(e.target.value)}>
            <option value="">{invoices.length ? 'Choose...' : 'No open invoice in ' + code}</option>
            {invoices.map((i) => (
              <option key={i.id} value={i.id}>
                {i.invoice_number} -- owes {code === 'SGD' ? money(i.outstanding_sgd) : `${code} ${(i.outstanding_fx ?? 0).toFixed(2)}`}
              </option>
            ))}
          </select>
        </div>
        <div className="form-row">
          <label htmlFor={`cn-apply-amount-${note.id}`}>Amount ({code})</label>
          <input id={`cn-apply-amount-${note.id}`} type="number" min="0.01" step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} />
        </div>
        <button
          disabled={busy || !invoiceId || !(parseFloat(amount) > 0)}
          onClick={() => run(async () => {
            await api.applyCreditNote(note.id, invoiceId, parseFloat(amount))
            return `${code} ${parseFloat(amount).toFixed(2)} of ${note.credit_note_number} set against the invoice.`
          })}
        >
          Apply credit
        </button>
      </div>
      <div>
        <strong>Or refund it</strong>
        <div className="form-row">
          <label htmlFor={`cn-refund-bank-${note.id}`}>Pay from</label>
          <select id={`cn-refund-bank-${note.id}`} value={bankId} onChange={(e) => setBankId(e.target.value)}>
            {banks.map((b) => (
              <option key={b.id} value={b.id}>
                {b.bank_name} -- {b.account_number} ({b.currency_code})
              </option>
            ))}
          </select>
        </div>
        <div className="form-row">
          <label htmlFor={`cn-refund-date-${note.id}`}>Payment date</label>
          <DateInput id={`cn-refund-date-${note.id}`} value={date} onChange={(e) => setDate(e.target.value)} required />
        </div>
        <button
          className="secondary"
          disabled={busy || !bankId || !date}
          onClick={() => {
            if (!window.confirm(`Refund ${code} ${(parseFloat(amount) || left).toFixed(2)} to ${note.customer_name} with a Payment Voucher?`)) return
            void run(async () => {
              const r = await api.refundCreditNote(note.id, { bank_account_id: bankId, payment_date: date, amount: parseFloat(amount) || undefined })
              return `Refund ${r.refund_voucher_number} raised. Bank it on Payment Voucher once paid.`
            })
          }}
        >
          Refund with a Payment Voucher
        </button>
      </div>
    </div>
  )
}
