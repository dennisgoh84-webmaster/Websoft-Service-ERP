// Setup Lists -- global reference data shared by every company. One
// component, mounted once per list at /setup-lists/:listType, so each
// list is its own Maintenance menu entry (Dennis, 2026-09-15: "Setup
// Lists - to shift out into the Maintenance Menu") without ten
// near-identical pages. State and City belong to a Country.
import { useEffect, useState, type FormEvent } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import ExportControl from '../components/ExportControl'
import { api, downloadBlob, type SetupListItem, type SetupListType } from '../lib/api'

export const LIST_TYPES: { value: SetupListType; label: string; blurb: string }[] = [
  { value: 'country', label: 'Country', blurb: 'Countries, for addresses. States and cities hang off these.' },
  { value: 'state', label: 'State / Province', blurb: 'States and provinces, each under its country.' },
  { value: 'city', label: 'City', blurb: 'Cities, each under its country, for the address pickers.' },
  { value: 'nationality', label: 'Nationality', blurb: 'Nationalities.' },
  { value: 'area_code', label: 'Area Code', blurb: 'Telephone area codes.' },
  { value: 'currency', label: 'Currency', blurb: 'Currency codes.' },
  { value: 'industry', label: 'Industry', blurb: 'Industries, for grouping Companies / Individuals.' },
  { value: 'product_category', label: 'Product Category', blurb: 'Categories the Product Catalog picks from.' },
  {
    value: 'unit_of_measure',
    label: 'Unit of Measure',
    blurb: 'Units the Product Catalog and quotation lines pick from. A line in "Hours" is what becomes a Service Support contract when a quotation is accepted.',
  },
  { value: 'relationship', label: 'Relationship', blurb: 'Relationship types between Companies / Individuals (Parent Company, Referred By, ...).' },
]

const HAS_COUNTRY: SetupListType[] = ['state', 'city']

