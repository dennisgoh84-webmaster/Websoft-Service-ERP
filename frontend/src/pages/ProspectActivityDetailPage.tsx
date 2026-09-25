import { useEffect, useState, type FormEvent } from 'react'
import { useParams, Link } from 'react-router-dom'
import InvoiceHistoryPanel from '../components/InvoiceHistoryPanel'
import { ACTIVITY_STATUS_LABELS, ACTIVITY_STATUSES, ACTIVITY_TYPES, api, type ProspectActivity } from '../lib/api'
import { formatDate, formatDateTime, sgDateIso } from '../lib/format'
import DateInput from '../components/DateInput'

export default function ProspectActivityDetailPage() {
  const { activityId } = useParams<{ activityId: string }>()
  const [activity, setActivity] = useState<ProspectActivity | null>(null)
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
      .getProspectActivity(activityId)
      .then((a) => {
        setActivity(a)
        setActivityType(a.activity_type)
        setSubject(a.subject)
        setDescription(a.description || '')
        setActivityDate(sgDateIso(a.activity_date))
        setStatus(a.status)
      })
      .catch((e) => setError(e.message))
  }, [activityId])

  async function onSave(e: FormEvent) {
    e.preventDefault()
    if (!activity || !activityId) return
    setError(null)
    try {
      await api.updateProspectActivity(activityId, {
        activity_type: activityType,
        subject,
        description: description || null,
        activity_date: activityDate ? `${activityDate} 00:00:00` : null,
        status,
      })
      setIsEditing(false)
      // Reload the activity to get the updated data
      const updated = await api.getProspectActivity(activityId)
      setActivity(updated)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to update activity')
    }
  }

  // Activities are never deleted (Dennis, 2026-09-26): a mistaken one
  // is voided with a reason and stays on the record.
  async function onVoid() {
    if (!activityId) return
    const reason = window.prompt('Why is this activity being voided? It stays on record as VOID.')
    if (!reason || !reason.trim()) return
    setError(null)
    try {
      setActivity(await api.voidProspectActivity(activityId, reason.trim()))
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to void activity')
    }
  }

  const isVoid = activity?.status === 'void'

  if (!activity) {
    return <div>{error ? <p className="error-banner">{error}</p> : <p>Loading...</p>}</div>
  }

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 20 }}>
        <h1>{isEditing ? 'Edit' : 'Prospect Activity'}</h1>
        <div>
          {!isEditing && !isVoid && (
            <>
              <button onClick={() => setIsEditing(true)}>Edit</button>
              <button onClick={onVoid} className="secondary" style={{ marginLeft: 10 }}>
                Void
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
            <p className="label">Prospect</p>
            <p>
              {activity.prospect_id ? (
                <Link to={`/prospects/${activity.prospect_id}`}>
                  {activity.prospect_number} — {activity.prospect_title}
                </Link>
              ) : (
                'None'
              )}
            </p>
          </div>
          <div>
            <p className="label">Company / Individual</p>
            <p>
              <Link to={`/company-individuals/${activity.customer_id}`}>{activity.customer_name}</Link>
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
                <p>
                  {isVoid ? (
                    <span className="badge exceeded">VOID</span>
                  ) : (
                    ACTIVITY_STATUS_LABELS.find((s) => s.value === activity.status)?.label
                  )}
                </p>
              </div>
              <div>
                <p className="label">Activity Date</p>
                <p>
                  {activity.activity_date ? formatDate(activity.activity_date) : 'Not set'}
                </p>
              </div>
            </div>
            {isVoid && (
              <div className="details-grid" style={{ marginTop: 20 }}>
                <div>
                  <p className="label">Void reason</p>
                  <p>{activity.void_reason}</p>
                </div>
                <div>
                  <p className="label">Voided by</p>
                  <p>
                    {activity.voided_by_name ?? '—'}
                    {activity.voided_at ? `, ${formatDateTime(activity.voided_at)}` : ''}
                  </p>
                </div>
              </div>
            )}
            {activity.description && (
              <div style={{ marginTop: 20 }}>
                <p className="label">Description</p>
                <p style={{ whiteSpace: 'pre-wrap' }}>{activity.description}</p>
              </div>
            )}
          </div>
        )}
      </div>

      <InvoiceHistoryPanel customerId={activity.customer_id} title={`Invoice history: ${activity.customer_name ?? ''}`} />

      <div style={{ marginTop: 20 }}>
        <Link to="/prospect-activities">← Back to Prospect Activities</Link>
      </div>
    </div>
  )
}
