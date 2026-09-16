/**
 * Mobile Web App for Support Staff (planned-work.md #1).
 *
 * Separate from the desktop app -- mobile-optimized, no sidebar.
 * Staff sees only their own assigned Job Orders. Features:
 * - Time In / Time Out (replaces manual minutes)
 * - Work description
 * - Photo/video attachments (camera + gallery for work photos)
 * - Customer sign-off: finger-drawn signature + typed name + chop photo
 *   (camera-only, auto-watermarked with SR number + timestamp)
 *
 * Confirmed decisions (2026-09-12):
 * - Same login, own jobs only
 * - Time in/out replaces manual minutes (auto-computed)
 * - Camera-only + watermark for chop (no re-use rule)
 * - Live connection assumed
 * - Finger-drawn signature + typed name
 * - Photos + videos, no limit
 */
import { useEffect, useRef, useState } from 'react'
import { useAuth } from '../lib/AuthContext'
import { getToken } from '../lib/api'
import { getDeviceId } from '../lib/deviceId'
import { formatDate } from '../lib/format'

// ── API helpers (talk to /api/mobile/*) ─────────────────────────────

async function mobileRequest<T>(path: string, options: RequestInit = {}): Promise<T> {
  const token = getToken()
  const headers: Record<string, string> = {
    ...(token ? { Authorization: `Bearer ${token}` } : {}),
    'X-Device-Id': getDeviceId(),
  }
  // Don't set Content-Type for FormData -- browser sets it with boundary
  if (options.body && !(options.body instanceof FormData)) {
    headers['Content-Type'] = 'application/json'
  }
  const res = await fetch(`/api/mobile${path}`, { ...options, headers })
  if (!res.ok) {
    let detail = res.statusText
    try { const b = await res.json(); detail = b.detail ?? detail } catch { /* */ }
    throw new Error(detail)
  }
  if (res.status === 204) return undefined as T
  return res.json() as Promise<T>
}

// ── Types ───────────────────────────────────────────────────────────

interface JobOrderSummary {
  id: string
  job_order_number: string
  subject: string
  status: string
  priority: string
  is_urgent: boolean
  customer_name: string
  contract_number: string | null
  due_date: string | null
  created_at: string | null
}

interface ServiceRecordMobile {
  id: string
  service_record_number: string
  work_date: string
  raw_minutes: number
  rounded_minutes: number
  status: string
  outcome: string
  completion_status: string
  is_after_hours: boolean
  work_description: string | null
  time_in: string | null
  time_out: string | null
  employee_name: string
  has_signoff: boolean
  attachment_count: number
}

interface JobOrderDetail {
  id: string
  job_order_number: string
  subject: string
  status: string
  priority: string
  is_urgent: boolean
  customer_name: string
  contract_number: string | null
  contract_remaining_minutes: number | null
  due_date: string | null
  service_records: ServiceRecordMobile[]
}

interface OpenTimeIn {
  service_record_id: string
  service_record_number: string
  job_order_id: string
  job_order_number: string
  job_order_subject: string
  time_in: string
}

interface AttachmentInfo {
  id: string
  kind: string
  original_filename: string
  content_type: string
  file_size_bytes: number
  uploaded_at: string | null
}

interface SignoffInfo {
  id: string
  signer_name: string
  signature_data_uri: string
  chop_attachment_id: string | null
  signed_at: string | null
}

// ── Styles ──────────────────────────────────────────────────────────

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
  headerTitle: { fontSize: 18, fontWeight: 700, margin: 0 } as React.CSSProperties,
  backBtn: {
    background: 'none', border: 'none', color: WHITE, fontSize: 16,
    cursor: 'pointer', padding: '4px 8px',
  } as React.CSSProperties,
  logoutBtn: {
    background: 'rgba(255,255,255,0.2)', border: 'none', color: WHITE,
    fontSize: 13, padding: '6px 12px', borderRadius: 6, cursor: 'pointer',
  } as React.CSSProperties,
  card: {
    background: WHITE, borderRadius: 12, padding: 16, margin: '12px 16px',
    boxShadow: '0 1px 3px rgba(0,0,0,0.08)',
  } as React.CSSProperties,
  btn: {
    display: 'block', width: '100%', padding: '14px', border: 'none',
    borderRadius: 10, fontSize: 16, fontWeight: 600, cursor: 'pointer',
    textAlign: 'center' as const,
  } as React.CSSProperties,
  btnPrimary: { background: MAROON, color: WHITE } as React.CSSProperties,
  btnDanger: { background: '#c0392b', color: WHITE } as React.CSSProperties,
  btnSecondary: { background: '#e0e0e0', color: '#333' } as React.CSSProperties,
  btnSuccess: { background: '#27ae60', color: WHITE } as React.CSSProperties,
  input: {
    width: '100%', padding: '12px', border: '1px solid #ddd', borderRadius: 8,
    fontSize: 15, boxSizing: 'border-box' as const,
  } as React.CSSProperties,
  label: { display: 'block', fontSize: 13, fontWeight: 600, color: '#555', marginBottom: 4 } as React.CSSProperties,
  badge: {
    display: 'inline-block', padding: '2px 8px', borderRadius: 10,
    fontSize: 11, fontWeight: 700, textTransform: 'uppercase' as const,
  } as React.CSSProperties,
  errorBox: {
    background: '#fdeaea', color: '#c0392b', padding: '10px 14px',
    borderRadius: 8, margin: '12px 16px', fontSize: 14,
  } as React.CSSProperties,
}

