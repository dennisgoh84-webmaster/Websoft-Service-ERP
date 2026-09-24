import { useEffect, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { api, type CompanyIndividual, type CurrentUser } from '../lib/api'
import { formatDate } from '../lib/format'

interface ProspectActivity {
  id: string
  company_id: string
  customer_id: string
  activity_type: string
  subject: string
  description: string | null
  activity_date: string | null
  status: string
  created_by_user_id: string
  created_by_name: string | null
  last_edited_by_user_id: string | null
  last_edited_by_name: string | null
  created_at: string
  updated_at: string
}

const ACTIVITY_TYPES = [
  { value: 'call', label: 'Call' },
  { value: 'email', label: 'Email' },
  { value: 'meeting', label: 'Meeting' },
  { value: 'note', label: 'Note' },
  { value: 'follow_up', label: 'Follow-up' },
  { value: 'proposal', label: 'Proposal' },
  { value: 'demo', label: 'Demo' },
  { value: 'negotiation', label: 'Negotiation' },
]

const ACTIVITY_STATUSES = [
  { value: 'planned', label: 'Planned' },
  { value: 'completed', label: 'Completed' },
  { value: 'pending', label: 'Pending' },
  { value: 'cancelled', label: 'Cancelled' },
]

export default function CrmProspectActivitiesPage() {
  const [activities, setActivities] = useState<ProspectActivity[]>([])
  const [customers, setCustomers] = useState<CompanyIndividual[]>([])
  const [currentUser, setCurrentUser] = useState<CurrentUser | null>(null)
  const [error, setError] = useState<string | null>(null)

  // Form state for new activity
  const [customerId, setCustomerId] = useState('')
  const [activityType, setActivityType] = useState('call')
  const [subject, setSubject] = useState('')
  const [description, setDescription] = useState('')
  const [activityDate, setActivityDate] = useState(new Date().toISOString().split('T')[0])
  const [status, setStatus] = useState('completed')

  // Filters
  const [filterCustomer, setFilterCustomer] = useState('')
  const [filterActivityType, setFilterActivityType] = useState('')
  const [filterStatus, setFilterStatus] = useState('')
  const [filterActivityBy, setFilterActivityBy] = useState('')

  function refresh() {
    const params: Record<string, string> = {}
    if (filterCustomer) params.customer_id = filterCustomer
    if (filterActivityType) params.activity_type = filterActivityType
    if (filterStatus) params.status = filterStatus
    if (filterActivityBy) params.created_by_user_id = filterActivityBy

    api.request<ProspectActivity[]>('GET', '/crm/activities', params)
      .then(setActivities)
      .catch((e) => setError(e.message))
  }

  useEffect(() => {
    api.getCurrentUser().then(setCurrentUser).catch((e) => setError(e.message))
    api.listCompanyIndividuals().then(setCustomers).catch((e) => setError(e.message))
  }, [])

  useEffect(refresh, [filterCustomer, filterActivityType, filterStatus, filterActivityBy])

  function customerName(id: string) {
    return customers.find((c) => c.id === id)?.name ?? id
  }

  function resetFilters() {
    setFilterCustomer('')
    setFilterActivityType('')
    setFilterStatus('')
    setFilterActivityBy('')
  }

  async function onCreate(e: FormEvent) {
    e.preventDefault()
    setError(null)
    try {
      await api.request('POST', '/crm/activities', undefined, {
        customer_id: customerId,
        activity_type: activityType,
        subject,
        description: description || undefined,
        activity_date: activityDate ? `${activityDate} 00:00:00` : undefined,
        status,
      })
      setCustomerId('')
      setActivityType('call')
      setSubject('')
      setDescription('')
      setActivityDate(new Date().toISOString().split('T')[0])
      setStatus('completed')
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to create activity')
    }
  }

  const canViewAllActivities = currentUser?.role === 'owner' || currentUser?.role === 'sales_manager'

  return (
    <div>
      <h1>Prospect Activities (CRM)</h1>
      <p className="muted">
        Track sales activities: calls, emails, meetings, notes, follow-ups, proposals, demos, and negotiations with prospects and customers.
        Sales Managers see all activities; Sales Staff see only their own.
      </p>

      <div className="card" style={{ marginTop: 20 }}>
        <h2>Add Prospect Activity</h2>
        <form onSubmit={onCreate}>
          <div className="form-row">
            <label>Customer / Prospect</label>
            <select value={customerId} onChange={(e) => setCustomerId(e.target.value)} required>
              <option value="">-- Select --</option>
              {customers.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </select>
          </div>
          <div className="form-row">
            <label>Activity Type</label>
            <select value={activityType} onChange={(e) => setActivityType(e.target.value)}>
              {ACTIVITY_TYPES.map((at) => (
                <option key={at.value} value={at.value}>
                  {at.label}
                </option>
              ))}
            </select>
          </div>
          <div className="form-row">
            <label>Subject</label>
            <input value={subject} onChange={(e) => setSubject(e.target.value)} required />
          </div>
          <div className="form-row">
            <label>Description (optional)</label>
            <textarea value={description} onChange={(e) => setDescription(e.target.value)} rows={3} />
          </div>
          <div className="form-row">
            <label>Activity Date</label>
            <input type="date" value={activityDate} onChange={(e) => setActivityDate(e.target.value)} />
          </div>
          <div className="form-row">
            <label>Status</label>
            <select value={status} onChange={(e) => setStatus(e.target.value)}>
              {ACTIVITY_STATUSES.map((s) => (
                <option key={s.value} value={s.value}>
                  {s.label}
                </option>
              ))}
            </select>
          </div>
          {error && <div className="error-banner">{error}</div>}
          <button type="submit">Add Activity</button>
        </form>
      </div>

      <div className="card">
        <div className="filter-bar">
          <div className="form-row" style={{ margin: 0 }}>
            <label>Customer</label>
            <select value={filterCustomer} onChange={(e) => setFilterCustomer(e.target.value)}>
              <option value="">All</option>
              {customers.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </select>
          </div>
          <div className="form-row" style={{ margin: 0 }}>
            <label>Activity Type</label>
            <select value={filterActivityType} onChange={(e) => setFilterActivityType(e.target.value)}>
              <option value="">All</option>
              {ACTIVITY_TYPES.map((at) => (
                <option key={at.value} value={at.value}>
                  {at.label}
                </option>
              ))}
            </select>
          </div>
          <div className="form-row" style={{ margin: 0 }}>
            <label>Status</label>
            <select value={filterStatus} onChange={(e) => setFilterStatus(e.target.value)}>
              <option value="">All</option>
              {ACTIVITY_STATUSES.map((s) => (
                <option key={s.value} value={s.value}>
                  {s.label}
                </option>
              ))}
            </select>
          </div>
          {canViewAllActivities && (
            <div className="form-row" style={{ margin: 0 }}>
              <label>By Staff Member</label>
              <select value={filterActivityBy} onChange={(e) => setFilterActivityBy(e.target.value)}>
                <option value="">All</option>
                {/* Staff list would be populated here */}
              </select>
            </div>
          )}
          <button type="button" className="secondary" onClick={resetFilters}>
            Reset filters
          </button>
        </div>

        <h2>Prospect Activities ({activities.length})</h2>
        <table>
          <thead>
            <tr>
              <th>Date</th>
              <th>Customer</th>
              <th>Subject</th>
              <th>Type</th>
              <th>Status</th>
              <th>By</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {activities.map((a) => (
              <tr key={a.id}>
                <td>{formatDate(a.activity_date || a.created_at)}</td>
                <td>
                  <Link to={`/company-individuals/${a.customer_id}`}>{customerName(a.customer_id)}</Link>
                </td>
                <td>
                  <Link to={`/crm/activities/${a.id}`}>{a.subject}</Link>
                </td>
                <td>{ACTIVITY_TYPES.find((at) => at.value === a.activity_type)?.label}</td>
                <td>{ACTIVITY_STATUSES.find((s) => s.value === a.status)?.label}</td>
                <td>{a.created_by_name}</td>
                <td>
                  <Link to={`/crm/activities/${a.id}`}>View</Link>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {activities.length === 0 && <p className="muted">No activities found</p>}
      </div>
    </div>
  )
}
