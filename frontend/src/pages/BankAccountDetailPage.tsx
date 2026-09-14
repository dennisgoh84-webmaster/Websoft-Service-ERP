// Bank Book: transaction ledger (debit/credit + running balance) and
// reconciliation for one bank account. Deliberately a separate ledger
// from the General Ledger's Journal Vouchers -- confirmed with Dennis,
// 2026-09-12 (see backend/app/models/treasury.py's module docstring).
import { useEffect, useState, type FormEvent } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api, type BankAccount, type BankLedger, type BankReconciliation } from '../lib/api'
import { formatMoney } from '../lib/format'

export default function BankAccountDetailPage() {
  const { id } = useParams<{ id: string }>()
  const [bankAccount, setBankAccount] = useState<BankAccount | null>(null)
  const [ledger, setLedger] = useState<BankLedger | null>(null)
  const [reconciliations, setReconciliations] = useState<BankReconciliation[]>([])
  const [error, setError] = useState<string | null>(null)
  const [working, setWorking] = useState(false)

  const [txnDate, setTxnDate] = useState(new Date().toISOString().slice(0, 10))
  const [txnDescription, setTxnDescription] = useState('')
  const [txnReference, setTxnReference] = useState('')
  const [txnType, setTxnType] = useState<'debit' | 'credit'>('debit')
  const [txnAmount, setTxnAmount] = useState('')
  const [posting, setPosting] = useState(false)

  const [showReconcile, setShowReconcile] = useState(false)
  const [statementDate, setStatementDate] = useState(new Date().toISOString().slice(0, 10))
  const [statementBalance, setStatementBalance] = useState('')
  const [reconcileNote, setReconcileNote] = useState('')
  const [checkedIds, setCheckedIds] = useState<Set<string>>(new Set())
  const [reconciling, setReconciling] = useState(false)

  function refresh() {
    if (!id) return
    api.getBankAccount(id).then(setBankAccount).catch((e) => setError(e.message))
    api.listBankTransactions(id).then(setLedger).catch((e) => setError(e.message))
    api.listBankReconciliations(id).then(setReconciliations).catch(() => setReconciliations([]))
  }

  useEffect(refresh, [id])

  async function onAddTransaction(e: FormEvent) {
    e.preventDefault()
    if (!id) return
    setError(null)
    setPosting(true)
    try {
      const amount = Number(txnAmount)
      await api.createBankTransaction(id, {
        transaction_date: txnDate,
        description: txnDescription,
        reference: txnReference || null,
        debit_sgd: txnType === 'debit' ? amount : 0,
        credit_sgd: txnType === 'credit' ? amount : 0,
      })
      setTxnDescription('')
      setTxnReference('')
      setTxnAmount('')
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to add transaction')
    } finally {
      setPosting(false)
    }
  }

  async function onVoidTransaction(transactionId: string) {
    const reason = window.prompt('Reason for voiding this transaction (required):')
    if (!reason || !reason.trim()) return
    setError(null)
    setWorking(true)
    try {
      await api.voidBankTransaction(transactionId, reason.trim())
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to void transaction')
    } finally {
      setWorking(false)
    }
  }

  async function onToggleReconciled(transactionId: string) {
    setError(null)
    setWorking(true)
    try {
      await api.toggleBankTransactionReconciled(transactionId)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to update reconciled status')
    } finally {
      setWorking(false)
    }
  }

  function toggleChecked(transactionId: string) {
    setCheckedIds((prev) => {
      const next = new Set(prev)
      if (next.has(transactionId)) next.delete(transactionId)
      else next.add(transactionId)
      return next
    })
  }

  async function onSaveReconciliation(e: FormEvent) {
    e.preventDefault()
    if (!id) return
    setError(null)
    setReconciling(true)
    try {
      await api.createBankReconciliation(id, {
        statement_date: statementDate,
        statement_balance_sgd: Number(statementBalance),
        reconciled_transaction_ids: Array.from(checkedIds),
        note: reconcileNote || null,
      })
      setCheckedIds(new Set())
      setStatementBalance('')
      setReconcileNote('')
      setShowReconcile(false)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to save reconciliation')
    } finally {
      setReconciling(false)
    }
  }

  if (!bankAccount || !ledger) return <p>Loading...</p>

  const unreconciledRows = ledger.rows.filter((r) => !r.is_voided && !r.is_reconciled)

  return (
    <div>
      <p>
        <Link to="/bank-accounts">&larr; Bank Master File</Link>
      </p>
      <h1>
        {bankAccount.bank_name} -- {bankAccount.account_name}
      </h1>
      <p className="muted">
        {bankAccount.account_number}
        {bankAccount.branch ? ` · ${bankAccount.branch}` : ''} · {bankAccount.currency_code}
      </p>
      {error && <div className="error-banner">{error}</div>}

      <div className="card" style={{ display: 'flex', gap: 32, flexWrap: 'wrap' }}>
        <div>
          <div className="muted" style={{ fontSize: 12, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
            Opening balance
          </div>
          <div style={{ fontSize: 20, fontVariantNumeric: 'tabular-nums' }}>
            {formatMoney(ledger.opening_balance_sgd)}
          </div>
          {ledger.opening_balance_date && <div className="muted">as at {ledger.opening_balance_date}</div>}
        </div>
        <div>
          <div className="muted" style={{ fontSize: 12, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
            Book balance (closing)
          </div>
          <div style={{ fontSize: 20, fontVariantNumeric: 'tabular-nums' }}>
            {formatMoney(ledger.closing_balance_sgd)}
          </div>
        </div>
        <div>
          <div className="muted" style={{ fontSize: 12, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
            Reconciled balance
          </div>
          <div style={{ fontSize: 20, fontVariantNumeric: 'tabular-nums' }}>
            {formatMoney(ledger.reconciled_balance_sgd)}
          </div>
        </div>
        <div>
          <div className="muted" style={{ fontSize: 12, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
            Unreconciled lines
          </div>
          <div style={{ fontSize: 20 }}>
            <span className={`badge ${ledger.unreconciled_count > 0 ? 'draft' : 'active'}`}>
              {ledger.unreconciled_count}
            </span>
          </div>
        </div>
      </div>

      <div className="card">
        <h2>Bank Book -- transaction ledger ({ledger.rows.length})</h2>
        <div style={{ overflowX: 'auto' }}>
          <table>
            <thead>
              <tr>
                <th>Date</th>
                <th>Txn no.</th>
                <th>Description</th>
                <th>Reference</th>
                <th style={{ textAlign: 'right' }}>Debit</th>
                <th style={{ textAlign: 'right' }}>Credit</th>
                <th style={{ textAlign: 'right' }}>Balance</th>
                <th>Reconciled</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {ledger.rows.map((r) => (
                <tr key={r.id} style={r.is_voided ? { opacity: 0.5, textDecoration: 'line-through' } : undefined}>
                  <td>{r.transaction_date}</td>
                  <td>
                    {r.transaction_number}
                    {r.source_type && (
                      <>
                        {' '}
                        <span
                          className="badge badge-neutral"
                          title="Created by the Bank step on this voucher (ACC-002); void it from the voucher, not here"
                        >
                          from {r.source_type === 'payment' ? 'Receipt Voucher' : 'Payment Voucher'}
                        </span>
                      </>
                    )}
                  </td>
                  <td>
                    {r.description}
                    {r.is_voided && r.void_reason && (
                      <div className="muted" style={{ fontSize: 12 }}>
                        Voided: {r.void_reason}
                      </div>
                    )}
                  </td>
                  <td>{r.reference ?? '-'}</td>
                  <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>
                    {r.debit_sgd ? formatMoney(r.debit_sgd) : ''}
                  </td>
                  <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>
                    {r.credit_sgd ? formatMoney(r.credit_sgd) : ''}
                  </td>
                  <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>
                    {formatMoney(r.running_balance_sgd)}
                  </td>
                  <td>
                    {!r.is_voided && (
                      <button className="secondary" onClick={() => onToggleReconciled(r.id)} disabled={working}>
                        {r.is_reconciled ? 'Reconciled' : 'Mark reconciled'}
                      </button>
                    )}
                  </td>
                  <td>
                    {!r.is_voided && (
                      <button className="secondary" onClick={() => onVoidTransaction(r.id)} disabled={working}>
                        Void
                      </button>
                    )}
                  </td>
                </tr>
              ))}
              {ledger.rows.length === 0 && (
                <tr>
                  <td colSpan={9} className="muted">
                    No transactions yet.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>

      <div className="card">
        <h2>Add a transaction</h2>
        <form onSubmit={onAddTransaction}>
          <div className="form-row">
            <label>Date</label>
            <input type="date" value={txnDate} onChange={(e) => setTxnDate(e.target.value)} required />
          </div>
          <div className="form-row">
            <label>Description</label>
            <input value={txnDescription} onChange={(e) => setTxnDescription(e.target.value)} required />
          </div>
          <div className="form-row">
            <label>Reference</label>
            <input value={txnReference} onChange={(e) => setTxnReference(e.target.value)} />
          </div>
          <div className="form-row">
            <label>Type</label>
            <select value={txnType} onChange={(e) => setTxnType(e.target.value as 'debit' | 'credit')}>
              <option value="debit">Debit (money in)</option>
              <option value="credit">Credit (money out)</option>
            </select>
          </div>
          <div className="form-row">
            <label>Amount (SGD)</label>
            <input
              type="number"
              step="0.01"
              min="0.01"
              value={txnAmount}
              onChange={(e) => setTxnAmount(e.target.value)}
              required
            />
          </div>
          <button type="submit" disabled={posting}>
            {posting ? 'Adding...' : 'Add transaction'}
          </button>
        </form>
      </div>

      <div className="card">
        <div className="filter-bar">
          <h2 style={{ margin: 0 }}>Bank Reconciliation</h2>
          <button
            className="secondary"
            onClick={() => {
              setShowReconcile((v) => !v)
              setCheckedIds(new Set())
            }}
          >
            {showReconcile ? 'Cancel' : 'New reconciliation'}
          </button>
        </div>

        {showReconcile && (
          <form onSubmit={onSaveReconciliation} style={{ marginTop: 12 }}>
            <div className="form-row">
              <label>Statement date</label>
              <input type="date" value={statementDate} onChange={(e) => setStatementDate(e.target.value)} required />
            </div>
            <div className="form-row">
              <label>Statement balance (SGD)</label>
              <input
                type="number"
                step="0.01"
                value={statementBalance}
                onChange={(e) => setStatementBalance(e.target.value)}
                required
              />
            </div>
            <div className="form-row">
              <label>Note</label>
              <input value={reconcileNote} onChange={(e) => setReconcileNote(e.target.value)} />
            </div>

            <p className="muted">Tick the lines confirmed against the bank statement:</p>
            <div style={{ overflowX: 'auto', maxHeight: 260, overflowY: 'auto' }}>
              <table>
                <thead>
                  <tr>
                    <th></th>
                    <th>Date</th>
                    <th>Description</th>
                    <th style={{ textAlign: 'right' }}>Debit</th>
                    <th style={{ textAlign: 'right' }}>Credit</th>
                  </tr>
                </thead>
                <tbody>
                  {unreconciledRows.map((r) => (
                    <tr key={r.id}>
                      <td>
                        <input
                          type="checkbox"
                          checked={checkedIds.has(r.id)}
                          onChange={() => toggleChecked(r.id)}
                        />
                      </td>
                      <td>{r.transaction_date}</td>
                      <td>{r.description}</td>
                      <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>
                        {r.debit_sgd ? formatMoney(r.debit_sgd) : ''}
                      </td>
                      <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>
                        {r.credit_sgd ? formatMoney(r.credit_sgd) : ''}
                      </td>
                    </tr>
                  ))}
                  {unreconciledRows.length === 0 && (
                    <tr>
                      <td colSpan={5} className="muted">
                        Every transaction is already reconciled.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>

            <button type="submit" disabled={reconciling} style={{ marginTop: 12 }}>
              {reconciling ? 'Saving...' : 'Save reconciliation'}
            </button>
          </form>
        )}

        <h3 style={{ marginTop: 20 }}>History</h3>
        <div style={{ overflowX: 'auto' }}>
          <table>
            <thead>
              <tr>
                <th>Statement date</th>
                <th style={{ textAlign: 'right' }}>Statement balance</th>
                <th style={{ textAlign: 'right' }}>Ledger balance</th>
                <th style={{ textAlign: 'right' }}>Difference</th>
                <th>Note</th>
                <th>Reconciled by</th>
              </tr>
            </thead>
            <tbody>
              {reconciliations.map((r) => (
                <tr key={r.id}>
                  <td>{r.statement_date}</td>
                  <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>
                    {formatMoney(r.statement_balance_sgd)}
                  </td>
                  <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>
                    {formatMoney(r.ledger_balance_sgd)}
                  </td>
                  <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>
                    <span className={`badge ${r.difference_sgd === 0 ? 'active' : 'exceeded'}`}>
                      {formatMoney(r.difference_sgd)}
                    </span>
                  </td>
                  <td>{r.note ?? '-'}</td>
                  <td>{r.reconciled_by_name ?? '-'}</td>
                </tr>
              ))}
              {reconciliations.length === 0 && (
                <tr>
                  <td colSpan={6} className="muted">
                    No reconciliations recorded yet.
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
