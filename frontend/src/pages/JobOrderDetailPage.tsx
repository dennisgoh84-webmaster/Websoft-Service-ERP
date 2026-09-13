import { useEffect, useState, type FormEvent } from 'react'
import { Link, useParams } from 'react-router-dom'
import {
  api,
  type CurrentUser,
  type ServiceRecord,
  type ServiceRecordCompletion,
  type JobOrder,
} from '../lib/api'
import DocumentAttachmentsPanel from '../components/DocumentAttachmentsPanel'
import ProjectSchedulePanel from '../components/ProjectSchedulePanel'
import SignaturePanel from '../components/SignaturePanel'
import { useAuth } from '../lib/AuthContext'

export default function JobOrderDetailPage() {
  const { id } = useParams<{ id: string }>()
  const { user } = useAuth()
  const [jobOrder, setJobOrder] = useState<JobOrder | null>(null)
  const [records, setRecords] = useState<ServiceRecord[]>([])
  const [users, setUsers] = useState<CurrentUser[]>([])
  const [error, setError] = useState<string | null>(null)
  const [working, setWorking] = useState(false)

  const [assignee, setAssignee] = useState('')
  const [employee, setEmployee] = useState('')
  const [workDate, setWorkDate] = useState(new Date().toISOString().slice(0, 10))
  const [minutes, setMinutes] = useState('30')
  const [completionStatus, setCompletionStatus] = useState<ServiceRecordCompletion>('U')
  const [afterHours, setAfterHours] = useState(false)
  const [workDescription, setWorkDescription] = useState('')
  const [dueDate, setDueDate] = useState('')

  function refresh() {
    if (!id) return
    api.getJobOrder(id).then((jo) => {
      setJobOrder(jo)
      setDueDate(jo.due_date ?? '')
    })
    api.listServiceRecords({ job_order_id: id }).then(setRecords)
    api.listUsers().then(setUsers)
  }

  useEffect(refresh, [id])

  const userName = (uid: string | null) => users.find((u) => u.id === uid)?.full_name ?? '-'

  async function onAssign(e: FormEvent) {
    e.preventDefault()
    if (!id) return
    setError(null)
    try {
      await api.assignJobOrder(id, assignee)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to assign')
    }
  }

  async function onLogRecord(e: FormEvent) {
    e.preventDefault()
    if (!id) return
    setError(null)
    try {
      await api.submitServiceRecord({
        job_order_id: id,
        employee_user_id: employee,
        work_date: workDate,
        raw_minutes: parseInt(minutes, 10),
        completion_status: completionStatus,
        is_after_hours: afterHours,
        work_description: workDescription || undefined,
      })
      setCompletionStatus('U')
      setAfterHours(false)
      setWorkDescription('')
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to log service record')
    }
  }

  async function onSetDueDate(e: FormEvent) {
    e.preventDefault()
    if (!id) return
    setError(null)
    try {
      await api.setJobOrderDueDate(id, dueDate || null)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to set due date')
    }
  }

  async function onToggleUrgent() {
    if (!id || !jobOrder) return
    setError(null)
    setWorking(true)
    try {
      await api.setJobOrderUrgent(id, !jobOrder.is_urgent)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to update Urgent')
    } finally {
      setWorking(false)
    }
  }

  async function onVoid() {
    if (!id) return
    const reason = window.prompt('Reason for voiding this job order (required):')
    if (!reason || !reason.trim()) return
    setError(null)
    setWorking(true)
    try {
      await api.voidJobOrder(id, reason.trim())
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to void job order')
    } finally {
      setWorking(false)
    }
  }

  async function onReopen() {
    if (!id) return
    setError(null)
    setWorking(true)
    try {
      await api.reopenJobOrder(id)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to reopen job order (owner only)')
    } finally {
      setWorking(false)
    }
  }

  async function onApproveBudgetOverrun() {
    if (!id) return
    if (!window.confirm('Approve budget overrun? This allows work to continue past the contract budget.')) return
    setError(null)
    setWorking(true)
    try {
      await api.approveBudgetOverrun(id)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to approve budget overrun')
    } finally {
      setWorking(false)
    }
  }

  if (!jobOrder) return <p>Loading...</p>

  const isClosed = jobOrder.status === 'closed'
  const isVoid = jobOrder.status === 'void'
  const isOpenOrAssigned = jobOrder.status === 'open' || jobOrder.status === 'assigned'
  const statusBadgeClass = isClosed ? 'expired' : isVoid ? 'exceeded' : 'active'

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 12 }}>
        <h1 style={{ margin: 0 }}>{jobOrder.subject}</h1>
        <Link to={`/job-orders/${jobOrder.id}/print`} className="secondary" style={{ alignSelf: 'center' }}>
          Print
        </Link>
      </div>
      <p>
        <span className="muted">{jobOrder.job_order_number}</span>{' '}
        <span className={`badge ${statusBadgeClass}`}>{jobOrder.status}</span>{' '}
        {jobOrder.job_order_type === 'project' && <span className="badge active">PROJECT</span>}{' '}
        {jobOrder.is_urgent && <span className="badge exceeded">URGENT</span>}{' '}
        <span className="muted">Priority: {jobOrder.priority} (SRV-009: no formal SLA target yet)</span>
        {jobOrder.due_date && (
          <>
            {' '}
            &middot; <span className="muted">Due {jobOrder.due_date}</span>
          </>
        )}
        {jobOrder.closed_at && (
          <>
            {' '}
            &middot; <span className="muted">Closed {new Date(jobOrder.closed_at).toLocaleString()}</span>
          </>
        )}
      </p>
      {isVoid && jobOrder.void_reason && (
        <p className="muted">Voided: {jobOrder.void_reason}</p>
      )}
      {isClosed && (
        <p className="muted">
          Auto-closed: the most recent Service Record was Approved and marked Completed. See
          Service Records below.
        </p>
      )}
      <p style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
        {isOpenOrAssigned && (
          <button className="secondary" onClick={onToggleUrgent} disabled={working}>
            {jobOrder.is_urgent ? 'Unmark Urgent' : 'Mark Urgent'}
          </button>
        )}
        {isOpenOrAssigned && (
          <button className="secondary" onClick={onVoid} disabled={working}>
            Void job order
          </button>
        )}
        {(isClosed || isVoid) && user?.role === 'owner' && (
          <button className="secondary" onClick={onReopen} disabled={working}>
            Reopen (owner)
          </button>
        )}
      </p>
      {error && <div className="error-banner">{error}</div>}

      {/* Budget overrun warning (7.1) */}
      {jobOrder.budget_overrun && (jobOrder.budget_overrun.is_over_hours || jobOrder.budget_overrun.is_over_cost) && (
        <div
          style={{
            background: jobOrder.budget_overrun_approved ? '#eafaf1' : '#fdecea',
            border: `1px solid ${jobOrder.budget_overrun_approved ? '#27ae60' : '#e74c3c'}`,
            borderRadius: 8,
            padding: '12px 16px',
            marginBottom: 16,
          }}
        >
          <strong style={{ color: jobOrder.budget_overrun_approved ? '#27ae60' : '#e74c3c' }}>
            {jobOrder.budget_overrun_approved ? '✅ Budget Overrun Approved' : '⚠️ Budget Overrun Detected'}
          </strong>
          <div style={{ marginTop: 6, fontSize: 13 }}>
            {jobOrder.budget_overrun.is_over_hours && (
              <p style={{ margin: '2px 0' }}>
                Hours: <strong>{(jobOrder.budget_overrun.consumed_minutes / 60).toFixed(1)}h</strong> consumed
                of <strong>{(jobOrder.budget_overrun.contracted_minutes / 60).toFixed(1)}h</strong> contracted
                ({((jobOrder.budget_overrun.consumed_minutes / Math.max(1, jobOrder.budget_overrun.contracted_minutes)) * 100).toFixed(0)}%)
              </p>
            )}
            {jobOrder.budget_overrun.is_over_cost && (
              <p style={{ margin: '2px 0' }}>
                Cost: <strong>SGD {jobOrder.budget_overrun.consumed_cost_sgd.toLocaleString()}</strong> consumed
                of <strong>SGD {jobOrder.budget_overrun.contract_value_sgd.toLocaleString()}</strong> contract value
              </p>
            )}
          </div>
          {jobOrder.budget_overrun_approved ? (
            <p style={{ margin: '6px 0 0', fontSize: 12, color: '#666' }}>
              Approved by {userName(jobOrder.budget_overrun_approved_by)} on{' '}
              {jobOrder.budget_overrun_approved_at
                ? new Date(jobOrder.budget_overrun_approved_at).toLocaleString()
                : '-'}
            </p>
          ) : (
            isOpenOrAssigned && (user?.role === 'sales_manager' || user?.role === 'owner') && (
              <button
                onClick={onApproveBudgetOverrun}
                disabled={working}
                style={{ marginTop: 8 }}
              >
                Approve Overrun
              </button>
            )
          )}
          {!jobOrder.budget_overrun_approved && !(user?.role === 'sales_manager' || user?.role === 'owner') && (
            <p style={{ margin: '6px 0 0', fontSize: 12, color: '#e74c3c' }}>
              Sales Manager or Owner approval required to continue past budget.
            </p>
          )}
        </div>
      )}

      {/* Side by side -- confirmed 2026-09-11, these two small forms
          don't need a full-width card each. Hidden once closed/void --
          nothing left to assign or reschedule on a finished or
          cancelled job order (backend enforces this too). */}
      {isOpenOrAssigned && (
        <div style={{ display: 'flex', gap: 20, flexWrap: 'wrap' }}>
          <div className="card" style={{ flex: '1 1 320px' }}>
            <h2>Due date</h2>
            <p className="muted">
              Set manually by Sales/Coordinator after discussion with Support -- not derived from
              priority.
            </p>
            <form onSubmit={onSetDueDate} style={{ display: 'flex', gap: 10, alignItems: 'flex-end' }}>
              <div className="form-row" style={{ margin: 0 }}>
                <label>Due date</label>
                <input type="date" value={dueDate} onChange={(e) => setDueDate(e.target.value)} />
              </div>
              <button type="submit">Save</button>
            </form>
          </div>

          <div className="card" style={{ flex: '1 1 320px' }}>
            <h2>Assignment</h2>
            <p>Currently assigned to: {userName(jobOrder.assigned_to_user_id)}</p>
            <form onSubmit={onAssign} style={{ display: 'flex', gap: 10, alignItems: 'flex-end' }}>
              <div className="form-row" style={{ margin: 0, flex: 1 }}>
                <label>Assign to</label>
                <select value={assignee} onChange={(e) => setAssignee(e.target.value)} required>
                  <option value="">Select...</option>
                  {users.map((u) => (
                    <option key={u.id} value={u.id}>
                      {u.full_name} ({u.role})
                    </option>
                  ))}
                </select>
              </div>
              <button type="submit">Assign</button>
            </form>
          </div>
        </div>
      )}

      {!isOpenOrAssigned && (
        <p className="muted">Currently assigned to: {userName(jobOrder.assigned_to_user_id)}</p>
      )}

      {isOpenOrAssigned && (
      <div className="card">
        <h2>Log a Service Record (SRV-007: rounds up to nearest 15 min)</h2>
        <form onSubmit={onLogRecord}>
          <div className="form-row">
            <label>Employee</label>
            <select value={employee} onChange={(e) => setEmployee(e.target.value)} required>
              <option value="">Select...</option>
              {users.map((u) => (
                <option key={u.id} value={u.id}>
                  {u.full_name}
                </option>
              ))}
            </select>
          </div>
          <div className="form-row">
            <label>Work date</label>
            <input type="date" value={workDate} onChange={(e) => setWorkDate(e.target.value)} />
          </div>
          <div className="form-row">
            <label>Minutes worked</label>
            <input type="number" min={1} value={minutes} onChange={(e) => setMinutes(e.target.value)} />
          </div>
          <div className="form-row">
            <label>Completion</label>
            <select
              value={completionStatus}
              onChange={(e) => setCompletionStatus(e.target.value as ServiceRecordCompletion)}
            >
              <option value="U">Uncompleted -- another visit needed</option>
              <option value="C">Completed -- this finishes the job</option>
            </select>
          </div>
          <div className="form-row">
            <label>
              <input
                type="checkbox"
                checked={afterHours}
                onChange={(e) => setAfterHours(e.target.checked)}
                style={{ marginRight: 6 }}
              />
              After office hours / weekend / holiday
            </label>
          </div>
          <div className="form-row">
            <label>Work description (optional)</label>
            <textarea
              value={workDescription}
              onChange={(e) => setWorkDescription(e.target.value)}
              rows={3}
              style={{ minWidth: 260 }}
              spellCheck="true"
              lang="en"
              placeholder="What was done this session..."
            />
          </div>
          <button type="submit">Submit Service Record</button>
        </form>
        <p className="muted" style={{ marginTop: 8 }}>
          Deduction minutes are keyed in on approval -- see{' '}
          <Link to="/service-record-approval">Service Record Approval</Link>.
        </p>
      </div>
      )}

      {jobOrder.job_order_type === 'project' && (
        <ProjectSchedulePanel
          jobOrderId={jobOrder.id}
          milestones={jobOrder.milestones || []}
          users={users}
          editable={isOpenOrAssigned}
          onRefresh={refresh}
        />
      )}

      <div className="card">
        <h2>Service Records</h2>
        <table>
          <thead>
            <tr>
              <th>Employee</th>
              <th>Date</th>
              <th>Raw / Rounded / Deducted</th>
              <th>Completion</th>
              <th>Status</th>
              <th>Outcome</th>
              <th>Description</th>
            </tr>
          </thead>
          <tbody>
            {records.map((r) => (
              <tr key={r.id}>
                <td>{userName(r.employee_user_id)}</td>
                <td>{r.work_date}</td>
                <td>
                  {r.raw_minutes}m &rarr; {r.rounded_minutes}m
                  {r.deducted_minutes != null && <> &rarr; {r.deducted_minutes}m deducted</>}
                </td>
                <td>
                  {r.completion_status === 'C' ? 'Completed' : 'Uncompleted'}
                  {r.is_after_hours && <span className="badge exceeded" style={{ marginLeft: 6 }}>after-hours</span>}
                </td>
                <td>
                  {r.status}
                  {r.is_late && <span className="badge exceeded" style={{ marginLeft: 6 }}>late</span>}
                </td>
                <td>{r.outcome}</td>
                <td>{r.work_description ?? <span className="muted">-</span>}</td>
              </tr>
            ))}
            {records.length === 0 && (
              <tr>
                <td colSpan={7} className="muted">
                  No Service Records yet.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      {id && (
        <div className="card">
          <DocumentAttachmentsPanel entityType="job_order" entityId={id} />
          <SignaturePanel entityType="job_order" entityId={id} />
        </div>
      )}
    </div>
  )
}
