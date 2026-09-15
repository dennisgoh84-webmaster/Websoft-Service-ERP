import { useEffect, useState, type ChangeEvent, type FormEvent } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import {
  api,
  type AuditLogEntry,
  type Branch,
  type Contact,
  type CompanyIndividual,
  type CompanyIndividualGroup,
  type CompanyIndividualProductUsageRow,
  type CompanyIndividualRelationship,
  type CompanyIndividualType,
  type PortalAccess,
  type SetupListItem,
} from '../lib/api'
import { formatDate, formatDateTime } from '../lib/format'

const ACTION_LABELS: Record<string, string> = {
  created: 'Created',
  updated: 'Details updated',
  deactivated: 'Deactivated',
  reactivated: 'Reactivated',
  pdpa_consent_recorded: 'PDPA Agreement e-signed',
  pdpa_consent_revoked: 'PDPA consent revoked',
  archived: 'Archived',
  unarchived: 'Unarchived',
}

function emptyForm() {
  return {
    customer_type: 'company' as CompanyIndividualType,
    name: '',
    customer_group_id: '',
    industry_code: '',
    legacy_customer_code: '',
    contact_person: '',
    uen: '',
    gst_registration_no: '',
    billing_email: '',
    phone: '',
    mobile: '',
    website: '',
    address_line1: '',
    address_line2: '',
    address_city: '',
    address_state: '',
    address_postal_code: '',
    address_country: '',
    tags: '',
    terms_and_conditions: '',
    memo: '',
    billing_notes: '',
    payment_terms_days: '',
    data_expiry_date: '',
  }
}

function emptyBranchForm() {
  return {
    branch_name: '',
    branch_code: '',
    address_line1: '',
    address_city: '',
    address_postal_code: '',
    address_country: '',
    phone: '',
  }
}

