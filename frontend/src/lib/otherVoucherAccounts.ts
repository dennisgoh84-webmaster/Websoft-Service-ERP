import { useEffect, useState } from 'react'
import { api, type Account, type BankAccount } from './api'

/**
 * The "Other" half of a Receipt or Payment Voucher (open-business-
 * decisions.md #49 / 31.1): bank interest, bank charges and the like are
 * keyed here, against a GL account, instead of straight into the Bank
 * Book -- so they reach the General Ledger and, through the Bank step,
 * the Bank Book together.
 *
 * The account list leaves out what the server refuses
 * (Posting::otherVoucherAccountOrFail): the AR / AP / GST control
 * accounts, and any bank's own account.
 */
const CONTROL_CODES = ['1000', '1100', '2000', '2100', '2110']

export function useOtherVoucherAccounts(bankAccounts: BankAccount[]): Account[] {
  const [accounts, setAccounts] = useState<Account[]>([])
  useEffect(() => {
    api.listAccounts().then(setAccounts).catch(() => setAccounts([]))
  }, [])
  const bankGl = new Set(bankAccounts.map((b) => b.gl_account_id).filter(Boolean))
  return accounts.filter((a) => a.is_active && !CONTROL_CODES.includes(a.code) && !bankGl.has(a.id)).sort((a, b) => a.code.localeCompare(b.code))
}

