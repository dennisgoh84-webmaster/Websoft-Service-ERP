import { useEffect, useState, type FormEvent } from 'react'
import ExportControl from '../components/ExportControl'
import { api, downloadBlob, type CurrentUser, type SoftwareTask } from '../lib/api'
import DateInput from '../components/DateInput'
import { formatDate } from '../lib/format'

export default function SoftwareTasksPage() {
  const [tasks, setTasks] = useState<SoftwareTask[]>([])
  const [users, setUsers] = useState<CurrentUser[]>([])
  const [error, setError] = useState<string | null>(null)
  const [untestedOnly, setUntestedOnly] = useState(false)

  const [title, setTitle] = useState('')
  const [description, setDescription] = useState('')
  const [modulesAffected, setModulesAffected] = useState('')
  const [programmerId, setProgrammerId] = useState('')
  const [finishDate, setFinishDate] = useState('')
  const [hours, setHours] = useState('')
  const [testerId, setTesterId] = useState('')

  function refresh() {
    api.listSoftwareTasks({ untested_only: untestedOnly }).then(setTasks).catch((e) => setError(e.message))
    api.listUsers().then(setUsers).catch((e) => setError(e.message))
  }

  useEffect(refresh, [untestedOnly])

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

  async function onToggleTested(task: SoftwareTask) {
    setError(null)
    try {
      if (task.is_tested) await api.reopenSoftwareTaskTesting(task.id)
      else await api.markSoftwareTaskTested(task.id)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to update task')
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

      <div className="card">
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <h2>Tasks ({tasks.length})</h2>
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
                    <span className={`badge ${t.is_tested ? 'active' : 'draft'}`}>
                      {t.is_tested ? 'Tested' : 'Un-tested'}
                    </span>
                  </td>
                  <td>
                    {t.tester_user_id && (
                      <button className="secondary" onClick={() => onToggleTested(t)}>
                        {t.is_tested ? 'Reopen testing' : 'Mark tested'}
                      </button>
                    )}
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
