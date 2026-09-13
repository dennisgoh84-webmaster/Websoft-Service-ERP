/**
 * Project Schedule Panel — milestone management + Gantt chart
 * for PROJECT-type Job Orders.
 *
 * Shows:
 *  - Editable milestone table (planned dates, actual dates, status, assigned staff)
 *  - Pure-CSS Gantt chart visualization with planned vs actual bars
 */
import { useState } from 'react'
import {
  api,
  type CurrentUser,
  type MilestoneStatus,
  type MilestoneType,
  type ProjectMilestone,
} from '../lib/api'
import { useAuth } from '../lib/AuthContext'

const MILESTONE_TYPE_LABELS: Record<MilestoneType, string> = {
  installation: 'Installation',
  training: 'Training',
  repeat_training: 'Repeat Training',
  handover: 'Handover',
  completion_signoff: 'Completion Sign-off',
}

const STATUS_LABELS: Record<MilestoneStatus, string> = {
  pending: 'Pending',
  in_progress: 'In Progress',
  completed: 'Completed',
  skipped: 'Skipped',
}

const STATUS_COLORS: Record<MilestoneStatus, string> = {
  pending: '#95a5a6',
  in_progress: '#3498db',
  completed: '#27ae60',
  skipped: '#bdc3c7',
}

interface Props {
  jobOrderId: string
  milestones: ProjectMilestone[]
  users: CurrentUser[]
  editable: boolean
  onRefresh: () => void
}