export default function CompanyIndividualDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()

  const [customer, setCustomer] = useState<CompanyIndividual | null>(null)
  const [contacts, setContacts] = useState<Contact[]>([])
  const [branches, setBranches] = useState<Branch[]>([])
  const [relationships, setRelationships] = useState<CompanyIndividualRelationship[]>([])
  const [allCompanyIndividuals, setAllCompanyIndividuals] = useState<CompanyIndividual[]>([])
  const [relTargetContacts, setRelTargetContacts] = useState<Contact[]>([])
  const [groups, setGroups] = useState<CompanyIndividualGroup[]>([])
  const [industries, setIndustries] = useState<SetupListItem[]>([])
  const [auditLog, setAuditLog] = useState<AuditLogEntry[]>([])
  const [productUsage, setProductUsage] = useState<CompanyIndividualProductUsageRow[]>([])
  const [error, setError] = useState<string | null>(null)
  const [notFound, setNotFound] = useState(false)
  const [saving, setSaving] = useState(false)

  const [form, setForm] = useState(emptyForm())
  const [excludeAutoSent, setExcludeAutoSent] = useState(false)
  // Role flags (2026-09-12): a record can be a customer, a supplier, or
  // both -- ticking "Is Supplier" is what makes this record selectable
  // on Purchase Order / Accounts Payable, instead of a separate file.
  const [isCustomer, setIsCustomer] = useState(true)
  const [isSupplier, setIsSupplier] = useState(false)

  // Helpdesk Portal logins, one per Contact. Kept keyed by contact id
  // rather than folded into the Contact row: it is a different record
  // in a different auth realm (docs/customer-portal-design.md §8), and
  // it is fetched per contact because the contacts endpoint knows
  // nothing about portal users.
  const [portalAccess, setPortalAccess] = useState<Record<string, PortalAccess>>({})
  // The temporary password, shown once after enable/reset and only when
  // SMTP is not configured -- with SMTP it is emailed and never
  // displayed. Cleared as soon as any other portal action runs.
  const [portalTempPassword, setPortalTempPassword] = useState<{ contactId: string; password: string } | null>(null)
  const [portalBusyContactId, setPortalBusyContactId] = useState<string | null>(null)

  // New-contact form
  const [contactName, setContactName] = useState('')
  const [contactEmail, setContactEmail] = useState('')
  const [contactPhone, setContactPhone] = useState('')
  const [contactDirectLine, setContactDirectLine] = useState('')

  // New-branch form
  const [branchForm, setBranchForm] = useState(emptyBranchForm())

  // New-relationship form
  const [relTargetCompanyIndividualId, setRelTargetCompanyIndividualId] = useState('')
  const [relTargetContactId, setRelTargetContactId] = useState('')
  const [relType, setRelType] = useState('')
  const [relNote, setRelNote] = useState('')

  // Inline "new group" entry, since a group of companies may not exist
  // yet when you first need to tag a customer into one.
  const [newGroupName, setNewGroupName] = useState('')

  function refresh() {
    if (!id) return
    api
      .getCompanyIndividual(id)
      .then((c) => {
        setCustomer(c)
        setForm({
          customer_type: c.customer_type,
          name: c.name,
          customer_group_id: c.customer_group_id ?? '',
          industry_code: c.industry_code ?? '',
          legacy_customer_code: c.legacy_customer_code ?? '',
          contact_person: c.contact_person ?? '',
          uen: c.uen ?? '',
          gst_registration_no: c.gst_registration_no ?? '',
          billing_email: c.billing_email ?? '',
          phone: c.phone ?? '',
          mobile: c.mobile ?? '',
          website: c.website ?? '',
          address_line1: c.address_line1 ?? '',
          address_line2: c.address_line2 ?? '',
          address_city: c.address_city ?? '',
          address_state: c.address_state ?? '',
          address_postal_code: c.address_postal_code ?? '',
          address_country: c.address_country ?? '',
          tags: c.tags ?? '',
          terms_and_conditions: c.terms_and_conditions ?? '',
          memo: c.memo ?? '',
          billing_notes: c.billing_notes ?? '',
          payment_terms_days: c.payment_terms_days === null ? '' : String(c.payment_terms_days),
          data_expiry_date: c.data_expiry_date ?? '',
        })
        setExcludeAutoSent(c.exclude_auto_sent)
        setIsCustomer(c.is_customer)
        setIsSupplier(c.is_supplier)
      })
      .catch(() => setNotFound(true))
    api.listContacts(id, true).then(setContacts).catch((e) => setError(e.message))
    api.listBranches(id, true).then(setBranches).catch((e) => setError(e.message))
    api.listCompanyIndividualRelationships(id).then(setRelationships).catch((e) => setError(e.message))
    api.getCompanyIndividualAuditLog(id).then(setAuditLog).catch((e) => setError(e.message))
    api.reportCompanyIndividualProductUsage({ customer_id: id }).then(setProductUsage).catch(() => setProductUsage([]))
  }

  useEffect(refresh, [id])

  // One call per contact. There is no bulk endpoint, and a customer's
  // contact list is short; a failure here leaves that contact's cell
  // blank rather than failing the whole page.
  useEffect(() => {
    if (!id || contacts.length === 0) {
      setPortalAccess({})

      return
    }
    let cancelled = false
    Promise.all(
      contacts.map((c) =>
        api
          .getPortalAccess(id, c.id)
          .then((access) => [c.id, access] as const)
          .catch(() => null),
      ),
    ).then((rows) => {
      if (cancelled) return
      setPortalAccess(Object.fromEntries(rows.filter((r): r is readonly [string, PortalAccess] => r !== null)))
    })

    return () => {
      cancelled = true
    }
  }, [id, contacts])
  useEffect(() => {
    api.listCompanyIndividualGroups().then(setGroups).catch((e) => setError(e.message))
    api.listSetupItems({ list_type: 'industry' }).then(setIndustries).catch(() => setIndustries([]))
    api.listCompanyIndividuals().then(setAllCompanyIndividuals).catch(() => setAllCompanyIndividuals([]))
  }, [])

  // Fetch the chosen target's contacts so "relate to a specific
  // contact person there" can be offered -- confirmed 2026-09-11
  // ("company contacts relationship also").
  useEffect(() => {
    setRelTargetContactId('')
    if (!relTargetCompanyIndividualId) {
      setRelTargetContacts([])
      return
    }
    api.listContacts(relTargetCompanyIndividualId).then(setRelTargetContacts).catch(() => setRelTargetContacts([]))
  }, [relTargetCompanyIndividualId])

  async function onSave(e: FormEvent) {
    e.preventDefault()
    if (!id) return
    setError(null)
    setSaving(true)
    try {
      await api.updateCompanyIndividual(id, {
        customer_type: form.customer_type,
        name: form.name,
        customer_group_id: form.customer_group_id || null,
        industry_code: form.industry_code || null,
        legacy_customer_code: form.legacy_customer_code || null,
        contact_person: form.contact_person || null,
        uen: form.uen || null,
        gst_registration_no: form.gst_registration_no || null,
        billing_email: form.billing_email || null,
        phone: form.phone || null,
        mobile: form.mobile || null,
        website: form.website || null,
        address_line1: form.address_line1 || null,
        address_line2: form.address_line2 || null,
        address_city: form.address_city || null,
        address_state: form.address_state || null,
        address_postal_code: form.address_postal_code || null,
        address_country: form.address_country || null,
        tags: form.tags || null,
        exclude_auto_sent: excludeAutoSent,
        is_customer: isCustomer,
        is_supplier: isSupplier,
        terms_and_conditions: form.terms_and_conditions || null,
        memo: form.memo || null,
        billing_notes: form.billing_notes || null,
        payment_terms_days: form.payment_terms_days === '' ? null : parseInt(form.payment_terms_days, 10),
        data_expiry_date: form.data_expiry_date || null,
      })
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to save changes')
    } finally {
      setSaving(false)
    }
  }

  // The signed agreement upload (2026-09-12: "need to be able to see the
  // signed agreement") -- same immediate-save pattern as the consent
  // checkbox and the photo upload elsewhere in this app. ~2 MB cap
  // mirrors the backend's MAX_PDPA_DOCUMENT_CHARS.
  const MAX_PDPA_DOCUMENT_BYTES = 2 * 1024 * 1024

  async function onPickPdpaDocument(e: ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0]
    e.target.value = '' // allow re-picking the same file name later
    if (!id || !file) return
    setError(null)
    if (!(file.type.startsWith('image/') || file.type === 'application/pdf')) {
      setError('Please choose an image or PDF file.')
      return
    }
    if (file.size > MAX_PDPA_DOCUMENT_BYTES) {
      setError('That file is too large -- please use one under ~2 MB.')
      return
    }
    const reader = new FileReader()
    reader.onload = async () => {
      try {
        await api.setPdpaAgreementDocument(id, reader.result as string)
        refresh()
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to upload the signed agreement')
      }
    }
    reader.onerror = () => setError('Could not read that file.')
    reader.readAsDataURL(file)
  }

  async function onRemovePdpaDocument() {
    if (!id) return
    setError(null)
    try {
      await api.setPdpaAgreementDocument(id, null)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to remove the signed agreement')
    }
  }

  async function onTogglePdpaConsent(given: boolean) {
    if (!id) return
    setError(null)
    try {
      await api.setPdpaConsent(id, given)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to update PDPA consent')
    }
  }

  async function onToggleArchive() {
    if (!id || !customer) return
    setError(null)
    try {
      if (customer.is_archived) await api.unarchiveCompanyIndividual(id)
      else await api.archiveCompanyIndividual(id)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to update archive status')
    }
  }

  async function onToggleActive() {
    if (!id || !customer) return
    setError(null)
    try {
      if (customer.is_active) await api.deactivateCompanyIndividual(id)
      else await api.reactivateCompanyIndividual(id)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to update customer status')
    }
  }

  async function onAddContact(e: FormEvent) {
    e.preventDefault()
    if (!id) return
    setError(null)
    try {
      await api.createContact(id, {
        name: contactName,
        email: contactEmail || undefined,
        phone: contactPhone || undefined,
        direct_line: contactDirectLine || undefined,
      })
      setContactName('')
      setContactEmail('')
      setContactPhone('')
      setContactDirectLine('')
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to add contact')
    }
  }

  async function onToggleContact(contact: Contact) {
    if (!id) return
    setError(null)
    try {
      if (contact.is_active) await api.deactivateContact(id, contact.id)
      else await api.reactivateContact(id, contact.id)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to update contact')
    }
  }

  /**
   * Enable / reset / disable one Contact's Helpdesk Portal login.
   * The refusals (no contact email, no PDPA consent, archived customer)
   * all come back from the backend as a message -- they are business
   * rules, so they are not duplicated here.
   */
  async function onPortalAction(contact: Contact, action: 'enable' | 'reset' | 'disable') {
    if (!id) return
    if (action === 'disable' && !confirm(`Disable the Helpdesk Portal login for ${contact.name}?`)) return
    setError(null)
    setPortalTempPassword(null)
    setPortalBusyContactId(contact.id)
    try {
      const access =
        action === 'enable'
          ? await api.enablePortalAccess(id, contact.id)
          : action === 'reset'
            ? await api.resetPortalAccessPassword(id, contact.id)
            : await api.disablePortalAccess(id, contact.id)
      setPortalAccess((prev) => ({ ...prev, [contact.id]: access }))
      if (access.temporary_password) {
        setPortalTempPassword({ contactId: contact.id, password: access.temporary_password })
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to update portal access')
    } finally {
      setPortalBusyContactId(null)
    }
  }

  async function onAddBranch(e: FormEvent) {
    e.preventDefault()
    if (!id) return
    setError(null)
    try {
      await api.createBranch(id, {
        branch_name: branchForm.branch_name,
        branch_code: branchForm.branch_code || undefined,
        address_line1: branchForm.address_line1 || undefined,
        address_city: branchForm.address_city || undefined,
        address_postal_code: branchForm.address_postal_code || undefined,
        address_country: branchForm.address_country || undefined,
        phone: branchForm.phone || undefined,
      })
      setBranchForm(emptyBranchForm())
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to add branch')
    }
  }

  async function onToggleBranch(branch: Branch) {
    if (!id) return
    setError(null)
    try {
      if (branch.is_active) await api.deactivateBranch(id, branch.id)
      else await api.reactivateBranch(id, branch.id)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to update branch')
    }
  }

  async function onAddRelationship(e: FormEvent) {
    e.preventDefault()
    if (!id) return
    setError(null)
    try {
      await api.createCompanyIndividualRelationship(id, {
        to_customer_id: relTargetContactId ? undefined : relTargetCompanyIndividualId,
        to_contact_id: relTargetContactId || undefined,
        relationship_type: relType,
        note: relNote || undefined,
      })
      setRelTargetCompanyIndividualId('')
      setRelTargetContactId('')
      setRelType('')
      setRelNote('')
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to add relationship')
    }
  }

  async function onRemoveRelationship(relationshipId: string) {
    if (!id) return
    setError(null)
    try {
      await api.deactivateCompanyIndividualRelationship(id, relationshipId)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to remove relationship')
    }
  }

  async function onAddGroup(e: FormEvent) {
    e.preventDefault()
    setError(null)
    try {
      const group = await api.createCompanyIndividualGroup({ name: newGroupName })
      setNewGroupName('')
      setGroups((prev) => [...prev, group])
      setForm((p) => ({ ...p, customer_group_id: group.id }))
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to create customer group')
    }
  }

  if (notFound) return <p>Not found. <Link to="/company-individuals">Back to Company / Individual</Link></p>
  if (!customer) return <p>Loading...</p>

  // "The archive button is only to appear after the expiry date"
  // (2026-09-12) -- a UI-level gate, not a backend restriction: an
  // already-archived record can always be unarchived regardless of date.
  const isPastExpiry = Boolean(customer.data_expiry_date && new Date(customer.data_expiry_date) < new Date())

  function field(key: keyof ReturnType<typeof emptyForm>) {
    return {
      value: form[key],
      onChange: (e: ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) =>
        setForm((prev) => ({ ...prev, [key]: e.target.value })),
    }
  }

  function branchField(key: keyof ReturnType<typeof emptyBranchForm>) {
    return {
      value: branchForm[key],
      onChange: (e: ChangeEvent<HTMLInputElement>) =>
        setBranchForm((prev) => ({ ...prev, [key]: e.target.value })),
    }
  }

  return (
    <div>
      <p>
        <Link to="/company-individuals">&larr; Company / Individual</Link>
      </p>
      <h1>{customer.name}</h1>
      <p>
        <span className={`badge ${customer.is_active ? 'active' : 'draft'}`}>
          {customer.is_active ? 'Active' : 'Inactive'}
        </span>{' '}
        {customer.is_archived && <span className="badge draft">Archived</span>}{' '}
        <span className="muted">
          {customer.customer_type === 'individual' ? 'Individual' : 'Company'} &middot; added{' '}
          {formatDate(customer.created_at)}
          {customer.legacy_customer_code && <> &middot; Odoo ID {customer.legacy_customer_code}</>}
        </span>
      </p>
      {error && <div className="error-banner">{error}</div>}

      <div className="card">
        <h2>Profile</h2>
        <form onSubmit={onSave}>
          <div className="form-row">
            <label>Type</label>
            <select
              value={form.customer_type}
              onChange={(e) => setForm((p) => ({ ...p, customer_type: e.target.value as CompanyIndividualType }))}
            >
              <option value="company">Company</option>
              <option value="individual">Individual</option>
            </select>
          </div>
          <div className="form-row">
            <label>Roles</label>
            <div style={{ display: 'flex', gap: 16 }}>
              <label style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
                <input
                  type="checkbox"
                  checked={isCustomer}
                  onChange={(e) => setIsCustomer(e.target.checked)}
                  style={{ width: 'auto' }}
                />
                Is Customer
              </label>
              <label style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
                <input
                  type="checkbox"
                  checked={isSupplier}
                  onChange={(e) => setIsSupplier(e.target.checked)}
                  style={{ width: 'auto' }}
                />
                Is Supplier
              </label>
            </div>
          </div>
          <p className="muted" style={{ marginTop: -8 }}>
            Tick "Is Supplier" to make this record selectable on Purchase Order / Accounts
            Payable -- a record can be either, or both.
          </p>
          <div className="form-row">
            <label>Name</label>
            <input {...field('name')} required />
          </div>
          <div className="form-row">
            <label>Group of companies</label>
            <select
              value={form.customer_group_id}
              onChange={(e) => setForm((p) => ({ ...p, customer_group_id: e.target.value }))}
            >
              <option value="">Not grouped</option>
              {groups.map((g) => (
                <option key={g.id} value={g.id}>
                  {g.name}
                </option>
              ))}
            </select>
          </div>
          <div className="form-row">
            <label></label>
            <div style={{ display: 'flex', gap: 8 }}>
              <input
                value={newGroupName}
                onChange={(e) => setNewGroupName(e.target.value)}
                placeholder="New group name, e.g. XYZ Holdings Group"
                style={{ flex: 1 }}
              />
              <button type="button" className="secondary" disabled={!newGroupName} onClick={onAddGroup}>
                Create group &amp; assign
              </button>
            </div>
          </div>
          <p className="muted">
            Tags this customer as part of a group of companies (e.g. 5 separate legal entities under
            one holding) so they can be found together -- each stays its own full account with its
            own contracts and invoices.
          </p>
          <div className="form-row">
            <label>Industry</label>
            <select
              value={form.industry_code}
              onChange={(e) => setForm((p) => ({ ...p, industry_code: e.target.value }))}
            >
              <option value="">Not set</option>
              {industries.map((i) => (
                <option key={i.code} value={i.code}>
                  {i.name}
                </option>
              ))}
            </select>
          </div>
          <div className="form-row">
            <label>Contact person</label>
            <input {...field('contact_person')} placeholder="Quick reference, e.g. Mr Tan Wei Ming" />
          </div>
          <div className="form-row">
            <label>Odoo Customer ID</label>
            <input {...field('legacy_customer_code')} placeholder="Legacy code, for migration matching" />
          </div>
          <div className="form-row">
            <label>UEN</label>
            <input {...field('uen')} />
          </div>
          <div className="form-row">
            <label>GST registration no.</label>
            <input {...field('gst_registration_no')} />
          </div>
          <div className="form-row">
            <label>Email</label>
            <input {...field('billing_email')} type="email" />
          </div>
          <div className="form-row">
            <label>Phone</label>
            <input {...field('phone')} />
          </div>
          <div className="form-row">
            <label>Mobile</label>
            <input {...field('mobile')} />
          </div>
          <div className="form-row">
            <label>Website</label>
            <input {...field('website')} />
          </div>
          <div className="form-row">
            <label>Address line 1</label>
            <input {...field('address_line1')} />
          </div>
          <div className="form-row">
            <label>Address line 2</label>
            <input {...field('address_line2')} />
          </div>
          <div className="form-row">
            <label>City</label>
            <input {...field('address_city')} />
          </div>
          <div className="form-row">
            <label>State</label>
            <input {...field('address_state')} />
          </div>
          <div className="form-row">
            <label>Postal code</label>
            <input {...field('address_postal_code')} />
          </div>
          <div className="form-row">
            <label>Country</label>
            <input {...field('address_country')} placeholder="e.g. Singapore" />
          </div>
          <div className="form-row">
            <label>Tags</label>
            <input {...field('tags')} placeholder="e.g. B2B, VIP" />
          </div>
          <div className="form-row">
            <label>Payment terms (days from invoice date)</label>
            <input
              type="number"
              min={0}
              value={form.payment_terms_days}
              onChange={(e) => setForm((p) => ({ ...p, payment_terms_days: e.target.value }))}
              placeholder="Leave blank if not yet agreed"
            />
          </div>
          <div className="form-row">
            <label>Data expiry date (PDPA)</label>
            <input
              type="date"
              value={form.data_expiry_date}
              onChange={(e) => setForm((p) => ({ ...p, data_expiry_date: e.target.value }))}
            />
          </div>
          <p className="muted" style={{ marginTop: -8 }}>
            After this date, this record's data should be archived (see "PDPA &amp; Data
            Retention" below) -- leave blank if no expiry has been agreed yet.
          </p>
          <div className="form-row">
            <label>Terms &amp; conditions (shown on orders)</label>
            <textarea {...field('terms_and_conditions')} rows={3} />
          </div>
          <div className="form-row">
            <label>Memo (internal only)</label>
            <textarea {...field('memo')} rows={3} placeholder="Never shown on any customer-facing document" />
          </div>
          <div className="form-row">
            <label>Billing notes</label>
            <textarea
              {...field('billing_notes')}
              rows={3}
              placeholder="For billing/AR staff, e.g. &quot;requires PO number on every invoice&quot;"
            />
          </div>
          <div className="form-row">
            <label>
              <input
                type="checkbox"
                checked={excludeAutoSent}
                onChange={(e) => setExcludeAutoSent(e.target.checked)}
                style={{ width: 'auto', marginRight: 8 }}
              />
              Exclude from automated sending
            </label>
          </div>
          <p className="muted">
            Reserved for later -- there is no automated invoice/reminder emailing built yet, so this
            checkbox has no effect today.
          </p>
          <button type="submit" disabled={saving}>
            {saving ? 'Saving...' : 'Save changes'}
          </button>
        </form>
      </div>

      <div className="card">
        <h2>Contact Person</h2>
        <p className="muted">Individual contacts at this Company / Individual -- separate from the quick "Contact person" field above.</p>
        <p className="muted">
          "Portal login" is this contact's own Customer Helpdesk Portal sign-in. It needs an
          email address on the contact, PDPA consent recorded on this Company / Individual, and
          an unarchived record. Enabling it issues a temporary password the contact must change
          on first sign-in -- emailed if SMTP is configured, otherwise shown here once.
        </p>
        <table>
          <thead>
            <tr>
              <th>Name</th>
              <th>Email</th>
              <th>Phone</th>
              <th>Direct line</th>
              <th>Status</th>
              <th>Portal login</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {contacts.map((c) => {
              const access = portalAccess[c.id]
              const busy = portalBusyContactId === c.id

              return (
                <tr key={c.id}>
                  <td>{c.name}</td>
                  <td>{c.email ?? '-'}</td>
                  <td>{c.phone ?? '-'}</td>
                  <td>{c.direct_line ?? '-'}</td>
                  <td>
                    <span className={`badge ${c.is_active ? 'active' : 'draft'}`}>
                      {c.is_active ? 'Active' : 'Inactive'}
                    </span>
                  </td>
                  <td>
                    {access?.enabled ? (
                      <>
                        <span className="badge active">Enabled</span>
                        {access.locked && <span className="badge draft" style={{ marginLeft: 6 }}>Locked out</span>}
                        {access.must_change_password && (
                          <span className="badge draft" style={{ marginLeft: 6 }}>Must set password</span>
                        )}
                        <div className="muted" style={{ marginTop: 4 }}>
                          {access.email}
                          <br />
                          {access.last_login_at
                            ? `Last signed in ${formatDateTime(access.last_login_at)}`
                            : 'Never signed in'}
                        </div>
                      </>
                    ) : (
                      <span className="muted">{access ? 'Not enabled' : '-'}</span>
                    )}
                    {portalTempPassword?.contactId === c.id && (
                      <div className="muted" style={{ marginTop: 6 }}>
                        Temporary password: <strong>{portalTempPassword.password}</strong>
                        <br />
                        Shown once -- pass it to the contact now. (Configure SMTP and it is
                        emailed instead.)
                      </div>
                    )}
                  </td>
                  <td>
                    <button className="secondary" onClick={() => onToggleContact(c)}>
                      {c.is_active ? 'Deactivate' : 'Reactivate'}
                    </button>{' '}
                    {access?.enabled ? (
                      <>
                        <button
                          className="secondary"
                          disabled={busy}
                          onClick={() => onPortalAction(c, 'reset')}
                        >
                          Reset portal password
                        </button>{' '}
                        <button
                          className="secondary"
                          disabled={busy}
                          onClick={() => onPortalAction(c, 'disable')}
                        >
                          Disable portal login
                        </button>
                      </>
                    ) : (
                      <button
                        className="secondary"
                        disabled={busy}
                        onClick={() => onPortalAction(c, 'enable')}
                      >
                        {access && !access.enabled && access.email
                          ? 'Re-enable portal login'
                          : 'Enable portal login'}
                      </button>
                    )}
                  </td>
                </tr>
              )
            })}
            {contacts.length === 0 && (
              <tr>
                <td colSpan={7} className="muted">
                  No contact person recorded yet.
                </td>
              </tr>
            )}
          </tbody>
        </table>

        <form onSubmit={onAddContact} style={{ marginTop: 14 }}>
          <div className="form-row">
            <label>Name</label>
            <input value={contactName} onChange={(e) => setContactName(e.target.value)} required />
          </div>
          <div className="form-row">
            <label>Email (optional)</label>
            <input value={contactEmail} onChange={(e) => setContactEmail(e.target.value)} type="email" />
          </div>
          <div className="form-row">
            <label>Phone (optional)</label>
            <input value={contactPhone} onChange={(e) => setContactPhone(e.target.value)} />
          </div>
          <div className="form-row">
            <label>Direct line (optional)</label>
            <input value={contactDirectLine} onChange={(e) => setContactDirectLine(e.target.value)} />
          </div>
          <button type="submit">Add contact person</button>
        </form>
      </div>

      <div className="card">
        <h2>Branches</h2>
        <p className="muted">
          Branch locations of this same customer -- not separate billing accounts, just other
          addresses and telephone numbers for the same company.
        </p>
        <table>
          <thead>
            <tr>
              <th>Branch</th>
              <th>Code</th>
              <th>Address</th>
              <th>Phone</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {branches.map((b) => (
              <tr key={b.id}>
                <td>{b.branch_name}</td>
                <td className="muted">{b.branch_code ?? '-'}</td>
                <td className="muted">
                  {[b.address_line1, b.address_city, b.address_country].filter(Boolean).join(', ') || '-'}
                </td>
                <td>{b.phone ?? '-'}</td>
                <td>
                  <span className={`badge ${b.is_active ? 'active' : 'draft'}`}>
                    {b.is_active ? 'Active' : 'Inactive'}
                  </span>
                </td>
                <td>
                  <button className="secondary" onClick={() => onToggleBranch(b)}>
                    {b.is_active ? 'Deactivate' : 'Reactivate'}
                  </button>
                </td>
              </tr>
            ))}
            {branches.length === 0 && (
              <tr>
                <td colSpan={6} className="muted">
                  No branches recorded yet.
                </td>
              </tr>
            )}
          </tbody>
        </table>

        <form onSubmit={onAddBranch} style={{ marginTop: 14 }}>
          <div className="form-row">
            <label>Branch name</label>
            <input {...branchField('branch_name')} required placeholder="e.g. Jurong Branch" />
          </div>
          <div className="form-row">
            <label>Branch code</label>
            <input {...branchField('branch_code')} />
          </div>
          <div className="form-row">
            <label>Address line 1</label>
            <input {...branchField('address_line1')} />
          </div>
          <div className="form-row">
            <label>City</label>
            <input {...branchField('address_city')} />
          </div>
          <div className="form-row">
            <label>Postal code</label>
            <input {...branchField('address_postal_code')} />
          </div>
          <div className="form-row">
            <label>Country</label>
            <input {...branchField('address_country')} placeholder="e.g. Singapore" />
          </div>
          <div className="form-row">
            <label>Telephone</label>
            <input {...branchField('phone')} />
          </div>
          <button type="submit">Add branch</button>
        </form>
      </div>

      <div className="card">
        <h2>Relationships</h2>
        <p className="muted">
          Link this record to another Company / Individual -- at the company level, the individual
          level, or to one of that company's named Contacts.
        </p>
        <table>
          <thead>
            <tr>
              <th>Related to</th>
              <th>Level</th>
              <th>Relationship</th>
              <th>Note</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {relationships.map((r) => (
              <tr key={r.id}>
                <td>
                  {r.to_customer_id ? (
                    <Link to={`/company-individuals/${r.to_customer_id}`}>{r.to_customer_name}</Link>
                  ) : (
                    <>
                      {r.to_contact_name}{' '}
                      <span className="muted">
                        (
                        <Link to={`/company-individuals/${r.to_contact_customer_id}`}>{r.to_contact_customer_name}</Link>
                        )
                      </span>
                    </>
                  )}
                </td>
                <td className="muted">
                  {r.to_contact_id ? 'Contact' : r.to_customer_type === 'individual' ? 'Individual' : 'Company'}
                </td>
                <td>{r.relationship_type}</td>
                <td className="muted">{r.note ?? '-'}</td>
                <td>
                  <button className="secondary" onClick={() => onRemoveRelationship(r.id)}>
                    Remove
                  </button>
                </td>
              </tr>
            ))}
            {relationships.length === 0 && (
              <tr>
                <td colSpan={5} className="muted">
                  No relationships recorded yet.
                </td>
              </tr>
            )}
          </tbody>
        </table>

        <form onSubmit={onAddRelationship} style={{ marginTop: 14 }}>
          <div className="form-row">
            <label>Related Company / Individual</label>
            <select
              value={relTargetCompanyIndividualId}
              onChange={(e) => setRelTargetCompanyIndividualId(e.target.value)}
              required
            >
              <option value="">Select...</option>
              {allCompanyIndividuals
                .filter((c) => c.id !== id)
                .map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.name} ({c.customer_type})
                  </option>
                ))}
            </select>
          </div>
          {relTargetContacts.length > 0 && (
            <div className="form-row">
              <label>Specific contact (optional)</label>
              <select value={relTargetContactId} onChange={(e) => setRelTargetContactId(e.target.value)}>
                <option value="">Whole company / individual</option>
                {relTargetContacts.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.name}
                  </option>
                ))}
              </select>
            </div>
          )}
          <div className="form-row">
            <label>Relationship</label>
            <input
              value={relType}
              onChange={(e) => setRelType(e.target.value)}
              required
              placeholder="e.g. Parent Company, Referred By, Business Partner"
              list="relationship-type-suggestions"
            />
            <datalist id="relationship-type-suggestions">
              <option value="Parent Company" />
              <option value="Subsidiary" />
              <option value="Sister Company" />
              <option value="Referred By" />
              <option value="Business Partner" />
              <option value="Same Decision Maker" />
            </datalist>
          </div>
          <div className="form-row">
            <label>Note (optional)</label>
            <input value={relNote} onChange={(e) => setRelNote(e.target.value)} />
          </div>
          <button type="submit">Add relationship</button>
        </form>
      </div>

      <div className="card">
        <h2>Products in use</h2>
        <p className="muted">
          Every catalog product currently covered under one of this customer's contracts (Product
          Coverage) -- a quick way to see what they already have before offering a renewal or
          add-on. Visibility only, no automated renewal reminders yet.
        </p>
        <table>
          <thead>
            <tr>
              <th>Product</th>
              <th>Contract</th>
              <th>Type</th>
              <th>Status</th>
              <th>Coverage</th>
            </tr>
          </thead>
          <tbody>
            {productUsage.map((row) => (
              <tr key={`${row.contract_id}-${row.product_id}`}>
                <td>{row.product_name}</td>
                <td>
                  <Link to={`/contracts/${row.contract_id}`}>{row.contract_number}</Link>
                </td>
                <td className="muted">{row.contract_kind}</td>
                <td>
                  <span className={`badge ${row.contract_status}`}>{row.contract_status}</span>
                </td>
                <td className="muted">
                  {row.start_date} &rarr; {row.end_date}
                </td>
              </tr>
            ))}
            {productUsage.length === 0 && (
              <tr>
                <td colSpan={5} className="muted">
                  No product coverage recorded on any contract yet.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      <div className="card">
        <h2>PDPA &amp; Data Retention</h2>
        <div className="form-row">
          <label>
            <input
              type="checkbox"
              checked={customer.pdpa_consent_given}
              onChange={(e) => onTogglePdpaConsent(e.target.checked)}
              style={{ width: 'auto', marginRight: 8 }}
            />
            PDPA Agreement e-signed
          </label>
        </div>
        <p className="muted" style={{ marginTop: -8 }}>
          {customer.pdpa_consent_given && customer.pdpa_consent_at
            ? `Recorded ${formatDateTime(customer.pdpa_consent_at)}.`
            : 'Not yet recorded. Ticking this box files it in the system with the current date/time.'}
        </p>

        <div className="form-row">
          <label>Signed agreement</label>
          <div>
            {customer.pdpa_agreement_document ? (
              <div>
                {customer.pdpa_agreement_document.startsWith('data:application/pdf') ? (
                  <embed
                    src={customer.pdpa_agreement_document}
                    type="application/pdf"
                    style={{ width: '100%', maxWidth: 480, height: 360, border: '1px solid var(--border)' }}
                  />
                ) : (
                  <a href={customer.pdpa_agreement_document} target="_blank" rel="noreferrer">
                    <img
                      src={customer.pdpa_agreement_document}
                      alt="Signed PDPA Agreement"
                      style={{ maxWidth: 240, maxHeight: 240, display: 'block', border: '1px solid var(--border)' }}
                    />
                  </a>
                )}
                <button
                  type="button"
                  className="secondary"
                  style={{ marginTop: 8 }}
                  onClick={onRemovePdpaDocument}
                >
                  Remove
                </button>
              </div>
            ) : (
              <>
                <input type="file" accept="image/*,application/pdf" onChange={onPickPdpaDocument} />
                <p className="muted" style={{ marginTop: 4 }}>
                  Upload a photo, scan, or PDF of the signed agreement (up to ~2 MB) so it can be
                  viewed here later.
                </p>
              </>
            )}
          </div>
        </div>

        {customer.data_expiry_date && (
          <p>
            Data expiry date: <strong>{customer.data_expiry_date}</strong>
            {isPastExpiry && !customer.is_archived && (
              <span className="badge exceeded" style={{ marginLeft: 8 }}>
                Past expiry -- archive this record
              </span>
            )}
          </p>
        )}

        <p className="muted">
          Archiving keeps all of this record's data intact in the same database -- per
          CLAUDE.md, nothing is ever permanently deleted -- but hides it from every normal
          list and picker.
          {customer.is_archived && customer.archived_at && (
            <> Archived {formatDateTime(customer.archived_at)}.</>
          )}
        </p>
        {customer.is_archived ? (
          <button className="secondary" onClick={onToggleArchive}>
            Unarchive
          </button>
        ) : isPastExpiry ? (
          <button className="secondary" onClick={onToggleArchive}>
            Archive now
          </button>
        ) : (
          <p className="muted">
            {customer.data_expiry_date
              ? 'Archiving becomes available once the data expiry date above has passed.'
              : 'Set a data expiry date above to enable archiving.'}
          </p>
        )}
      </div>

      <div className="card">
        <h2>Company / Individual status</h2>
        <p className="muted">
          Deactivating a record keeps their history intact -- contracts, job orders and invoices stay
          exactly as they are (per CLAUDE.md: never permanently delete business records).
        </p>
        <button className="secondary" onClick={onToggleActive}>
          {customer.is_active ? 'Deactivate' : 'Reactivate'}
        </button>
      </div>

      <div className="card">
        <h2>Recent activity</h2>
        <table>
          <thead>
            <tr>
              <th>When</th>
              <th>Action</th>
              <th>By</th>
              <th>Details</th>
            </tr>
          </thead>
          <tbody>
            {auditLog.map((entry) => (
              <tr key={entry.id}>
                <td>{formatDateTime(entry.at)}</td>
                <td>{ACTION_LABELS[entry.action] ?? entry.action}</td>
                <td>{entry.actor_name ?? 'System'}</td>
                <td className="muted">{entry.details ?? entry.reason ?? '-'}</td>
              </tr>
            ))}
            {auditLog.length === 0 && (
              <tr>
                <td colSpan={4} className="muted">
                  No activity recorded yet.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      <button className="secondary" onClick={() => navigate('/company-individuals')}>
        Back to Company / Individual
      </button>
    </div>
  )
}