export default function SetupListsPage() {
  const { listType: routeType } = useParams<{ listType: string }>()
  const navigate = useNavigate()
  const listType = (LIST_TYPES.some((t) => t.value === routeType) ? routeType : 'country') as SetupListType
  const meta = LIST_TYPES.find((t) => t.value === listType)!
  const hasCountry = HAS_COUNTRY.includes(listType)
  const [items, setItems] = useState<SetupListItem[]>([])
  const [countries, setCountries] = useState<SetupListItem[]>([])
  const [countryFilter, setCountryFilter] = useState('')
  const [showInactive, setShowInactive] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const [parentCode, setParentCode] = useState('')
  const [creating, setCreating] = useState(false)
  const [editing, setEditing] = useState<Record<string, string>>({})

  function refresh() {
    api
      .listSetupItems({
        list_type: listType,
        include_inactive: showInactive,
        parent_code: hasCountry && countryFilter ? countryFilter : undefined,
      })
      .then(setItems)
      .catch((e) => setError(e.message))
  }

  useEffect(refresh, [listType, showInactive, countryFilter, hasCountry])
  useEffect(() => {
    // The Country pickers (State's and City's owning country, the
    // list filter) and the Country column.
    api.listSetupItems({ list_type: 'country' }).then(setCountries).catch(() => setCountries([]))
  }, [])

  async function onCreate(e: FormEvent) {
    e.preventDefault()
    setError(null)
    setCreating(true)
    try {
      await api.createSetupItem({
        list_type: listType,
        code: code.toUpperCase(),
        name,
        parent_code: hasCountry ? parentCode || null : null,
      })
      setCode('')
      setName('')
      setParentCode('')
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to add item')
    } finally {
      setCreating(false)
    }
  }

  async function onRename(item: SetupListItem) {
    const newName = editing[item.id]
    if (!newName || newName === item.name) return
    setError(null)
    try {
      await api.updateSetupItem(item.id, { name: newName })
      setEditing((prev) => ({ ...prev, [item.id]: '' }))
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to rename item')
    }
  }

  async function onToggleActive(item: SetupListItem) {
    setError(null)
    try {
      await api.updateSetupItem(item.id, { is_active: !item.is_active })
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to update item')
    }
  }

  async function onExport(format: string) {
    setError(null)
    const filters = { list_type: listType, include_inactive: showInactive }
    const blob = format === 'csv' ? await api.exportSetupItemsCsv(filters) : await api.exportSetupItemsExcel(filters)
    downloadBlob(blob, `${listType}.${format === 'csv' ? 'csv' : 'xlsx'}`)
  }

  const countryName = (parentCode: string | null) => {
    if (!parentCode) return '-'
    return countries.find((c) => c.code === parentCode)?.name ?? parentCode
  }

  return (
    <div>
      <h1>{meta.label}</h1>
      <p className="muted">{meta.blurb} Shared by every company.</p>
      {error && <div className="error-banner">{error}</div>}

      <div className="card">
        <div className="filter-bar">
          <div className="form-row" style={{ margin: 0 }}>
            <label>List</label>
            <select value={listType} onChange={(e) => navigate(`/setup-lists/${e.target.value}`)}>
              {LIST_TYPES.map((t) => (
                <option key={t.value} value={t.value}>
                  {t.label}
                </option>
              ))}
            </select>
          </div>
          {hasCountry && (
            <div className="form-row" style={{ margin: 0 }}>
              <label>Country</label>
              <select value={countryFilter} onChange={(e) => setCountryFilter(e.target.value)}>
                <option value="">All countries</option>
                {countries.map((c) => (
                  <option key={c.code} value={c.code}>
                    {c.name}
                  </option>
                ))}
              </select>
            </div>
          )}
          <label style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 13 }}>
            <input type="checkbox" checked={showInactive} onChange={(e) => setShowInactive(e.target.checked)} />
            Show inactive
          </label>
          <ExportControl formats={[{ value: 'csv', label: 'CSV' }, { value: 'excel', label: 'Excel' }]} onExport={onExport} onError={setError} />
        </div>

        <h2>
          {meta.label} ({items.length})
        </h2>
        <div className="report-table-wrap" style={{ overflowX: 'auto' }}>
          <table>
            <thead>
              <tr>
                <th>Code</th>
                <th>Name</th>
                {hasCountry && <th>Country</th>}
                <th>Status</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {items.map((item) => (
                <tr key={item.id} style={{ opacity: item.is_active ? 1 : 0.6 }}>
                  <td>{item.code}</td>
                  <td>
                    <input
                      value={editing[item.id] ?? item.name}
                      onChange={(e) => setEditing((prev) => ({ ...prev, [item.id]: e.target.value }))}
                      onBlur={() => onRename(item)}
                      style={{ width: '100%', minWidth: 200 }}
                    />
                  </td>
                  {hasCountry && <td>{countryName(item.parent_code)}</td>}
                  <td>
                    <span className={`badge ${item.is_active ? 'active' : 'draft'}`}>
                      {item.is_active ? 'Active' : 'Inactive'}
                    </span>
                  </td>
                  <td>
                    <button className="secondary" onClick={() => onToggleActive(item)}>
                      {item.is_active ? 'Deactivate' : 'Reactivate'}
                    </button>
                  </td>
                </tr>
              ))}
              {items.length === 0 && (
                <tr>
                  <td colSpan={hasCountry ? 4 : 3} className="muted">
                    Nothing set up yet.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>

      <div className="card">
        <h2>Add to {meta.label}</h2>
        <form onSubmit={onCreate}>
          <div className="form-row">
            <label>Code</label>
            <input value={code} onChange={(e) => setCode(e.target.value)} placeholder="e.g. SG" required />
          </div>
          <div className="form-row">
            <label>Name</label>
            <input value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Singapore" required />
          </div>
          {hasCountry && (
            <div className="form-row">
              <label>Country</label>
              <select value={parentCode} onChange={(e) => setParentCode(e.target.value)} required>
                <option value="">Select a country</option>
                {countries.map((c) => (
                  <option key={c.code} value={c.code}>
                    {c.name}
                  </option>
                ))}
              </select>
              {countries.length === 0 && (
                <span className="muted">No countries set up yet -- add them under Maintenance → Country first.</span>
              )}
            </div>
          )}
          <button type="submit" disabled={creating}>
            {creating ? 'Adding...' : 'Add'}
          </button>
        </form>
      </div>
    </div>
  )
}
