import { useEffect, useState, type FormEvent } from 'react'
import { ACTIVITY_STATUSES, ACTIVITY_TYPES, api, PROSPECT_STATUSES, seesAllProspects, type Prospect, type ProspectDetail } from '../../lib/api'
import { formatDate, formatMoney } from '../../lib/format'
import { ACTIVITY_ICON, mobileStyles as styles, statusColors } from './mobileStyles'

/** The Mobile App's Prospects tab: the salesperson's prospects (the managers', everyone's). */
export function MobileProspectsList({ role, onSelect }: { role: string; onSelect: (id: string) => void }) {
  const [prospects, setProspects] = useState<Prospect[]>([])
  const [openOnly, setOpenOnly] = useState(true)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    api
      .listProspects(openOnly ? { status: 'active' } : {})
      .then(setProspects)
      .catch((e: unknown) => setError(e instanceof Error ? e.message : 'Failed to load'))
      .finally(() => setLoading(false))
  }, [openOnly])

  const chip = (active: boolean) => ({
    ...styles.btn,
    display: 'inline-block',
    width: 'auto',
    padding: '10px 16px',
    marginRight: 8,
    ...(active ? styles.btnPrimary : { background: '#e0e0e0', color: '#333' }),
  })

  return (
    <div style={styles.container}>
      <div style={styles.header}>
        <h1 style={styles.headerTitle}>{seesAllProspects(role) ? 'Prospects' : 'My Prospects'}</h1>
      </div>
      <div style={{ padding: '12px 16px 0' }}>
        <button style={chip(openOnly)} onClick={() => setOpenOnly(true)}>
          In pipeline
        </button>
        <button style={chip(!openOnly)} onClick={() => setOpenOnly(false)}>
          All
        </button>
      </div>

      {error && <div style={styles.errorBox}>{error}</div>}
      {loading ? (
        <div style={{ padding: 40, textAlign: 'center' }}>Loading...</div>
      ) : prospects.length === 0 ? (
        <div style={{ padding: 40, textAlign: 'center', color: '#999' }}>No prospects yet. Create them under Prospect / Leads on the full site.</div>
      ) : (
        prospects.map((p) => {
          const colors = statusColors(p.status)
          return (
            <div key={p.id} style={{ ...styles.card, cursor: 'pointer' }} onClick={() => onSelect(p.id)}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 8 }}>
                <div style={{ flex: 1 }}>
                  <div style={{ fontWeight: 700, fontSize: 15, color: '#222' }}>{p.title}</div>
                  <div style={{ fontSize: 13, color: '#666', marginTop: 2 }}>{p.customer_name}</div>
                  <div style={{ fontSize: 12, color: '#999', marginTop: 4 }}>
                    {p.prospect_number}
                    {p.estimated_value_sgd !== null && <> · Est. {formatMoney(p.estimated_value_sgd)}</>}
                    {p.expected_close_date && <> · Close {formatDate(p.expected_close_date)}</>}
                  </div>
                </div>
                <span style={{ ...styles.badge, background: colors.bg, color: colors.text }}>{PROSPECT_STATUSES.find((x) => x.value === p.status)?.label ?? p.status}</span>
              </div>
            </div>
          )
        })
      )}
    </div>
  )
}

