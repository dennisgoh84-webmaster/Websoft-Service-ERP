import { useEffect, useState } from 'react'
import { api, type CompanyIndividual } from '../lib/api'
import { formatMoney as money } from '../lib/format'

/**
 * Credit limit check on the forms that raise business for a customer
 * (quotation, sales invoice, Job Order). Dennis, 2026-09-26, decision
 * #48: **warn only** -- staff see the warning and can carry on; nothing
 * is blocked. `addingSgd` is what this document would add to what the
 * customer owes (a sales invoice's amount), so a document that would
 * take them over the limit warns too.
 */
export default function CreditLimitWarning({ customerId, addingSgd = 0 }: { customerId: string; addingSgd?: number }) {
  const [customer, setCustomer] = useState<CompanyIndividual | null>(null)

  useEffect(() => {
    if (!customerId) return
    let live = true
    api
      .getCompanyIndividual(customerId)
      .then((c) => live && setCustomer(c))
      .catch(() => live && setCustomer(null))
    return () => {
      live = false
    }
  }, [customerId])

  if (!customerId || !customer || customer.id !== customerId || customer.credit_limit_sgd === null) return null
  const owing = customer.outstanding_sgd ?? 0
  const after = owing + addingSgd
  if (after <= customer.credit_limit_sgd) return null

  return (
    <div className="error-banner" role="status">
      <strong>Credit limit warning:</strong> {customer.name} owes {money(owing)}
      {addingSgd > 0 ? ` (${money(after)} with this invoice, before GST)` : ''} against a credit limit of{' '}
      {money(customer.credit_limit_sgd)}. You can still carry on.
    </div>
  )
}