// ── Utility ─────────────────────────────────────────────────────────

function fmtMinutes(m: number): string {
  const h = Math.floor(m / 60)
  const r = m % 60
  return h > 0 ? `${h}h ${r}m` : `${r}m`
}

function fmtDate(iso: string): string {
  return formatDate(iso)
}

function fmtTime(iso: string): string {
  return new Date(iso).toLocaleTimeString('en-SG', { hour: '2-digit', minute: '2-digit' })
}

function fmtDateTime(iso: string): string {
  return `${fmtDate(iso)} ${fmtTime(iso)}`
}

function elapsed(timeIn: string): string {
  const diff = Math.floor((Date.now() - new Date(timeIn).getTime()) / 1000)
  const h = Math.floor(diff / 3600)
  const m = Math.floor((diff % 3600) / 60)
  const s = diff % 60
  return `${h.toString().padStart(2, '0')}:${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`
}

function priorityColor(p: string): string {
  switch (p) {
    case 'critical': return '#e74c3c'
    case 'high': return '#e67e22'
    case 'normal': return '#3498db'
    default: return '#95a5a6'
  }
}

// ── Signature Pad Component ─────────────────────────────────────────

function SignaturePad({ onSave }: { onSave: (dataUri: string) => void }) {
  const canvasRef = useRef<HTMLCanvasElement>(null)
  const [drawing, setDrawing] = useState(false)
  const [hasDrawn, setHasDrawn] = useState(false)

  function getPos(e: React.TouchEvent | React.MouseEvent): { x: number; y: number } {
    const canvas = canvasRef.current!
    const rect = canvas.getBoundingClientRect()
    if ('touches' in e) {
      return { x: e.touches[0].clientX - rect.left, y: e.touches[0].clientY - rect.top }
    }
    return { x: (e as React.MouseEvent).clientX - rect.left, y: (e as React.MouseEvent).clientY - rect.top }
  }

  function startDraw(e: React.TouchEvent | React.MouseEvent) {
    e.preventDefault()
    const ctx = canvasRef.current?.getContext('2d')
    if (!ctx) return
    setDrawing(true)
    setHasDrawn(true)
    const pos = getPos(e)
    ctx.beginPath()
    ctx.moveTo(pos.x, pos.y)
  }

  function draw(e: React.TouchEvent | React.MouseEvent) {
    if (!drawing) return
    e.preventDefault()
    const ctx = canvasRef.current?.getContext('2d')
    if (!ctx) return
    const pos = getPos(e)
    ctx.lineTo(pos.x, pos.y)
    ctx.strokeStyle = '#000'
    ctx.lineWidth = 2.5
    ctx.lineCap = 'round'
    ctx.lineJoin = 'round'
    ctx.stroke()
  }

  function endDraw() {
    setDrawing(false)
  }

  function clear() {
    const canvas = canvasRef.current
    if (!canvas) return
    const ctx = canvas.getContext('2d')
    if (!ctx) return
    ctx.clearRect(0, 0, canvas.width, canvas.height)
    setHasDrawn(false)
  }

  function save() {
    if (!canvasRef.current || !hasDrawn) return
    onSave(canvasRef.current.toDataURL('image/png'))
  }

  return (
    <div>
      <canvas
        ref={canvasRef}
        width={340}
        height={150}
        style={{
          border: '2px solid #ccc', borderRadius: 8, background: '#fff',
          touchAction: 'none', display: 'block', width: '100%', maxWidth: 340,
        }}
        onMouseDown={startDraw} onMouseMove={draw} onMouseUp={endDraw} onMouseLeave={endDraw}
        onTouchStart={startDraw} onTouchMove={draw} onTouchEnd={endDraw}
      />
      <div style={{ display: 'flex', gap: 8, marginTop: 8 }}>
        <button onClick={clear} style={{ ...styles.btn, ...styles.btnSecondary, flex: 1, padding: 10, fontSize: 14 }}>Clear</button>
        <button
          onClick={save}
          disabled={!hasDrawn}
          style={{ ...styles.btn, ...styles.btnPrimary, flex: 1, padding: 10, fontSize: 14, opacity: hasDrawn ? 1 : 0.5 }}
        >
          Confirm Signature
        </button>
      </div>
    </div>
  )
}

// ── Mobile Login ────────────────────────────────────────────────────

