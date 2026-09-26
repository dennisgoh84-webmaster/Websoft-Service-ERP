// Email Inbox (2026-09-26) -- the helpdesk mailbox, read by the server
// over IMAP so no HTTPS is needed (the Outlook / Gmail add-ins wait for
// HTTPS). Each new email is logged as an Incident, converted to a Job
// Order, or dismissed with a reason; nothing is ever deleted, and the
// mailbox itself is never changed. See backend App\Services\EmailInbox.
import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { api, type InboxEmail, type InboxEmailStatus, type InboxMailbox } from '../lib/api'
import { formatDateTime } from '../lib/format'

const TABS: { key: InboxEmailStatus; label: string }[] = [
  { key: 'new', label: 'New' },
  { key: 'logged', label: 'Logged' },
  { key: 'dismissed', label: 'Dismissed' },
]

export default function EmailInboxPage() {
  const [tab, setTab] = useState<InboxEmailStatus>('new')
  const [emails, setEmails] = useState<InboxEmail[]>([])
  const [mailbox, setMailbox] = useState<InboxMailbox | null>(null)
  const [newCount, setNewCount] = useState(0)
  const [error, setError] = useState<string | null>(null)
  const [message, setMessage] = useState<string | null>(null)
  const [busy, setBusy] = useState<string | null>(null)
  const [checking, setChecking] = useState(false)

  const load = useCallback(
    async (status: InboxEmailStatus) => {
      try {
        const r = await api.emailInbox(status)
        setEmails(r.emails)
        setMailbox(r.mailbox)
        setNewCount(r.counts.new)
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to load the Email Inbox')
      }
    },
    [],
  )

  useEffect(() => {
    load(tab)
    // Opening the list checks the mailbox at most once a minute, so
    // keeping the screen open picks up new emails by itself.
    const timer = window.setInterval(() => load(tab), 60000)
    return () => window.clearInterval(timer)
  }, [tab, load])

  async function onCheckNow() {
    setError(null)
    setMessage(null)
    setChecking(true)
    try {
      const r = await api.checkEmailInbox()
      setMessage(r.mailbox.last_error ? null : `Mailbox checked — ${r.mailbox.added} new email${r.mailbox.added === 1 ? '' : 's'}.`)
      await load(tab)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to check the mailbox')
    } finally {
      setChecking(false)
    }
  }

  async function act(email: InboxEmail, what: () => Promise<InboxEmail>, done: (r: InboxEmail) => string) {
    setError(null)
    setMessage(null)
    setBusy(email.id)
    try {
      const r = await what()
      setMessage(done(r))
      await load(tab)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'That did not work')
    } finally {
      setBusy(null)
    }
  }

  return (
    <div>
      <h1>Email Inbox</h1>
      <p className="muted">
        Emails to the helpdesk mailbox{mailbox?.address ? ` (${mailbox.address})` : ''}, read by the system. Log each one as an
        Incident, convert it to a Job Order, or dismiss it with a reason. The mailbox itself is not changed — nothing is marked
        read, moved or deleted there.
      </p>
      {mailbox && !mailbox.configured && (
        <div className="error-banner">
          The helpdesk mailbox has no IMAP settings yet. Fill them in under Maintenance → System Email (Helpdesk), then come back.
        </div>
      )}
      {mailbox?.last_error && (
        <div className="error-banner">
          <strong>Could not read the mailbox:</strong> {mailbox.last_error}
        </div>
      )}
      {error && <div className="error-banner">{error}</div>}
      {message && (
        <p className="muted" style={{ marginBottom: 12 }}>
          {message}
        </p>
      )}

      <div className="filter-bar" style={{ flexWrap: 'wrap', gap: 6 }}>
        {TABS.map((t) => (
          <button key={t.key} className={tab === t.key ? '' : 'secondary'} onClick={() => setTab(t.key)}>
            {t.label}
            {t.key === 'new' ? ` (${newCount})` : ''}
          </button>
        ))}
        <button className="secondary" onClick={onCheckNow} disabled={checking || !mailbox?.configured}>
          {checking ? 'Checking...' : 'Check now'}
        </button>
        {mailbox?.last_checked_at && <span className="muted">Last checked {formatDateTime(mailbox.last_checked_at)}</span>}
      </div>

      {emails.length === 0 && (
        <div className="card">
          <p className="muted" style={{ margin: 0 }}>
            {tab === 'new' ? 'No new emails.' : tab === 'logged' ? 'No emails logged yet.' : 'No dismissed emails.'}
          </p>
        </div>
      )}
      {emails.map((e) => (
        <EmailCard
          key={e.id}
          email={e}
          busy={busy === e.id}
          onLog={() => act(e, () => api.logInboxEmail(e.id), (r) => `Logged as Incident ${r.incident_number}.`)}
          onConvert={() =>
            act(
              e,
              () => api.convertInboxEmail(e.id),
              (r) =>
                r.job_order_number
                  ? `Job Order ${r.job_order_number} opened (Incident ${r.incident_number}).`
                  : `Logged as Incident ${r.incident_number} — no Job Order: ${r.fallback_reason}`,
            )
          }
          onDismiss={(reason) => act(e, () => api.dismissInboxEmail(e.id, reason), () => 'Email dismissed.')}
          onRestore={() => act(e, () => api.restoreInboxEmail(e.id), () => 'Email restored to New.')}
        />
      ))}
    </div>
  )
}

