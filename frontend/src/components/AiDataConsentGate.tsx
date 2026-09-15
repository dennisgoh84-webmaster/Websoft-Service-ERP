// PDPA self-declaration for the AI Assistant (Dennis, 2026-09-15,
// settling the open half of decision 12.1: "no issues" sending masked
// text to Anthropic's US-hosted API, PROVIDED every staff member has
// first agreed to it explicitly).
//
// Blocks the app -- staff and mobile alike, see App.tsx and
// MobileApp.tsx -- until acknowledged. Recorded exactly once
// (`users.ai_data_consent_at`, set only by AuthController::
// acknowledgeAiConsent()); Staff Master shows the date but can never
// edit or clear it, so it stands as a durable, un-erasable record of
// when each person agreed.
import { useState } from 'react'
import { api } from '../lib/api'

export default function AiDataConsentGate({ onAcknowledged }: { onAcknowledged: () => void }) {
  const [checked, setChecked] = useState(false)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function onAccept() {
    if (!checked || saving) return
    setError(null)
    setSaving(true)
    try {
      await api.acknowledgeAiDataConsent()
      onAcknowledged()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not record your acknowledgement')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div style={{ minHeight: '100vh', display: 'flex', alignItems: 'center', justifyContent: 'center', background: '#f4f5f7', padding: 20 }}>
      <div className="card" style={{ maxWidth: 560 }}>
        <h1 style={{ marginTop: 0 }}>Before you continue</h1>
        <p>
          This system's <strong>AI Assistant</strong> answers questions by sending information from your records to
          Anthropic, a United States-hosted API provider.
        </p>
        <ul style={{ paddingLeft: 20 }}>
          <li>
            Personal details -- names, email addresses, telephone numbers -- are <strong>masked</strong> before anything
            is sent, unless an administrator has switched that off under Maintenance → AI Assistant.
          </li>
          <li>
            Webmaster Consultancy Pte Ltd may use <strong>non-sensitive, aggregated</strong> information about how the
            AI Assistant is used (which features are asked about, and how often) for internal analysis and to improve
            the service.
          </li>
          <li>This is recorded once, against your name, with the date and time, and cannot be withdrawn or edited.</li>
        </ul>
        {error && <div className="error-banner">{error}</div>}
        <label style={{ display: 'flex', gap: 10, alignItems: 'flex-start', margin: '16px 0', cursor: 'pointer' }}>
          <input type="checkbox" checked={checked} onChange={(e) => setChecked(e.target.checked)} style={{ marginTop: 3 }} />
          <span>
            I acknowledge the above and agree that information may be masked and sent to a US-hosted API, and that
            non-sensitive usage information may be used for internal analysis.
          </span>
        </label>
        <button onClick={onAccept} disabled={!checked || saving}>
          {saving ? 'Saving...' : 'Accept and continue'}
        </button>
      </div>
    </div>
  )
}