function MobileLogin() {
  const { login, completeLogin } = useAuth()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)

  async function handleLogin(e: React.FormEvent) {
    e.preventDefault()
    setError('')
    setLoading(true)
    try {
      const result = await login(email, password)
      if (result.status === 'ok' && result.access_token) {
        await completeLogin(result.access_token)
      } else if (result.status === 'otp_required') {
        setError('Email OTP is enabled on your account. Please log in via the desktop app first, then try again.')
      } else if (result.status === 'must_change_password') {
        setError('You must change your password first. Please log in via the desktop app to set a new password.')
      } else {
        setError('Login failed')
      }
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Login failed')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div style={styles.container}>
      <div style={{ ...styles.header, justifyContent: 'center' }}>
        <h1 style={styles.headerTitle}>Websoft Mobile</h1>
      </div>
      <form onSubmit={handleLogin} style={{ padding: 20 }}>
        <div style={styles.card}>
          <h2 style={{ margin: '0 0 16px', fontSize: 20, color: '#333' }}>Staff Login</h2>
          {error && <div style={{ ...styles.errorBox, margin: '0 0 12px' }}>{error}</div>}
          <div style={{ marginBottom: 12 }}>
            <label style={styles.label}>Email</label>
            <input
              type="email" value={email} onChange={e => setEmail(e.target.value)}
              placeholder="your@email.com" required autoComplete="email"
              style={styles.input}
            />
          </div>
          <div style={{ marginBottom: 16 }}>
            <label style={styles.label}>Password</label>
            <input
              type="password" value={password} onChange={e => setPassword(e.target.value)}
              placeholder="Password" required autoComplete="current-password"
              style={styles.input}
            />
          </div>
          <button type="submit" disabled={loading} style={{ ...styles.btn, ...styles.btnPrimary }}>
            {loading ? 'Signing in...' : 'Sign In'}
          </button>
        </div>
      </form>
    </div>
  )
}

// ── Job Order List ──────────────────────────────────────────────────