function EmailCard({
  email: e,
  busy,
  onLog,
  onConvert,
  onDismiss,
  onRestore,
}: {
  email: InboxEmail
  busy: boolean
  onLog: () => void
  onConvert: () => void
  onDismiss: (reason: string) => void
  onRestore: () => void
}) {
  const [open, setOpen] = useState(false)
  const [dismissing, setDismissing] = useState(false)
  const [reason, setReason] = useState('')
  const body = e.body_text ?? ''
  const preview = body.length > 240 ? `${body.slice(0, 240)}…` : body

  function submitDismiss(ev: FormEvent) {
    ev.preventDefault()
    if (!reason.trim()) return
    onDismiss(reason.trim())
  }

  return (
    <div className="card" data-testid="inbox-email">
      <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8, flexWrap: 'wrap' }}>
        <strong style={{ wordBreak: 'break-word' }}>{e.subject}</strong>
        <span className="muted" style={{ whiteSpace: 'nowrap' }}>
          {formatDateTime(e.received_at)}
        </span>
      </div>
      <div className="muted" style={{ wordBreak: 'break-word' }}>
        From {e.from_name ? `${e.from_name} <${e.from_email}>` : e.from_email}
        {e.status === 'new' &&
          (e.matched_company_individual ? (
            <>
              {' '}
              — <span className="badge active">{e.matched_company_individual}</span>
            </>
          ) : (
            ' — no Company / Individual matches this sender'
          ))}
      </div>
      <p style={{ whiteSpace: 'pre-wrap', wordBreak: 'break-word', margin: '8px 0' }}>{open ? body : preview}</p>
      {body.length > 240 && (
        <button type="button" className="link" onClick={() => setOpen(!open)} style={{ background: 'none', border: 'none', padding: 0, textDecoration: 'underline', cursor: 'pointer', color: 'inherit' }}>
          {open ? 'Show less' : 'Show the whole email'}
        </button>
      )}
      {e.attachment_names.length > 0 && (
        <p className="muted" style={{ margin: '6px 0' }}>
          Attachments (left in the mailbox): {e.attachment_names.join(', ')}
        </p>
      )}

      {e.status === 'new' && !dismissing && (
        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginTop: 8 }}>
          <button onClick={onLog} disabled={busy}>
            Log as Incident
          </button>
          <button onClick={onConvert} disabled={busy} title="Opens a Job Order against the sender's valid contract; without one, logs an Incident and says why">
            Convert to Job Order
          </button>
          <button className="secondary" onClick={() => setDismissing(true)} disabled={busy}>
            Dismiss
          </button>
        </div>
      )}
      {e.status === 'new' && dismissing && (
        <form onSubmit={submitDismiss} style={{ marginTop: 8 }}>
          <div className="form-row">
            <label htmlFor={`dismiss-${e.id}`}>Reason for dismissing</label>
            <textarea id={`dismiss-${e.id}`} rows={2} value={reason} onChange={(ev) => setReason(ev.target.value)} required />
          </div>
          <div style={{ display: 'flex', gap: 6 }}>
            <button type="submit" disabled={busy || !reason.trim()}>
              Dismiss email
            </button>
            <button type="button" className="secondary" onClick={() => setDismissing(false)}>
              Cancel
            </button>
          </div>
        </form>
      )}
      {e.status === 'logged' && (
        <p style={{ marginBottom: 0 }}>
          {e.job_order_number ? (
            <>
              Job Order <Link to="/job-orders">{e.job_order_number}</Link>, Incident <Link to="/incidents">{e.incident_number}</Link>
            </>
          ) : (
            <>
              Incident <Link to="/incidents">{e.incident_number}</Link>
            </>
          )}
          <span className="muted">
            {' '}
            — {e.handled_by_name ?? 'someone'}, {formatDateTime(e.handled_at)}
          </span>
        </p>
      )}
      {e.status === 'dismissed' && (
        <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
          <span>
            Dismissed: {e.dismiss_reason}
            <span className="muted">
              {' '}
              — {e.handled_by_name ?? 'someone'}, {formatDateTime(e.handled_at)}
            </span>
          </span>
          <button className="secondary" onClick={onRestore} disabled={busy}>
            Restore
          </button>
        </div>
      )}
    </div>
  )
}
