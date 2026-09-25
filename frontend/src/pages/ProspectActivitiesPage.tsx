import { useEffect, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import DateInput from '../components/DateInput'
import {
  ACTIVITY_STATUS_LABELS,
  ACTIVITY_STATUSES,
  ACTIVITY_TYPES,
  api,
  isProspectActive,
  seesAllProspects,
  type CurrentUser,
  type Prospect,
  type ProspectActivity,
} from '../lib/api'
import { useAuth } from '../lib/AuthContext'
import { formatDate, todayIso } from '../lib/format'

/** Every prospect activity the user can see, across prospects. Logging one needs a prospect to put it under. */
export default function ProspectActivitiesPage() {
  const { user } = useAuth()
  const seesAll = seesAllProspects(user?.role)
  const [activities, setActivities] = useState<ProspectActivity[]>([])
  const [prospects, setProspects] = useState<Prospect[]>([])
  const [staff, setStaff] = useState<CurrentUser[]>([])
  const [error, setError] = useState<string | null>(null)

  const [prospectId, setProspectId] = useState('')
  const [activityType, setActivityType] = useState('call')
  const [subject, setSubject] = useState('')
  const [description, setDescription] = useState('')
  const [activityDate, setActivityDate] = useState(todayIso())
  const [status, setStatus] = useState('completed')

  const [filterProspect, setFilterProspect] = useState('')
  const [filterType, setFilterType] = useState('')
  const [filterStatus, setFilterStatus] = useState('')
  const [filterBy, setFilterBy] = useState('')

  function refresh() {
    api
      .listProspectActivities({
        prospect_id: filterProspect || undefined,
        activity_type: filterType || undefined,
        status: filterStatus || undefined,
        created_by_user_id: filterBy || undefined,
      })
      .then(setActivities)
      .catch((e) => setError(e.message))
  }

  useEffect(() => {
    api.listProspects().then(setProspects).catch((e) => setError(e.message))
    if (seesAll) api.listUsers().then(setStaff).catch(() => setStaff([]))
  }, [seesAll])

  useEffect(refresh, [filterProspect, filterType, filterStatus, filterBy])

  async function onCreate(e: FormEvent) {
    e.preventDefault()
    setError(null)
    try {
      await api.createProspectActivity({
        prospect_id: prospectId,
        activity_type: activityType,
        subject,
        description: description || null,
        activity_date: activityDate ? `${activityDate} 00:00:00` : null,
        status,
      })
      setActivityType('call')
      setSubject('')
      setDescription('')
      setActivityDate(todayIso())
      setStatus('completed')
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to log activity')
    }
  }

  const openProspects = prospects.filter((p) => isProspectActive(p.status))

  return (
    <div>
      <h1>Prospect Activities</h1>
      <p className="muted">
        Calls, emails, meetings, follow-ups, proposals, demos and negotiations, each logged against a prospect --
        usually by the salesperson on the Mobile App. {seesAll ? 'You see every activity.' : 'You see the activities on your own prospects.'}
      </p>

      <div className="card">
        <h2>Log an activity</h2>
        <form onSubmit={onCreate}>
          <div className="form-row">
            <label>Prospect</label>
            <select value={prospectId} onChange={(e) => setProspectId(e.target.value)} required>
              <option value="">Select a prospect...</option>
              {openProspects.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.prospect_number} — {p.title} ({p.customer_name})
                </option>
              ))}
            </select>
            {openProspects.length === 0 && (
              <p className="muted">
                No prospects in the pipeline yet -- <Link to="/prospects">create one under Prospect / Leads</Link> first.
              </p>
            )}
          </div>
          <div className="form-row">
            <label>Activity type</label>
            <select value={activityType} onChange={(e) => setActivityType(e.target.value)}>
              {ACTIVITY_TYPES.map((t) => (
                <option key={t.value} value={t.value}>
                  {t.label}
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
            <label>Activity date</label>
            <DateInput value={activityDate} onChange={(e) => setActivityDate(e.target.value)} />
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
          <button type="submit">Log activity</button>
        </form>
      </div>

      {error && <div className="error-banner">{error}</div>}

      <div className="card">
        <div className="filter-bar">
          <div className="form-row" style={{ margin: 0 }}>
            <label>Prospect</label>
            <select value={filterProspect} onChange={(e) => setFilterProspect(e.target.value)}>
              <option value="">All</option>
              {prospects.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.prospect_number} — {p.title}
                </option>
              ))}
            </select>
          </div>
          <div className="form-row" style={{ margin: 0 }}>
            <label>Activity type</label>
            <select value={filterType} onChange={(e) => setFilterType(e.target.value)}>
              <option value="">All</option>
              {ACTIVITY_TYPES.map((t) => (
                <option key={t.value} value={t.value}>
                  {t.label}
                </option>
              ))}
            </select>
          </div>
          <div className="form-row" style={{ margin: 0 }}>
            <label>Status</label>
            <select value={filterStatus} onChange={(e) => setFilterStatus(e.target.value)}>
              <option value="">All</option>
              {ACTIVITY_STATUS_LABELS.map((s) => (
                <option key={s.value} value={s.value}>
                  {s.label}
                </option>
              ))}
            </select>
          </div>
          {seesAll && (
            <div className="form-row" style={{ margin: 0 }}>
              <label>By staff member</label>
              <select value={filterBy} onChange={(e) => setFilterBy(e.target.value)}>
                <option value="">All</option>
                {staff.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.full_name}
                  </option>
                ))}
              </select>
            </div>
          )}
          <button
            type="button"
            className="secondary"
            onClick={() => {
              setFilterProspect('')
              setFilterType('')
              setFilterStatus('')
              setFilterBy('')
            }}
          >
            Reset filters
          </button>
        </div>

        <h2>Prospect Activities ({activities.length})</h2>
        <div style={{ overflowX: 'auto' }}>
          <table>
            <thead>
              <tr>
                <th>Date</th>
                <th>Prospect</th>
                <th>Company / Individual</th>
                <th>Subject</th>
                <th>Type</th>
                <th>Status</th>
                <th>By</th>
              </tr>
            </thead>
            <tbody>
              {activities.map((a) => (
                <tr key={a.id}>
                  <td>{formatDate(a.activity_date || a.created_at)}</td>
                  <td>{a.prospect_id ? <Link to={`/prospects/${a.prospect_id}`}>{a.prospect_number}</Link> : '—'}</td>
                  <td>
                    <Link to={`/company-individuals/${a.customer_id}`}>{a.customer_name}</Link>
                  </td>
                  <td>
                    <Link to={`/prospect-activities/${a.id}`}>{a.subject}</Link>
                  </td>
                  <td>{ACTIVITY_TYPES.find((t) => t.value === a.activity_type)?.label ?? a.activity_type}</td>
                  <td>{ACTIVITY_STATUS_LABELS.find((s) => s.value === a.status)?.label ?? a.status}</td>
                  <td>{a.created_by_name}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {activities.length === 0 && <p className="muted">No activities found.</p>}
      </div>
    </div>
  )
}
