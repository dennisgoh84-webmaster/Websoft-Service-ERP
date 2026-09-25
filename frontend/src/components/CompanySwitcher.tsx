import { useState } from 'react'
import { useAuth } from '../lib/AuthContext'

/** Switches which company the user is working in. Only rendered when
 * they have access to more than one -- single-company staff never see
 * it. Switching re-scopes the entire application (dashboard, customers,
 * contracts, job orders, invoices, staff, groups, event logs). */
export default function CompanySwitcher() {
  const { companies, activeCompany, switchCompany } = useAuth()
  const [switching, setSwitching] = useState(false)

  if (companies.length < 2 || !activeCompany) return null

  async function onChange(companyId: string) {
    setSwitching(true)
    try {
      await switchCompany(companyId)
    } finally {
      setSwitching(false)
    }
  }

  return (
    <label className="company-switcher">
      <span className="muted">Internal Company</span>
      <select
        value={activeCompany.id}
        disabled={switching}
        onChange={(e) => onChange(e.target.value)}
        aria-label="Switch company"
      >
        {companies.map((c) => (
          <option key={c.id} value={c.id}>
            {c.name}
          </option>
        ))}
      </select>
    </label>
  )
}