function JobOrderList({ onSelect }: { onSelect: (id: string) => void }) {
  const { user, logout } = useAuth()
  const [orders, setOrders] = useState<JobOrderSummary[]>([])
  const [openTimeIn, setOpenTimeIn] = useState<OpenTimeIn | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  async function load() {
    try {
      const [jo, oti] = await Promise.all([
        mobileRequest<JobOrderSummary[]>('/job-orders'),
        mobileRequest<OpenTimeIn | null>('/my-open-timein'),
      ])
      setOrders(jo)
      setOpenTimeIn(oti)
    } catch (e: unknown) {
      setError(e instanceof Error ? e.message : 'Failed to load')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { load() }, [])

  return (
    <div style={styles.container}>
      <div style={styles.header}>
        <h1 style={styles.headerTitle}>My Job Orders</h1>
        <button onClick={logout} style={styles.logoutBtn}>Logout</button>
      </div>

      {user && (
        <div style={{ padding: '12px 16px 0', fontSize: 14, color: '#666' }}>
          Logged in as <strong>{user.full_name}</strong>
        </div>
      )}

      {error && <div style={styles.errorBox}>{error}</div>}

      {openTimeIn && (
        <div
          style={{ ...styles.card, background: '#eafaf1', border: '2px solid #27ae60', cursor: 'pointer' }}
          onClick={() => onSelect(openTimeIn.job_order_id)}
        >
          <div style={{ fontSize: 12, fontWeight: 700, color: '#27ae60', textTransform: 'uppercase', marginBottom: 4 }}>
            ⏱ Active Time-In
          </div>
          <div style={{ fontWeight: 600, fontSize: 15 }}>{openTimeIn.job_order_number} — {openTimeIn.job_order_subject}</div>
          <div style={{ fontSize: 13, color: '#666', marginTop: 4 }}>
            {openTimeIn.service_record_number} · Started {fmtDateTime(openTimeIn.time_in)}
          </div>
        </div>
      )}

      {loading ? (
        <div style={{ padding: 40, textAlign: 'center', color: '#999' }}>Loading...</div>
      ) : orders.length === 0 ? (
        <div style={{ padding: 40, textAlign: 'center', color: '#999' }}>
          No Job Orders assigned to you right now.
        </div>
      ) : (
        orders.map(jo => (
          <div key={jo.id} style={{ ...styles.card, cursor: 'pointer' }} onClick={() => onSelect(jo.id)}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
              <div style={{ flex: 1 }}>
                <div style={{ fontWeight: 700, fontSize: 15, color: '#222' }}>{jo.job_order_number}</div>
                <div style={{ fontSize: 14, color: '#444', marginTop: 2 }}>{jo.subject}</div>
                <div style={{ fontSize: 13, color: '#777', marginTop: 4 }}>{jo.customer_name}</div>
              </div>
              <div style={{ textAlign: 'right' }}>
                <span style={{
                  ...styles.badge,
                  background: priorityColor(jo.priority) + '20',
                  color: priorityColor(jo.priority),
                }}>
                  {jo.priority}
                </span>
                {jo.is_urgent && (
                  <span style={{ ...styles.badge, background: '#fdeaea', color: '#e74c3c', marginLeft: 4 }}>
                    URGENT
                  </span>
                )}
              </div>
            </div>
            {jo.due_date && (
              <div style={{ fontSize: 12, color: '#999', marginTop: 6 }}>Due: {fmtDate(jo.due_date)}</div>
            )}
          </div>
        ))
      )}
    </div>
  )
}

// ── Job Order Detail ────────────────────────────────────────────────

function JobOrderDetailView({ jobOrderId, onBack }: { jobOrderId: string; onBack: () => void }) {
  const [detail, setDetail] = useState<JobOrderDetail | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [actionLoading, setActionLoading] = useState(false)
  const [activeRecord, setActiveRecord] = useState<string | null>(null)
  const [, setTimerTick] = useState(0)

  async function load() {
    try {
      const d = await mobileRequest<JobOrderDetail>(`/job-orders/${jobOrderId}`)
      setDetail(d)
    } catch (e: unknown) {
      setError(e instanceof Error ? e.message : 'Failed to load')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { load() }, [jobOrderId])

  // Timer tick for active time-in display
  useEffect(() => {
    const openSr = detail?.service_records.find(sr => sr.time_in && !sr.time_out)
    if (!openSr) return
    const interval = setInterval(() => setTimerTick(t => t + 1), 1000)
    return () => clearInterval(interval)
  }, [detail])

  async function handleTimeIn() {
    setActionLoading(true)
    setError('')
    try {
      await mobileRequest('/job-orders/' + jobOrderId + '/time-in', { method: 'POST' })
      await load()
    } catch (e: unknown) {
      setError(e instanceof Error ? e.message : 'Failed')
    } finally {
      setActionLoading(false)
    }
  }

  if (loading) return <div style={{ ...styles.container, padding: 40, textAlign: 'center' }}>Loading...</div>

  const openSr = detail?.service_records.find(sr => sr.time_in && !sr.time_out)

  return (
    <div style={styles.container}>
      <div style={styles.header}>
        <button onClick={onBack} style={styles.backBtn}>← Back</button>
        <h1 style={{ ...styles.headerTitle, flex: 1, textAlign: 'center' }}>{detail?.job_order_number}</h1>
        <div style={{ width: 50 }} />
      </div>

      {error && <div style={styles.errorBox}>{error}</div>}

      {detail && (
        <>
          {/* JO Info Card */}
          <div style={styles.card}>
            <h2 style={{ margin: '0 0 8px', fontSize: 17 }}>{detail.subject}</h2>
            <div style={{ fontSize: 14, color: '#666' }}>{detail.customer_name}</div>
            <div style={{ display: 'flex', gap: 8, marginTop: 8, flexWrap: 'wrap' }}>
              <span style={{ ...styles.badge, background: priorityColor(detail.priority) + '20', color: priorityColor(detail.priority) }}>
                {detail.priority}
              </span>
              {detail.is_urgent && <span style={{ ...styles.badge, background: '#fdeaea', color: '#e74c3c' }}>URGENT</span>}
              {detail.contract_number && (
                <span style={{ ...styles.badge, background: '#eaf0fa', color: '#2c3e80' }}>
                  {detail.contract_number}
                </span>
              )}
            </div>
            {detail.contract_remaining_minutes != null && (
              <div style={{ fontSize: 13, color: '#777', marginTop: 8 }}>
                Contract hours remaining: <strong>{fmtMinutes(detail.contract_remaining_minutes)}</strong>
              </div>
            )}
            {detail.due_date && (
              <div style={{ fontSize: 13, color: '#999', marginTop: 4 }}>Due: {fmtDate(detail.due_date)}</div>
            )}
          </div>

          {/* Active Timer */}
          {openSr && (
            <div style={{ ...styles.card, background: '#eafaf1', border: '2px solid #27ae60' }}>
              <div style={{ fontSize: 12, fontWeight: 700, color: '#27ae60', textTransform: 'uppercase' }}>
                ⏱ Time Running — {openSr.service_record_number}
              </div>
              <div style={{ fontSize: 32, fontWeight: 700, textAlign: 'center', margin: '12px 0', fontVariantNumeric: 'tabular-nums' }}>
                {elapsed(openSr.time_in!)}
              </div>
              <div style={{ fontSize: 13, color: '#666', textAlign: 'center' }}>
                Started {fmtDateTime(openSr.time_in!)}
              </div>
              <button
                onClick={() => setActiveRecord(openSr.id)}
                style={{ ...styles.btn, ...styles.btnDanger, marginTop: 12 }}
              >
                ⏹ Time Out & Complete
              </button>
            </div>
          )}

          {/* Time In button (only if no open record) */}
          {!openSr && detail.status !== 'closed' && detail.status !== 'void' && (
            <div style={{ padding: '0 16px' }}>
              <button
                onClick={handleTimeIn}
                disabled={actionLoading}
                style={{ ...styles.btn, ...styles.btnSuccess }}
              >
                {actionLoading ? 'Starting...' : '▶ Time In — Start Service Record'}
              </button>
            </div>
          )}

          {/* Service Records */}
          <div style={{ padding: '12px 16px 4px' }}>
            <h3 style={{ fontSize: 15, color: '#555', margin: 0 }}>
              Service Records ({detail.service_records.length})
            </h3>
          </div>
          {detail.service_records.map(sr => (
            <div key={sr.id} style={styles.card}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <div style={{ fontWeight: 600, fontSize: 14 }}>{sr.service_record_number}</div>
                <span style={{
                  ...styles.badge,
                  background: sr.status === 'approved' ? '#eafaf1' : '#fef9e7',
                  color: sr.status === 'approved' ? '#27ae60' : '#f39c12',
                }}>
                  {sr.status}
                </span>
              </div>
              <div style={{ fontSize: 13, color: '#777', marginTop: 4 }}>
                {fmtDate(sr.work_date)} · {fmtMinutes(sr.rounded_minutes)}
                {sr.is_after_hours && ' · After-hours'}
              </div>
              {sr.time_in && (
                <div style={{ fontSize: 12, color: '#999', marginTop: 2 }}>
                  {fmtTime(sr.time_in)}{sr.time_out ? ` → ${fmtTime(sr.time_out)}` : ' → (in progress)'}
                </div>
              )}
              {sr.work_description && (
                <div style={{ fontSize: 13, color: '#555', marginTop: 6, fontStyle: 'italic' }}>
                  "{sr.work_description}"
                </div>
              )}
              <div style={{ display: 'flex', gap: 8, marginTop: 8, fontSize: 12 }}>
                {sr.has_signoff && <span style={{ color: '#27ae60' }}>✓ Signed off</span>}
                {sr.attachment_count > 0 && <span style={{ color: '#3498db' }}>📎 {sr.attachment_count} files</span>}
                {sr.completion_status === 'C' && <span style={{ color: '#27ae60' }}>✓ Completed</span>}
              </div>

              {/* Action buttons for records that need work */}
              {sr.time_out && !sr.has_signoff && (
                <div style={{ display: 'flex', gap: 8, marginTop: 10 }}>
                  <button
                    onClick={() => setActiveRecord(activeRecord === sr.id ? null : sr.id)}
                    style={{ ...styles.btn, ...styles.btnPrimary, flex: 1, padding: 10, fontSize: 13 }}
                  >
                    📎 Attachments & Sign-off
                  </button>
                </div>
              )}
              {sr.time_out && sr.has_signoff && (
                <button
                  onClick={() => setActiveRecord(activeRecord === sr.id ? null : sr.id)}
                  style={{ ...styles.btn, ...styles.btnSecondary, marginTop: 8, padding: 8, fontSize: 13 }}
                >
                  {activeRecord === sr.id ? 'Hide Details' : 'View Details'}
                </button>
              )}

              {/* Expanded section */}
              {activeRecord === sr.id && (
                <ServiceRecordActions
                  sr={sr}
                  onDone={() => { setActiveRecord(null); load() }}
                />
              )}
            </div>
          ))}
        </>
      )}
    </div>
  )
}

// ── Service Record Actions (Time Out form + Attachments + Signoff) ─

function ServiceRecordActions({ sr, onDone }: { sr: ServiceRecordMobile; onDone: () => void }) {
  const [tab, setTab] = useState<'timeout' | 'attach' | 'signoff'>(!sr.time_out ? 'timeout' : 'attach')

  return (
    <div style={{ marginTop: 12, borderTop: '1px solid #eee', paddingTop: 12 }}>
      {/* Tab bar */}
      <div style={{ display: 'flex', gap: 4, marginBottom: 12 }}>
        {!sr.time_out && (
          <TabBtn active={tab === 'timeout'} onClick={() => setTab('timeout')}>Time Out</TabBtn>
        )}
        <TabBtn active={tab === 'attach'} onClick={() => setTab('attach')}>Attachments</TabBtn>
        {!sr.has_signoff && sr.time_out && (
          <TabBtn active={tab === 'signoff'} onClick={() => setTab('signoff')}>Sign-off</TabBtn>
        )}
        {sr.has_signoff && (
          <TabBtn active={tab === 'signoff'} onClick={() => setTab('signoff')}>Sign-off ✓</TabBtn>
        )}
      </div>

      {tab === 'timeout' && !sr.time_out && <TimeOutForm recordId={sr.id} onDone={onDone} />}
      {tab === 'attach' && <AttachmentPanel recordId={sr.id} />}
      {tab === 'signoff' && <SignoffPanel recordId={sr.id} hasExisting={sr.has_signoff} onDone={onDone} />}
    </div>
  )
}

function TabBtn({ active, onClick, children }: { active: boolean; onClick: () => void; children: React.ReactNode }) {
  return (
    <button
      onClick={onClick}
      style={{
        flex: 1, padding: '8px 4px', border: 'none', borderRadius: 6,
        fontSize: 12, fontWeight: 600, cursor: 'pointer',
        background: active ? MAROON : '#f0f0f0',
        color: active ? WHITE : '#555',
      }}
    >
      {children}
    </button>
  )
}

// ── Time Out Form ───────────────────────────────────────────────────

function TimeOutForm({ recordId, onDone }: { recordId: string; onDone: () => void }) {
  const [description, setDescription] = useState('')
  const [completionStatus, setCompletionStatus] = useState('U')
  const [isAfterHours, setIsAfterHours] = useState(false)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')

  async function handleSubmit() {
    setLoading(true)
    setError('')
    try {
      const form = new FormData()
      form.append('completion_status', completionStatus)
      form.append('is_after_hours', String(isAfterHours))
      form.append('work_description', description)
      await mobileRequest(`/service-records/${recordId}/time-out`, { method: 'POST', body: form })
      onDone()
    } catch (e: unknown) {
      setError(e instanceof Error ? e.message : 'Failed')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div>
      {error && <div style={{ ...styles.errorBox, margin: '0 0 8px' }}>{error}</div>}
      <div style={{ marginBottom: 10 }}>
        <label style={styles.label}>Work Description</label>
        <textarea
          value={description} onChange={e => setDescription(e.target.value)}
          rows={3} spellCheck="true"
          style={{ ...styles.input, resize: 'vertical' }}
          placeholder="Describe what was done..."
        />
      </div>
      <div style={{ marginBottom: 10 }}>
        <label style={styles.label}>Job Completed?</label>
        <select value={completionStatus} onChange={e => setCompletionStatus(e.target.value)} style={styles.input}>
          <option value="U">Uncompleted — will come back</option>
          <option value="C">Completed — job is done</option>
        </select>
      </div>
      <div style={{ marginBottom: 12 }}>
        <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 14 }}>
          <input type="checkbox" checked={isAfterHours} onChange={e => setIsAfterHours(e.target.checked)} />
          After Office Hours / Weekend / Holiday (×2.0)
        </label>
      </div>
      <button onClick={handleSubmit} disabled={loading} style={{ ...styles.btn, ...styles.btnDanger }}>
        {loading ? 'Saving...' : '⏹ Confirm Time Out'}
      </button>
    </div>
  )
}

// ── Attachment Panel ────────────────────────────────────────────────

function AttachmentPanel({ recordId }: { recordId: string }) {
  const [attachments, setAttachments] = useState<AttachmentInfo[]>([])
  const [loading, setLoading] = useState(true)
  const [uploading, setUploading] = useState(false)
  const [error, setError] = useState('')

  async function load() {
    try {
      const atts = await mobileRequest<AttachmentInfo[]>(`/service-records/${recordId}/attachments`)
      setAttachments(atts)
    } catch { /* */ }
    finally { setLoading(false) }
  }

  useEffect(() => { load() }, [recordId])

  async function handleUpload(e: React.ChangeEvent<HTMLInputElement>) {
    const files = e.target.files
    if (!files) return
    setUploading(true)
    setError('')
    try {
      for (const file of Array.from(files)) {
        const form = new FormData()
        form.append('file', file)
        await mobileRequest(`/service-records/${recordId}/attachments`, { method: 'POST', body: form })
      }
      await load()
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Upload failed')
    } finally {
      setUploading(false)
      e.target.value = ''
    }
  }

  async function handleDelete(attId: string) {
    if (!confirm('Remove this attachment?')) return
    try {
      await mobileRequest(`/attachments/${attId}`, { method: 'DELETE' })
      await load()
    } catch { /* */ }
  }

  function viewFile(attId: string) {
    const token = getToken()
    window.open(`/api/mobile/attachments/${attId}/file?token=${token}`, '_blank')
  }

  return (
    <div>
      {error && <div style={{ ...styles.errorBox, margin: '0 0 8px' }}>{error}</div>}

      {/* Upload buttons */}
      <div style={{ display: 'flex', gap: 8, marginBottom: 12 }}>
        <label style={{ ...styles.btn, ...styles.btnPrimary, flex: 1, padding: 10, fontSize: 13, textAlign: 'center', cursor: 'pointer' }}>
          📷 Take Photo
          <input
            type="file" accept="image/*" capture="environment"
            onChange={handleUpload} hidden
          />
        </label>
        <label style={{ ...styles.btn, ...styles.btnSecondary, flex: 1, padding: 10, fontSize: 13, textAlign: 'center', cursor: 'pointer' }}>
          📁 Choose File
          <input
            type="file" accept="image/*,video/*" multiple
            onChange={handleUpload} hidden
          />
        </label>
      </div>
      {uploading && <div style={{ fontSize: 13, color: '#999', marginBottom: 8 }}>Uploading...</div>}

      {loading ? (
        <div style={{ fontSize: 13, color: '#999' }}>Loading attachments...</div>
      ) : attachments.length === 0 ? (
        <div style={{ fontSize: 13, color: '#999' }}>No attachments yet.</div>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
          {attachments.map(att => (
            <div key={att.id} style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '6px 0', borderBottom: '1px solid #f0f0f0' }}>
              <span style={{ fontSize: 18 }}>
                {att.kind === 'CHOP_PHOTO' ? '🔏' : att.content_type.startsWith('video') ? '🎬' : '📷'}
              </span>
              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{ fontSize: 13, fontWeight: 500, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                  {att.original_filename}
                </div>
                <div style={{ fontSize: 11, color: '#999' }}>
                  {(att.file_size_bytes / 1024).toFixed(0)} KB
                  {att.kind === 'CHOP_PHOTO' && ' · Chop (watermarked)'}
                </div>
              </div>
              <button onClick={() => viewFile(att.id)} style={{ background: 'none', border: 'none', fontSize: 16, cursor: 'pointer' }}>👁</button>
              {att.kind !== 'CHOP_PHOTO' && (
                <button onClick={() => handleDelete(att.id)} style={{ background: 'none', border: 'none', fontSize: 16, cursor: 'pointer', color: '#c0392b' }}>🗑</button>
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

// ── Sign-off Panel ──────────────────────────────────────────────────

function SignoffPanel({ recordId, hasExisting, onDone }: { recordId: string; hasExisting: boolean; onDone: () => void }) {
  const [existing, setExisting] = useState<SignoffInfo | null>(null)
  const [signerName, setSignerName] = useState('')
  const [signatureDataUri, setSignatureDataUri] = useState('')
  const [loading, setLoading] = useState(true)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState('')
  const [step, setStep] = useState<'name' | 'signature' | 'chop' | 'done'>('name')

  useEffect(() => {
    if (hasExisting) {
      mobileRequest<SignoffInfo | null>(`/service-records/${recordId}/signoff`)
        .then(s => { setExisting(s); setStep('done') })
        .finally(() => setLoading(false))
    } else {
      setLoading(false)
    }
  }, [recordId, hasExisting])

  async function handleSubmitChop(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0]
    if (!file) return
    setSubmitting(true)
    setError('')
    try {
      const form = new FormData()
      form.append('signer_name', signerName)
      form.append('signature_data_uri', signatureDataUri)
      form.append('chop_photo', file)
      await mobileRequest(`/service-records/${recordId}/signoff`, { method: 'POST', body: form })
      onDone()
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Failed')
    } finally {
      setSubmitting(false)
    }
  }

  if (loading) return <div style={{ fontSize: 13, color: '#999' }}>Loading...</div>

  // Show existing sign-off
  if (existing && step === 'done') {
    return (
      <div>
        <div style={{ fontSize: 13, color: '#27ae60', fontWeight: 600, marginBottom: 8 }}>✓ Sign-off completed</div>
        <div style={{ fontSize: 14 }}>
          <strong>Signed by:</strong> {existing.signer_name}
        </div>
        {existing.signed_at && (
          <div style={{ fontSize: 12, color: '#999', marginTop: 2 }}>
            {fmtDateTime(existing.signed_at)}
          </div>
        )}
        {existing.signature_data_uri && (
          <div style={{ marginTop: 8 }}>
            <div style={{ fontSize: 12, color: '#777', marginBottom: 4 }}>Signature:</div>
            <img src={existing.signature_data_uri} alt="Signature" style={{ maxWidth: '100%', border: '1px solid #eee', borderRadius: 4 }} />
          </div>
        )}
      </div>
    )
  }

  return (
    <div>
      {error && <div style={{ ...styles.errorBox, margin: '0 0 8px' }}>{error}</div>}

      {step === 'name' && (
        <div>
          <p style={{ fontSize: 14, color: '#555', margin: '0 0 12px' }}>
            Present the Service Record to the customer's in-charge person for sign-off.
          </p>
          <div style={{ marginBottom: 12 }}>
            <label style={styles.label}>Customer Representative Name</label>
            <input
              value={signerName} onChange={e => setSignerName(e.target.value)}
              placeholder="Full name of the person signing"
              style={styles.input}
            />
          </div>
          <button
            onClick={() => setStep('signature')}
            disabled={!signerName.trim()}
            style={{ ...styles.btn, ...styles.btnPrimary, opacity: signerName.trim() ? 1 : 0.5 }}
          >
            Next: Capture Signature →
          </button>
        </div>
      )}

      {step === 'signature' && (
        <div>
          <p style={{ fontSize: 14, color: '#555', margin: '0 0 8px' }}>
            <strong>{signerName}</strong> — please draw your signature below:
          </p>
          <SignaturePad onSave={uri => { setSignatureDataUri(uri); setStep('chop') }} />
          <button onClick={() => setStep('name')} style={{ ...styles.btn, ...styles.btnSecondary, marginTop: 8, padding: 10, fontSize: 13 }}>
            ← Back
          </button>
        </div>
      )}

      {step === 'chop' && (
        <div>
          <p style={{ fontSize: 14, color: '#555', margin: '0 0 8px' }}>
            Now photograph the company chop stamp. The photo will be watermarked with the Service Record number.
          </p>
          <div style={{ background: '#fef9e7', padding: 10, borderRadius: 8, fontSize: 13, color: '#856404', marginBottom: 12 }}>
            ⚠ Camera capture only — this photo cannot be reused on another Service Record.
          </div>
          <label style={{ ...styles.btn, ...styles.btnPrimary, textAlign: 'center', cursor: 'pointer' }}>
            {submitting ? 'Submitting...' : '📷 Take Chop Photo & Submit'}
            <input
              type="file" accept="image/*" capture="environment"
              onChange={handleSubmitChop} hidden disabled={submitting}
            />
          </label>
          <button onClick={() => setStep('signature')} style={{ ...styles.btn, ...styles.btnSecondary, marginTop: 8, padding: 10, fontSize: 13 }}>
            ← Back
          </button>
        </div>
      )}
    </div>
  )
}

// ── Tab Navigation ─────────────────────────────────────────────────

interface TabItem {
  id: string
  label: string
  icon: string
}

function getTabsForRole(role: string): TabItem[] {
  if (role === 'sales_manager') {
    return [
      { id: 'crm', label: '📞 CRM', icon: '📞' },
      { id: 'quotations', label: '📄 Quotations', icon: '📄' },
      { id: 'jobs', label: '🛠️ Jobs', icon: '🛠️' },
    ]
  }
  if (role === 'sales_engineer') {
    return [
      { id: 'crm', label: '📞 CRM', icon: '📞' },
      { id: 'quotations', label: '📄 Quotations', icon: '📄' },
      { id: 'jobs', label: '🛠️ Jobs', icon: '🛠️' },
    ]
  }
  // Default: support/operations staff
  return [
    { id: 'jobs', label: '🛠️ Jobs', icon: '🛠️' },
  ]
}

function TabBar({ tabs, active, onChange }: { tabs: TabItem[]; active: string; onChange: (id: string) => void }) {
  return (
    <div style={{
      display: 'flex', background: MAROON, borderBottom: `1px solid ${MAROON}`,
      overflowX: 'auto', gap: 0,
    }}>
      {tabs.map(tab => (
        <button
          key={tab.id}
          onClick={() => onChange(tab.id)}
          style={{
            flex: 1, padding: '12px 8px', border: 'none', background: active === tab.id ? '#a0001a' : MAROON,
            color: WHITE, fontSize: 12, fontWeight: 600, cursor: 'pointer', whiteSpace: 'nowrap',
            borderBottom: active === tab.id ? '3px solid #fff' : 'none',
          }}
        >
          {tab.icon} {tab.label}
        </button>
      ))}
    </div>
  )
}

// ── Mobile CRM Activities List ──────────────────────────────────────

interface MobileCrmActivity {
  id: string
  customer_id: string
  customer_name: string
  activity_type: string
  subject: string
  activity_date: string
  status: string
  created_by_name: string
}

function MobileCrmActivitiesList({ onSelect }: { onSelect: (id: string) => void }) {
  const { user } = useAuth()
  const [activities, setActivities] = useState<MobileCrmActivity[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    async function load() {
      try {
        const res = await mobileRequest<MobileCrmActivity[]>('/crm/activities')
        setActivities(res)
      } catch (e: unknown) {
        setError(e instanceof Error ? e.message : 'Failed to load')
      } finally {
        setLoading(false)
      }
    }
    load()
  }, [])

  if (loading) return <div style={{ ...styles.container, padding: 40, textAlign: 'center' }}>Loading...</div>
  if (error) return <div style={styles.errorBox}>{error}</div>

  const actTypeIcon: Record<string, string> = {
    call: '☎️', email: '📧', meeting: '👥', note: '📝',
    follow_up: '↩️', proposal: '💼', demo: '🎬', negotiation: '🤝',
  }

  return (
    <div style={styles.container}>
      <div style={styles.header}>
        <h1 style={styles.headerTitle}>CRM Activities</h1>
      </div>

      {activities.length === 0 ? (
        <div style={{ padding: 40, textAlign: 'center', color: '#999' }}>
          No CRM activities yet.
        </div>
      ) : (
        activities.map(act => (
          <div key={act.id} style={{ ...styles.card, cursor: 'pointer' }} onClick={() => onSelect(act.id)}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
              <div style={{ flex: 1 }}>
                <div style={{ fontWeight: 700, fontSize: 15, color: '#222' }}>
                  {actTypeIcon[act.activity_type] || '📌'} {act.subject}
                </div>
                <div style={{ fontSize: 13, color: '#666', marginTop: 2 }}>{act.customer_name}</div>
                <div style={{ fontSize: 12, color: '#999', marginTop: 4 }}>
                  {fmtDate(act.activity_date)} · By {act.created_by_name}
                </div>
              </div>
              <span style={{
                ...styles.badge,
                background: act.status === 'completed' ? '#eafaf1' : '#fef9e7',
                color: act.status === 'completed' ? '#27ae60' : '#f39c12',
              }}>
                {act.status}
              </span>
            </div>
          </div>
        ))
      )}
    </div>
  )
}

// ── Main Mobile App Component ───────────────────────────────────────

export default function MobileApp() {
  const { user, loading } = useAuth()
  const [selectedJobOrder, setSelectedJobOrder] = useState<string | null>(null)
  const [selectedActivity, setSelectedActivity] = useState<string | null>(null)
  const [activeTab, setActiveTab] = useState<string>('jobs')

  if (loading) return <div style={{ ...styles.container, padding: 40, textAlign: 'center' }}>Loading...</div>
  if (!user) return <MobileLogin />

  const tabs = getTabsForRole(user.role)
  const hasJobsTab = tabs.some(t => t.id === 'jobs')
  const hasCrmTab = tabs.some(t => t.id === 'crm')

  // If job order detail is open, show it regardless of tab
  if (selectedJobOrder) {
    return <JobOrderDetailView jobOrderId={selectedJobOrder} onBack={() => setSelectedJobOrder(null)} />
  }

  // If activity detail is open, show it regardless of tab
  if (selectedActivity) {
    return (
      <div style={styles.container}>
        <div style={styles.header}>
          <button onClick={() => setSelectedActivity(null)} style={styles.backBtn}>← Back</button>
          <h1 style={{ ...styles.headerTitle, flex: 1, textAlign: 'center' }}>Activity</h1>
          <div style={{ width: 50 }} />
        </div>
        <div style={{ padding: '16px', color: '#999' }}>Activity detail view coming soon...</div>
      </div>
    )
  }

  return (
    <>
      <TabBar tabs={tabs} active={activeTab} onChange={setActiveTab} />
      {activeTab === 'jobs' && hasJobsTab && <JobOrderList onSelect={setSelectedJobOrder} />}
      {activeTab === 'crm' && hasCrmTab && <MobileCrmActivitiesList onSelect={setSelectedActivity} />}
      {activeTab === 'quotations' && (
        <div style={styles.container}>
          <div style={styles.header}>
            <h1 style={styles.headerTitle}>My Quotations</h1>
          </div>
          <div style={{ padding: '40px 16px', textAlign: 'center', color: '#999' }}>
            Quotations view coming soon...
          </div>
        </div>
      )}
    </>
  )
}
