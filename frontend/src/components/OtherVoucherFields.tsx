import type { Account } from '../lib/api'

/**
 * The Account and "What it is" fields of an Other Receipt or Payment
 * Voucher -- see lib/otherVoucherAccounts.ts for which accounts it offers.
 */
export default function OtherVoucherFields({
  accounts,
  accountId,
  onAccountId,
  description,
  onDescription,
  placeholder,
}: {
  accounts: Account[]
  accountId: string
  onAccountId: (id: string) => void
  description: string
  onDescription: (text: string) => void
  placeholder: string
}) {
  return (
    <>
      <div className="form-row">
        <label>Account</label>
        <select value={accountId} onChange={(e) => onAccountId(e.target.value)} required>
          <option value="">Select an account...</option>
          {accounts.map((a) => (
            <option key={a.id} value={a.id}>
              {a.code} {a.name}
            </option>
          ))}
        </select>
        {accounts.length === 0 && (
          <span className="muted">No accounts to pick: this needs view access to Finance / Accounting (the chart of accounts).</span>
        )}
      </div>
      <div className="form-row">
        <label>What it is</label>
        <input value={description} onChange={(e) => onDescription(e.target.value)} placeholder={placeholder} required />
      </div>
    </>
  )
}
