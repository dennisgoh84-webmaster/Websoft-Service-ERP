import { useEffect, useState, type FormEvent } from 'react'
import { useParams, useNavigate, Link } from 'react-router-dom'
import InvoiceHistoryPanel from '../components/InvoiceHistoryPanel'
import { api, type CompanyIndividual } from '../lib/api'
import { formatDate, formatDateTime } from '../lib/format'

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

export default function CrmProspectActivityDetailPage() {
  const { activityId } = useParams<{ activityId: string }>()
  const navigate = useNavigate()
  const [activity, setActivity] = useState<ProspectActivity | null>(null)
  const [customer, setCustomer] = useState<CompanyIndividual | null>(null)
  const [isEditing, setIsEditing] = useState(false)
  const [error, setError] = useState<string | null>(null)

  // Form state
  const [activityType, setActivityType] = useState('')
  const [subject, setSubject] = useState('')
  const [description, setDescription] = useState('')
  const [activityDate, setActivityDate] = useState('')
  const [status, setStatus] = useState('')

  useEffect(() => {
    if (!activityId) return
    api
      .request<ProspectActivity>('GET', `/crm/activities/${activityId}`)
      .then((a) => {
        setActivity(a)
        setActivityType(a.activity_type)
        setSubject(a.subject)
        setDescription(a.description || '')
        setActivityDate(a.activity_date ? a.activity_date.split('T')[0] : '')
        setStatus(a.status)
        return a.customer_id
      })
      .then((customerId) => api.getCompanyIndividual(customerId).then(setCustomer))
      .catch((e) => setError(e.message))
  }, [activityId])

  async function onSave(e: FormEvent) {
    e.preventDefault()
    if (!activity || !activityId) return
    setError(null)
    try {
      await api.request('PATCH', `/crm/activities/${activityId}`, undefined, {
        activity_type: activityType,
        subject,
        description: description || undefined,
        activity_date: activityDate ? `${activityDate} 00:00:00` : undefined,
        status,
      })
      setIsEditing(false)
      // Reload the activity to get the updated data
      const updated = await api.request<ProspectActivity>('GET', `/crm/activities/${activityId}`)
      setActivity(updated)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to update activity')
    }
  }

  async function onDelete() {
    if (!activityId) return
    if (!window.confirm('Are you sure you want to delete this activity?')) return
    setError(null)
    try {
      await api.request('DELETE', `/crm/activities/${activityId}`)
      navigate('/crm/activities')
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to delete activity')
    }
  }

  if (!activity) {
    return <div>{error ? <p className="error-banner">{error}</p> : <p>Loading...</p>}</div>
  }

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 20 }}>
        <h1>{isEditing ? 'Edit' : 'Prospect Activity'}</h1>
        <div>
          {!isEditing && (
            <>
              <button onClick={() => setIsEditing(true)}>Edit</button>
              <button onClick={onDelete} className="secondary" style={{ marginLeft: 10 }}>
                Delete
              </button>
            </>
          )}
          {isEditing && (
            <>
              <button onClick={() => setIsEditing(false)} className="secondary">
                Cancel
              </button>
            </>
          )}
        </div>
      </div>

      {error && <div className="error-banner">{error}</div>}

      <div className="card">
        <div className="details-grid">
          <div>
            <p className="label">Company / Individual</p>
            <p>
              <Link to={`/company-individuals/${customer?.id}`}>{customer?.name}</Link>
            </p>
          </div>
          <div>
            <p className="label">Created By</p>
            <p>{activity.created_by_name}</p>
          </div>
          <div>
            <p className="label">Created At</p>
            <p>{formatDateTime(activity.created_at)}</p>
          </div>
          {activity.last_edited_by_name && (
            <>
              <div>
                <p className="label">Last Edited By</p>
                <p>{activity.last_edited_by_name}</p>
              </div>
              <div>
                <p className="label">Last Edited At</p>
                <p>{formatDateTime(activity.updated_at)}</p>
              </div>
            </>
          )}
        </div>
      </div>

      <div className="card" style={{ marginTop: 20 }}>
        {isEditing ? (
          <form onSubmit={onSave}>
            <h2>Edit Activity</h2>
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
              <label>Description</label>
              <textarea value={description} onChange={(e) => setDescription(e.target.value)} rows={5} />
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
            <button type="submit">Save Changes</button>
          </form>
        ) : (
          <div>
            <h2>{activity.subject}</h2>
            <div className="details-grid">
              <div>
                <p className="label">Activity Type</p>
                <p>{ACTIVITY_TYPES.find((at) => at.value === activity.activity_type)?.label}</p>
              </div>
              <div>
                <p className="label">Status</p>
                <p>{ACTIVITY_STATUSES.find((s) => s.value === activity.status)?.label}</p>
              </div>
              <div>
                <p className="label">Activity Date</p>
                <p>
                  {activity.activity_date ? formatDate(activity.activity_date) : 'Not set'}
                </p>
              </div>
            </div>
            {activity.description && (
              <div style={{ marginTop: 20 }}>
                <p className="label">Description</p>
                <p style={{ whiteSpace: 'pre-wrap' }}>{activity.description}</p>
              </div>
            )}
          </div>
        )}
      </div>

      {customer && <InvoiceHistoryPanel customerId={customer.id} title={`Invoice history: ${customer.name}`} />}

      <div style={{ marginTop: 20 }}>
        <Link to="/crm/activities">← Back to Prospect Activities</Link>
      </div>
    </div>
  )
}
