import { useEffect, useState, type FormEvent } from 'react'
import ExportControl from '../components/ExportControl'
import { api, downloadBlob, type CurrentUser, type ProgrammerCard, type SoftwareTask, type SoftwareTaskStatus } from '../lib/api'
import DateInput from '../components/DateInput'
import { formatDate } from '../lib/format'

// Decision 12.1 (Dennis, 2026-09-26): Open -> Programming -> For Testing -> Tested -> Released.
const STATUS_LABEL: Record<SoftwareTaskStatus, string> = {
  open: 'Open',
  programming: 'Programming',
  for_testing: 'For Testing',
  tested: 'Tested',
  released: 'Released',
}
const STATUS_BADGE: Record<SoftwareTaskStatus, string> = {
  open: 'status-not-started',
  programming: 'status-in-progress',
  for_testing: 'status-watch',
  tested: 'active',
  released: 'renewed',
}
// The button that moves a task to each status.
const MOVE_LABEL: Record<SoftwareTaskStatus, string> = {
  open: 'Back to Open',
  programming: 'Start programming',
  for_testing: 'Send for testing',
  tested: 'Mark tested',
  released: 'Release',
}

export default function SoftwareTasksPage() {
  const [tasks, setTasks] = useState<SoftwareTask[]>([])
  const [users, setUsers] = useState<CurrentUser[]>([])
  const [error, setError] = useState<string | null>(null)
  const [untestedOnly, setUntestedOnly] = useState(false)
  const [statusFilter, setStatusFilter] = useState<SoftwareTaskStatus | ''>('')
  const [programmers, setProgrammers] = useState<ProgrammerCard[]>([])
  const [busy, setBusy] = useState<string | null>(null)

  const [title, setTitle] = useState('')
  const [description, setDescription] = useState('')
  const [modulesAffected, setModulesAffected] = useState('')
  const [programmerId, setProgrammerId] = useState('')
  const [finishDate, setFinishDate] = useState('')
  const [hours, setHours] = useState('')
  const [testerId, setTesterId] = useState('')

  function refresh() {
    api
      .listSoftwareTasks({ untested_only: untestedOnly, status: statusFilter || undefined })
      .then(setTasks)
      .catch((e) => setError(e.message))
    api.listUsers().then(setUsers).catch((e) => setError(e.message))
    api.softwareTaskProgrammers().then(setProgrammers).catch(() => setProgrammers([]))
  }

  useEffect(refresh, [untestedOnly, statusFilter])

  const userName = (id: string | null) => (id ? users.find((u) => u.id === id)?.full_name ?? id.slice(0, 8) : null)

  async function onCreate(e: FormEvent) {
    e.preventDefault()
    setError(null)
    try {
      await api.createSoftwareTask({
        title,
        description: description || undefined,
        modules_affected: modulesAffected || undefined,
        assigned_programmer_id: programmerId || undefined,
        programming_finish_date: finishDate || undefined,
        programming_hours: hours === '' ? undefined : parseFloat(hours),
        tester_user_id: testerId || undefined,
      })
      setTitle('')
      setDescription('')
      setModulesAffected('')
      setProgrammerId('')
      setFinishDate('')
      setHours('')
      setTesterId('')
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to add task')
    }
  }

  async function onExport(format: string) {
    setError(null)
    const filters = { untested_only: untestedOnly }
    if (format === 'csv') {
      downloadBlob(await api.exportSoftwareTasksCsv(filters), 'software-tasks.csv')
    } else {
      downloadBlob(await api.exportSoftwareTasksExcel(filters), 'software-tasks.xlsx')
    }
  }

  async function onMove(task: SoftwareTask, to: SoftwareTaskStatus) {
    setError(null)
    setBusy(task.id)
    try {
      await api.moveSoftwareTask(task.id, to)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to update task')
    } finally {
      setBusy(null)
    }
  }

  return (
    <div>
      <h1>Software Tasks</h1>
      <p className="muted">
        A first slice for Software Development: enter a task, assign it to a programmer, note which
        modules/reports it affects, a target finish date, programming hours (keyed in manually), and
        who it's assigned to for testing. Feeds "Un-Tested Software Tasks" on Support Monitoring.
      </p>
      {error && <div className="error-banner">{error}</div>}

      <div className="card">
        <h2>New task</h2>
        <form onSubmit={onCreate}>
          <div className="form-row">
            <label>Title</label>
            <input value={title} onChange={(e) => setTitle(e.target.value)} required />
          </div>
          <div className="form-row">
            <label>Description</label>
            <input value={description} onChange={(e) => setDescription(e.target.value)} />
          </div>
          <div className="form-row">
            <label>Module(s) / report(s) affected</label>
            <input value={modulesAffected} onChange={(e) => setModulesAffected(e.target.value)} />
          </div>
          <div className="form-row">
            <label>Assigned programmer</label>
            <select value={programmerId} onChange={(e) => setProgrammerId(e.target.value)}>
              <option value="">Unassigned</option>
              {users.map((u) => (
                <option key={u.id} value={u.id}>
                  {u.full_name}
                </option>
              ))}
            </select>
          </div>
          <div className="form-row">
            <label>Target finish date (programming)</label>
            <DateInput value={finishDate} onChange={(e) => setFinishDate(e.target.value)} />
          </div>
          <div className="form-row">
            <label>Programming hours (manual)</label>
            <input type="number" min="0" step="0.5" value={hours} onChange={(e) => setHours(e.target.value)} />
          </div>
          <div className="form-row">
            <label>Assigned tester</label>
            <select value={testerId} onChange={(e) => setTesterId(e.target.value)}>
              <option value="">Unassigned</option>
              {users.map((u) => (
                <option key={u.id} value={u.id}>
                  {u.full_name}
                </option>
              ))}
            </select>
          </div>
          <button type="submit" disabled={!title}>
            Add task
          </button>
        </form>
      </div>

      {programmers.length > 0 && (
        <div className="card">
          <h2 style={{ marginTop: 0 }}>By programmer</h2>
          <p className="muted" style={{ marginTop: 0 }}>Tasks not yet tested: open, past their finish date, and waiting for a tester.</p>
          <div className="salesperson-grid">
            {programmers.map((p) => (
              <div key={p.programmer_id ?? 'none'} className="salesperson-card" data-testid="programmer-card">
                <strong>{p.name}</strong>
                <dl className="salesperson-figures">
                  <dt>Open</dt>
                  <dd>{p.open}</dd>
                  <dt>Overdue</dt>
                  <dd>{p.overdue > 0 ? <span className="badge exceeded">{p.overdue}</span> : 0}</dd>
                  <dt>Awaiting test</dt>
                  <dd>{p.awaiting_test}</dd>
                  <dt>Hours</dt>
                  <dd>{p.hours}</dd>
                </dl>
              </div>
            ))}
          </div>
        </div>
      )}

      <div className="card">
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <h2>Tasks ({tasks.length})</h2>
          <select aria-label="Status" value={statusFilter} onChange={(e) => setStatusFilter(e.target.value as SoftwareTaskStatus | '')}>
            <option value="">All statuses</option>
            {(Object.keys(STATUS_LABEL) as SoftwareTaskStatus[]).map((st) => (
              <option key={st} value={st}>
                {STATUS_LABEL[st]}
              </option>
            ))}
          </select>
          <label style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 13 }}>
            <input type="checkbox" checked={untestedOnly} onChange={(e) => setUntestedOnly(e.target.checked)} />
            Un-tested only
          </label>
          <ExportControl
            formats={[
              { value: 'csv', label: 'CSV' },
              { value: 'excel', label: 'Excel' },
            ]}
            onExport={onExport}
            onError={setError}
          />
        </div>
        <div style={{ overflowX: 'auto' }}>
          <table>
            <thead>
              <tr>
                <th>Title</th>
                <th>Modules/reports</th>
                <th>Programmer</th>
                <th>Finish date</th>
                <th>Prog. hrs</th>
                <th>Tester</th>
                <th>Status</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {tasks.map((t) => (
                <tr key={t.id}>
                  <td>
                    {t.title}
                    {t.description && <div className="muted">{t.description}</div>}
                  </td>
                  <td className="muted">{t.modules_affected ?? '-'}</td>
                  <td>{userName(t.assigned_programmer_id) ?? <span className="muted">-</span>}</td>
                  <td>{t.programming_finish_date ? formatDate(t.programming_finish_date) : <span className="muted">-</span>}</td>
                  <td>{t.programming_hours ?? <span className="muted">-</span>}</td>
                  <td>{userName(t.tester_user_id) ?? <span className="muted">-</span>}</td>
                  <td>
                    <span className={`badge ${STATUS_BADGE[t.status]}`}>{STATUS_LABEL[t.status]}</span>
                    {t.released_at && <div className="muted" style={{ fontSize: '0.8em' }}>{formatDate(t.released_at)}</div>}
                  </td>
                  <td>
                    <div style={{ display: 'flex', gap: 4, flexWrap: 'wrap' }}>
                      {t.next_statuses.map((to) => (
                        <button
                          key={to}
                          className={to === 'open' || (t.status === 'for_testing' && to === 'programming') || (t.status === 'tested' && to === 'for_testing') ? 'secondary' : ''}
                          disabled={busy === t.id}
                          onClick={() => onMove(t, to)}
                        >
                          {t.status === 'for_testing' && to === 'programming'
                            ? 'Failed test'
                            : t.status === 'tested' && to === 'for_testing'
                              ? 'Reopen testing'
                              : MOVE_LABEL[to]}
                        </button>
                      ))}
                    </div>
                  </td>
                </tr>
              ))}
              {tasks.length === 0 && (
                <tr>
                  <td colSpan={8} className="muted">
                    No software tasks yet.
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
