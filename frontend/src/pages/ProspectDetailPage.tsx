import { useEffect, useState, type FormEvent } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import DateInput from '../components/DateInput'
import InvoiceHistoryPanel from '../components/InvoiceHistoryPanel'
import {
  ACTIVITY_STATUSES,
  ACTIVITY_TYPES,
  api,
  PROSPECT_BADGE,
  PROSPECT_STATUSES,
  seesAllProspects,
  type CurrentUser,
  type ProspectDetail,
  type ProspectStatus,
  type Quotation,
} from '../lib/api'
import { useAuth } from '../lib/AuthContext'
import { formatDate, formatMoney, todayIso } from '../lib/format'

const words = (s: string) => s.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase())

/** One prospect: what it is worth at each stage, its activities, its quotations and the invoices they led to. */
export default function ProspectDetailPage() {
  const { id = '' } = useParams()
  const navigate = useNavigate()
  const { user } = useAuth()
  const seesAll = seesAllProspects(user?.role)
  const [p, setP] = useState<ProspectDetail | null>(null)
  const [staff, setStaff] = useState<CurrentUser[]>([])
  const [unlinked, setUnlinked] = useState<Quotation[]>([])
  const [error, setError] = useState<string | null>(null)
  const [saved, setSaved] = useState(false)

  const [title, setTitle] = useState('')
  const [status, setStatus] = useState<ProspectStatus>('open')
  const [lostReason, setLostReason] = useState('')
  const [estimated, setEstimated] = useState('')
  const [expectedClose, setExpectedClose] = useState('')
  const [source, setSource] = useState('')
  const [salespersonId, setSalespersonId] = useState('')
  const [notes, setNotes] = useState('')

  const [actType, setActType] = useState('call')
  const [actSubject, setActSubject] = useState('')
  const [actDescription, setActDescription] = useState('')
  const [actDate, setActDate] = useState(todayIso())
  const [actStatus, setActStatus] = useState('completed')
  const [linkId, setLinkId] = useState('')

  function load() {
    api
      .getProspect(id)
      .then((d) => {
        setP(d)
        setTitle(d.title)
        setStatus(d.status)
        setLostReason(d.lost_reason ?? '')
        setEstimated(d.estimated_value_sgd === null ? '' : String(d.estimated_value_sgd))
        setExpectedClose(d.expected_close_date ?? '')
        setSource(d.source ?? '')
        setSalespersonId(d.salesperson_user_id ?? '')
        setNotes(d.notes ?? '')
        return api.listQuotations({ customer_id: d.customer_id })
      })
      .then((qs) => setUnlinked(qs.filter((q) => !q.prospect_id)))
      .catch((e) => setError(e.message))
  }

  useEffect(load, [id])
  useEffect(() => {
    if (seesAll) api.listUsers().then(setStaff).catch(() => setStaff([]))
  }, [seesAll])

  async function onSave(e: FormEvent) {
    e.preventDefault()
    setError(null)
    setSaved(false)
    try {
      await api.updateProspect(id, {
        title,
        status,
        lost_reason: status === 'lost' ? lostReason : null,
        estimated_value_sgd: estimated === '' ? null : Number(estimated),
        expected_close_date: expectedClose || null,
        source: source || null,
        ...(seesAll ? { salesperson_user_id: salespersonId || null } : {}),
        notes: notes || null,
      })
      setSaved(true)
      load()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to save')
    }
  }

  async function onLogActivity(e: FormEvent) {
    e.preventDefault()
    setError(null)
    try {
      await api.createProspectActivity({
        prospect_id: id,
        activity_type: actType,
        subject: actSubject,
        description: actDescription || null,
        activity_date: actDate ? `${actDate} 00:00:00` : null,
        status: actStatus,
      })
      setActSubject('')
      setActDescription('')
      setActDate(todayIso())
      load()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to log activity')
    }
  }

  async function onLinkQuotation() {
    if (!linkId) return
    setError(null)
    try {
      await api.linkQuotationProspect(linkId, id)
      setLinkId('')
      load()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to link quotation')
    }
  }

  if (!p) return <div>{error ? <div className="error-banner">{error}</div> : 'Loading...'}</div>

  const tiles: [string, number | null][] = [
    ['Estimated value', p.estimated_value_sgd],
    ['Quoted', p.quoted_amount_sgd],
    ['Billed', p.billed_amount_sgd],
    ['Paid', p.paid_amount_sgd],
    ['Outstanding', p.outstanding_amount_sgd],
  ]

  return (
    <div>
      <p>
        <Link to="/prospects">← Prospect / Leads</Link>
      </p>
      <h1>
        {p.prospect_number} — {p.title}{' '}
        <span className={`badge ${PROSPECT_BADGE[p.status]}`}>{PROSPECT_STATUSES.find((s) => s.value === p.status)?.label}</span>
      </h1>
      <p className="muted">
        <Link to={`/company-individuals/${p.customer_id}`}>{p.customer_name}</Link>
        {p.salesperson_name && <> · Salesperson: {p.salesperson_name}</>}
        {p.created_by_name && <> · Raised by {p.created_by_name} on {formatDate(p.created_at)}</>}
      </p>

      <div className="stat-grid">
        {tiles.map(([label, value]) => (
          <div key={label} className="card stat-tile">
            <div className="stat-value stat-value-text">{value === null ? '—' : formatMoney(value)}</div>
            <div className="stat-label">{label}</div>
          </div>
        ))}
      </div>
      <p className="muted" style={{ marginTop: -8 }}>
        Quoted counts the quotations sent to or accepted by the customer. Billed, paid and outstanding come from the invoices
        raised from those quotations' contracts. All include GST except the estimate, which is whatever the salesperson entered.
      </p>

      {error && <div className="error-banner">{error}</div>}

      <div className="card">
        <h2>Prospect</h2>
        <form onSubmit={onSave}>
          <div className="form-row">
            <label>Title</label>
            <input value={title} onChange={(e) => setTitle(e.target.value)} required />
          </div>
          <div className="form-row">
            <label>Status</label>
            <select value={status} onChange={(e) => setStatus(e.target.value as ProspectStatus)}>
              {PROSPECT_STATUSES.map((s) => (
                <option key={s.value} value={s.value}>
                  {s.label}
                </option>
              ))}
            </select>
          </div>
          {status === 'lost' && (
            <div className="form-row">
              <label>Why was it lost?</label>
              <input value={lostReason} onChange={(e) => setLostReason(e.target.value)} required />
            </div>
          )}
          <div className="form-row">
            <label>Estimated value (SGD)</label>
            <input type="number" min="0" step="0.01" value={estimated} onChange={(e) => setEstimated(e.target.value)} />
          </div>
          <div className="form-row">
            <label>Expected close date</label>
            <DateInput value={expectedClose} onChange={(e) => setExpectedClose(e.target.value)} />
          </div>
          <div className="form-row">
            <label>Source</label>
            <input value={source} onChange={(e) => setSource(e.target.value)} />
          </div>
          {seesAll && (
            <div className="form-row">
              <label>Salesperson</label>
              <select value={salespersonId} onChange={(e) => setSalespersonId(e.target.value)}>
                {staff.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.full_name}
                  </option>
                ))}
              </select>
            </div>
          )}
          <div className="form-row">
            <label>Notes</label>
            <textarea value={notes} onChange={(e) => setNotes(e.target.value)} rows={3} />
          </div>
          <button type="submit">Save</button> {saved && <span className="muted">Saved.</span>}
        </form>
      </div>

      <div className="card">
        <h2>Activities ({p.activities.length})</h2>
        <form onSubmit={onLogActivity} className="filter-bar">
          <div className="form-row" style={{ margin: 0 }}>
            <label>Type</label>
            <select value={actType} onChange={(e) => setActType(e.target.value)}>
              {ACTIVITY_TYPES.map((t) => (
                <option key={t.value} value={t.value}>
                  {t.label}
                </option>
              ))}
            </select>
          </div>
          <div className="form-row" style={{ margin: 0, flex: 2 }}>
            <label>Subject</label>
            <input value={actSubject} onChange={(e) => setActSubject(e.target.value)} required />
          </div>
          <div className="form-row" style={{ margin: 0 }}>
            <label>Date</label>
            <DateInput value={actDate} onChange={(e) => setActDate(e.target.value)} />
          </div>
          <div className="form-row" style={{ margin: 0 }}>
            <label>Status</label>
            <select value={actStatus} onChange={(e) => setActStatus(e.target.value)}>
              {ACTIVITY_STATUSES.map((s) => (
                <option key={s.value} value={s.value}>
                  {s.label}
                </option>
              ))}
            </select>
          </div>
          <div className="form-row" style={{ margin: 0, flex: 2 }}>
            <label>Description (optional)</label>
            <input value={actDescription} onChange={(e) => setActDescription(e.target.value)} />
          </div>
          <button type="submit">Log activity</button>
        </form>
        <div style={{ overflowX: 'auto' }}>
          <table>
            <thead>
              <tr>
                <th>Date</th>
                <th>Subject</th>
                <th>Type</th>
                <th>Status</th>
                <th>By</th>
              </tr>
            </thead>
            <tbody>
              {p.activities.map((a) => (
                <tr key={a.id}>
                  <td>{formatDate(a.activity_date)}</td>
                  <td>
                    <Link to={`/prospect-activities/${a.id}`}>{a.subject}</Link>
                  </td>
                  <td>{ACTIVITY_TYPES.find((t) => t.value === a.activity_type)?.label ?? a.activity_type}</td>
                  <td>{ACTIVITY_STATUSES.find((s) => s.value === a.status)?.label ?? a.status}</td>
                  <td>{a.created_by_name}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {p.activities.length === 0 && <p className="muted">No activities logged yet.</p>}
      </div>

      <div className="card">
        <h2>Quotations ({p.quotations.length})</h2>
        <div className="filter-bar">
          <button type="button" onClick={() => navigate(`/quotations?customer_id=${p.customer_id}&prospect_id=${p.id}`)}>
            New quotation for this prospect
          </button>
          {unlinked.length > 0 && (
            <>
              <div className="form-row" style={{ margin: 0 }}>
                <label>Or link an earlier quotation</label>
                <select value={linkId} onChange={(e) => setLinkId(e.target.value)}>
                  <option value="">Select...</option>
                  {unlinked.map((q) => (
                    <option key={q.id} value={q.id}>
                      {q.quotation_number} · {words(q.status)} · {formatMoney(q.total_amount_sgd)}
                    </option>
                  ))}
                </select>
              </div>
              <button type="button" className="secondary" onClick={onLinkQuotation} disabled={!linkId}>
                Link
              </button>
            </>
          )}
        </div>
        <div style={{ overflowX: 'auto' }}>
          <table>
            <thead>
              <tr>
                <th>Number</th>
                <th>Date</th>
                <th>Status</th>
                <th>Total</th>
                <th>Counts as quoted</th>
              </tr>
            </thead>
            <tbody>
              {p.quotations.map((q) => (
                <tr key={q.id}>
                  <td>
                    <Link to={`/quotations/${q.id}/print`}>{q.quotation_number}</Link>
                  </td>
                  <td>{formatDate(q.quotation_date)}</td>
                  <td>{words(q.status)}</td>
                  <td>{formatMoney(q.total_amount_sgd)}</td>
                  <td>{q.counts_as_quoted ? 'Yes' : 'No'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {p.quotations.length === 0 && <p className="muted">No quotations yet.</p>}
      </div>

      <div className="card">
        <h2>Invoices ({p.invoices.length})</h2>
        <div style={{ overflowX: 'auto' }}>
          <table>
            <thead>
              <tr>
                <th>Number</th>
                <th>Issued</th>
                <th>Due</th>
                <th>Status</th>
                <th>Total</th>
                <th>Paid</th>
                <th>Outstanding</th>
              </tr>
            </thead>
            <tbody>
              {p.invoices.map((i) => (
                <tr key={i.id}>
                  <td>
                    <Link to={`/invoices/${i.id}/print`}>{i.invoice_number}</Link>
                  </td>
                  <td>{formatDate(i.issued_at)}</td>
                  <td>{formatDate(i.due_date)}</td>
                  <td>{words(i.status)}</td>
                  <td>{formatMoney(i.total_amount_sgd)}</td>
                  <td>{formatMoney(i.amount_paid_sgd)}</td>
                  <td>{formatMoney(i.outstanding_sgd)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {p.invoices.length === 0 && <p className="muted">No invoices yet.</p>}
      </div>

      <InvoiceHistoryPanel customerId={p.customer_id} title={`All invoices for ${p.customer_name ?? 'this Company / Individual'}`} />
    </div>
  )
}
