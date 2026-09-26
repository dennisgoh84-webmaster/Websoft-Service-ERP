import { useEffect, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import ExportControl from '../components/ExportControl'
import {
  api,
  downloadBlob,
  type CompanyIndividual,
  type CompanyIndividualGroup,
  type CompanyIndividualType,
  type SetupListItem,
} from '../lib/api'

export default function CompanyIndividualsPage() {
  const [customers, setCustomers] = useState<CompanyIndividual[]>([])
  const [groups, setGroups] = useState<CompanyIndividualGroup[]>([])
  const [industries, setIndustries] = useState<SetupListItem[]>([])
  const [name, setName] = useState('')
  const [customerType, setCompanyIndividualType] = useState<CompanyIndividualType>('company')
  const [email, setEmail] = useState('')
  const [phone, setPhone] = useState('')
  const [terms, setTerms] = useState('')
  const [isSupplier, setIsSupplier] = useState(false)
  const [error, setError] = useState<string | null>(null)

  // Dynamic filter -- search for a particular customer, pull up a
  // whole group of companies together, or narrow to one industry
  // (confirmed 2026-09-11: customer grouping by industry).
  const [q, setQ] = useState('')
  const [filterGroup, setFilterGroup] = useState('')
  const [filterIndustry, setFilterIndustry] = useState('')
  const [showInactive, setShowInactive] = useState(false)
  // PDPA (2026-09-12): archived records are hidden even from "Show
  // inactive" -- a separate, explicit opt-in.
  const [showArchived, setShowArchived] = useState(false)

  function refresh() {
    api
      .listCompanyIndividuals({
        q: q || undefined,
        customer_group_id: filterGroup || undefined,
        industry_code: filterIndustry || undefined,
        include_inactive: showInactive,
        include_archived: showArchived,
      })
      .then(setCustomers)
      .catch((e) => setError(e.message))
  }

  useEffect(refresh, [q, filterGroup, filterIndustry, showInactive, showArchived])
  useEffect(() => {
    api.listCompanyIndividualGroups().then(setGroups).catch((e) => setError(e.message))
    api.listSetupItems({ list_type: 'industry' }).then(setIndustries).catch(() => setIndustries([]))
  }, [])

  function groupName(id: string | null) {
    if (!id) return null
    return groups.find((g) => g.id === id)?.name ?? null
  }

  function industryName(code: string | null) {
    if (!code) return null
    return industries.find((i) => i.code === code)?.name ?? code
  }

  function resetFilters() {
    setQ('')
    setFilterGroup('')
    setFilterIndustry('')
    setShowInactive(false)
    setShowArchived(false)
  }

  async function onExport(format: string) {
    setError(null)
    const filters = {
      q: q || undefined,
      customer_group_id: filterGroup || undefined,
      industry_code: filterIndustry || undefined,
      include_inactive: showInactive,
    }
    if (format === 'csv') {
      downloadBlob(await api.exportCompanyIndividualsCsv(filters), 'company-individuals.csv')
    } else {
      downloadBlob(await api.exportCompanyIndividualsExcel(filters), 'company-individuals.xlsx')
    }
  }

  async function onCreate(e: FormEvent) {
    e.preventDefault()
    setError(null)
    try {
      await api.createCompanyIndividual({
        name,
        customer_type: customerType,
        billing_email: email || undefined,
        phone: phone || undefined,
        payment_terms_days: terms === '' ? null : parseInt(terms, 10),
        is_supplier: isSupplier,
      })
      setName('')
      setCompanyIndividualType('company')
      setEmail('')
      setPhone('')
      setTerms('')
      setIsSupplier(false)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to create customer')
    }
  }

  return (
    <div>
      <h1>Company / Individual</h1>
      <p className="muted">
        Company / Individual records (Customer Management) -- the account each contract, job order,
        and invoice belongs to. A record can also be ticked "Is Supplier" (here, or on its own page)
        to make it selectable on Purchase Order / Accounts Payable -- there is no separate supplier
        file. Use Relationships (on a record's own page) to link it to another Company / Individual
        or a specific Contact there.
      </p>

      <div className="card" style={{ marginTop: 20 }}>
        <h2>Add Company / Individual</h2>
        <p className="muted">
          This is a quick add -- everything else (address, UEN, GST no., contact people, branches,
          terms &amp; conditions) is filled in from its own page after it's created.
        </p>
        <form onSubmit={onCreate}>
          <div className="form-row">
            <label>Type</label>
            <select value={customerType} onChange={(e) => setCompanyIndividualType(e.target.value as CompanyIndividualType)}>
              <option value="company">Company</option>
              <option value="individual">Individual</option>
            </select>
          </div>
          <div className="form-row">
            <label>Name</label>
            {/* Always FULL CAPITALS (Dennis, 2026-09-26); the server applies the same rule. */}
            <input value={name} onChange={(e) => setName(e.target.value.toUpperCase())} autoCapitalize="characters" required />
          </div>
          <div className="form-row">
            <label>Email (optional)</label>
            <input value={email} onChange={(e) => setEmail(e.target.value)} type="email" />
          </div>
          <div className="form-row">
            <label>Phone (optional)</label>
            <input value={phone} onChange={(e) => setPhone(e.target.value)} />
          </div>
          <div className="form-row">
            <label>Payment terms (days from invoice date)</label>
            <input
              type="number"
              min={0}
              value={terms}
              onChange={(e) => setTerms(e.target.value)}
              placeholder="e.g. 30 -- leave blank if not yet agreed"
            />
          </div>
          <div className="form-row">
            <label>
              <input
                type="checkbox"
                checked={isSupplier}
                onChange={(e) => setIsSupplier(e.target.checked)}
                style={{ width: 'auto', marginRight: 8 }}
              />
              Is Supplier (selectable on Purchase Order / Accounts Payable)
            </label>
          </div>
          {error && <div className="error-banner">{error}</div>}
          <button type="submit">Add Company / Individual</button>
        </form>
      </div>

      <div className="card">
        <div className="filter-bar">
          <div className="form-row" style={{ margin: 0, minWidth: 220 }}>
            <label>Search</label>
            <input
              value={q}
              onChange={(e) => setQ(e.target.value)}
              placeholder="Name, email, phone, UEN, tag..."
            />
          </div>
          <div className="form-row" style={{ margin: 0 }}>
            <label>Group of companies</label>
            <select value={filterGroup} onChange={(e) => setFilterGroup(e.target.value)}>
              <option value="">All</option>
              {groups.map((g) => (
                <option key={g.id} value={g.id}>
                  {g.name}
                </option>
              ))}
            </select>
          </div>
          <div className="form-row" style={{ margin: 0 }}>
            <label>Industry</label>
            <select value={filterIndustry} onChange={(e) => setFilterIndustry(e.target.value)}>
              <option value="">All</option>
              {industries.map((i) => (
                <option key={i.code} value={i.code}>
                  {i.name}
                </option>
              ))}
            </select>
          </div>
          <label style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 13 }}>
            <input type="checkbox" checked={showInactive} onChange={(e) => setShowInactive(e.target.checked)} />
            Show inactive
          </label>
          <label style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 13 }}>
            <input type="checkbox" checked={showArchived} onChange={(e) => setShowArchived(e.target.checked)} />
            Show archived
          </label>
          <button type="button" className="secondary" onClick={resetFilters}>
            Reset filters
          </button>
          <ExportControl
            formats={[
              { value: 'csv', label: 'CSV' },
              { value: 'excel', label: 'Excel' },
            ]}
            onExport={onExport}
            onError={setError}
          />
        </div>

        <h2>Company / Individual ({customers.length})</h2>
        <div style={{ overflowX: 'auto' }}>
          <table>
            <thead>
              <tr>
                <th>Name</th>
                <th>Group</th>
                <th>Industry</th>
                <th>Type</th>
                <th>Roles</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Address</th>
                <th>Payment terms</th>
                <th>Status</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {customers.map((c) => (
                <tr key={c.id}>
                  <td>
                    <Link to={`/company-individuals/${c.id}`}>{c.name}</Link>
                  </td>
                  <td className="muted">{groupName(c.customer_group_id) ?? '-'}</td>
                  <td className="muted">{industryName(c.industry_code) ?? '-'}</td>
                  <td className="muted">{c.customer_type === 'individual' ? 'Individual' : 'Company'}</td>
                  <td className="muted">
                    {[c.is_customer && 'Customer', c.is_supplier && 'Supplier'].filter(Boolean).join(' + ') || '-'}
                  </td>
                  <td>{c.billing_email ?? '-'}</td>
                  <td>{c.phone ?? '-'}</td>
                  <td className="muted">
                    {[c.address_line1, c.address_city, c.address_country].filter(Boolean).join(', ') || '-'}
                  </td>
                  <td>
                    {c.payment_terms_days === null ? (
                      <span className="muted">not agreed</span>
                    ) : (
                      `Net ${c.payment_terms_days} days`
                    )}
                  </td>
                  <td>
                    <span className={`badge ${c.is_active ? 'active' : 'draft'}`}>
                      {c.is_active ? 'Active' : 'Inactive'}
                    </span>{' '}
                    {c.is_archived && <span className="badge draft">Archived</span>}
                  </td>
                  <td style={{ display: 'flex', gap: 10 }}>
                    <Link to={`/company-individuals/${c.id}`}>Open</Link>
                    <Link to={`/contracts?customer=${c.id}`}>Service Contracts</Link>
                  </td>
                </tr>
              ))}
              {customers.length === 0 && (
                <tr>
                  <td colSpan={11} className="muted">
                    No customers match these filters.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  )
}
