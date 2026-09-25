import { useEffect, useState, type FormEvent } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import DateInput from '../components/DateInput'
import ExportControl from '../components/ExportControl'
import {
  api,
  downloadBlob,
  PROSPECT_BADGE,
  PROSPECT_STATUSES,
  seesAllProspects,
  type CompanyIndividual,
  type CurrentUser,
  type Prospect,
} from '../lib/api'
import { useAuth } from '../lib/AuthContext'
import { formatDate, formatMoney } from '../lib/format'

/** Prospect / Leads: one sales opportunity per row, for a Company / Individual. */
export default function ProspectsPage() {
  const { user } = useAuth()
  const seesAll = seesAllProspects(user?.role)
  const [prospects, setProspects] = useState<Prospect[]>([])
  const [customers, setCustomers] = useState<CompanyIndividual[]>([])
  const [staff, setStaff] = useState<CurrentUser[]>([])
  const [error, setError] = useState<string | null>(null)

  // "New prospect" on a Company / Individual's page arrives with it preselected.
  const [searchParams] = useSearchParams()
  const [customerId, setCustomerId] = useState(searchParams.get('customer_id') ?? '')
  const [title, setTitle] = useState('')
  const [source, setSource] = useState('')
  const [estimated, setEstimated] = useState('')
  const [expectedClose, setExpectedClose] = useState('')
  const [salespersonId, setSalespersonId] = useState('')
  const [notes, setNotes] = useState('')

  const [filterStatus, setFilterStatus] = useState('')
  const [filterCustomer, setFilterCustomer] = useState(searchParams.get('customer_id') ?? '')
  const [filterSalesperson, setFilterSalesperson] = useState('')
  const [filterText, setFilterText] = useState('')

  const filters = {
    status: filterStatus || undefined,
    customer_id: filterCustomer || undefined,
    salesperson_user_id: filterSalesperson || undefined,
    q: filterText || undefined,
  }
  // Bumped after a create so the list reloads with the current filters.
  const [reloads, setReloads] = useState(0)

  useEffect(() => {
    api.listCompanyIndividuals().then(setCustomers).catch((e) => setError(e.message))
    api.listUsers().then(setStaff).catch(() => setStaff([]))
  }, [])

  useEffect(() => {
    api
      .listProspects({
        status: filterStatus || undefined,
        customer_id: filterCustomer || undefined,
        salesperson_user_id: filterSalesperson || undefined,
        q: filterText || undefined,
      })
      .then(setProspects)
      .catch((e) => setError(e.message))
  }, [filterStatus, filterCustomer, filterSalesperson, filterText, reloads])

  async function onCreate(e: FormEvent) {
    e.preventDefault()
    setError(null)
    try {
      await api.createProspect({
        customer_id: customerId,
        title,
        source: source || null,
        estimated_value_sgd: estimated === '' ? null : Number(estimated),
        expected_close_date: expectedClose || null,
        salesperson_user_id: salespersonId || null,
        notes: notes || null,
      })
      setCustomerId('')
      setTitle('')
      setSource('')
      setEstimated('')
      setExpectedClose('')
      setSalespersonId('')
      setNotes('')
      setReloads((n) => n + 1)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to create prospect')
    }
  }

  async function onExport(format: string) {
    setError(null)
    const blob = format === 'excel' ? await api.exportProspectsExcel(filters) : await api.exportProspectsCsv(filters)
    downloadBlob(blob, format === 'excel' ? 'prospects.xlsx' : 'prospects.csv')
  }

  return (
    <div>
      <h1>Prospect / Leads</h1>
      <p className="muted">
        One row per sales opportunity. Log its activities, raise its quotations, and follow what has been quoted, billed
        and paid against it. {seesAll ? 'You see every prospect.' : 'You see the prospects you own.'}
      </p>

      <div className="card">
        <h2>New prospect</h2>
        <form onSubmit={onCreate}>
          <div className="form-row">
            <label>Company / Individual</label>
            <select value={customerId} onChange={(e) => setCustomerId(e.target.value)} required>
              <option value="">Select a Company / Individual...</option>
              {customers.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </select>
          </div>
          <div className="form-row">
            <label>Title</label>
            <input value={title} onChange={(e) => setTitle(e.target.value)} placeholder="e.g. Server room upgrade" required />
          </div>
          <div className="form-row">
            <label>Source (optional)</label>
            <input value={source} onChange={(e) => setSource(e.target.value)} placeholder="e.g. Referral, website, walk-in" />
          </div>
          <div className="form-row">
            <label>Estimated value (SGD, optional)</label>
            <input type="number" min="0" step="0.01" value={estimated} onChange={(e) => setEstimated(e.target.value)} />
          </div>
          <div className="form-row">
            <label>Expected close date (optional)</label>
            <DateInput value={expectedClose} onChange={(e) => setExpectedClose(e.target.value)} />
          </div>
          {seesAll && (
            <div className="form-row">
              <label>Salesperson</label>
              <select value={salespersonId} onChange={(e) => setSalespersonId(e.target.value)}>
                <option value="">Me</option>
                {staff.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.full_name}
                  </option>
                ))}
              </select>
            </div>
          )}
          <div className="form-row">
            <label>Notes (optional)</label>
            <textarea value={notes} onChange={(e) => setNotes(e.target.value)} rows={2} />
          </div>
          <button type="submit">Create prospect</button>
        </form>
      </div>

      {error && <div className="error-banner">{error}</div>}

      <div className="card">
        <div className="filter-bar">
          <div className="form-row" style={{ margin: 0 }}>
            <label>Status</label>
            <select value={filterStatus} onChange={(e) => setFilterStatus(e.target.value)}>
              <option value="">All</option>
              <option value="active">In pipeline (not won / lost)</option>
              {PROSPECT_STATUSES.map((s) => (
                <option key={s.value} value={s.value}>
                  {s.label}
                </option>
              ))}
            </select>
          </div>
          <div className="form-row" style={{ margin: 0 }}>
            <label>Company / Individual</label>
            <select value={filterCustomer} onChange={(e) => setFilterCustomer(e.target.value)}>
              <option value="">All</option>
              {customers.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </select>
          </div>
          {seesAll && (
            <div className="form-row" style={{ margin: 0 }}>
              <label>Salesperson</label>
              <select value={filterSalesperson} onChange={(e) => setFilterSalesperson(e.target.value)}>
                <option value="">All</option>
                {staff.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.full_name}
                  </option>
                ))}
              </select>
            </div>
          )}
          <div className="form-row" style={{ margin: 0 }}>
            <label>Search</label>
            <input value={filterText} onChange={(e) => setFilterText(e.target.value)} placeholder="Title or number" />
          </div>
          <button
            type="button"
            className="secondary"
            onClick={() => {
              setFilterStatus('')
              setFilterCustomer('')
              setFilterSalesperson('')
              setFilterText('')
            }}
          >
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

        <h2>Prospects ({prospects.length})</h2>
        <div style={{ overflowX: 'auto' }}>
          <table>
            <thead>
              <tr>
                <th>Number</th>
                <th>Title</th>
                <th>Company / Individual</th>
                <th>Status</th>
                <th>Salesperson</th>
                <th>Expected close</th>
                <th>Estimated</th>
                <th>Quoted</th>
                <th>Billed</th>
                <th>Paid</th>
                <th>Outstanding</th>
              </tr>
            </thead>
            <tbody>
              {prospects.map((p) => (
                <tr key={p.id}>
                  <td>
                    <Link to={`/prospects/${p.id}`}>{p.prospect_number}</Link>
                  </td>
                  <td>
                    <Link to={`/prospects/${p.id}`}>{p.title}</Link>
                  </td>
                  <td>
                    <Link to={`/company-individuals/${p.customer_id}`}>{p.customer_name}</Link>
                  </td>
                  <td>
                    <span className={`badge ${PROSPECT_BADGE[p.status]}`}>
                      {PROSPECT_STATUSES.find((s) => s.value === p.status)?.label}
                    </span>
                  </td>
                  <td>{p.salesperson_name}</td>
                  <td>{formatDate(p.expected_close_date)}</td>
                  <td>{p.estimated_value_sgd === null ? '—' : formatMoney(p.estimated_value_sgd)}</td>
                  <td>{formatMoney(p.quoted_amount_sgd)}</td>
                  <td>{formatMoney(p.billed_amount_sgd)}</td>
                  <td>{formatMoney(p.paid_amount_sgd)}</td>
                  <td>{formatMoney(p.outstanding_amount_sgd)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {prospects.length === 0 && <p className="muted">No prospects found.</p>}
      </div>
    </div>
  )
}
