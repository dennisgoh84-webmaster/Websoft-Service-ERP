// Dedicated approval queue (confirmed 2026-09-11, "Add this below
// Service Records Menu") -- separate from the plain Service Records
// listing because approving now requires keying in the actual
// deduction minutes (distinct from the objective raw/rounded log),
// with a suggested value prefilled from the Urgent/after-hours
// multipliers (see suggested_deduction_minutes() in
// app/services/service_records.py) that the approver can always
// override.
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api, type PendingServiceRecord } from '../lib/api'
import { formatDate } from '../lib/format'

export default function ServiceRecordApprovalPage() {
  const [pending, setPending] = useState<PendingServiceRecord[]>([])
  const [deductions, setDeductions] = useState<Record<string, string>>({})
  const [error, setError] = useState<string | null>(null)
  const [approvingId, setApprovingId] = useState<string | null>(null)

  function refresh() {
    api
      .listPendingServiceRecordApprovals()
      .then((rows) => {
        setPending(rows)
        setDeductions((prev) => {
          const next = { ...prev }
          for (const r of rows) {
            if (!(r.id in next)) next[r.id] = String(r.suggested_deducted_minutes)
          }
          return next
        })
      })
      .catch((e) => setError(e.message))
  }

  useEffect(refresh, [])

  async function onApprove(id: string) {
    const minutes = parseInt(deductions[id] ?? '', 10)
    if (!minutes || minutes <= 0) {
      setError('Enter how many minutes to deduct before approving.')
      return
    }
    setError(null)
    setApprovingId(id)
    try {
      await api.approveServiceRecord(id, minutes)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to approve')
    } finally {
      setApprovingId(null)
    }
  }

  return (
    <div>
      <h1>Service Record Approval</h1>
      <p className="muted">
        Every Submitted Service Record, oldest first. Only Nico (Service Lead) or Cherish (Sales
        Manager) can approve, and each record should be approved within a week of submission
        (SRV-019) -- anything past that is marked overdue here and counted on the Company
        Dashboard. Approving keys in the actual minutes to deduct from the contract -- a suggestion
        is prefilled (rounded minutes x the Urgent/after-hours multiplier, higher one wins if both
        apply) but you can key in any value, e.g. actual 240min logged, 220min or 360min deducted.
        On a job order classified Billable or Non-billable the figure is recorded but nothing is
        deducted from contract hours (SRV-020).
      </p>
      {error && <div className="error-banner">{error}</div>}

      <div className="card">
        <h2>Pending approval ({pending.length})</h2>
        <table>
          <thead>
            <tr>
              <th>Number</th>
              <th>Job Order</th>
              <th>Employee</th>
              <th>Date</th>
              <th>Raw / Rounded</th>
              <th>Completion</th>
              <th>Contract remaining</th>
              <th>Deduct (min)</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {pending.map((r) => (
              <tr key={r.id}>
                <td className="muted">{r.service_record_number}</td>
                <td>
                  <Link to={`/job-orders/${r.job_order_id}`}>{r.job_order_subject}</Link>
                  {r.is_urgent && <span className="badge exceeded" style={{ marginLeft: 6 }}>URGENT</span>}
                </td>
                <td>{r.employee_name}</td>
                <td>
                  {formatDate(r.work_date)}
                  {r.is_late && <span className="badge exceeded" style={{ marginLeft: 6 }}>late</span>}
                  {r.is_approval_overdue && (
                    <span className="badge exceeded" style={{ marginLeft: 6 }} title="Submitted more than a week ago (SRV-019)">
                      approval overdue
                    </span>
                  )}
                </td>
                <td>
                  {r.raw_minutes}m &rarr; {r.rounded_minutes}m
                </td>
                <td>
                  {r.completion_status === 'C' ? 'Completed' : 'Uncompleted'}
                  {r.is_after_hours && (
                    <span className="badge exceeded" style={{ marginLeft: 6 }}>
                      after-hours
                    </span>
                  )}
                </td>
                <td>
                  {r.billing_classification === 'contract'
                    ? r.contract_remaining_minutes != null
                      ? `${r.contract_remaining_minutes}m`
                      : 'n/a'
                    : r.billing_classification === 'billable'
                      ? 'Billable -- not deducted'
                      : 'Non-billable -- not deducted'}
                </td>
                <td>
                  <input
                    type="number"
                    min={1}
                    value={deductions[r.id] ?? ''}
                    onChange={(e) => setDeductions((prev) => ({ ...prev, [r.id]: e.target.value }))}
                    style={{ width: 90 }}
                  />
                </td>
                <td>
                  <button onClick={() => onApprove(r.id)} disabled={approvingId === r.id}>
                    {approvingId === r.id ? 'Approving...' : 'Approve'}
                  </button>
                </td>
              </tr>
            ))}
            {pending.length === 0 && (
              <tr>
                <td colSpan={9} className="muted">
                  Nothing pending approval.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  )
}
