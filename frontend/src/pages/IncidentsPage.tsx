// Incident Module (2026-09-12) -- the Helpdesk front door for an
// incoming call or email, before it's routed to a Sales Quotation, a
// Job Order, or a Software Task. See docs/open-business-decisions.md
// #36 for the confirmed rules this implements.
import { Fragment, useEffect, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import AiChatPanel from '../components/AiChatPanel'
import DocumentAttachmentsPanel from '../components/DocumentAttachmentsPanel'
import SignaturePanel from '../components/SignaturePanel'
import {
  api,
  type CompanyIndividual,
  type Contract,
  type CurrentUser,
  type Incident,
  type IncidentSource,
  type IncidentStatus,
  type IncidentTriage,
} from '../lib/api'
import { useAuth } from '../lib/AuthContext'
import { formatDate, todayIso } from '../lib/format'

const STATUS_BADGE: Record<IncidentStatus, string> = {
  open: 'draft',
  pending_callback: 'exceeded',
  converted: 'active',
  closed: 'expired',
}
const STATUS_LABEL: Record<IncidentStatus, string> = {
  open: 'Open',
  pending_callback: 'Pending callback',
  converted: 'Converted',
  closed: 'Closed',
}

export default function IncidentsPage() {
  const { moduleAccess } = useAuth()
  const [incidents, setIncidents] = useState<Incident[]>([])
  const [customers, setCustomers] = useState<CompanyIndividual[]>([])
  const [contracts, setContracts] = useState<Contract[]>([])
  const [users, setUsers] = useState<CurrentUser[]>([])
  const [error, setError] = useState<string | null>(null)
  const [message, setMessage] = useState<string | null>(null)
  const [statusFilter, setStatusFilter] = useState<IncidentStatus | ''>('')

  // New incident form
  const [customerId, setCustomerId] = useState('')
  const [source, setSource] = useState<IncidentSource>('phone')
  const [subject, setSubject] = useState('')
  const [description, setDescription] = useState('')
  const [senderName, setSenderName] = useState('')
  const [senderEmail, setSenderEmail] = useState('')
  const [senderPhone, setSenderPhone] = useState('')
  const [saving, setSaving] = useState(false)

  const [docPanelId, setDocPanelId] = useState<string | null>(null)

  // Per-row action panel
  const [expandedId, setExpandedId] = useState<string | null>(null)
  const [panelCustomerId, setPanelCustomerId] = useState('')
  const [callbackUserId, setCallbackUserId] = useState('')
  const [jobOrderContractId, setJobOrderContractId] = useState('')
  const [softwareTaskProgrammerId, setSoftwareTaskProgrammerId] = useState('')
  const [working, setWorking] = useState(false)
  // AI Assistant (planned-work #12, slice 1): the latest triage
  // suggestion for the expanded incident. It only ever fills in the
  // pickers below -- staff still press the same buttons.
  const [triage, setTriage] = useState<IncidentTriage | null>(null)
  const [triageLoading, setTriageLoading] = useState(false)
  const [triageError, setTriageError] = useState<string | null>(null)
  // Module Control decides: no `ai_assistant`, no AI card at all.
  const [aiRefused, setAiRefused] = useState(false)
  const aiAvailable = moduleAccess.ai_assistant === true && !aiRefused

  function refresh() {
    api.listIncidents(statusFilter ? { status: statusFilter } : {}).then(setIncidents).catch((e) => setError(e.message))
    api.listCompanyIndividuals().then(setCustomers).catch((e) => setError(e.message))
    api.listContracts().then(setContracts).catch(() => setContracts([]))
    api.listUsers().then(setUsers).catch(() => setUsers([]))
  }

  useEffect(refresh, [statusFilter])

  const customerName = (id: string | null) => (id ? customers.find((c) => c.id === id)?.name ?? id.slice(0, 8) : null)
  const userName = (id: string | null) => (id ? users.find((u) => u.id === id)?.full_name ?? id.slice(0, 8) : null)

  async function onCreate(e: FormEvent) {
    e.preventDefault()
    setError(null)
    setMessage(null)
    setSaving(true)
    try {
      const inc = await api.createIncident({
        customer_id: customerId || undefined,
        source,
        subject,
        description: description || undefined,
        sender_name: senderName || undefined,
        sender_email: senderEmail || undefined,
        sender_phone: senderPhone || undefined,
      })
      setMessage(`${inc.incident_number} logged.${inc.customer_id ? '' : ' No Company/Individual matched yet -- set one before converting.'}`)
      setCustomerId('')
      setSubject('')
      setDescription('')
      setSenderName('')
      setSenderEmail('')
      setSenderPhone('')
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to log incident')
    } finally {
      setSaving(false)
    }
  }

  function openPanel(incident: Incident) {
    const opening = expandedId !== incident.id
    setExpandedId(opening ? incident.id : null)
    setPanelCustomerId(incident.customer_id ?? '')
    setCallbackUserId('')
    setJobOrderContractId('')
    setSoftwareTaskProgrammerId('')
    setTriage(null)
    setTriageError(null)
    if (opening && aiAvailable) {
      // A 403 means the AI Assistant module is not licensed here; hide the card rather than nag.
      api
        .getIncidentTriage(incident.id)
        .then(setTriage)
        .catch((e: Error) => {
          if (/not enabled|403|access/i.test(e.message)) setAiRefused(true)
        })
    }
  }

  async function onAskAi(incident: Incident) {
    setTriageError(null)
    setTriageLoading(true)
    try {
      const t = await api.runIncidentTriage(incident.id)
      setTriage(t)
      if (t.status !== 'ok') setTriageError(t.error ?? 'The assistant declined to answer.')
    } catch (err) {
      setTriageError(err instanceof Error ? err.message : 'AI triage failed')
    } finally {
      setTriageLoading(false)
    }
  }

  function applySuggestion(incident: Incident) {
    const s = triage?.suggestion
    if (!s) return
    if (!incident.customer_id && s.customer_id) setPanelCustomerId(s.customer_id)
    if (s.contract_id) setJobOrderContractId(s.contract_id)
    setMessage('Suggestion applied to the pickers below -- review, then press the action you agree with.')
  }

  const ROUTE_LABEL: Record<string, string> = {
    job_order: 'Convert to Job Order',
    quotation: 'Convert to Quotation',
    software_task: 'Convert to Software Task',
    callback: 'Mark pending callback',
    close: 'Close -- no action needed',
  }

  async function withPanel(fn: () => Promise<unknown>, successMessage: string) {
    setError(null)
    setMessage(null)
    setWorking(true)
    try {
      await fn()
      setMessage(successMessage)
      setExpandedId(null)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Action failed')
    } finally {
      setWorking(false)
    }
  }

  async function onClose(incident: Incident) {
    const reason = window.prompt(`Reason for closing ${incident.incident_number} without converting it:`)
    if (!reason || !reason.trim()) return
    withPanel(() => api.closeIncident(incident.id, reason.trim()), `${incident.incident_number} closed.`)
  }

  return (
    <div>
      <h1>Incidents</h1>
      <p className="muted">
        The Helpdesk front door: log an incoming call or email here, then route it -- converting
        creates the real Sales Quotation, Job Order, or Software Task (pre-filled, linked back to
        this Incident), rather than just assigning it. "Needs a callback" is just a status and an
        assignee here, not a separate task.
      </p>
      {error && <div className="error-banner">{error}</div>}
      {message && (
        <p className="muted" style={{ marginBottom: 12 }}>
          {message}
        </p>
      )}
      <AiChatPanel
        context={
          expandedId
            ? { type: 'incident', id: expandedId, label: incidents.find((i) => i.id === expandedId)?.incident_number ?? 'this incident' }
            : { type: 'incident', id: null, label: 'Incidents' }
        }
      />

      <div className="card">
        <h2>Log an incident</h2>
        <form onSubmit={onCreate}>
          <div className="form-row">
            <label>Source</label>
            <select value={source} onChange={(e) => setSource(e.target.value as IncidentSource)}>
              <option value="phone">Phone</option>
              <option value="email">Email</option>
              <option value="other">Other</option>
            </select>
          </div>
          <div className="form-row">
            <label>Company / Individual (if known)</label>
            <select value={customerId} onChange={(e) => setCustomerId(e.target.value)}>
              <option value="">Not identified yet</option>
              {customers.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
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
            <input value={description} onChange={(e) => setDescription(e.target.value)} style={{ minWidth: 280 }} />
          </div>
          <div className="form-row">
            <label>Caller/sender name</label>
            <input value={senderName} onChange={(e) => setSenderName(e.target.value)} />
          </div>
          <div className="form-row">
            <label>Caller/sender email</label>
            <input type="email" value={senderEmail} onChange={(e) => setSenderEmail(e.target.value)} />
          </div>
          <div className="form-row">
            <label>Caller/sender phone</label>
            <input value={senderPhone} onChange={(e) => setSenderPhone(e.target.value)} />
          </div>
          <button type="submit" disabled={saving || !subject}>
            {saving ? 'Logging...' : 'Log incident'}
          </button>
        </form>
      </div>

      <div className="card">
        <div className="filter-bar">
          <h2 style={{ margin: 0 }}>Incidents ({incidents.length})</h2>
          <div className="form-row" style={{ margin: 0 }}>
            <label>Status</label>
            <select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value as IncidentStatus | '')}>
              <option value="">All</option>
              <option value="open">Open</option>
              <option value="pending_callback">Pending callback</option>
              <option value="converted">Converted</option>
              <option value="closed">Closed</option>
            </select>
          </div>
        </div>

        <div style={{ overflowX: 'auto' }}>
          <table>
            <thead>
              <tr>
                <th>No.</th>
                <th>Source</th>
                <th>Company / Individual</th>
                <th>Subject</th>
                <th>Status</th>
                <th>Logged</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {incidents.map((inc) => (
                <Fragment key={inc.id}>
                  <tr>
                    <td>{inc.incident_number}</td>
                    <td className="muted">{inc.source}</td>
                    <td>
                      {customerName(inc.customer_id) ?? <span className="muted">Not identified</span>}
                      {inc.sender_name && <div className="muted">{inc.sender_name}{inc.sender_email ? ` <${inc.sender_email}>` : ''}</div>}
                    </td>
                    <td>
                      {inc.subject}
                      {inc.status === 'pending_callback' && (
                        <div className="muted">Callback: {userName(inc.assigned_to_user_id)}</div>
                      )}
                      {inc.status === 'closed' && inc.close_reason && <div className="muted">Closed: {inc.close_reason}</div>}
                      {inc.status === 'converted' && (
                        <div className="muted">
                          {inc.converted_quotation_id && <Link to="/quotations">View Quotation</Link>}
                          {inc.converted_job_order_id && <Link to={`/job-orders/${inc.converted_job_order_id}`}>View Job Order</Link>}
                          {inc.converted_software_task_id && <Link to="/software-tasks">View Software Task</Link>}
                        </div>
                      )}
                    </td>
                    <td>
                      <span className={`badge ${STATUS_BADGE[inc.status]}`}>{STATUS_LABEL[inc.status]}</span>
                    </td>
                    <td>{formatDate(inc.created_at)}</td>
                    <td>
                      <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                        <button
                          className="secondary icon-button"
                          title="Attachments & Signatures"
                          aria-label="Attachments & Signatures"
                          onClick={() => setDocPanelId(docPanelId === inc.id ? null : inc.id)}
                        >📎</button>
                        {inc.status === 'open' && (
                          <>
                            <button className="secondary" onClick={() => openPanel(inc)}>
                              {expandedId === inc.id ? 'Hide' : 'Route...'}
                            </button>
                            <button className="secondary" onClick={() => onClose(inc)}>
                              Close
                            </button>
                          </>
                        )}
                      </div>
                    </td>
                  </tr>
                  {docPanelId === inc.id && (
                    <tr>
                      <td colSpan={7} style={{ padding: 16, background: 'var(--bg-muted, #f9f9f9)' }}>
                        <DocumentAttachmentsPanel entityType="incident" entityId={inc.id} />
                        <SignaturePanel entityType="incident" entityId={inc.id} />
                      </td>
                    </tr>
                  )}
                  {expandedId === inc.id && (
                    <tr>
                      <td colSpan={7}>
                        <div className="card" style={{ margin: '8px 0' }}>
                          {aiAvailable && inc.status !== 'converted' && inc.status !== 'closed' && (
                            <div className="card" style={{ margin: '0 0 12px', background: '#f7f9fc' }}>
                              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                                <strong>AI triage</strong>
                                <div style={{ display: 'flex', gap: 8 }}>
                                  <button type="button" className="secondary" onClick={() => onAskAi(inc)} disabled={triageLoading || working}>
                                    {triageLoading ? 'Asking...' : triage?.suggestion ? 'Ask again' : 'Ask the assistant'}
                                  </button>
                                  {triage?.suggestion && (
                                    <button type="button" onClick={() => applySuggestion(inc)} disabled={working}>
                                      Fill in the pickers
                                    </button>
                                  )}
                                </div>
                              </div>
                              {triageError && <div className="error-banner">{triageError}</div>}
                              {!triage && !triageLoading && (
                                <p className="muted" style={{ margin: '6px 0 0' }}>
                                  Suggests the customer, contract, priority and route, similar past incidents and what fixed
                                  them, and a draft reply. It proposes only -- nothing changes until you press an action.
                                </p>
                              )}
                              {triage?.suggestion && (
                                <div style={{ marginTop: 8, fontSize: 14 }}>
                                  <p style={{ margin: '4px 0' }}>{triage.suggestion.summary}</p>
                                  <table style={{ marginTop: 6 }}>
                                    <tbody>
                                      <tr>
                                        <th style={{ width: 160 }}>Customer</th>
                                        <td>
                                          {triage.suggestion.customer_name ?? <span className="muted">no match</span>}{' '}
                                          <span className="muted">
                                            ({triage.suggestion.customer_confidence}) {triage.suggestion.customer_reason}
                                          </span>
                                        </td>
                                      </tr>
                                      <tr>
                                        <th>Contract</th>
                                        <td>
                                          {triage.suggestion.contract_number ?? <span className="muted">none</span>}
                                          {triage.suggestion.contract_hours_remaining != null && (
                                            <span className="muted"> -- {triage.suggestion.contract_hours_remaining} h remaining</span>
                                          )}
                                        </td>
                                      </tr>
                                      <tr>
                                        <th>Suggested route</th>
                                        <td>
                                          <strong>{ROUTE_LABEL[triage.suggestion.route] ?? triage.suggestion.route}</strong>, priority{' '}
                                          {triage.suggestion.priority} <span className="muted">-- {triage.suggestion.route_reason}</span>
                                        </td>
                                      </tr>
                                      {triage.suggestion.similar_incidents.length > 0 && (
                                        <tr>
                                          <th>Similar incidents</th>
                                          <td>
                                            <ul style={{ margin: 0, paddingLeft: 18 }}>
                                              {triage.suggestion.similar_incidents.map((x) => (
                                                <li key={x.incident_id}>
                                                  <span className="muted">{x.incident_number}</span> {x.subject} -- {x.why_similar}
                                                  {x.what_fixed_it && (
                                                    <>
                                                      {' '}
                                                      <em>Fixed by:</em> {x.what_fixed_it}
                                                    </>
                                                  )}
                                                </li>
                                              ))}
                                            </ul>
                                          </td>
                                        </tr>
                                      )}
                                      <tr>
                                        <th>Draft reply</th>
                                        <td>
                                          <textarea readOnly value={triage.suggestion.suggested_reply} rows={3} style={{ width: '100%' }} />
                                          <button
                                            type="button"
                                            className="secondary"
                                            onClick={() => navigator.clipboard?.writeText(triage.suggestion?.suggested_reply ?? '')}
                                          >
                                            Copy reply
                                          </button>
                                        </td>
                                      </tr>
                                    </tbody>
                                  </table>
                                  <p className="muted" style={{ margin: '6px 0 0', fontSize: 12 }}>
                                    {triage.model}, {triage.input_tokens} in / {triage.output_tokens} out tokens, {formatDate(triage.created_at)}.
                                    {triage.suggestion.personal_data_redacted
                                      ? ' Personal data was masked before sending.'
                                      : ' Personal data was sent as-is (masking is off under Maintenance → AI Assistant).'}
                                  </p>
                                </div>
                              )}
                            </div>
                          )}
                          {!inc.customer_id && (
                            <div className="form-row">
                              <label>Set Company / Individual first</label>
                              <select value={panelCustomerId} onChange={(e) => setPanelCustomerId(e.target.value)}>
                                <option value="">Select...</option>
                                {customers.map((c) => (
                                  <option key={c.id} value={c.id}>
                                    {c.name}
                                  </option>
                                ))}
                              </select>
                              <button
                                type="button"
                                disabled={!panelCustomerId || working}
                                onClick={() =>
                                  withPanel(() => api.setIncidentCustomer(inc.id, panelCustomerId), `Company/Individual set on ${inc.incident_number}.`)
                                }
                              >
                                Save
                              </button>
                            </div>
                          )}

                          <div className="form-row">
                            <label>Needs a callback -- assign to</label>
                            <select value={callbackUserId} onChange={(e) => setCallbackUserId(e.target.value)}>
                              <option value="">Select staff...</option>
                              {users.map((u) => (
                                <option key={u.id} value={u.id}>
                                  {u.full_name}
                                </option>
                              ))}
                            </select>
                            <button
                              type="button"
                              disabled={!callbackUserId || working}
                              onClick={() => withPanel(() => api.setIncidentCallback(inc.id, callbackUserId), `${inc.incident_number} marked pending callback.`)}
                            >
                              Set callback
                            </button>
                          </div>

                          <div className="form-row">
                            <label>Convert to Sales Quotation</label>
                            <button
                              type="button"
                              disabled={!inc.customer_id || working}
                              onClick={() =>
                                withPanel(
                                  () => api.convertIncidentToQuotation(inc.id, todayIso()),
                                  `${inc.incident_number} converted to a draft Quotation.`,
                                )
                              }
                            >
                              Convert to Quotation
                            </button>
                            {!inc.customer_id && <span className="muted"> -- needs a Company/Individual first</span>}
                          </div>

                          <div className="form-row">
                            <label>Convert to Job Order -- contract</label>
                            <select value={jobOrderContractId} onChange={(e) => setJobOrderContractId(e.target.value)} disabled={!inc.customer_id}>
                              <option value="">Select contract...</option>
                              {contracts
                                .filter((c) => c.customer_id === inc.customer_id && (c.status === 'active' || c.status === 'exceeded'))
                                .map((c) => (
                                  <option key={c.id} value={c.id}>
                                    {c.contract_number} ({c.status})
                                  </option>
                                ))}
                            </select>
                            <button
                              type="button"
                              disabled={!jobOrderContractId || working}
                              onClick={() =>
                                withPanel(() => api.convertIncidentToJobOrder(inc.id, jobOrderContractId), `${inc.incident_number} converted to a Job Order.`)
                              }
                            >
                              Convert to Job Order
                            </button>
                            {inc.customer_id && contracts.filter((c) => c.customer_id === inc.customer_id && (c.status === 'active' || c.status === 'exceeded')).length === 0 && (
                              <span className="muted"> -- no active contract for this customer</span>
                            )}
                          </div>

                          <div className="form-row">
                            <label>Convert to Software Task -- programmer</label>
                            <select value={softwareTaskProgrammerId} onChange={(e) => setSoftwareTaskProgrammerId(e.target.value)}>
                              <option value="">Unassigned</option>
                              {users.map((u) => (
                                <option key={u.id} value={u.id}>
                                  {u.full_name}
                                </option>
                              ))}
                            </select>
                            <button
                              type="button"
                              disabled={working}
                              onClick={() =>
                                withPanel(
                                  () => api.convertIncidentToSoftwareTask(inc.id, softwareTaskProgrammerId || null),
                                  `${inc.incident_number} converted to a Software Task.`,
                                )
                              }
                            >
                              Convert to Software Task
                            </button>
                          </div>
                        </div>
                      </td>
                    </tr>
                  )}
                </Fragment>
              ))}
              {incidents.length === 0 && (
                <tr>
                  <td colSpan={7} className="muted">
                    No incidents logged yet.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  )
}
