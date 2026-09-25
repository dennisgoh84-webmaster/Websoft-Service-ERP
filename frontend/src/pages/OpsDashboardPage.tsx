// Personal Ops Dashboard (confirmed 2026-09-11): a freeform task
// tracker per staff member, modeled on a sample screenshot, shown
// alongside a read-only rollup of that staff member's real assigned
// work already in the system (Job Orders, Software Tasks). See
// docs/open-business-decisions.md for the confirmed scope and
// app/models/ops_tasks.py for why several columns are free text rather
// than foreign keys.
import { useEffect, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { api, downloadBlob, type CurrentUser, type OpsDashboard, type OpsTaskStatus } from '../lib/api'
import { useAuth } from '../lib/AuthContext'
import DateInput from '../components/DateInput'
import { formatDate } from '../lib/format'

const STATUS_LABELS: Record<OpsTaskStatus, string> = {
  not_started: 'Not started',
  in_progress: 'In progress',
  watch: 'Watch',
  blocked: 'Blocked',
  done: 'Done',
}

// Matches the colour sample confirmed 2026-09-11 (amber / blue /
// purple / red / green) -- see the .badge.status-* rules in index.css.
const STATUS_BADGE: Record<OpsTaskStatus, string> = {
  not_started: 'status-not-started',
  in_progress: 'status-in-progress',
  watch: 'status-watch',
  blocked: 'status-blocked',
  done: 'active',
}

// Same palette, applied directly to the status <select> in each task
// row so it reads as a colour-coded pill (per the sample) rather than
// a plain dropdown.
const STATUS_SELECT_STYLE: Record<OpsTaskStatus, { background: string; color: string; borderColor: string }> = {
  not_started: { background: 'var(--warn-bg)', color: 'var(--warn-text)', borderColor: 'var(--warn-border)' },
  in_progress: { background: 'var(--info-bg)', color: 'var(--info-text)', borderColor: 'var(--info-border)' },
  watch: { background: 'var(--watch-bg)', color: 'var(--watch-text)', borderColor: 'var(--watch-border)' },
  blocked: { background: 'var(--danger-bg)', color: 'var(--danger)', borderColor: 'var(--danger-border)' },
  done: { background: 'var(--ok-bg)', color: 'var(--ok-text)', borderColor: 'var(--ok-border)' },
}

const STATUS_LEGEND: { status: OpsTaskStatus; text: string }[] = [
  { status: 'not_started', text: 'Queued -- not kicked off yet.' },
  { status: 'in_progress', text: 'Actively being worked now.' },
  { status: 'watch', text: 'On the radar but waiting -- external dependency or a staff reply. Review regularly.' },
  { status: 'blocked', text: 'Cannot move until someone or something unblocks it.' },
  { status: 'done', text: 'Finished -- kept for history.' },
]

export default function OpsDashboardPage() {
  const { user } = useAuth()
  const [dashboard, setDashboard] = useState<OpsDashboard | null>(null)
  const [staffList, setStaffList] = useState<CurrentUser[]>([])
  const [viewingStaffId, setViewingStaffId] = useState('')
  const [error, setError] = useState<string | null>(null)

  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState<OpsTaskStatus | ''>('')
  const [collapsed, setCollapsed] = useState<Record<string, boolean>>({})

  const [newCategoryName, setNewCategoryName] = useState('')
  const [newCategoryCadence, setNewCategoryCadence] = useState('')
  const [newTaskTitle, setNewTaskTitle] = useState<Record<string, string>>({})

  function refresh(staffId?: string) {
    api
      .getOpsDashboard(staffId || undefined)
      .then(setDashboard)
      .catch((e) => setError(e.message))
  }

  useEffect(refresh, [])
  useEffect(() => {
    api.listUsers().then(setStaffList).catch(() => setStaffList([]))
  }, [])

  function onSwitchStaff(id: string) {
    setViewingStaffId(id)
    refresh(id)
  }

  async function onAddCategory(e: FormEvent) {
    e.preventDefault()
    if (!newCategoryName.trim()) return
    setError(null)
    try {
      await api.createOpsTaskCategory({
        name: newCategoryName,
        cadence_label: newCategoryCadence || undefined,
        owner_user_id: viewingStaffId || undefined,
      })
      setNewCategoryName('')
      setNewCategoryCadence('')
      refresh(viewingStaffId)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to add category')
    }
  }

  async function onAddTask(categoryId: string, e: FormEvent) {
    e.preventDefault()
    const title = newTaskTitle[categoryId]?.trim()
    if (!title) return
    setError(null)
    try {
      await api.createOpsTask({ category_id: categoryId, title })
      setNewTaskTitle((prev) => ({ ...prev, [categoryId]: '' }))
      refresh(viewingStaffId)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to add task')
    }
  }

  async function patchTask(id: string, payload: Parameters<typeof api.updateOpsTask>[1]) {
    setError(null)
    try {
      await api.updateOpsTask(id, payload)
      refresh(viewingStaffId)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to update task')
    }
  }

  async function onArchive(id: string) {
    setError(null)
    try {
      await api.archiveOpsTask(id)
      refresh(viewingStaffId)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to remove task')
    }
  }

  function onExportJson() {
    if (!dashboard) return
    const blob = new Blob([JSON.stringify(dashboard, null, 2)], { type: 'application/json' })
    downloadBlob(blob, `ops-dashboard-${dashboard.staff_name.replace(/\s+/g, '-').toLowerCase()}.json`)
  }

  function toggleCollapsed(id: string) {
    setCollapsed((prev) => ({ ...prev, [id]: !prev[id] }))
  }

  function expandAll() {
    setCollapsed({})
  }

  function collapseAllCategories() {
    if (!dashboard) return
    const next: Record<string, boolean> = {}
    for (const c of dashboard.categories) next[c.category.id] = true
    setCollapsed(next)
  }

  if (!dashboard) return <p>Loading...</p>

  const q = search.trim().toLowerCase()
  const visibleCategories = dashboard.categories
    .map((c) => ({
      ...c,
      tasks: c.tasks.filter((t) => {
        if (statusFilter && t.status !== statusFilter) return false
        if (q && !t.title.toLowerCase().includes(q) && !(t.next_action ?? '').toLowerCase().includes(q)) return false
        return true
      }),
    }))
    .filter((c) => !q && !statusFilter ? true : c.tasks.length > 0)

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: 12 }}>
        <div>
          <h1 style={{ margin: 0 }}>{dashboard.staff_name} Ops Dashboard</h1>
          <p className="muted">Pending work by category -- freeform tasks plus your real assigned work below.</p>
        </div>
        {dashboard.can_view_others && (
          <div className="form-row" style={{ margin: 0 }}>
            <label>Viewing</label>
            <select value={viewingStaffId} onChange={(e) => onSwitchStaff(e.target.value)}>
              <option value="">Myself ({user?.full_name})</option>
              {staffList.filter((s) => s.id !== user?.id).map((s) => (
                <option key={s.id} value={s.id}>
                  {s.full_name}
                </option>
              ))}
            </select>
          </div>
        )}
      </div>
      {error && <div className="error-banner">{error}</div>}

      <div className="stat-grid">
        <div className="card stat-tile">
          <div className="stat-value stat-value-total">{dashboard.total_tasks}</div>
          <div className="stat-label stat-label-total">Total tasks</div>
        </div>
        <div className="card stat-tile">
          <div className="stat-value stat-value-not-started">{dashboard.open_count}</div>
          <div className="stat-label stat-label-not-started">Not started</div>
        </div>
        <div className="card stat-tile">
          <div className="stat-value stat-value-in-progress">{dashboard.in_progress_count}</div>
          <div className="stat-label stat-label-in-progress">In progress / Watch</div>
        </div>
        <div className="card stat-tile">
          <div className="stat-value stat-value-blocked">{dashboard.blocked_count}</div>
          <div className="stat-label stat-label-blocked">Blocked</div>
        </div>
        <div className="card stat-tile">
          <div className="stat-value stat-value-done">{dashboard.done_count}</div>
          <div className="stat-label stat-label-done">Done</div>
        </div>
      </div>

      <div className="card">
        <h2>Status meanings</h2>
        <table>
          <tbody>
            {STATUS_LEGEND.map((row) => (
              <tr key={row.status}>
                <td style={{ width: 140 }}>
                  <span className={`badge ${STATUS_BADGE[row.status]}`}>{STATUS_LABELS[row.status]}</span>
                </td>
                <td className="muted">{row.text}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="card">
        <div className="filter-bar">
          <div className="form-row" style={{ margin: 0 }}>
            <label>Filter tasks</label>
            <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Title or next action..." />
          </div>
          <div className="form-row" style={{ margin: 0 }}>
            <label>Status</label>
            <select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value as OpsTaskStatus | '')}>
              <option value="">All statuses</option>
              {(Object.keys(STATUS_LABELS) as OpsTaskStatus[]).map((s) => (
                <option key={s} value={s}>
                  {STATUS_LABELS[s]}
                </option>
              ))}
            </select>
          </div>
          <button type="button" className="secondary" onClick={expandAll}>
            Expand all
          </button>
          <button type="button" className="secondary" onClick={collapseAllCategories}>
            Collapse all
          </button>
          <button type="button" className="secondary" onClick={onExportJson}>
            Export JSON
          </button>
          <Link to="/staff" className="secondary" style={{ padding: '6px 10px' }}>
            Edit staff list
          </Link>
        </div>
      </div>

      {visibleCategories.map(({ category, tasks }) => (
        <div className="card" key={category.id}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <div>
              <h2 style={{ marginBottom: 2 }}>
                <button
                  type="button"
                  onClick={() => toggleCollapsed(category.id)}
                  style={{ background: 'none', border: 'none', padding: 0, font: 'inherit', color: 'inherit', cursor: 'pointer' }}
                >
                  {collapsed[category.id] ? '▸' : '▾'} {category.name}
                </button>
              </h2>
              {category.cadence_label && <p className="muted" style={{ margin: 0 }}>{category.cadence_label}</p>}
            </div>
            <span className="muted">{tasks.length} task{tasks.length === 1 ? '' : 's'}</span>
          </div>

          {!collapsed[category.id] && (
            <>
              <table style={{ marginTop: 10 }}>
                <thead>
                  <tr>
                    <th>Task</th>
                    <th>Status</th>
                    <th>Next action</th>
                    <th>Owner</th>
                    <th>Due</th>
                    <th>Follow-up staff</th>
                    <th>Follow-up date</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  {tasks.map((t) => (
                    <tr key={t.id}>
                      <td>
                        {t.title}
                        {t.is_sample && <span className="muted"> (sample)</span>}
                      </td>
                      <td>
                        <select
                          value={t.status}
                          onChange={(e) => patchTask(t.id, { status: e.target.value as OpsTaskStatus })}
                          style={{ ...STATUS_SELECT_STYLE[t.status], fontWeight: 600, borderRadius: 999 }}
                        >
                          {(Object.keys(STATUS_LABELS) as OpsTaskStatus[]).map((s) => (
                            <option key={s} value={s}>
                              {STATUS_LABELS[s]}
                            </option>
                          ))}
                        </select>
                      </td>
                      <td>
                        <input
                          defaultValue={t.next_action ?? ''}
                          style={{ minWidth: 160 }}
                          onBlur={(e) => {
                            if (e.target.value !== (t.next_action ?? '')) patchTask(t.id, { next_action: e.target.value })
                          }}
                        />
                      </td>
                      <td>
                        <input
                          defaultValue={t.owner_label ?? ''}
                          style={{ width: 110 }}
                          onBlur={(e) => {
                            if (e.target.value !== (t.owner_label ?? '')) patchTask(t.id, { owner_label: e.target.value })
                          }}
                        />
                      </td>
                      <td>
                        <input
                          defaultValue={t.due_label ?? ''}
                          style={{ width: 100 }}
                          placeholder="e.g. Month-end"
                          onBlur={(e) => {
                            if (e.target.value !== (t.due_label ?? '')) patchTask(t.id, { due_label: e.target.value })
                          }}
                        />
                      </td>
                      <td>
                        <select
                          value={t.follow_up_staff_id ?? ''}
                          onChange={(e) =>
                            e.target.value
                              ? patchTask(t.id, { follow_up_staff_id: e.target.value })
                              : patchTask(t.id, { clear_follow_up_staff: true })
                          }
                        >
                          <option value="">-- select staff --</option>
                          {staffList.map((s) => (
                            <option key={s.id} value={s.id}>
                              {s.full_name}
                            </option>
                          ))}
                        </select>
                      </td>
                      <td>
                        <DateInput
                          value={t.follow_up_date ?? ''}
                          onChange={(e) =>
                            e.target.value
                              ? patchTask(t.id, { follow_up_date: e.target.value })
                              : patchTask(t.id, { clear_follow_up_date: true })
                          }
                        />
                      </td>
                      <td>
                        <button className="secondary" onClick={() => onArchive(t.id)}>
                          Remove
                        </button>
                      </td>
                    </tr>
                  ))}
                  {tasks.length === 0 && (
                    <tr>
                      <td colSpan={8} className="muted">
                        No tasks match the current filters.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
              <form onSubmit={(e) => onAddTask(category.id, e)} style={{ display: 'flex', gap: 10, marginTop: 10 }}>
                <input
                  value={newTaskTitle[category.id] ?? ''}
                  onChange={(e) => setNewTaskTitle((prev) => ({ ...prev, [category.id]: e.target.value }))}
                  placeholder="New task title..."
                  style={{ flex: 1 }}
                />
                <button type="submit">Add task</button>
              </form>
            </>
          )}
        </div>
      ))}

      <div className="card">
        <h2>Add a category</h2>
        <form onSubmit={onAddCategory} style={{ display: 'flex', gap: 10, alignItems: 'flex-end', flexWrap: 'wrap' }}>
          <div className="form-row" style={{ margin: 0 }}>
            <label>Name</label>
            <input value={newCategoryName} onChange={(e) => setNewCategoryName(e.target.value)} required placeholder="e.g. Comms & calendar" />
          </div>
          <div className="form-row" style={{ margin: 0 }}>
            <label>Cadence (optional)</label>
            <input value={newCategoryCadence} onChange={(e) => setNewCategoryCadence(e.target.value)} placeholder="e.g. Weekly / month-end" />
          </div>
          <button type="submit">Add category</button>
        </form>
      </div>

      {dashboard.my_job_orders.length > 0 && (
        <div className="card">
          <h2>My open Job Orders</h2>
          <p className="muted">Read-only -- pulled from the actual assigned Job Orders in the system.</p>
          <table>
            <thead>
              <tr>
                <th>Number</th>
                <th>Subject</th>
                <th>Status</th>
                <th>Due</th>
              </tr>
            </thead>
            <tbody>
              {dashboard.my_job_orders.map((jo) => (
                <tr key={jo.id}>
                  <td className="muted">{jo.job_order_number}</td>
                  <td>
                    <Link to={`/job-orders/${jo.id}`}>{jo.subject}</Link>
                  </td>
                  <td>{jo.status}</td>
                  <td>{jo.due_date ? formatDate(jo.due_date) : <span className="muted">-</span>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {dashboard.my_software_tasks.length > 0 && (
        <div className="card">
          <h2>My Software Tasks</h2>
          <p className="muted">Read-only -- pulled from Software Tasks assigned to you as programmer or tester.</p>
          <table>
            <thead>
              <tr>
                <th>Title</th>
                <th>Role</th>
              </tr>
            </thead>
            <tbody>
              {dashboard.my_software_tasks.map((t) => (
                <tr key={`${t.id}-${t.role}`}>
                  <td>
                    <Link to="/software-tasks">{t.title}</Link>
                  </td>
                  <td>{t.role}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
