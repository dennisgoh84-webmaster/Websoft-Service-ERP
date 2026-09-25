import { useEffect, useState } from 'react'
import DateInput from '../../components/DateInput'
import { ACTIVITY_STATUSES, ACTIVITY_TYPES, api, type ProspectActivity } from '../../lib/api'
import { formatDate } from '../../lib/format'
import { ACTIVITY_ICON, mobileStyles as styles, statusColors } from './mobileStyles'

/** One prospect activity on the Mobile App: view, edit, delete. */
export default function MobileProspectActivityDetailPage({ activityId, onBack }: { activityId: string; onBack: () => void }) {
  const [activity, setActivity] = useState<ProspectActivity | null>(null)
  const [loading, setLoading] = useState(true)
  const [isEditing, setIsEditing] = useState(false)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [form, setForm] = useState({ activity_type: '', subject: '', description: '', activity_date: '', status: '' })

  function fill(a: ProspectActivity) {
    setActivity(a)
    setForm({
      activity_type: a.activity_type,
      subject: a.subject,
      description: a.description || '',
      activity_date: a.activity_date?.split('T')[0] || '',
      status: a.status,
    })
  }

  useEffect(() => {
    api
      .getProspectActivity(activityId)
      .then(fill)
      .catch((e: unknown) => setError(e instanceof Error ? e.message : 'Failed to load'))
      .finally(() => setLoading(false))
  }, [activityId])

  async function handleSave() {
    setSaving(true)
    setError('')
    try {
      await api.updateProspectActivity(activityId, {
        activity_type: form.activity_type,
        subject: form.subject,
        description: form.description || null,
        activity_date: form.activity_date ? `${form.activity_date} 12:00:00` : null,
        status: form.status,
      })
      fill(await api.getProspectActivity(activityId))
      setIsEditing(false)
    } catch (e: unknown) {
      setError(e instanceof Error ? e.message : 'Failed to save')
    } finally {
      setSaving(false)
    }
  }

  async function handleDelete() {
    if (!confirm('Delete this activity? This cannot be undone.')) return
    setSaving(true)
    try {
      await api.deleteProspectActivity(activityId)
      onBack()
    } catch (e: unknown) {
      setError(e instanceof Error ? e.message : 'Failed to delete')
      setSaving(false)
    }
  }

  if (loading) return <div style={{ ...styles.container, padding: 40, textAlign: 'center' }}>Loading...</div>
  if (!activity) return <div style={{ ...styles.container, padding: 40, textAlign: 'center' }}>{error || 'Activity not found'}</div>

  const colors = statusColors(activity.status)

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
        <div style={styles.card}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 8 }}>
            <div>
              <h2 style={{ margin: '0 0 8px', fontSize: 18, color: '#222' }}>
                {ACTIVITY_ICON[activity.activity_type] || '📌'} {activity.subject}
              </h2>
              <div style={{ fontSize: 14, color: '#666' }}>
                {activity.prospect_number} — {activity.prospect_title}
              </div>
              <div style={{ fontSize: 13, color: '#888' }}>{activity.customer_name}</div>
            </div>
            <span style={{ ...styles.badge, background: colors.bg, color: colors.text }}>{activity.status}</span>
          </div>

          {activity.description && (
            <div style={{ marginTop: 12, padding: '10px', background: '#f9f9f9', borderRadius: 8 }}>
              <div style={{ fontSize: 13, color: '#666', lineHeight: 1.5, whiteSpace: 'pre-wrap' }}>{activity.description}</div>
            </div>
          )}

          <div style={{ marginTop: 12, fontSize: 12, color: '#999' }}>
            <div>Date: {formatDate(activity.activity_date)}</div>
            <div>Type: {ACTIVITY_TYPES.find((t) => t.value === activity.activity_type)?.label ?? activity.activity_type}</div>
            <div>Logged by: {activity.created_by_name}</div>
          </div>

          <button onClick={() => setIsEditing(true)} style={{ ...styles.btn, ...styles.btnPrimary, marginTop: 16 }}>
            ✎ Edit Activity
          </button>
          <button onClick={handleDelete} disabled={saving} style={{ ...styles.btn, ...styles.btnDanger, marginTop: 8 }}>
            🗑 Delete
          </button>
        </div>
      ) : (
        <div style={styles.card}>
          <h3 style={{ margin: '0 0 16px', fontSize: 16, color: '#222' }}>Edit Activity</h3>

          <label style={styles.label}>Activity Type</label>
          <select value={form.activity_type} onChange={(e) => setForm({ ...form, activity_type: e.target.value })} style={styles.select}>
            {ACTIVITY_TYPES.map((t) => (
              <option key={t.value} value={t.value}>
                {ACTIVITY_ICON[t.value]} {t.label}
              </option>
            ))}
          </select>

          <label style={styles.label}>Subject</label>
          <input value={form.subject} onChange={(e) => setForm({ ...form, subject: e.target.value })} style={styles.input} />

          <label style={styles.label}>Description</label>
          <textarea
            value={form.description}
            onChange={(e) => setForm({ ...form, description: e.target.value })}
            style={styles.textarea}
            placeholder="Optional notes..."
          />

          <label style={styles.label}>Date</label>
          <DateInput value={form.activity_date} onChange={(e) => setForm({ ...form, activity_date: e.target.value })} style={styles.input} />

          <label style={styles.label}>Status</label>
          <select value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })} style={styles.select}>
            {ACTIVITY_STATUSES.map((s) => (
              <option key={s.value} value={s.value}>
                {s.label}
              </option>
            ))}
          </select>

          <button onClick={handleSave} disabled={saving} style={{ ...styles.btn, ...styles.btnPrimary, opacity: saving ? 0.6 : 1 }}>
            {saving ? 'Saving...' : '✓ Save Changes'}
          </button>
          <button onClick={() => setIsEditing(false)} disabled={saving} style={{ ...styles.btn, ...styles.btnSecondary }}>
            Cancel
          </button>
        </div>
      )}
    </div>
  )
}