/** One prospect on the Mobile App: its amounts, a quick "log activity" form, and its activity history. */
export function MobileProspectDetail({
  prospectId,
  onBack,
  onOpenActivity,
}: {
  prospectId: string
  onBack: () => void
  onOpenActivity: (id: string) => void
}) {
  const [p, setP] = useState<ProspectDetail | null>(null)
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)
  const [type, setType] = useState('call')
  const [subject, setSubject] = useState('')
  const [notes, setNotes] = useState('')
  const [status, setStatus] = useState('completed')

  function load() {
    api
      .getProspect(prospectId)
      .then(setP)
      .catch((e: unknown) => setError(e instanceof Error ? e.message : 'Failed to load'))
  }
  useEffect(load, [prospectId])

  async function onLog(e: FormEvent) {
    e.preventDefault()
    setSaving(true)
    setError('')
    try {
      // No date: the server stamps it now, which is when it happened on the go.
      await api.createProspectActivity({ prospect_id: prospectId, activity_type: type, subject, description: notes || null, status })
      setSubject('')
      setNotes('')
      setType('call')
      setStatus('completed')
      load()
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Failed to log activity')
    } finally {
      setSaving(false)
    }
  }

  if (!p) return <div style={{ ...styles.container, padding: 40, textAlign: 'center' }}>{error || 'Loading...'}</div>

  const colors = statusColors(p.status)
  const amounts: [string, number | null][] = [
    ['Estimated', p.estimated_value_sgd],
    ['Quoted', p.quoted_amount_sgd],
    ['Billed', p.billed_amount_sgd],
    ['Paid', p.paid_amount_sgd],
    ['Outstanding', p.outstanding_amount_sgd],
  ]

  return (
    <div style={styles.container}>
      <div style={styles.header}>
        <button onClick={onBack} style={styles.backBtn}>
          ← Back
        </button>
        <h1 style={styles.headerTitle}>{p.prospect_number}</h1>
        <div style={{ width: 50 }} />
      </div>

      {error && <div style={styles.errorBox}>{error}</div>}

      <div style={styles.card}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 8 }}>
          <div>
            <div style={{ fontWeight: 700, fontSize: 17, color: '#222' }}>{p.title}</div>
            <div style={{ fontSize: 14, color: '#666', marginTop: 2 }}>{p.customer_name}</div>
          </div>
          <span style={{ ...styles.badge, background: colors.bg, color: colors.text }}>{PROSPECT_STATUSES.find((x) => x.value === p.status)?.label ?? p.status}</span>
        </div>
        <div style={{ marginTop: 12, fontSize: 14 }}>
          {amounts.map(([label, value]) => (
            <div key={label} style={{ display: 'flex', justifyContent: 'space-between', padding: '4px 0', borderTop: '1px solid #f0f0f0' }}>
              <span style={{ color: '#666' }}>{label}</span>
              <strong>{value === null ? '—' : formatMoney(value)}</strong>
            </div>
          ))}
        </div>
      </div>

      <form style={styles.card} onSubmit={onLog}>
        <h3 style={{ margin: '0 0 12px', fontSize: 16, color: '#222' }}>Log activity</h3>
        <label style={styles.label}>Type</label>
        <select value={type} onChange={(e) => setType(e.target.value)} style={styles.select}>
          {ACTIVITY_TYPES.map((t) => (
            <option key={t.value} value={t.value}>
              {ACTIVITY_ICON[t.value]} {t.label}
            </option>
          ))}
        </select>
        <label style={styles.label}>Subject</label>
        <input value={subject} onChange={(e) => setSubject(e.target.value)} style={styles.input} placeholder="e.g. Met the finance director" required />
        <label style={styles.label}>Notes (optional)</label>
        <textarea value={notes} onChange={(e) => setNotes(e.target.value)} style={styles.textarea} />
        <label style={styles.label}>Status</label>
        <select value={status} onChange={(e) => setStatus(e.target.value)} style={styles.select}>
          {ACTIVITY_STATUSES.map((s) => (
            <option key={s.value} value={s.value}>
              {s.label}
            </option>
          ))}
        </select>
        <button type="submit" disabled={saving} style={{ ...styles.btn, ...styles.btnPrimary, opacity: saving ? 0.6 : 1 }}>
          {saving ? 'Saving...' : '＋ Log activity'}
        </button>
      </form>

      <h3 style={{ margin: '16px 16px 0', fontSize: 15, color: '#555' }}>Activities ({p.activities.length})</h3>
      {p.activities.length === 0 && <div style={{ padding: 20, textAlign: 'center', color: '#999' }}>Nothing logged yet.</div>}
      {p.activities.map((a) => {
        const c = statusColors(a.status)
        return (
          <div key={a.id} style={{ ...styles.card, cursor: 'pointer' }} onClick={() => onOpenActivity(a.id)}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 8 }}>
              <div style={{ flex: 1 }}>
                <div style={{ fontWeight: 600, fontSize: 15, color: '#222' }}>
                  {ACTIVITY_ICON[a.activity_type] || '📌'} {a.subject}
                </div>
                <div style={{ fontSize: 12, color: '#999', marginTop: 4 }}>
                  {formatDate(a.activity_date)} · {a.created_by_name}
                </div>
              </div>
              <span style={{ ...styles.badge, background: c.bg, color: c.text }}>{a.status === 'void' ? 'VOID' : a.status}</span>
            </div>
          </div>
        )
      })}
    </div>
  )
}