export default function ProjectSchedulePanel({ jobOrderId, milestones, users, editable, onRefresh }: Props) {
  const { user: currentUser } = useAuth()
  const canApproveCompletion = currentUser?.role === 'sales_manager' || currentUser?.role === 'owner'
  const [error, setError] = useState('')
  const [editingId, setEditingId] = useState<string | null>(null)
  const [editForm, setEditForm] = useState<{
    planned_start: string
    planned_end: string
    actual_start: string
    actual_end: string
    status: MilestoneStatus
    assigned_user_id: string
    notes: string
  }>({
    planned_start: '',
    planned_end: '',
    actual_start: '',
    actual_end: '',
    status: 'pending',
    assigned_user_id: '',
    notes: '',
  })

  const userName = (uid: string | null) => users.find((u) => u.id === uid)?.full_name ?? '-'

  const startEditing = (m: ProjectMilestone) => {
    setEditingId(m.id)
    setEditForm({
      planned_start: m.planned_start ?? '',
      planned_end: m.planned_end ?? '',
      actual_start: m.actual_start ?? '',
      actual_end: m.actual_end ?? '',
      status: m.status,
      assigned_user_id: m.assigned_user_id ?? '',
      notes: m.notes ?? '',
    })
  }

  const handleSave = async (milestoneId: string) => {
    setError('')
    try {
      await api.updateMilestone(jobOrderId, milestoneId, {
        planned_start: editForm.planned_start || null,
        planned_end: editForm.planned_end || null,
        actual_start: editForm.actual_start || null,
        actual_end: editForm.actual_end || null,
        status: editForm.status,
        assigned_user_id: editForm.assigned_user_id || null,
        notes: editForm.notes || null,
      })
      setEditingId(null)
      onRefresh()
    } catch (err: any) {
      setError(err.message)
    }
  }

  const handleInitTemplate = async () => {
    setError('')
    try {
      await api.initMilestoneTemplate(jobOrderId)
      onRefresh()
    } catch (err: any) {
      setError(err.message)
    }
  }

  // ---- Gantt chart calculations ----
  const milestonesWithDates = milestones.filter(
    (m) => m.planned_start || m.actual_start
  )

  let ganttMinDate = ''
  let ganttMaxDate = ''
  if (milestonesWithDates.length > 0) {
    const allDates = milestonesWithDates.flatMap((m) =>
      [m.planned_start, m.planned_end, m.actual_start, m.actual_end].filter(Boolean) as string[]
    )
    ganttMinDate = allDates.reduce((a, b) => (a < b ? a : b))
    ganttMaxDate = allDates.reduce((a, b) => (a > b ? a : b))
  }

  const daysBetween = (a: string, b: string) => {
    const d1 = new Date(a)
    const d2 = new Date(b)
    return Math.max(1, Math.round((d2.getTime() - d1.getTime()) / (1000 * 60 * 60 * 24)))
  }

  const totalDays = ganttMinDate && ganttMaxDate ? daysBetween(ganttMinDate, ganttMaxDate) + 1 : 1

  const barPosition = (start: string, end: string) => {
    const left = ((daysBetween(ganttMinDate, start)) / totalDays) * 100
    const width = ((daysBetween(start, end) + 1) / totalDays) * 100
    return { left: `${Math.max(0, left - (1 / totalDays) * 100)}%`, width: `${Math.min(100, width)}%` }
  }

  // Generate month labels for the Gantt header
  const monthLabels: { label: string; left: string; width: string }[] = []
  if (ganttMinDate && ganttMaxDate) {
    const startDate = new Date(ganttMinDate)
    const endDate = new Date(ganttMaxDate)
    let current = new Date(startDate.getFullYear(), startDate.getMonth(), 1)
    while (current <= endDate) {
      const monthStart = current.toISOString().slice(0, 10)
      const nextMonth = new Date(current.getFullYear(), current.getMonth() + 1, 0)
      const monthEnd = nextMonth.toISOString().slice(0, 10)
      const effectiveStart = monthStart < ganttMinDate ? ganttMinDate : monthStart
      const effectiveEnd = monthEnd > ganttMaxDate ? ganttMaxDate : monthEnd
      const pos = barPosition(effectiveStart, effectiveEnd)
      monthLabels.push({
        label: current.toLocaleString('en', { month: 'short', year: 'numeric' }),
        left: pos.left,
        width: pos.width,
      })
      current = new Date(current.getFullYear(), current.getMonth() + 1, 1)
    }
  }

  return (
    <div className="card" style={{ marginBottom: 16 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
        <h2>📅 Project Schedule</h2>
        {editable && milestones.length === 0 && (
          <button className="primary" onClick={handleInitTemplate}>
            Initialize Template
          </button>
        )}
      </div>

      {error && <div className="error-banner">{error}</div>}

      <p className="muted" style={{ fontSize: 12, marginBottom: 8 }}>
        Milestone completion requires Sales Manager or Owner approval (decision 7.3).
      </p>

      {milestones.length === 0 ? (
        <p className="muted">No milestones. Click "Initialize Template" to add the standard project milestones.</p>
      ) : (
        <>
          {/* Milestone table */}
          <div style={{ overflowX: 'auto', marginBottom: 24 }}>
            <table>
              <thead>
                <tr>
                  <th>#</th>
                  <th>Milestone</th>
                  <th>Status</th>
                  <th>Planned Start</th>
                  <th>Planned End</th>
                  <th>Actual Start</th>
                  <th>Actual End</th>
                  <th>Assigned To</th>
                  <th>Notes</th>
                  {editable && <th>Action</th>}
                </tr>
              </thead>
              <tbody>
                {milestones.map((m, idx) => {
                  const isEditing = editingId === m.id
                  return (
                    <tr key={m.id}>
                      <td className="muted">{idx + 1}</td>
                      <td>
                        <strong>{m.label}</strong>
                        <br />
                        <span className="muted" style={{ fontSize: '0.85em' }}>
                          {MILESTONE_TYPE_LABELS[m.milestone_type]}
                        </span>
                      </td>
                      <td>
                        {isEditing ? (
                          <select
                            value={editForm.status}
                            onChange={(e) =>
                              setEditForm({ ...editForm, status: e.target.value as MilestoneStatus })
                            }
                          >
                            <option value="pending">Pending</option>
                            <option value="in_progress">In Progress</option>
                            <option value="completed" disabled={!canApproveCompletion && m.status !== 'completed'}>
                              Completed {!canApproveCompletion && m.status !== 'completed' ? '(Sales Mgr only)' : ''}
                            </option>
                            <option value="skipped">Skipped</option>
                          </select>
                        ) : (
                          <span
                            style={{
                              padding: '2px 8px',
                              borderRadius: 4,
                              fontSize: '0.85em',
                              background: STATUS_COLORS[m.status],
                              color: '#fff',
                            }}
                          >
                            {STATUS_LABELS[m.status]}
                          </span>
                        )}
                      </td>
                      <td>
                        {isEditing ? (
                          <input
                            type="date"
                            value={editForm.planned_start}
                            onChange={(e) => setEditForm({ ...editForm, planned_start: e.target.value })}
                            style={{ width: 130 }}
                          />
                        ) : (
                          m.planned_start ?? <span className="muted">—</span>
                        )}
                      </td>
                      <td>
                        {isEditing ? (
                          <input
                            type="date"
                            value={editForm.planned_end}
                            onChange={(e) => setEditForm({ ...editForm, planned_end: e.target.value })}
                            style={{ width: 130 }}
                          />
                        ) : (
                          m.planned_end ?? <span className="muted">—</span>
                        )}
                      </td>
                      <td>
                        {isEditing ? (
                          <input
                            type="date"
                            value={editForm.actual_start}
                            onChange={(e) => setEditForm({ ...editForm, actual_start: e.target.value })}
                            style={{ width: 130 }}
                          />
                        ) : (
                          m.actual_start ?? <span className="muted">—</span>
                        )}
                      </td>
                      <td>
                        {isEditing ? (
                          <input
                            type="date"
                            value={editForm.actual_end}
                            onChange={(e) => setEditForm({ ...editForm, actual_end: e.target.value })}
                            style={{ width: 130 }}
                          />
                        ) : (
                          m.actual_end ?? <span className="muted">—</span>
                        )}
                      </td>
                      <td>
                        {isEditing ? (
                          <select
                            value={editForm.assigned_user_id}
                            onChange={(e) =>
                              setEditForm({ ...editForm, assigned_user_id: e.target.value })
                            }
                          >
                            <option value="">Unassigned</option>
                            {users.map((u) => (
                              <option key={u.id} value={u.id}>
                                {u.full_name}
                              </option>
                            ))}
                          </select>
                        ) : (
                          userName(m.assigned_user_id)
                        )}
                      </td>
                      <td>
                        {isEditing ? (
                          <input
                            type="text"
                            value={editForm.notes}
                            onChange={(e) => setEditForm({ ...editForm, notes: e.target.value })}
                            placeholder="Notes..."
                            style={{ minWidth: 120 }}
                          />
                        ) : (
                          m.notes ?? <span className="muted">—</span>
                        )}
                      </td>
                      {editable && (
                        <td>
                          {isEditing ? (
                            <div style={{ display: 'flex', gap: 4 }}>
                              <button className="primary" onClick={() => handleSave(m.id)} style={{ fontSize: '0.85em', padding: '2px 8px' }}>
                                Save
                              </button>
                              <button className="secondary" onClick={() => setEditingId(null)} style={{ fontSize: '0.85em', padding: '2px 8px' }}>
                                Cancel
                              </button>
                            </div>
                          ) : (
                            <button className="secondary" onClick={() => startEditing(m)} style={{ fontSize: '0.85em', padding: '2px 8px' }}>
                              Edit
                            </button>
                          )}
                        </td>
                      )}
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>

          {/* Gantt Chart */}
          {milestonesWithDates.length > 0 && (
            <div>
              <h3 style={{ marginBottom: 8 }}>📊 Gantt Chart</h3>
              <div
                style={{
                  border: '1px solid var(--border, #ddd)',
                  borderRadius: 8,
                  overflow: 'hidden',
                }}
              >
                {/* Month headers */}
                <div
                  style={{
                    position: 'relative',
                    height: 28,
                    background: 'var(--surface, #f5f5f5)',
                    borderBottom: '1px solid var(--border, #ddd)',
                  }}
                >
                  {monthLabels.map((ml, i) => (
                    <div
                      key={i}
                      style={{
                        position: 'absolute',
                        left: ml.left,
                        width: ml.width,
                        height: '100%',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        fontSize: '0.75em',
                        fontWeight: 600,
                        color: 'var(--text-secondary, #666)',
                        borderRight: '1px solid var(--border, #eee)',
                        boxSizing: 'border-box',
                        overflow: 'hidden',
                        whiteSpace: 'nowrap',
                      }}
                    >
                      {ml.label}
                    </div>
                  ))}
                </div>

                {/* Gantt rows */}
                {milestones.map((m) => {
                  const hasPlanned = m.planned_start && m.planned_end
                  const hasActual = m.actual_start && (m.actual_end || m.actual_start)
                  if (!hasPlanned && !hasActual) return null

                  return (
                    <div
                      key={m.id}
                      style={{
                        display: 'flex',
                        alignItems: 'center',
                        height: 44,
                        borderBottom: '1px solid var(--border, #eee)',
                      }}
                    >
                      {/* Label */}
                      <div
                        style={{
                          width: 160,
                          minWidth: 160,
                          padding: '0 12px',
                          fontSize: '0.85em',
                          fontWeight: 500,
                          overflow: 'hidden',
                          textOverflow: 'ellipsis',
                          whiteSpace: 'nowrap',
                        }}
                      >
                        {m.label}
                      </div>

                      {/* Bar area */}
                      <div
                        style={{
                          flex: 1,
                          position: 'relative',
                          height: '100%',
                        }}
                      >
                        {/* Today marker */}
                        {(() => {
                          const today = new Date().toISOString().slice(0, 10)
                          if (today >= ganttMinDate && today <= ganttMaxDate) {
                            const pos = ((daysBetween(ganttMinDate, today)) / totalDays) * 100
                            return (
                              <div
                                style={{
                                  position: 'absolute',
                                  left: `${pos - (1 / totalDays) * 100}%`,
                                  top: 0,
                                  bottom: 0,
                                  width: 2,
                                  background: '#e74c3c',
                                  zIndex: 2,
                                  opacity: 0.5,
                                }}
                              />
                            )
                          }
                          return null
                        })()}

                        {/* Planned bar */}
                        {hasPlanned && (() => {
                          const pos = barPosition(m.planned_start!, m.planned_end!)
                          return (
                            <div
                              style={{
                                position: 'absolute',
                                top: 6,
                                height: 14,
                                borderRadius: 3,
                                background: '#bdc3c7',
                                opacity: 0.6,
                                ...pos,
                              }}
                              title={`Planned: ${m.planned_start} → ${m.planned_end}`}
                            />
                          )
                        })()}

                        {/* Actual bar */}
                        {hasActual && (() => {
                          const actualEnd = m.actual_end || m.actual_start!
                          const pos = barPosition(m.actual_start!, actualEnd)
                          return (
                            <div
                              style={{
                                position: 'absolute',
                                top: 22,
                                height: 14,
                                borderRadius: 3,
                                background: STATUS_COLORS[m.status],
                                ...pos,
                              }}
                              title={`Actual: ${m.actual_start} → ${m.actual_end ?? 'ongoing'}`}
                            />
                          )
                        })()}
                      </div>
                    </div>
                  )
                })}

                {/* Legend */}
                <div
                  style={{
                    display: 'flex',
                    gap: 16,
                    padding: '8px 12px',
                    fontSize: '0.75em',
                    background: 'var(--surface, #f9f9f9)',
                    borderTop: '1px solid var(--border, #eee)',
                    flexWrap: 'wrap',
                  }}
                >
                  <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}>
                    <span style={{ display: 'inline-block', width: 16, height: 10, borderRadius: 2, background: '#bdc3c7', opacity: 0.6 }} />
                    Planned
                  </span>
                  <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}>
                    <span style={{ display: 'inline-block', width: 16, height: 10, borderRadius: 2, background: '#3498db' }} />
                    In Progress
                  </span>
                  <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}>
                    <span style={{ display: 'inline-block', width: 16, height: 10, borderRadius: 2, background: '#27ae60' }} />
                    Completed
                  </span>
                  <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}>
                    <span style={{ display: 'inline-block', width: 2, height: 14, background: '#e74c3c', opacity: 0.5 }} />
                    Today
                  </span>
                </div>
              </div>
            </div>
          )}
        </>
      )}
    </div>
  )
}
