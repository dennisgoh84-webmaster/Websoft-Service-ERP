// Maintenance -> Bank Portal Testing (built 2026-09-16). A module-gated
// placeholder for the "Bank Portal / ZSOFT HP Agency" backlog item
// (docs/backlog.md) -- Dennis has not yet said what this actually is
// (an in-app record + Send button, vs literal automation of a real
// bank's website), so no bank integration is built here. This page
// only exists to prove the `bank_portal_testing` module gate itself:
// nobody sees this link or route unless the module is switched on for
// their company under Module Control (reaching this page at all means
// the backend's Authority::requireModuleAccess() check already passed).
import { useEffect, useState } from 'react'
import { api } from '../lib/api'

export default function BankPortalTestingPage() {
  const [message, setMessage] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    api
      .bankPortalStatus()
      .then((s) => setMessage(s.message))
      .catch((e) => setError(e instanceof Error ? e.message : 'Failed to load'))
  }, [])

  return (
    <div>
      <h1>Bank Portal Testing</h1>
      <div className="card" style={{ maxWidth: 640 }}>
        {error && <div className="error-banner">{error}</div>}
        {!error && message && <p className="muted">{message}</p>}
        <p className="muted">
          This page is a placeholder only, gated behind Module Control so it stays invisible until
          switched on for testing. The real Bank Portal feature is not scoped yet -- see
          docs/backlog.md ("Bank Portal / ZSOFT HP Agency").
        </p>
      </div>
    </div>
  )
}
