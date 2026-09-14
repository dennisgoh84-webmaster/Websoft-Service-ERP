import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import StaffAvatar from '../components/StaffAvatar'
import { api, type StaffMonitoring, type SupportMonitoring } from '../lib/api'
import { formatDate } from '../lib/format'

function StaffCard({ row }: { row: StaffMonitoring }) {
  const overloaded = row.overdue_job_orders > 0
  return (
    <div className="card monitor-card" style={{ borderColor: overloaded ? '#b33' : undefined }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 8 }}>
        <StaffAvatar photo={row.photo} fullName={row.full_name} size={56} />
        <span style={{ minWidth: 0 }}>
          <strong className="monitor-name" style={{ display: 'block' }}>
            {row.full_name}
          </strong>
          {overloaded && <span className="badge exceeded">overloaded</span>}
        </span>
      </div>
      <div className="monitor-row">
        <span className="muted">Open Job Orders</span>
        <span className="monitor-value">{row.open_job_orders}</span>
      </div>
      <div className="monitor-row">
        <span className="muted">Overdue</span>
        <span className="monitor-value" style={{ color: row.overdue_job_orders > 0 ? '#b33' : undefined }}>
          {row.overdue_job_orders}
        </span>
      </div>
      <div className="monitor-row">
        <span className="muted">Due soon</span>
        <span className="monitor-value">{row.due_soon_job_orders}</span>
      </div>
      <div className="monitor-row">
        <span className="muted">Pending Svc. Records</span>
        <span className="monitor-value">{row.pending_service_records}</span>
      </div>
      <div className="monitor-row">
        <span className="muted">Un-Tested S/T</span>
        <span className="monitor-value">{row.untested_software_tasks}</span>
      </div>
      <div className="monitor-row">
        <span className="muted">CM Svc. Rcc. (mth/today)</span>
        <span className="monitor-value">
          {row.cm_svc_records_month}/{row.cm_svc_records_today}
        </span>
      </div>
      <div className="monitor-row">
        <span className="muted">CM Svc. Hrs (mth/today)</span>
        <span className="monitor-value">
          {row.cm_svc_hours_month.toFixed(1)}/{row.cm_svc_hours_today.toFixed(1)}
        </span>
      </div>
      <div className="monitor-row">
        <span className="muted">Avg. Daily Cont. Hrs</span>
        <span className="monitor-value">{row.avg_daily_contract_hours.toFixed(2)}</span>
      </div>
    </div>
  )
}

export default function SupportMonitoringPage() {
  const [data, setData] = useState<SupportMonitoring | null>(null)
  const [error, setError] = useState<string | null>(null)

  function refresh() {
    api.supportMonitoring().then(setData).catch((e) => setError(e.message))
  }

  useEffect(refresh, [])

  return (
    <div>
      <h1>Support Monitoring</h1>
      <p className="muted">
        Job Order workload and contract-hours throughput per support staff, as at{' '}
        {data ? formatDate(data.as_at) : '...'}. "Overdue" only counts Job Orders
        with a due date set -- due dates are set manually by Sales/Coordinator, not automatic.
        <Link to="/job-orders" style={{ marginLeft: 6 }}>
          Set a due date from a Job Order
        </Link>
        .
      </p>
      {error && <div className="error-banner">{error}</div>}

      {data && (
        <div className="card">
          <div className="stat-grid">
            <div className="card stat-tile">
              <div className="stat-value">{data.summary.total_open_job_orders}</div>
              <div className="stat-label">Open Job Orders</div>
            </div>
            <div className="card stat-tile">
              <div className="stat-value">{data.summary.total_overdue_job_orders}</div>
              <div className="stat-label">Overdue</div>
            </div>
            <div className="card stat-tile">
              <div className="stat-value">{data.summary.unassigned_job_orders}</div>
              <div className="stat-label">Unassigned</div>
            </div>
            <div className="card stat-tile">
              <div className="stat-value">{data.summary.total_pending_service_records}</div>
              <div className="stat-label">Pending Service Records</div>
            </div>
            <div className="card stat-tile">
              <div className="stat-value">{data.summary.total_untested_software_tasks}</div>
              <div className="stat-label">Un-Tested Software Tasks</div>
            </div>
          </div>
        </div>
      )}

      {data && (
        <>
          <p className="muted" style={{ marginTop: 4 }}>
            {data.staff.length + (data.unassigned.open_job_orders > 0 ? 1 : 0)} staff shown
          </p>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))', gap: 10 }}>
            {data.unassigned.open_job_orders > 0 && <StaffCard row={data.unassigned} />}
            {data.staff.map((row) => (
              <StaffCard key={row.user_id} row={row} />
            ))}
          </div>
        </>
      )}
    </div>
  )
}
