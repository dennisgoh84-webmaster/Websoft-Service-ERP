// Bank Master File -- the company's own bank accounts. Setup data
// only: no Receipt/Payment Voucher or GL posting reads from this yet.
import { useEffect, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import ExportControl from '../components/ExportControl'
import { api, downloadBlob, type Account, type BankAccount } from '../lib/api'
import { formatMoney } from '../lib/format'
import DateInput from '../components/DateInput'

export default function BankAccountsPage() {
  const [bankAccounts, setBankAccounts] = useState<BankAccount[]>([])
  const [glAccounts, setGlAccounts] = useState<Account[]>([])
  const [showInactive, setShowInactive] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const [bankName, setBankName] = useState('')
  const [accountName, setAccountName] = useState('')
  const [accountNumber, setAccountNumber] = useState('')
  const [branch, setBranch] = useState('')
  const [swiftCode, setSwiftCode] = useState('')
  const [currencyCode, setCurrencyCode] = useState('SGD')
  const [glAccountId, setGlAccountId] = useState('')
  const [openingBalance, setOpeningBalance] = useState('0')
  // A foreign-currency account's opening balance in its own currency (multi-currency).
  const [openingBalanceFx, setOpeningBalanceFx] = useState('')
  const [openingBalanceDate, setOpeningBalanceDate] = useState('')
  const [creating, setCreating] = useState(false)

  function refresh() {
    api.listBankAccounts(showInactive).then(setBankAccounts).catch((e) => setError(e.message))
    api.listAccounts({ account_type: 'asset' }).then(setGlAccounts).catch(() => setGlAccounts([]))
  }

  useEffect(refresh, [showInactive])

  const glAccountLabel = (id: string | null) => {
    if (!id) return '-'
    const a = glAccounts.find((g) => g.id === id)
    return a ? `${a.code} ${a.name}` : id.slice(0, 8)
  }

  async function onCreate(e: FormEvent) {
    e.preventDefault()
    setError(null)
    setCreating(true)
    try {
      await api.createBankAccount({
        bank_name: bankName,
        account_name: accountName,
        account_number: accountNumber,
        branch: branch || undefined,
        swift_code: swiftCode || undefined,
        currency_code: currencyCode || 'SGD',
        gl_account_id: glAccountId || null,
        opening_balance_sgd: Number(openingBalance) || 0,
        opening_balance_fx: currencyCode && currencyCode !== 'SGD' ? Number(openingBalanceFx) || 0 : null,
        opening_balance_date: openingBalanceDate || null,
      })
      setBankName('')
      setAccountName('')
      setAccountNumber('')
      setBranch('')
      setSwiftCode('')
      setGlAccountId('')
      setOpeningBalance('0')
      setOpeningBalanceDate('')
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to add bank account')
    } finally {
      setCreating(false)
    }
  }

  async function onToggleActive(bankAccount: BankAccount) {
    setError(null)
    try {
      await api.updateBankAccount(bankAccount.id, { is_active: !bankAccount.is_active })
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to update bank account')
    }
  }

  async function onExport(format: string) {
    setError(null)
    const blob = format === 'csv' ? await api.exportBankAccountsCsv(showInactive) : await api.exportBankAccountsExcel(showInactive)
    downloadBlob(blob, `bank-accounts.${format === 'csv' ? 'csv' : 'xlsx'}`)
  }

  return (
    <div>
      <h1>Bank Master File</h1>
      <p className="muted">
        The company's own bank accounts. Setup data only -- no Receipt/Payment Voucher or GL posting
        reads from this yet; the optional GL account link is for reference until that's wired up.
        Click a bank account to open its Bank Book: transaction ledger and reconciliation.
      </p>
      {error && <div className="error-banner">{error}</div>}

      <div className="card">
        <div className="filter-bar">
          <label style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 13 }}>
            <input type="checkbox" checked={showInactive} onChange={(e) => setShowInactive(e.target.checked)} />
            Show inactive
          </label>
          <ExportControl formats={[{ value: 'csv', label: 'CSV' }, { value: 'excel', label: 'Excel' }]} onExport={onExport} onError={setError} />
        </div>

        <h2>Bank Accounts ({bankAccounts.length})</h2>
        <div style={{ overflowX: 'auto' }}>
          <table>
            <thead>
              <tr>
                <th>Bank</th>
                <th>Account name</th>
                <th>Account no.</th>
                <th>Branch</th>
                <th>SWIFT</th>
                <th>Currency</th>
                <th>GL account</th>
                <th style={{ textAlign: 'right' }}>Current balance</th>
                <th>Status</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {bankAccounts.map((b) => (
                <tr key={b.id} style={{ opacity: b.is_active ? 1 : 0.6 }}>
                  <td>
                    <Link to={`/bank-accounts/${b.id}`}>{b.bank_name}</Link>
                  </td>
                  <td>{b.account_name}</td>
                  <td>{b.account_number}</td>
                  <td>{b.branch ?? '-'}</td>
                  <td>{b.swift_code ?? '-'}</td>
                  <td>{b.currency_code}</td>
                  <td>{glAccountLabel(b.gl_account_id)}</td>
                  <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>
                    {formatMoney(b.current_balance_sgd)}
                  </td>
                  <td>
                    <span className={`badge ${b.is_active ? 'active' : 'draft'}`}>
                      {b.is_active ? 'Active' : 'Inactive'}
                    </span>
                  </td>
                  <td>
                    <button className="secondary" onClick={() => onToggleActive(b)}>
                      {b.is_active ? 'Deactivate' : 'Reactivate'}
                    </button>
                  </td>
                </tr>
              ))}
              {bankAccounts.length === 0 && (
                <tr>
                  <td colSpan={10} className="muted">
                    No bank accounts set up yet.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>

      <div className="card">
        <h2>Add a bank account</h2>
        <form onSubmit={onCreate}>
          <div className="form-row">
            <label>Bank name</label>
            <input value={bankName} onChange={(e) => setBankName(e.target.value)} required />
          </div>
          <div className="form-row">
            <label>Account name</label>
            <input value={accountName} onChange={(e) => setAccountName(e.target.value)} required />
          </div>
          <div className="form-row">
            <label>Account number</label>
            <input value={accountNumber} onChange={(e) => setAccountNumber(e.target.value)} required />
          </div>
          <div className="form-row">
            <label>Branch</label>
            <input value={branch} onChange={(e) => setBranch(e.target.value)} />
          </div>
          <div className="form-row">
            <label>SWIFT code</label>
            <input value={swiftCode} onChange={(e) => setSwiftCode(e.target.value)} />
          </div>
          <div className="form-row">
            <label>Currency</label>
            <input value={currencyCode} onChange={(e) => setCurrencyCode(e.target.value.toUpperCase())} maxLength={3} />
          </div>
          <div className="form-row">
            <label>GL account (optional)</label>
            <select value={glAccountId} onChange={(e) => setGlAccountId(e.target.value)}>
              <option value="">None</option>
              {glAccounts.map((a) => (
                <option key={a.id} value={a.id}>
                  {a.code} {a.name}
                </option>
              ))}
            </select>
          </div>
          {currencyCode && currencyCode !== 'SGD' && (
            <div className="form-row">
              <label htmlFor="bank-opening-fx">Opening balance ({currencyCode})</label>
              <input id="bank-opening-fx" type="number" step="0.01" value={openingBalanceFx} onChange={(e) => setOpeningBalanceFx(e.target.value)} />
              <span className="muted">
                A {currencyCode} account's Bank Book runs in {currencyCode}, with the SGD value beside each line.
              </span>
            </div>
          )}
          <div className="form-row">
            <label>Opening balance (SGD)</label>
            <input
              type="number"
              step="0.01"
              value={openingBalance}
              onChange={(e) => setOpeningBalance(e.target.value)}
            />
          </div>
          <div className="form-row">
            <label>Opening balance date</label>
            <DateInput value={openingBalanceDate} onChange={(e) => setOpeningBalanceDate(e.target.value)} />
          </div>
          <button type="submit" disabled={creating}>
            {creating ? 'Adding...' : 'Add bank account'}
          </button>
        </form>
      </div>
    </div>
  )
}
