import { Fragment, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { EmailIcon, PrintIcon, WhatsAppIcon } from '../components/DocActionIcons'
import DocumentAttachmentsPanel from '../components/DocumentAttachmentsPanel'
import ExportControl from '../components/ExportControl'
import SignaturePanel from '../components/SignaturePanel'
import { api, downloadBlob, type AgingReport, type CompanyIndividual, type CompanyIndividualStatement, type Invoice, type InvoiceStatus } from '../lib/api'
import { formatMoney as money } from '../lib/format'

const STATUS_BADGE: Record<InvoiceStatus, string> = {
  outstanding: 'draft',
  partially_paid: 'exceeded',
  paid: 'active',
  written_off: 'expired',
}

export default function InvoicesPage() {
  const [invoices, setInvoices] = useState<Invoice[]>([])
  const [customers, setCustomers] = useState<CompanyIndividual[]>([])
  const [aging, setAging] = useState<AgingReport | null>(null)
  const [statement, setStatement] = useState<CompanyIndividualStatement | null>(null)
  const [filterCompanyIndividual, setFilterCompanyIndividual] = useState('')
  const [filterType, setFilterType] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [message, setMessage] = useState<string | null>(null)
  const [statementBusy, setStatementBusy] = useState(false)
  const [busyInvoiceId, setBusyInvoiceId] = useState<string | null>(null)
  const [docPanelId, setDocPanelId] = useState<string | null>(null)

  function refresh() {
    api.listInvoices({ customer_id: filterCompanyIndividual || undefined }).then(setInvoices)
    api.listCompanyIndividuals().then(setCustomers)
    api.arAging().then(setAging).catch((e) => setError(e.message))
  }

  useEffect(refresh, [filterCompanyIndividual])

  const customerName = (id: string) => customers.find((c) => c.id === id)?.name ?? id.slice(0, 8)
  const visible = filterType ? invoices.filter((i) => i.invoice_type === filterType) : invoices
  const net = visible.reduce((sum, i) => sum + i.amount_sgd, 0)
  const gst = visible.reduce((sum, i) => sum + i.gst_amount_sgd, 0)
  const total = visible.reduce((sum, i) => sum + i.total_amount_sgd, 0)
  const outstanding = visible.reduce((sum, i) => sum + i.outstanding_sgd, 0)

  function openStatement(customerIdToShow: string) {
    api.customerStatement(customerIdToShow).then(setStatement).catch((e) => setError(e.message))
  }

  async function onDownloadStatement() {
    if (!statement) return
    setError(null)
    downloadBlob(
      await api.exportCompanyIndividualStatementDocx(statement.customer_id),
      `Statement-${statement.customer_name}-${statement.as_at}.docx`,
    )
  }

  async function onEmailStatement() {
    if (!statement) return
    setError(null)
    setMessage(null)
    setStatementBusy(true)
    try {
      const result = await api.emailCompanyIndividualStatement(statement.customer_id)
      setMessage(`Statement for ${statement.customer_name} emailed to ${result.to}.`)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to email statement')
    } finally {
      setStatementBusy(false)
    }
  }

  function onWhatsAppStatement() {
    if (!statement) return
    setError(null)
    const customer = customers.find((c) => c.id === statement.customer_id)
    if (!customer?.phone) {
      setError(`${statement.customer_name} has no phone number on file -- add one on the Company/Individual page first.`)
      return
    }
    const text = `Statement of Accounts as at ${statement.as_at}, total outstanding ${money(statement.total_outstanding_sgd)}. PDF to follow.`
    window.open(`https://wa.me/${customer.phone.replace(/[^0-9]/g, '')}?text=${encodeURIComponent(text)}`, '_blank')
  }

  async function onExport(format: string) {
    setError(null)
    if (format === 'csv') {
      downloadBlob(await api.exportInvoicesCsv({ customer_id: filterCompanyIndividual || undefined }), 'invoices.csv')
    } else {
      downloadBlob(await api.exportInvoicesExcel({ customer_id: filterCompanyIndividual || undefined }), 'invoices.xlsx')
    }
  }

  async function onExportAging(format: string) {
    setError(null)
    if (format === 'csv') {
      downloadBlob(await api.exportArAgingCsv(), 'ar-aging.csv')
    } else {
      downloadBlob(await api.exportArAgingExcel(), 'ar-aging.xlsx')
    }
  }

  async function onWriteOff(invoice: Invoice) {
    const reason = window.prompt(
      `Write off ${money(invoice.outstanding_sgd)} on ${invoice.invoice_number}?\n\n` +
        'A reason is required and is recorded in the Event Logs (AR-002).',
    )
    if (reason === null) return
    setError(null)
    setMessage(null)
    try {
      await api.writeOffInvoice(invoice.id, reason)
      setMessage(`${invoice.invoice_number} written off.`)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to write off invoice')
    }
  }

  async function onToggleDispute(invoice: Invoice) {
    setError(null)
    setMessage(null)
    try {
      if (invoice.is_disputed) {
        await api.flagInvoiceDispute(invoice.id, false)
      } else {
        const note = window.prompt('What is disputed? (AR-003: collections continue regardless)')
        if (note === null) return
        await api.flagInvoiceDispute(invoice.id, true, note)
      }
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to update dispute flag')
    }
  }

  async function onEmailInvoice(invoice: Invoice) {
    setError(null)
    setMessage(null)
    setBusyInvoiceId(invoice.id)
    try {
      const result = await api.emailInvoice(invoice.id)
      setMessage(`${invoice.invoice_number} emailed to ${result.to}.`)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to email invoice')
    } finally {
      setBusyInvoiceId(null)
    }
  }

  function onWhatsAppInvoice(invoice: Invoice) {
    setError(null)
    const customer = customers.find((c) => c.id === invoice.customer_id)
    if (!customer?.phone) {
      setError(`${customerName(invoice.customer_id)} has no phone number on file -- add one on the Company/Individual page first.`)
      return
    }
    const text = `Invoice ${invoice.invoice_number}, ${money(invoice.total_amount_sgd)}. PDF to follow.`
    window.open(`https://wa.me/${customer.phone.replace(/[^0-9]/g, '')}?text=${encodeURIComponent(text)}`, '_blank')
  }

  return (
    <div>
      <h1>Invoices</h1>
      <p className="muted">
        Tax invoices. BILL-002: no approval required, issued directly. BILL-005: revenue
        recognized on invoice. GST is charged at the company's standard rate; the net column is the
        revenue figure, since GST collected is owed to IRAS rather than earned. AR-002: write-offs
        need a reason, and the owner's approval above the threshold set in Company Setup. AR-003: a
        disputed invoice is flagged but keeps aging normally -- nothing is put on hold.
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
            <h2 style={{ margin: 0 }}>Aging as at {aging.as_at}</h2>
            <ExportControl
              formats={[
                { value: 'csv', label: 'CSV' },
                { value: 'excel', label: 'Excel' },
              ]}
              onExport={onExportAging}
              onError={setError}
            />
          </div>
          <div className="stat-grid">
            <div className="card stat-tile">
              <div className="stat-value stat-value-text">{money(aging.current)}</div>
              <div className="stat-label">Current / not yet due</div>
            </div>
            <div className="card stat-tile">
              <div className="stat-value stat-value-text">{money(aging.days_1_30)}</div>
              <div className="stat-label">1-30 days overdue</div>
            </div>
            <div className="card stat-tile">
              <div className="stat-value stat-value-text">{money(aging.days_31_60)}</div>
              <div className="stat-label">31-60 days</div>
            </div>
            <div className="card stat-tile">
              <div className="stat-value stat-value-text">{money(aging.days_61_90)}</div>
              <div className="stat-label">61-90 days</div>
            </div>
            <div className="card stat-tile">
              <div className="stat-value stat-value-text">{money(aging.over_90)}</div>
              <div className="stat-label">Over 90 days</div>
            </div>
            <div className="card stat-tile">
              <div className="stat-value stat-value-text">{money(aging.total)}</div>
              <div className="stat-label">Total outstanding (SGD)</div>
            </div>
          </div>

          <table>
            <thead>
              <tr>
                <th>Company / Individual</th>
                <th>Current</th>
                <th>1-30</th>
                <th>31-60</th>
                <th>61-90</th>
                <th>90+</th>
                <th>Total</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {aging.rows.map((r) => (
                <tr key={r.customer_id}>
                  <td>{r.customer_name}</td>
                  <td>{money(r.current)}</td>
                  <td>{money(r.days_1_30)}</td>
                  <td>{money(r.days_31_60)}</td>
                  <td>{money(r.days_61_90)}</td>
                  <td>{money(r.over_90)}</td>
                  <td>
                    <strong>{money(r.total)}</strong>
                  </td>
                  <td>
                    <button className="secondary" onClick={() => openStatement(r.customer_id)}>
                      Statement
                    </button>
                  </td>
                </tr>
              ))}
              {aging.rows.length === 0 && (
                <tr>
                  <td colSpan={8} className="muted">
                    Nothing outstanding.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      )}

      {statement && (
        <div className="card">
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 6 }}>
            <h2>Statement -- {statement.customer_name}</h2>
            <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
              <button className="secondary" onClick={onDownloadStatement}>
                Download (Word)
              </button>
              <button
                className="secondary icon-button"
                disabled={statementBusy || !customers.find((c) => c.id === statement.customer_id)?.billing_email}
                title={
                  customers.find((c) => c.id === statement.customer_id)?.billing_email
                    ? 'Email'
                    : 'Add an email on the Company/Individual page first'
                }
                aria-label="Email"
                onClick={onEmailStatement}
              >
                <EmailIcon />
              </button>
              <button
                className="secondary icon-button"
                disabled={statementBusy || !customers.find((c) => c.id === statement.customer_id)?.phone}
                title={
                  customers.find((c) => c.id === statement.customer_id)?.phone
                    ? 'WhatsApp'
                    : 'Add a phone number on the Company/Individual page first'
                }
                aria-label="WhatsApp"
                onClick={onWhatsAppStatement}
              >
                <WhatsAppIcon />
              </button>
              <button className="secondary" onClick={() => setStatement(null)}>
                Close
              </button>
            </div>
          </div>
          <p className="muted">
            As at {statement.as_at} &middot;{' '}
            {statement.payment_terms_days === null
              ? 'no payment terms agreed'
              : `Net ${statement.payment_terms_days} days`}{' '}
            &middot; outstanding <strong>{money(statement.total_outstanding_sgd)}</strong>
            {statement.unallocated_credit_sgd > 0 && (
              <> &middot; {money(statement.unallocated_credit_sgd)} unallocated on account</>
            )}
          </p>
          <table>
            <thead>
              <tr>
                <th>Invoice</th>
                <th>Issued</th>
                <th>Due</th>
                <th>Total</th>
                <th>Paid</th>
                <th>Outstanding</th>
                <th>Overdue</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              {statement.lines.map((l) => (
                <tr key={l.invoice_id}>
                  <td>{l.invoice_number}</td>
                  <td>{l.issued_on}</td>
                  <td>{l.due_date ?? <span className="muted">-</span>}</td>
                  <td>{money(l.total_amount_sgd)}</td>
                  <td>{money(l.amount_paid_sgd)}</td>
                  <td>
                    <strong>{money(l.outstanding_sgd)}</strong>
                  </td>
                  <td>{l.days_overdue > 0 ? `${l.days_overdue} days` : '-'}</td>
                  <td>
                    {l.status.replace('_', ' ')}
                    {l.is_disputed && (
                      <span className="badge exceeded" style={{ marginLeft: 6 }}>
                        disputed
                      </span>
                    )}
                  </td>
                </tr>
              ))}
              {statement.lines.length === 0 && (
                <tr>
                  <td colSpan={8} className="muted">
                    Nothing outstanding for this customer.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      )}

      <div className="card">
        <div className="filter-bar">
          <div className="form-row" style={{ margin: 0 }}>
            <label>Company / Individual</label>
            <select value={filterCompanyIndividual} onChange={(e) => setFilterCompanyIndividual(e.target.value)}>
              <option value="">All</option>
              {customers.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </select>
          </div>
          <div className="form-row" style={{ margin: 0 }}>
            <label>Type</label>
            <select value={filterType} onChange={(e) => setFilterType(e.target.value)}>
              <option value="">All</option>
              <option value="contract_annual">Contract (annual)</option>
              <option value="excess_usage">Excess usage</option>
            </select>
          </div>
          <button
            type="button"
            className="secondary"
            onClick={() => {
              setFilterCompanyIndividual('')
              setFilterType('')
            }}
          >
            Reset filters
          </button>
          <ExportControl
            formats={[
              { value: 'csv', label: 'CSV' },
              { value: 'excel', label: 'Excel' },
            ]}
            onExport={onExport}
            onError={setError}
          />
        </div>

        <h2>Invoices ({visible.length})</h2>
        <p className="muted">
          Net {money(net)} + GST {money(gst)} = {money(total)} billed
          &middot; <strong>{money(outstanding)} outstanding</strong>
        </p>
        <div style={{ overflowX: 'auto' }}>
        <table>
          <thead>
            <tr>
              <th>Invoice no.</th>
              <th>Company / Individual</th>
              <th>Description</th>
              <th>Net</th>
              <th>GST</th>
              <th>Total</th>
              <th>Outstanding</th>
              <th>Due</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {visible.map((inv) => (
              <Fragment key={inv.id}>
              <tr>
                <td style={{ whiteSpace: 'nowrap' }}>
                  {inv.invoice_number}
                  <div className="muted">{new Date(inv.issued_at).toLocaleDateString()}</div>
                </td>
                <td>{customerName(inv.customer_id)}</td>
                <td>
                  {inv.description}
                  <div className="muted">{inv.invoice_type}</div>
                </td>
                <td>{money(inv.amount_sgd)}</td>
                <td>
                  {money(inv.gst_amount_sgd)}
                  <div className="muted">
                    {inv.tax_code} {inv.gst_rate}%
                  </div>
                </td>
                <td>
                  <strong>{money(inv.total_amount_sgd)}</strong>
                </td>
                <td>{money(inv.outstanding_sgd)}</td>
                <td style={{ whiteSpace: 'nowrap' }}>
                  {inv.due_date ?? <span className="muted">no terms set</span>}
                </td>
                <td>
                  <span className={`badge ${STATUS_BADGE[inv.status] ?? 'draft'}`}>
                    {inv.status.replace('_', ' ')}
                  </span>
                  {inv.is_disputed && (
                    <div>
                      <span className="badge exceeded">disputed</span>
                    </div>
                  )}
                </td>
                <td style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                  <button
                    className="secondary icon-button"
                    title="Attachments & Signatures"
                    aria-label="Attachments & Signatures"
                    onClick={() => setDocPanelId(docPanelId === inv.id ? null : inv.id)}
                  >
                    📎
                  </button>
                  <Link to={`/invoices/${inv.id}/print`} className="secondary icon-button" title="Print" aria-label="Print">
                    <PrintIcon />
                  </Link>
                  <button
                    className="secondary icon-button"
                    disabled={busyInvoiceId === inv.id || !customers.find((c) => c.id === inv.customer_id)?.billing_email}
                    title={
                      customers.find((c) => c.id === inv.customer_id)?.billing_email
                        ? 'Email'
                        : 'Add an email on the Company/Individual page first'
                    }
                    aria-label="Email"
                    onClick={() => onEmailInvoice(inv)}
                  >
                    <EmailIcon />
                  </button>
                  <button
                    className="secondary icon-button"
                    disabled={busyInvoiceId === inv.id || !customers.find((c) => c.id === inv.customer_id)?.phone}
                    title={
                      customers.find((c) => c.id === inv.customer_id)?.phone
                        ? 'WhatsApp'
                        : 'Add a phone number on the Company/Individual page first'
                    }
                    aria-label="WhatsApp"
                    onClick={() => onWhatsAppInvoice(inv)}
                  >
                    <WhatsAppIcon />
                  </button>
                  <button className="secondary" onClick={() => onToggleDispute(inv)}>
                    {inv.is_disputed ? 'Clear dispute' : 'Flag dispute'}
                  </button>
                  {inv.outstanding_sgd > 0 && (
                    <button className="secondary" onClick={() => onWriteOff(inv)}>
                      Write off
                    </button>
                  )}
                </td>
              </tr>
              {docPanelId === inv.id && (
                <tr>
                  <td colSpan={10} style={{ padding: 16, background: 'var(--bg-muted, #f9f9f9)' }}>
                    <DocumentAttachmentsPanel entityType="invoice" entityId={inv.id} />
                    <SignaturePanel entityType="invoice" entityId={inv.id} />
                  </td>
                </tr>
              )}
              </Fragment>
            ))}
            {visible.length === 0 && (
              <tr>
                <td colSpan={10} className="muted">
                  No invoices match these filters.
                </td>
              </tr>
            )}
          </tbody>
        </table>
        </div>
      </div>
    </div>
  )
}
