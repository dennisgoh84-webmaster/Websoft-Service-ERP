import { useState } from 'react'
import { useAuth } from '../lib/AuthContext'
import { api } from '../lib/api'
import { formatDateTime } from '../lib/format'

/**
 * The Gmail add-on's sign-in (docs/outlook-addin.md): opened from the
 * add-on's "Get a code" link, it hands a signed-in user a one-time code
 * to type into the add-on. A page of its own, outside the desktop
 * layout, so it works the same from a phone.
 */
export default function ConnectAddinPage() {
  const { user } = useAuth()
  const [code, setCode] = useState<{ code: string; expires_at: string; expires_in_minutes: number } | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [copied, setCopied] = useState(false)

  async function getCode() {
    setBusy(true)
    setError(null)
    setCopied(false)
    try {
      setCode(await api.addinConnectCode())
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Could not get a code.')
    } finally {
      setBusy(false)
    }
  }

  async function copy() {
    if (!code) return
    try {
      await navigator.clipboard.writeText(code.code)
      setCopied(true)
    } catch {
      /* the code is on screen to type instead */
    }
  }

  return (
    <div className="connect-addin">
      <div className="card">
        <h1>Connect the Gmail add-on</h1>
        <p className="muted">
          Signed in as <b>{user?.full_name}</b> ({user?.email}). Get a code, then type it into the Websoft Incidents panel in
          Gmail and click <b>Connect</b>. The code works once, for {code?.expires_in_minutes ?? 10} minutes.
        </p>
        {error && <div className="error-banner">{error}</div>}
        {code && (
          <div className="connect-addin-code">
            <code aria-label="Connect code">{code.code}</code>
            <div className="muted small">Valid until {formatDateTime(code.expires_at)}</div>
          </div>
        )}
        <div className="button-row">
          <button type="button" onClick={getCode} disabled={busy}>
            {busy ? 'Getting a code…' : code ? 'Get a new code' : 'Get a code'}
          </button>
          {code && (
            <button type="button" className="secondary" onClick={copy}>
              {copied ? 'Copied' : 'Copy'}
            </button>
          )}
        </div>
        <p className="muted small">Asking for a new code cancels the previous one. Never share a code: it signs in as you.</p>
      </div>
    </div>
  )
}
