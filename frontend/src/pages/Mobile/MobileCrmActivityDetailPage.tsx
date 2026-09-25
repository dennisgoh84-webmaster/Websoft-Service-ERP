import { useEffect, useState } from 'react'
import { api } from '../../lib/api'
import { formatDate } from '../../lib/format'
import DateInput from '../../components/DateInput'

const MAROON = '#800020'
const LIGHT_BG = '#f8f7f5'
const WHITE = '#ffffff'

const styles = {
  container: {
    maxWidth: 480,
    margin: '0 auto',
    minHeight: '100vh',
    background: LIGHT_BG,
    fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
  } as React.CSSProperties,
  header: {
    background: MAROON,
    color: WHITE,
    padding: '16px 20px',
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'space-between',
    position: 'sticky' as const,
    top: 0,
    zIndex: 10,
  } as React.CSSProperties,
  backBtn: {
    background: 'none',
    border: 'none',
    color: WHITE,
    fontSize: 16,
    cursor: 'pointer',
    padding: '4px 8px',
  } as React.CSSProperties,
  headerTitle: { fontSize: 18, fontWeight: 700, margin: 0, flex: 1, textAlign: 'center' } as React.CSSProperties,
  card: {
    background: WHITE,
    borderRadius: 12,
    padding: 16,
    margin: '12px 16px',
    boxShadow: '0 1px 3px rgba(0,0,0,0.08)',
  } as React.CSSProperties,
  label: {
    display: 'block',
    fontSize: 13,
    fontWeight: 600,
    color: '#555',
    marginBottom: 4,
  } as React.CSSProperties,
  input: {
    width: '100%',
    padding: '10px',
    border: '1px solid #ddd',
    borderRadius: 8,
    fontSize: 14,
    boxSizing: 'border-box' as const,
    marginBottom: 12,
  } as React.CSSProperties,
  textarea: {
    width: '100%',
    padding: '10px',
    border: '1px solid #ddd',
    borderRadius: 8,
    fontSize: 14,
    boxSizing: 'border-box' as const,
    marginBottom: 12,
    fontFamily: 'inherit',
    resize: 'vertical' as const,
    minHeight: 80,
  } as React.CSSProperties,
  select: {
    width: '100%',
    padding: '10px',
    border: '1px solid #ddd',
    borderRadius: 8,
    fontSize: 14,
    boxSizing: 'border-box' as const,
    marginBottom: 12,
  } as React.CSSProperties,
  btn: {
    display: 'block',
    width: '100%',
    padding: '12px',
    border: 'none',
    borderRadius: 10,
    fontSize: 14,
    fontWeight: 600,
    cursor: 'pointer',
    textAlign: 'center' as const,
  } as React.CSSProperties,
  btnPrimary: { background: MAROON, color: WHITE } as React.CSSProperties,
  btnDanger: { background: '#c0392b', color: WHITE } as React.CSSProperties,
  btnSecondary: { background: '#e0e0e0', color: '#333', marginTop: 8 } as React.CSSProperties,
  badge: {
    display: 'inline-block',
    padding: '4px 12px',
    borderRadius: 16,
    fontSize: 12,
    fontWeight: 600,
    textTransform: 'uppercase' as const,
  } as React.CSSProperties,
  errorBox: {
    background: '#fdeaea',
    color: '#c0392b',
    padding: '10px 14px',
    borderRadius: 8,
    margin: '12px 16px',
    fontSize: 14,
  } as React.CSSProperties,
  infoText: { fontSize: 12, color: '#999', marginTop: 4 } as React.CSSProperties,
}

interface CrmActivity {
  id: string
  customer_id: string
  customer_name: string
  activity_type: string
  subject: string
  description: string | null
  activity_date: string
  status: string
  created_by_name: string
  created_at: string
}

interface CompanyIndividual {
  id: string
  name: string
}

