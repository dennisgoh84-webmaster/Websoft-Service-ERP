// Maintenance -> System Email: the two system-level mailboxes, kept in
// the database (system_mail_settings) rather than .env at Dennis's
// request (2026-09-15) -- one for sign-in codes / password resets /
// portal invites, one for the Helpdesk acknowledgements the Outlook
// Add-in's Incident and Job Order conversions send. Distinct from each
// company's own document mailbox on Company Setup; nothing falls back
// between any of the three.
import { useEffect, useState } from 'react'
import { api, type SystemMailbox } from '../lib/api'
import MailboxSettingsForm from '../components/MailboxSettingsForm'
import ImapSettingsForm from '../components/ImapSettingsForm'

function sourceNote(m: SystemMailbox): string {
  switch (m.source) {
    case 'database':
      return 'Live: the settings below are in use.'
    case 'env':
      return 'Live from the server\'s .env file (SMTP_*). Save settings here and they take over; .env is then ignored for this mailbox.'
    default:
      return 'Not configured.'
  }
}

export default function SystemEmailPage() {
  const [otp, setOtp] = useState<SystemMailbox | null>(null)
  const [helpdesk, setHelpdesk] = useState<SystemMailbox | null>(null)
  const [error, setError] = useState<string | null>(null)
  // Remounts the forms after a save so they pick up password_set etc.
  const [version, setVersion] = useState(0)

  function refresh() {
    api
      .getSystemMail()
      .then((r) => {
        setOtp(r.otp)
        setHelpdesk(r.helpdesk)
        setVersion((v) => v + 1)
      })
      .catch((e) => setError(e.message))
  }

  useEffect(refresh, [])

  function card(m: SystemMailbox | null, title: string, blurb: string, fromNamePlaceholder: string) {
    if (!m) return null

    return (
      <div className="card">
        <h2>
          {title}
          <span className={`badge ${m.configured ? 'active' : 'draft'}`} style={{ marginLeft: 8 }}>
            {m.configured ? 'Configured' : 'Not configured'}
          </span>
        </h2>
        <p className="muted">{blurb}</p>
        <p className="muted">{sourceNote(m)}</p>
        <MailboxSettingsForm
          key={`${m.purpose}-${version}`}
          values={m}
          configured={m.configured}
          fromNamePlaceholder={fromNamePlaceholder}
          onSave={async (payload) => {
            await api.updateSystemMail(m.purpose, payload)
            refresh()
          }}
          onTest={(to) => api.testSystemMail(m.purpose, to)}
        />
        <ImapSettingsForm
          key={`${m.purpose}-imap-${version}`}
          purpose={m.purpose}
          values={m}
          onSave={async (payload) => {
            await api.updateSystemMailImap(m.purpose, payload)
            refresh()
          }}
          onTest={() => api.testSystemMailImap(m.purpose)}
        />
      </div>
    )
  }

  return (
    <div>
      <h1>System Email</h1>
      <p className="muted">
        The two mailboxes the system itself sends from. They are separate from each company's
        own document mailbox (Company Setup → Outbound email), and none of the three ever
        borrows another: a login code should not come from the support desk, and a support
        acknowledgement should not come from the address that sends login codes.
      </p>
      {error && <div className="error-banner">{error}</div>}

      {card(
        otp,
        'Sign-in / OTP mailbox',
        'Sends the one-time sign-in codes, password-reset codes, and Helpdesk Portal invites and resets. These run before any company is chosen, which is why this is a system mailbox. Leave it unconfigured and sign-in skips the email code, and a portal invite shows its temporary password on screen instead.',
        'Websoft Service ERP',
      )}
      {card(
        helpdesk,
        'Helpdesk mailbox (Outlook Add-in)',
        'When the Outlook Add-in turns an email into an Incident or a Job Order, the sender gets an acknowledgement from this mailbox quoting the reference number. Leave it unconfigured and the conversion still happens, just without the acknowledgement -- the add-in reports which.',
        'Webmaster Support',
      )}
    </div>
  )
}
