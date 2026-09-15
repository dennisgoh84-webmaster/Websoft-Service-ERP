import { useState, type FormEvent } from 'react'

/**
 * One SMTP mailbox's settings, with Save and "Send test email". Shared
 * by Company Setup (the company's own document mailbox) and
 * Maintenance -> System Email (the sign-in / OTP mailbox and the
 * Helpdesk mailbox), so the three screens cannot drift in how a
 * mailbox is entered or proven.
 *
 * The password is write-only everywhere: the backend never returns
 * it, only whether one is on file. A blank field means "leave it as it
 * is" and is omitted from the save; the explicit checkbox sends null
 * to clear it.
 */
export interface MailboxValues {
  host: string | null
  port: number
  username: string | null
  use_tls: boolean
  from_email: string | null
  from_name: string | null
  password_set: boolean
}

export interface MailboxPayload {
  host: string | null
  port: number
  username: string | null
  password?: string | null
  use_tls: boolean
  from_email: string | null
  from_name: string | null
}

export default function MailboxSettingsForm({
  values,
  configured,
  fromNamePlaceholder,
  onSave,
  onTest,
}: {
  values: MailboxValues
  /** Whether the backend counts this mailbox as usable right now. */
  configured: boolean
  fromNamePlaceholder?: string
  onSave: (payload: MailboxPayload) => Promise<void>
  onTest: (toEmail: string) => Promise<{ to: string }>
}) {
  const [host, setHost] = useState(values.host ?? '')
  const [port, setPort] = useState(String(values.port))
  const [username, setUsername] = useState(values.username ?? '')
  const [password, setPassword] = useState('')
  const [clearPassword, setClearPassword] = useState(false)
  const [useTls, setUseTls] = useState(values.use_tls)
  const [fromEmail, setFromEmail] = useState(values.from_email ?? '')
  const [fromName, setFromName] = useState(values.from_name ?? '')
  const [testTo, setTestTo] = useState('')
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
        host: host || null,
        port: Number(port) || 587,
        username: username || null,
        // Omitted entirely unless there is something to change, so a
        // save with the field left blank never wipes the stored one.
        ...(clearPassword ? { password: null } : password ? { password } : {}),
        use_tls: useTls,
        from_email: fromEmail || null,
        from_name: fromName || null,
      })
      setPassword('')
      setClearPassword(false)
      setSaved(true)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to save email settings')
    } finally {
      setSaving(false)
    }
  }

  async function sendTest() {
    setError(null)
    setTestResult(null)
    setTesting(true)
    try {
      const r = await onTest(testTo)
      setTestResult(`Sent to ${r.to}. Check that inbox (and its spam folder).`)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Test email failed')
    } finally {
      setTesting(false)
    }
  }

  return (
    <form onSubmit={submit}>
      {error && <div className="error-banner">{error}</div>}
      <div style={{ display: 'flex', gap: 20, flexWrap: 'wrap' }}>
        <div style={{ flex: 1, minWidth: 260 }}>
          <div className="form-row">
            <label>SMTP host</label>
            <input value={host} onChange={(e) => setHost(e.target.value)} placeholder="e.g. smtp.office365.com" />
          </div>
          <div className="form-row">
            <label>Port</label>
            <input type="number" min={1} max={65535} value={port} onChange={(e) => setPort(e.target.value)} />
            <span className="muted">587 with TLS is the usual setting; 465 for implicit SSL; 25 for a plain relay.</span>
          </div>
          <div className="form-row">
            <label>
              <input
                type="checkbox"
                checked={useTls}
                onChange={(e) => setUseTls(e.target.checked)}
                style={{ width: 'auto', marginRight: 8 }}
              />
              Use TLS (STARTTLS)
            </label>
          </div>
          <div className="form-row">
            <label>Username</label>
            <input value={username} onChange={(e) => setUsername(e.target.value)} autoComplete="off" />
          </div>
          <div className="form-row">
            <label>Password {values.password_set && !clearPassword && '(one is on file -- leave blank to keep it)'}</label>
            <input
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              autoComplete="new-password"
              disabled={clearPassword}
              placeholder={values.password_set ? '********' : ''}
            />
            {values.password_set && (
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
        <div style={{ flex: 1, minWidth: 260 }}>
          <div className="form-row">
            <label>From address</label>
            <input
              type="email"
              value={fromEmail}
              onChange={(e) => setFromEmail(e.target.value)}
              placeholder="e.g. accounts@websoft.sg"
            />
            <span className="muted">Most providers require this to be the mailbox you sign in as.</span>
          </div>
          <div className="form-row">
            <label>From name</label>
            <input value={fromName} onChange={(e) => setFromName(e.target.value)} placeholder={fromNamePlaceholder} />
          </div>
          <button type="submit" disabled={saving}>
            {saving ? 'Saving...' : 'Save email settings'}
          </button>
          {saved && (
            <span className="muted" style={{ marginLeft: 10 }}>
              Saved.
            </span>
          )}

          <div className="form-row" style={{ marginTop: 22 }}>
            <label>Send a test email to</label>
            <input type="email" value={testTo} onChange={(e) => setTestTo(e.target.value)} placeholder="your own address" />
            <span className="muted">
              Sends a real message through the settings saved above (save first). A failure here
              shows the mail server's own reply, which is usually the quickest way to spot a wrong
              password or a blocked port.
            </span>
          </div>
          <button type="button" className="secondary" disabled={testing || !configured || !testTo} onClick={sendTest}>
            {testing ? 'Sending...' : 'Send test email'}
          </button>
          {testResult && (
            <span className="muted" style={{ marginLeft: 10 }}>
              {testResult}
            </span>
          )}
        </div>
      </div>
    </form>
  )
}