export default function MobileCrmActivityDetailPage({
  activityId,
  onBack,
}: {
  activityId: string
  onBack: () => void
}) {
  const [activity, setActivity] = useState<CrmActivity | null>(null)
  const [customers, setCustomers] = useState<CompanyIndividual[]>([])
  const [loading, setLoading] = useState(true)
  const [isEditing, setIsEditing] = useState(false)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [formData, setFormData] = useState({
    customer_id: '',
    activity_type: '',
    subject: '',
    description: '',
    activity_date: '',
    status: '',
  })

  useEffect(() => {
    async function load() {
      try {
        const [act, custs] = await Promise.all([
          api.request<CrmActivity>('GET', `/crm/activities/${activityId}`),
          api.request<CompanyIndividual[]>('GET', '/company-individuals'),
        ])
        if (act) {
          setActivity(act)
          setFormData({
            customer_id: act.customer_id,
            activity_type: act.activity_type,
            subject: act.subject,
            description: act.description || '',
            activity_date: act.activity_date?.split('T')[0] || '',
            status: act.status,
          })
        }
        setCustomers(custs || [])
      } catch (e: unknown) {
        setError(e instanceof Error ? e.message : 'Failed to load')
      } finally {
        setLoading(false)
      }
    }
    load()
  }, [activityId])

  const handleSave = async () => {
    setSaving(true)
    setError('')
    try {
      await api.request('PATCH', `/crm/activities/${activityId}`, undefined, {
        customer_id: formData.customer_id,
        activity_type: formData.activity_type,
        subject: formData.subject,
        description: formData.description || null,
        activity_date: formData.activity_date ? `${formData.activity_date} 12:00:00` : null,
        status: formData.status,
      })
      // Reload activity
      const updated = await api.request<CrmActivity>('GET', `/crm/activities/${activityId}`)
      if (updated) {
        setActivity(updated)
        setIsEditing(false)
      }
    } catch (e: unknown) {
      setError(e instanceof Error ? e.message : 'Failed to save')
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async () => {
    if (!confirm('Delete this activity? This cannot be undone.')) return
    setSaving(true)
    try {
      await api.request('DELETE', `/crm/activities/${activityId}`)
      onBack()
    } catch (e: unknown) {
      setError(e instanceof Error ? e.message : 'Failed to delete')
      setSaving(false)
    }
  }

  if (loading)
    return (
      <div style={{ ...styles.container, padding: 40, textAlign: 'center' }}>
        Loading...
      </div>
    )

  if (!activity)
    return (
      <div style={{ ...styles.container, padding: 40, textAlign: 'center' }}>
        Activity not found
      </div>
    )

  const actTypeIcon: Record<string, string> = {
    call: '☎️',
    email: '📧',
    meeting: '👥',
    note: '📝',
    follow_up: '↩️',
    proposal: '💼',
    demo: '🎬',
    negotiation: '🤝',
  }

  const statusColor =
    activity.status === 'completed'
      ? { bg: '#eafaf1', text: '#27ae60' }
      : { bg: '#fef9e7', text: '#f39c12' }

  return (
    <div style={styles.container}>
      <div style={styles.header}>
        <button onClick={onBack} style={styles.backBtn}>
          ← Back
        </button>
        <h1 style={styles.headerTitle}>Activity</h1>
        <div style={{ width: 50 }} />
      </div>

      {error && <div style={styles.errorBox}>{error}</div>}

      {!isEditing ? (
        <>
          {/* View Mode */}
          <div style={styles.card}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
              <div>
                <h2 style={{ margin: '0 0 8px', fontSize: 18, color: '#222' }}>
                  {actTypeIcon[activity.activity_type] || '📌'} {activity.subject}
                </h2>
                <div style={{ fontSize: 14, color: '#666' }}>{activity.customer_name}</div>
              </div>
              <span style={{ ...styles.badge, background: statusColor.bg, color: statusColor.text }}>
                {activity.status}
              </span>
            </div>

            {activity.description && (
              <div style={{ marginTop: 12, padding: '10px', background: '#f9f9f9', borderRadius: 8 }}>
                <div style={{ fontSize: 13, color: '#666', lineHeight: 1.5 }}>{activity.description}</div>
              </div>
            )}

            <div style={{ marginTop: 12, fontSize: 12, color: '#999' }}>
              <div>Date: {formatDate(activity.activity_date)}</div>
              <div>Type: {activity.activity_type}</div>
              <div>Created by: {activity.created_by_name}</div>
              <div>Created: {formatDate(activity.created_at)}</div>
            </div>

            <button
              onClick={() => setIsEditing(true)}
              style={{ ...styles.btn, ...styles.btnPrimary, marginTop: 16 }}
            >
              ✎ Edit Activity
            </button>
            <button
              onClick={handleDelete}
              style={{ ...styles.btn, ...styles.btnDanger, marginTop: 8 }}
            >
              🗑 Delete
            </button>
          </div>
        </>
      ) : (
        <>
          {/* Edit Mode */}
          <div style={styles.card}>
            <h3 style={{ margin: '0 0 16px', fontSize: 16, color: '#222' }}>Edit Activity</h3>

            <label style={styles.label}>Customer</label>
            <select
              value={formData.customer_id}
              onChange={e => setFormData({ ...formData, customer_id: e.target.value })}
              style={styles.select}
            >
              <option value="">Select customer...</option>
              {customers.map(c => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </select>

            <label style={styles.label}>Activity Type</label>
            <select
              value={formData.activity_type}
              onChange={e => setFormData({ ...formData, activity_type: e.target.value })}
              style={styles.select}
            >
              <option value="call">☎️ Call</option>
              <option value="email">📧 Email</option>
              <option value="meeting">👥 Meeting</option>
              <option value="note">📝 Note</option>
              <option value="follow_up">↩️ Follow-up</option>
              <option value="proposal">💼 Proposal</option>
              <option value="demo">🎬 Demo</option>
              <option value="negotiation">🤝 Negotiation</option>
            </select>

            <label style={styles.label}>Subject</label>
            <input
              type="text"
              value={formData.subject}
              onChange={e => setFormData({ ...formData, subject: e.target.value })}
              style={styles.input}
              placeholder="Activity subject..."
            />

            <label style={styles.label}>Description</label>
            <textarea
              value={formData.description}
              onChange={e => setFormData({ ...formData, description: e.target.value })}
              style={styles.textarea}
              placeholder="Optional notes..."
            />

            <label style={styles.label}>Date</label>
            <DateInput
              value={formData.activity_date}
              onChange={e => setFormData({ ...formData, activity_date: e.target.value })}
              style={styles.input}
            />

            <label style={styles.label}>Status</label>
            <select
              value={formData.status}
              onChange={e => setFormData({ ...formData, status: e.target.value })}
              style={styles.select}
            >
              <option value="planned">Planned</option>
              <option value="completed">Completed</option>
              <option value="pending">Pending</option>
              <option value="cancelled">Cancelled</option>
            </select>

            <button
              onClick={handleSave}
              disabled={saving}
              style={{ ...styles.btn, ...styles.btnPrimary, opacity: saving ? 0.6 : 1 }}
            >
              {saving ? 'Saving...' : '✓ Save Changes'}
            </button>
            <button
              onClick={() => setIsEditing(false)}
              disabled={saving}
              style={{ ...styles.btn, ...styles.btnSecondary }}
            >
              Cancel
            </button>
          </div>
        </>
      )}
    </div>
  )
}
