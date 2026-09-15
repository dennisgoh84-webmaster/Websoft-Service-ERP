import { useEffect, useState, type ChangeEvent, type FormEvent } from 'react'
import { api, type Company } from '../lib/api'
import { useAuth } from '../lib/AuthContext'

const MAX_LOGO_BYTES = 300 * 1024

const MONTHS = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
]

/** Same labelling rule as the backend: a financial year is named for the year it ends in. */
function fyDescription(startMonth: number): string {
  if (startMonth === 1) return 'The financial year is the calendar year.'
  const now = new Date()
  const startYear = now.getMonth() + 1 >= startMonth ? now.getFullYear() : now.getFullYear() - 1
  const endMonth = MONTHS[(startMonth + 10) % 12]
  return `The current financial year is FY${startYear + 1}: ${MONTHS[startMonth - 1].slice(0, 3)} ${startYear} - ${endMonth.slice(0, 3)} ${startYear + 1}.`
}

/**
 * This company's own outbound mailbox -- what the customer-facing
 * "Email Invoice / Quotation / ..." buttons send from. It is NOT the
 * system mailbox in .env (login codes, password resets, portal
 * invites), and neither falls back to the other: an invoice sent from
 * the wrong domain fails SPF/DKIM and lands in spam, so the backend
 * refuses to send a document until this is filled in. The password is
 * write-only -- the backend never returns it, only whether one is set
 * -- so a blank password field here means "leave it as it is".
 */
