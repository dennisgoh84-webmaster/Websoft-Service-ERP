import { useEffect, useState } from 'react'
import ExportControl from '../components/ExportControl'
import { api, downloadBlob, type AuditLogEntry, type EventLogFilters, type StaffUser } from '../lib/api'
import { isoToMonth, monthEndISO, monthStartISO } from '../lib/period'
import { formatDateTime } from '../lib/format'

const ENTITY_TYPES = [
  'user',
  'group',
  'company_module',
  'contract',
  'service_record',
  'excess_usage_record',
  'invoice',
  'report',
]

function formatChanges(oldJson: string | null, newJson: string | null): string {
  if (!oldJson && !newJson) return ''
  try {
    const oldObj = oldJson ? JSON.parse(oldJson) : {}
    const newObj = newJson ? JSON.parse(newJson) : {}
    const keys = new Set([...Object.keys(oldObj), ...Object.keys(newObj)])
    return [...keys]
      .map((k) => `${k}: ${oldObj[k] ?? '—'} → ${newObj[k] ?? '—'}`)
      .join('; ')
  } catch {
    return [oldJson, newJson].filter(Boolean).join(' → ')
  }
}

export default function EventLogsPage() {
  const [entries, setEntries] = useState<AuditLogEntry[]>([])
  const [staff, setStaff] = useState<StaffUser[]>([])
  const [error, setError] = useState<string | null>(null)

  const [entityType, setEntityType] = useState('')
  const [action, setAction] = useState('')
  const [actorUserId, setActorUserId] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [q, setQ] = useState('')

  function currentFilters(): EventLogFilters {
    return {
      entity_type: entityType || undefined,
      action: action || undefined,
      actor_user_id: actorUserId || undefined,
      date_from: dateFrom || undefined,
      date_to: dateTo || undefined,
      q: q || undefined,
    }
  }

  function refresh() {
    api
      .listEventLogs({ ...currentFilters(), limit: 200 })
      .then(setEntries)
      .catch((e) => setError(e.message))
  }

  useEffect(refresh, [entityType, action, actorUserId, dateFrom, dateTo, q])
  useEffect(() => {
    api.listStaff(true).then(setStaff).catch((e) => setError(e.message))
  }, [])

  function resetFilters() {
    setEntityType('')
    setAction('')
    setActorUserId('')
    setDateFrom('')
    setDateTo('')
    setQ('')
  }

  async function onExport(format: string) {
    setError(null)
    if (format === 'csv') {
      downloadBlob(await api.exportEventLogsCsv(currentFilters()), 'event-log-export.csv')
    } else {
      downloadBlob(await api.exportEventLogsExcel(currentFilters()), 'event-log-export.xlsx')
    }
    refresh() // the export itself is now a new "report_generated" entry
  }

  return (
    <div>
      <h1>Event Logs</h1>
      <p className="muted">
        The system-wide audit trail: who created, edited (to what value), or deleted a record, and
        who generated which report -- with the acting staff member, IP address, and device.
        Access to this module is itself controlled by Group Authority (default: Owner / Admin only).
      </p>
      {error && <div className="error-banner">{error}</div>}

      <div className="card">
        <div className="filter-bar">
          <div className="form-row" style={{ margin: 0 }}>
            <label>Entity</label>
            <select value={entityType} onChange={(e) => setEntityType(e.target.value)}>
              <option value="">All</option>
              {ENTITY_TYPES.map((t) => (
                <option key={t} value={t}>
                  {t}
                </option>
              ))}
            </select>
          </div>
          <div className="form-row" style={{ margin: 0 }}>
            <label>Action</label>
            <input
              value={action}
              onChange={(e) => setAction(e.target.value)}
              placeholder="e.g. updated, deleted"
              style={{ width: 160 }}
            />
          </div>
          <div className="form-row" style={{ margin: 0 }}>
            <label>Staff</label>
            <select value={actorUserId} onChange={(e) => setActorUserId(e.target.value)}>
              <option value="">All</option>
              {staff.map((u) => (
                <option key={u.id} value={u.id}>
                  {u.full_name}
                </option>
              ))}
            </select>
          </div>
          <div className="form-row" style={{ margin: 0 }}>
            <label>Period from</label>
            <input
              type="month"
              value={isoToMonth(dateFrom)}
              onChange={(e) => setDateFrom(monthStartISO(e.target.value))}
            />
          </div>
          <div className="form-row" style={{ margin: 0 }}>
            <label>Period to</label>
            <input
              type="month"
              value={isoToMonth(dateTo)}
              onChange={(e) => setDateTo(monthEndISO(e.target.value))}
            />
          </div>
          <div className="form-row" style={{ margin: 0 }}>
            <label>Search</label>
            <input
              value={q}
              onChange={(e) => setQ(e.target.value)}
              placeholder="details, reason..."
              style={{ width: 180 }}
            />
          </div>
          <button type="button" className="secondary" onClick={resetFilters}>
            Reset filters
          </button>
          <ExportControl
            formats={[
              { value: 'csv', label: 'CSV' },
              { value: 'excel', label: 'Excel' },
            ]}
            onExport={onExport}
            onError={setError}
          />
        </div>

        <h2>Events ({entries.length})</h2>
        <div style={{ overflowX: 'auto' }}>
          <table>
            <thead>
              <tr>
                <th>When</th>
                <th>Staff</th>
                <th>Action</th>
                <th>Entity</th>
                <th>Changes</th>
                <th>Details / Reason</th>
                <th>IP address</th>
                <th>Device</th>
              </tr>
            </thead>
            <tbody>
              {entries.map((e) => (
                <tr key={e.id}>
                  <td style={{ whiteSpace: 'nowrap' }}>{formatDateTime(e.at)}</td>
                  <td>{e.actor_name ?? <span className="muted">System</span>}</td>
                  <td>{e.action}</td>
                  <td>
                    {e.entity_type}
                    <div className="muted">{e.entity_id.slice(0, 8)}</div>
                  </td>
                  <td>{formatChanges(e.old_value, e.new_value) || <span className="muted">-</span>}</td>
                  <td className="muted">{e.details ?? e.reason ?? '-'}</td>
                  <td className="muted">{e.ip_address ?? '-'}</td>
                  <td className="muted" title={e.user_agent ?? undefined}>
                    {e.device_id ? e.device_id.slice(0, 12) : '-'}
                  </td>
                </tr>
              ))}
              {entries.length === 0 && (
                <tr>
                  <td colSpan={8} className="muted">
                    No events match these filters.
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
