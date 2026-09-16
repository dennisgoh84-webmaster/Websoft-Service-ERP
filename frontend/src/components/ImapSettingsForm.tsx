import { useState, type FormEvent } from 'react'
import type { ImapMailboxPayload, SystemMailbox } from '../lib/api'

/**
 * IMAP credentials for a system mailbox (Maintenance -> System Email),
 * so it can also be logged into and read, alongside the SMTP settings
 * that send from it (MailboxSettingsForm). Deliberately its own,
 * separate component -- MailboxSettingsForm is shared with Company
 * Setup's per-company document mailbox, which has no IMAP need, so
 * these fields stay out of it rather than leaking there too.
 *
 * "Test IMAP connection" only connects, logs in, and logs out -- it
 * proves the credentials work; nothing here reads or lists messages.
 */
export default function ImapSettingsForm({
  purpose,
  values,
  onSave,
  onTest,
}: {
  purpose: 'otp' | 'helpdesk'
  values: SystemMailbox
  onSave: (payload: ImapMailboxPayload) => Promise<void>
  onTest: () => Promise<{ ok: true }>
}) {
  const [host, setHost] = useState(values.imap_host ?? '')
  const [port, setPort] = useState(String(values.imap_port))
  const [username, setUsername] = useState(values.imap_username ?? '')
  const [password, setPassword] = useState('')
  const [clearPassword, setClearPassword] = useState(false)
  const [useSsl, setUseSsl] = useState(values.imap_use_ssl)
  const [error, setError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)
  const [saved, setSaved] = useState(false)
  const [testing, setTesting] = useState(false)
  const [testResult, setTestResult] = useState<string | null>(null)

  async function submit(e: FormEvent) {
    e.preventDefault()
    setError(null)
    setSaved(false)
    setTestResult(null)
    setSaving(true)
    try {
      await onSave({
        imap_host: host || null,
        imap_port: Number(port) || 993,
        imap_username: username || null,
        ...(clearPassword ? { imap_password: null } : password ? { imap_password: password } : {}),
        imap_use_ssl: useSsl,
      })
      setPassword('')
      setClearPassword(false)
      setSaved(true)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to save IMAP settings')
    } finally {
      setSaving(false)
    }
  }

  async function runTest() {
    setError(null)
    setTestResult(null)
    setTesting(true)
    try {
      await onTest()
      setTestResult('Connected and logged in successfully.')
    } catch (err) {
      setError(err instanceof Error ? err.message : 'IMAP test failed')
    } finally {
      setTesting(false)
    }
  }

  return (
    <details style={{ marginTop: 14 }}>
      <summary style={{ cursor: 'pointer' }}>
        IMAP (read this mailbox){' '}
        <span className={`badge ${values.imap_configured ? 'active' : 'draft'}`}>
          {values.imap_configured ? 'Configured' : 'Not configured'}
        </span>
      </summary>
      <form onSubmit={submit} style={{ marginTop: 10 }}>
        {error && <div className="error-banner">{error}</div>}
        <div style={{ display: 'flex', gap: 20, flexWrap: 'wrap' }}>
          <div style={{ flex: 1, minWidth: 220 }}>
            <div className="form-row">
              <label>IMAP host</label>
              <input value={host} onChange={(e) => setHost(e.target.value)} placeholder="e.g. imap.office365.com" />
            </div>
            <div className="form-row">
              <label>Port</label>
              <input type="number" min={1} max={65535} value={port} onChange={(e) => setPort(e.target.value)} />
              <span className="muted">993 for implicit SSL is the usual setting.</span>
            </div>
            <div className="form-row">
              <label>
                <input
                  type="checkbox"
                  checked={useSsl}
                  onChange={(e) => setUseSsl(e.target.checked)}
                  style={{ width: 'auto', marginRight: 8 }}
                />
                Use SSL
              </label>
            </div>
          </div>
          <div style={{ flex: 1, minWidth: 220 }}>
            <div className="form-row">
              <label>Username</label>
              <input value={username} onChange={(e) => setUsername(e.target.value)} autoComplete="off" />
            </div>
            <div className="form-row">
              <label>Password {values.imap_password_set && !clearPassword && '(one is on file -- leave blank to keep it)'}</label>
              <input
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                autoComplete="new-password"
                disabled={clearPassword}
                placeholder={values.imap_password_set ? '********' : ''}
              />
              {values.imap_password_set && (
                <label style={{ marginTop: 6 }}>
                  <input
                    type="checkbox"
                    checked={clearPassword}
                    onChange={(e) => setClearPassword(e.target.checked)}
                    style={{ width: 'auto', marginRight: 8 }}
                  />
                  Remove the stored password
                </label>
              )}
            </div>
          </div>
        </div>
        <button type="submit" disabled={saving}>
          {saving ? 'Saving...' : `Save ${purpose === 'otp' ? 'sign-in / OTP' : 'helpdesk'} IMAP settings`}
        </button>
        {saved && (
          <span className="muted" style={{ marginLeft: 10 }}>
            Saved.
          </span>
        )}
        {' '}
        <button type="button" className="secondary" disabled={testing || !values.imap_configured} onClick={runTest}>
          {testing ? 'Testing...' : 'Test IMAP connection'}
        </button>
        {testResult && (
          <span className="muted" style={{ marginLeft: 10 }}>
            {testResult}
          </span>
        )}
      </form>
    </details>
  )
}