function CompanyMailboxCard({ company, onSaved }: { company: Company; onSaved: () => void }) {
  const [host, setHost] = useState(company.smtp_host ?? '')
  const [port, setPort] = useState(String(company.smtp_port))
  const [username, setUsername] = useState(company.smtp_username ?? '')
  const [password, setPassword] = useState('')
  const [clearPassword, setClearPassword] = useState(false)
  const [useTls, setUseTls] = useState(company.smtp_use_tls)
  const [fromEmail, setFromEmail] = useState(company.smtp_from_email ?? '')
  const [fromName, setFromName] = useState(company.smtp_from_name ?? '')
  const [testTo, setTestTo] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)
  const [saved, setSaved] = useState(false)
  const [testing, setTesting] = useState(false)
  const [testResult, setTestResult] = useState<string | null>(null)

  const configured = !!(company.smtp_host && company.smtp_from_email)

  async function onSave(e: FormEvent) {
    e.preventDefault()
    setError(null)
    setSaved(false)
    setTestResult(null)
    setSaving(true)
    try {
      await api.updateCompany(company.id, {
        smtp_host: host || null,
        smtp_port: Number(port) || 587,
        smtp_username: username || null,
        // Omitted entirely unless there is something to change, so a
        // save with the field left blank never wipes the stored one.
        ...(clearPassword ? { smtp_password: null } : password ? { smtp_password: password } : {}),
        smtp_use_tls: useTls,
        smtp_from_email: fromEmail || null,
        smtp_from_name: fromName || null,
      })
      setPassword('')
      setClearPassword(false)
      setSaved(true)
      onSaved()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to save email settings')
    } finally {
      setSaving(false)
    }
  }

  async function onSendTest() {
    setError(null)
    setTestResult(null)
    setTesting(true)
    try {
      const r = await api.testCompanyEmail(company.id, testTo)
      setTestResult(`Sent to ${r.to}. Check that inbox (and its spam folder).`)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Test email failed')
    } finally {
      setTesting(false)
    }
  }

  return (
    <div className="card">
      <h2>
        Outbound email -- {company.name}
        <span className={`badge ${configured ? 'active' : 'draft'}`} style={{ marginLeft: 8 }}>
          {configured ? 'Configured' : 'Not configured'}
        </span>
      </h2>
      <p className="muted">
        The mailbox this company's documents are emailed from -- Invoices, Quotations, Purchase
        Orders, Statements and the rest of the "Email ..." buttons. Use an account on your own
        domain so the mail passes SPF/DKIM at the customer's end. Until this is filled in those
        buttons report "Email is not configured for {company.name}"; the Word and PDF downloads
        work regardless. Sign-in codes and password resets use the server's own mailbox
        (SMTP_* in .env), not this one.
      </p>
      {error && <div className="error-banner">{error}</div>}
      <form onSubmit={onSave}>
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
              <label>Password {company.smtp_password_set && !clearPassword && '(one is on file -- leave blank to keep it)'}</label>
              <input
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                autoComplete="new-password"
                disabled={clearPassword}
                placeholder={company.smtp_password_set ? '********' : ''}
              />
              {company.smtp_password_set && (
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
              <input value={fromName} onChange={(e) => setFromName(e.target.value)} placeholder={company.name} />
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
              <input
                type="email"
                value={testTo}
                onChange={(e) => setTestTo(e.target.value)}
                placeholder="your own address"
              />
              <span className="muted">
                Sends a real message through the settings saved above (save first). A failure here
                shows the mail server's own reply, which is usually the quickest way to spot a
                wrong password or a blocked port.
              </span>
            </div>
            <button type="button" className="secondary" disabled={testing || !configured || !testTo} onClick={onSendTest}>
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
    </div>
  )
}

function CompanyCard({ company, onSaved }: { company: Company; onSaved: () => void }) {
  const { user } = useAuth()
  const [name, setName] = useState(company.name)
  const [country, setCountry] = useState(company.country)
  const [currency, setCurrency] = useState(company.currency)
  const [timezone, setTimezone] = useState(company.timezone)
  const [address, setAddress] = useState(company.address ?? '')
  const [gstNo, setGstNo] = useState(company.gst_registration_no ?? '')
  const [phone, setPhone] = useState(company.phone ?? '')
  const [website, setWebsite] = useState(company.website ?? '')
  const [uen, setUen] = useState(company.uen ?? '')
  const [writeOffThreshold, setWriteOffThreshold] = useState(
    company.write_off_approval_threshold_sgd?.toString() ?? '',
  )
  const [fyStartMonth, setFyStartMonth] = useState(company.financial_year_start_month)
  const [logo, setLogo] = useState<string | null>(company.logo)
  const [error, setError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)
  const [saved, setSaved] = useState(false)

  const isActiveCompany = user?.company_id === company.id

  function onPickLogo(e: ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0]
    if (!file) return
    setError(null)
    if (!file.type.startsWith('image/')) {
      setError('Please choose an image file.')
      return
    }
    if (file.size > MAX_LOGO_BYTES) {
      setError('That image is too large -- please use one under 300 KB.')
      return
    }
    const reader = new FileReader()
    reader.onload = () => setLogo(reader.result as string)
    reader.onerror = () => setError('Could not read that file.')
    reader.readAsDataURL(file)
  }

  async function onSave(e: FormEvent) {
    e.preventDefault()
    setError(null)
    setSaved(false)
    setSaving(true)
    try {
      await api.updateCompany(company.id, {
        name,
        country,
        currency,
        timezone,
        logo,
        address: address || null,
        gst_registration_no: gstNo || null,
        phone: phone || null,
        website: website || null,
        uen: uen || null,
        write_off_approval_threshold_sgd:
          writeOffThreshold === '' ? null : parseFloat(writeOffThreshold),
        financial_year_start_month: fyStartMonth,
      })
      setSaved(true)
      onSaved()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to save company')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="card">
      <h2>
        {company.name}
        {isActiveCompany && (
          <span className="badge active" style={{ marginLeft: 8 }}>
            currently active
          </span>
        )}
      </h2>
      {error && <div className="error-banner">{error}</div>}
      <form onSubmit={onSave}>
        <div style={{ display: 'flex', gap: 20, alignItems: 'flex-start', flexWrap: 'wrap' }}>
          <div>
            <div className="form-row">
              <label>Logo (shown at the top-left)</label>
              <div
                style={{
                  width: 220,
                  height: 96,
                  border: '1px solid var(--border)',
                  borderRadius: 10,
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  overflow: 'hidden',
                  background: 'var(--bg)',
                }}
              >
                {logo ? (
                  <img
                    src={logo}
                    alt={`${company.name} logo`}
                    style={{ maxWidth: '100%', maxHeight: '100%', objectFit: 'contain' }}
                  />
                ) : (
                  <span className="muted">No logo</span>
                )}
              </div>
            </div>
            <input type="file" accept="image/*" onChange={onPickLogo} />
            {logo && (
              <button
                type="button"
                className="secondary"
                style={{ marginTop: 8, display: 'block' }}
                onClick={() => setLogo(null)}
              >
                Remove logo
              </button>
            )}
          </div>

          <div style={{ flex: 1, minWidth: 260 }}>
            <div className="form-row">
              <label>Company name</label>
              <input value={name} onChange={(e) => setName(e.target.value)} required />
            </div>
            <div className="form-row">
              <label>Country</label>
              <input value={country} onChange={(e) => setCountry(e.target.value)} />
            </div>
            <div className="form-row">
              <label>Currency</label>
              <input
                value={currency}
                onChange={(e) => setCurrency(e.target.value.toUpperCase())}
                maxLength={3}
              />
            </div>
            <div className="form-row">
              <label>Timezone</label>
              <input value={timezone} onChange={(e) => setTimezone(e.target.value)} />
            </div>
            <div className="form-row">
              <label>Registered address (shown on tax invoices)</label>
              <input value={address} onChange={(e) => setAddress(e.target.value)} />
            </div>
            <div className="form-row">
              <label>GST registration number (shown on tax invoices)</label>
              <input
                value={gstNo}
                onChange={(e) => setGstNo(e.target.value)}
                placeholder="Leave blank if not GST-registered"
              />
            </div>
            <div className="form-row">
              <label>Phone (shown on printed forms)</label>
              <input value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="e.g. 6709 1233 / 6747 0705" />
            </div>
            <div className="form-row">
              <label>Website (shown on printed forms)</label>
              <input value={website} onChange={(e) => setWebsite(e.target.value)} placeholder="e.g. www.websoft.sg" />
            </div>
            <div className="form-row">
              <label>Business Reg. No. / UEN (shown on printed forms)</label>
              <input value={uen} onChange={(e) => setUen(e.target.value)} />
            </div>
            <div className="form-row">
              <label>Write-off approval threshold (SGD)</label>
              <input
                type="number"
                min={0}
                step="0.01"
                value={writeOffThreshold}
                onChange={(e) => setWriteOffThreshold(e.target.value)}
                placeholder="Blank = every write-off needs owner approval"
              />
              <span className="muted">
                AR-002: Finance may write off below this; above it, the owner approves. Blank means
                the threshold has not been decided, so the owner approves every write-off.
              </span>
            </div>
            <div className="form-row">
              <label>Financial year starts in</label>
              <select value={fyStartMonth} onChange={(e) => setFyStartMonth(Number(e.target.value))}>
                {MONTHS.map((m, i) => (
                  <option key={m} value={i + 1}>
                    {m}
                  </option>
                ))}
              </select>
              <span className="muted">
                {fyDescription(fyStartMonth)} The Sales Dashboard's "this Financial Year" listings
                and the Year-End Closing use this.
              </span>
            </div>
            <button type="submit" disabled={saving}>
              {saving ? 'Saving...' : 'Save company'}
            </button>
            {saved && (
              <span className="muted" style={{ marginLeft: 10 }}>
                Saved.
              </span>
            )}
          </div>
        </div>
      </form>
    </div>
  )
}

export default function CompanySetupPage() {
  const { companies, refresh } = useAuth()
  const [list, setList] = useState<Company[]>([])
  const [error, setError] = useState<string | null>(null)
  const [newName, setNewName] = useState('')
  const [creating, setCreating] = useState(false)

  function reload() {
    api.listMyCompanies().then(setList).catch((e) => setError(e.message))
    refresh()
  }

  useEffect(() => {
    setList(companies)
  }, [companies])

  async function onCreate(e: FormEvent) {
    e.preventDefault()
    setError(null)
    setCreating(true)
    try {
      await api.createCompany({ name: newName })
      setNewName('')
      reload()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to create company')
    } finally {
      setCreating(false)
    }
  }

  return (
    <div>
      <h1>Company Setup</h1>
      <p className="muted">
        The legal entities running in this system. Each company has its own logo, customers,
        contracts, job orders, invoices, staff, groups and event logs -- and its own module mix
        under Module Control. Staff with access to more than one company get a company switcher in
        the top bar; everything they see is scoped to whichever company is active.
      </p>
      {error && <div className="error-banner">{error}</div>}

      {list.map((c) => (
        <div key={c.id}>
          <CompanyCard company={c} onSaved={reload} />
          <CompanyMailboxCard company={c} onSaved={reload} />
        </div>
      ))}

      <div className="card">
        <h2>Add a company</h2>
        <p className="muted">
          A new company starts with the same module catalog (built modules enabled), no customers or
          contracts of its own, and you added as a user who can switch into it. Set its logo and
          details above once created.
        </p>
        <form onSubmit={onCreate}>
          <div className="form-row">
            <label>Company name</label>
            <input
              value={newName}
              onChange={(e) => setNewName(e.target.value)}
              placeholder="e.g. Websoft Digital Pte Ltd"
              required
            />
          </div>
          <button type="submit" disabled={creating}>
            {creating ? 'Creating...' : 'Create company'}
          </button>
        </form>
      </div>
    </div>
  )
}
