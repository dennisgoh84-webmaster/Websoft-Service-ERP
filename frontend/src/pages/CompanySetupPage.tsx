import { useEffect, useState, type ChangeEvent, type FormEvent } from 'react'
import { api, type Company } from '../lib/api'
import MailboxSettingsForm from '../components/MailboxSettingsForm'
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
 * "Email Invoice / Quotation / ..." buttons send from. It is NOT either
 * of the system mailboxes under Maintenance -> System Email (sign-in
 * codes; Helpdesk acknowledgements), and none of the three falls back
 * to another: an invoice sent from the wrong domain fails SPF/DKIM and
 * lands in spam, so the backend refuses to send a document until this
 * is filled in.
 */
function CompanyMailboxCard({ company, onSaved }: { company: Company; onSaved: () => void }) {
  const configured = !!(company.smtp_host && company.smtp_from_email)

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
        work regardless. Sign-in codes and Helpdesk acknowledgements use the system mailboxes
        under Maintenance → System Email, not this one.
      </p>
      <MailboxSettingsForm
        values={{
          host: company.smtp_host,
          port: company.smtp_port,
          username: company.smtp_username,
          use_tls: company.smtp_use_tls,
          from_email: company.smtp_from_email,
          from_name: company.smtp_from_name,
          password_set: company.smtp_password_set,
        }}
        configured={configured}
        fromNamePlaceholder={company.name}
        onSave={async (p) => {
          await api.updateCompany(company.id, {
            smtp_host: p.host,
            smtp_port: p.port,
            smtp_username: p.username,
            ...('password' in p ? { smtp_password: p.password } : {}),
            smtp_use_tls: p.use_tls,
            smtp_from_email: p.from_email,
            smtp_from_name: p.from_name,
          })
          onSaved()
        }}
        onTest={(to) => api.testCompanyEmail(company.id, to)}
      />
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
        <span className="badge draft" style={{ marginRight: 10, fontFamily: 'monospace' }} title="Company code -- system-generated, never changes">
          {company.code}
        </span>
        {company.name}
        {isActiveCompany && (
          <span className="badge active" style={{ marginLeft: 8 }}>
            currently active
          </span>
        )}
      </h2>
      <p className="muted" style={{ marginTop: -6 }}>
        Company code <strong>{company.code}</strong> -- assigned by the system when the company was
        created: three letters of the first word of the name, two of the second, two of the
        third, then a running number padded to eight characters in all. It never changes, even
        if the name does.
      </p>
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
        <h2>Add a Sub Company</h2>
        <p className="muted">
          A sub company starts with the same module catalog (built modules enabled), no customers or
          contracts of its own, and you added as a user who can switch into it. It receives the next
          company code automatically from its name (e.g. Websoft Digital Pte Ltd becomes WEBDIPT1).
          Set its logo and details above once created.
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
            {creating ? 'Creating...' : 'Create Sub Company'}
          </button>
        </form>
      </div>
    </div>
  )
}
