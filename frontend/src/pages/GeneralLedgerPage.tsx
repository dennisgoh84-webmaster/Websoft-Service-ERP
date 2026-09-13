import { Fragment, useEffect, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import DocumentAttachmentsPanel from '../components/DocumentAttachmentsPanel'
import ExportControl from '../components/ExportControl'
import SignaturePanel from '../components/SignaturePanel'
import { api, downloadBlob, type Account, type JournalEntry, type TrialBalance } from '../lib/api'
import { formatMoney as money } from '../lib/format'

interface DraftLine {
  accountId: string
  debit: string
  credit: string
  description: string
}

function emptyLine(): DraftLine {
  return { accountId: '', debit: '', credit: '', description: '' }
}

export default function GeneralLedgerPage() {
  const [vouchers, setVouchers] = useState<JournalEntry[]>([])
  const [accounts, setAccounts] = useState<Account[]>([])
  const [trialBalance, setTrialBalance] = useState<TrialBalance | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [message, setMessage] = useState<string | null>(null)

  const [entryDate, setEntryDate] = useState(new Date().toISOString().slice(0, 10))
  const [narration, setNarration] = useState('')
  const [lines, setLines] = useState<DraftLine[]>([emptyLine(), emptyLine()])
  const [saving, setSaving] = useState(false)
  const [docPanelId, setDocPanelId] = useState<string | null>(null)

  function refresh() {
    api.listVouchers().then(setVouchers).catch((e) => setError(e.message))
    api.listAccounts().then(setAccounts).catch((e) => setError(e.message))
    api.trialBalance().then(setTrialBalance).catch((e) => setError(e.message))
  }

  useEffect(refresh, [])

  const draftDebit = lines.reduce((sum, l) => sum + (parseFloat(l.debit) || 0), 0)
  const draftCredit = lines.reduce((sum, l) => sum + (parseFloat(l.credit) || 0), 0)
  const draftBalanced = draftDebit > 0 && Math.abs(draftDebit - draftCredit) < 0.005

  function updateLine(index: number, patch: Partial<DraftLine>) {
    setLines((prev) => prev.map((l, i) => (i === index ? { ...l, ...patch } : l)))
  }

  function addLine() {
    setLines((prev) => [...prev, emptyLine()])
  }

  function removeLine(index: number) {
    setLines((prev) => prev.filter((_, i) => i !== index))
  }

  async function submitVoucher(e: FormEvent, post: boolean) {
    e.preventDefault()
    setError(null)
    setMessage(null)
    setSaving(true)
    try {
      const entry = await api.createJournalVoucher({
        entry_date: entryDate,
        narration,
        post,
        lines: lines
          .filter((l) => l.accountId && (l.debit || l.credit))
          .map((l) => ({
            account_id: l.accountId,
            debit_sgd: l.debit ? parseFloat(l.debit) : 0,
            credit_sgd: l.credit ? parseFloat(l.credit) : 0,
            description: l.description || undefined,
          })),
      })
      setMessage(`${entry.voucher_number} ${post ? 'posted' : 'saved as draft'}.`)
      setNarration('')
      setLines([emptyLine(), emptyLine()])
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to create voucher')
    } finally {
      setSaving(false)
    }
  }

  async function onPost(voucher: JournalEntry) {
    setError(null)
    try {
      await api.postVoucher(voucher.id)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to post voucher')
    }
  }

  async function onExportTrialBalance(format: string) {
    setError(null)
    if (format === 'csv') {
      downloadBlob(await api.exportTrialBalanceCsv(), 'trial-balance.csv')
    } else {
      downloadBlob(await api.exportTrialBalanceExcel(), 'trial-balance.xlsx')
    }
  }

  async function onExportVouchers(format: string) {
    setError(null)
    if (format === 'csv') {
      downloadBlob(await api.exportVouchersCsv(), 'vouchers.csv')
    } else {
      downloadBlob(await api.exportVouchersExcel(), 'vouchers.xlsx')
    }
  }

  async function onReverse(voucher: JournalEntry) {
    const reason = window.prompt(`Reverse ${voucher.voucher_number}? Give a reason:`)
    if (!reason) return
    setError(null)
    try {
      const reversal = await api.reverseVoucher(voucher.id, reason)
      setMessage(`Reversed by ${reversal.voucher_number}.`)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to reverse voucher')
    }
  }

  return (
    <div>
      <h1>General Ledger</h1>
      <p className="muted">
        Journal Vouchers -- the accounts moved are chosen by whoever raises the voucher, since which
        account each business transaction (a sales invoice, a receipt) should post to automatically
        has not been decided yet. A voucher cannot be posted unless its debits equal its credits.
        Once posted it is permanent; a correction is made by reversing it, never by editing it.
      </p>
      {error && <div className="error-banner">{error}</div>}
      {message && (
        <p className="muted" style={{ marginBottom: 12 }}>
          {message}
        </p>
      )}

      {trialBalance && (
        <div className="card">
          <div className="filter-bar">
            <h2 style={{ margin: 0 }}>
              Trial balance{' '}
              <span className={`badge ${trialBalance.is_balanced ? 'active' : 'exceeded'}`}>
                {trialBalance.is_balanced ? 'balanced' : 'OUT OF BALANCE'}
              </span>
            </h2>
            <ExportControl
              formats={[
                { value: 'csv', label: 'CSV' },
                { value: 'excel', label: 'Excel' },
              ]}
              onExport={onExportTrialBalance}
              onError={setError}
            />
          </div>
          <table>
            <thead>
              <tr>
                <th>Code</th>
                <th>Account</th>
                <th>Type</th>
                <th>Debit</th>
                <th>Credit</th>
                <th>Balance</th>
              </tr>
            </thead>
            <tbody>
              {trialBalance.rows.map((r) => (
                <tr key={r.account_id}>
                  <td>{r.code}</td>
                  <td>
                    <Link to={`/gl-transactions?account=${r.account_id}`}>{r.name}</Link>
                  </td>
                  <td>{r.account_type}</td>
                  <td>{money(r.debit_sgd)}</td>
                  <td>{money(r.credit_sgd)}</td>
                  <td>{money(r.balance_sgd)}</td>
                </tr>
              ))}
              {trialBalance.rows.length === 0 && (
                <tr>
                  <td colSpan={6} className="muted">
                    Nothing posted yet.
                  </td>
                </tr>
              )}
            </tbody>
            {trialBalance.rows.length > 0 && (
              <tfoot>
                <tr>
                  <td colSpan={3}>
                    <strong>Total</strong>
                  </td>
                  <td>
                    <strong>{money(trialBalance.total_debit)}</strong>
                  </td>
                  <td>
                    <strong>{money(trialBalance.total_credit)}</strong>
                  </td>
                  <td></td>
                </tr>
              </tfoot>
            )}
          </table>
        </div>
      )}

      <div className="card">
        <h2>Raise a Journal Voucher</h2>
        <form onSubmit={(e) => submitVoucher(e, false)}>
          <div className="form-row">
            <label>Date</label>
            <input type="date" value={entryDate} onChange={(e) => setEntryDate(e.target.value)} required />
          </div>
          <div className="form-row">
            <label>Narration</label>
            <input
              value={narration}
              onChange={(e) => setNarration(e.target.value)}
              placeholder="What this entry records"
              required
            />
          </div>

          <table>
            <thead>
              <tr>
                <th>Account</th>
                <th>Debit</th>
                <th>Credit</th>
                <th>Description</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {lines.map((line, i) => (
                <tr key={i}>
                  <td>
                    <select
                      value={line.accountId}
                      onChange={(e) => updateLine(i, { accountId: e.target.value })}
                      style={{ minWidth: 220 }}
                    >
                      <option value="">Select account...</option>
                      {accounts.map((a) => (
                        <option key={a.id} value={a.id}>
                          {a.code} {a.name}
                        </option>
                      ))}
                    </select>
                  </td>
                  <td>
                    <input
                      type="number"
                      min="0"
                      step="0.01"
                      style={{ width: 100 }}
                      value={line.debit}
                      onChange={(e) => updateLine(i, { debit: e.target.value, credit: '' })}
                    />
                  </td>
                  <td>
                    <input
                      type="number"
                      min="0"
                      step="0.01"
                      style={{ width: 100 }}
                      value={line.credit}
                      onChange={(e) => updateLine(i, { credit: e.target.value, debit: '' })}
                    />
                  </td>
                  <td>
                    <input
                      value={line.description}
                      onChange={(e) => updateLine(i, { description: e.target.value })}
                      style={{ width: '100%' }}
                    />
                  </td>
                  <td>
                    {lines.length > 2 && (
                      <button type="button" className="secondary" onClick={() => removeLine(i)}>
                        Remove
                      </button>
                    )}
                  </td>
                </tr>
              ))}
              <tr>
                <td>
                  <strong>Total</strong>
                </td>
                <td>
                  <strong>{money(draftDebit)}</strong>
                </td>
                <td>
                  <strong>{money(draftCredit)}</strong>
                </td>
                <td colSpan={2}>
                  {!draftBalanced && (
                    <span className="muted">
                      {draftDebit === 0 && draftCredit === 0
                        ? 'Enter at least one debit and one matching credit.'
                        : `Out of balance by ${money(Math.abs(draftDebit - draftCredit))}.`}
                    </span>
                  )}
                </td>
              </tr>
            </tbody>
          </table>

          <button type="button" className="secondary" style={{ marginTop: 10 }} onClick={addLine}>
            Add line
          </button>

          <div style={{ marginTop: 14, display: 'flex', gap: 10 }}>
            <button type="submit" disabled={saving}>
              Save as draft
            </button>
            <button
              type="button"
              disabled={saving || !draftBalanced}
              onClick={(e) => submitVoucher(e as unknown as FormEvent, true)}
            >
              Save &amp; post
            </button>
          </div>
        </form>
      </div>

      <div className="card">
        <div className="filter-bar">
          <h2 style={{ margin: 0 }}>Vouchers ({vouchers.length})</h2>
          <ExportControl
            formats={[
              { value: 'csv', label: 'CSV' },
              { value: 'excel', label: 'Excel' },
            ]}
            onExport={onExportVouchers}
            onError={setError}
          />
        </div>
        <div style={{ overflowX: 'auto' }}>
          <table>
            <thead>
              <tr>
                <th>Voucher</th>
                <th>Date</th>
                <th>Narration</th>
                <th>Amount</th>
                <th>Status</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {vouchers.map((v) => (
                <Fragment key={v.id}>
                <tr>
                  <td>{v.voucher_number}</td>
                  <td>{v.entry_date}</td>
                  <td>
                    {v.narration}
                    <div className="muted">
                      {v.lines.map((l) => `${l.account_code} ${l.debit_sgd || l.credit_sgd}`).join(' / ')}
                    </div>
                  </td>
                  <td>{money(v.total_debit)}</td>
                  <td>
                    <span
                      className={`badge ${v.status === 'posted' ? 'active' : v.status === 'reversed' ? 'expired' : 'draft'}`}
                    >
                      {v.status}
                    </span>
                  </td>
                  <td style={{ display: 'flex', gap: 6 }}>
                    <button
                      className="secondary icon-button"
                      title="Attachments & Signatures"
                      aria-label="Attachments & Signatures"
                      onClick={() => setDocPanelId(docPanelId === v.id ? null : v.id)}
                    >📎</button>
                    {v.status === 'draft' && (
                      <button className="secondary" onClick={() => onPost(v)}>
                        Post
                      </button>
                    )}
                    {v.status === 'posted' && (
                      <button className="secondary" onClick={() => onReverse(v)}>
                        Reverse
                      </button>
                    )}
                  </td>
                </tr>
                {docPanelId === v.id && (
                  <tr>
                    <td colSpan={6} style={{ padding: 16, background: 'var(--bg-muted, #f9f9f9)' }}>
                      <DocumentAttachmentsPanel entityType="journal_entry" entityId={v.id} />
                      <SignaturePanel entityType="journal_entry" entityId={v.id} />
                    </td>
                  </tr>
                )}
                </Fragment>
              ))}
              {vouchers.length === 0 && (
                <tr>
                  <td colSpan={6} className="muted">
                    No vouchers yet.
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
