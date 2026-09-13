import { Fragment, useEffect, useState } from 'react'
import DocumentAttachmentsPanel from '../components/DocumentAttachmentsPanel'
import SignaturePanel from '../components/SignaturePanel'
import {
  api,
  type CommissionPayout,
  type CommissionPayoutStatus,
  type CurrentUser,
} from '../lib/api'

const STATUS_BADGES: Record<CommissionPayoutStatus, string> = {
  draft: 'muted',
  pending_approval: 'active',
  approved: 'active',
  paid: 'active',
  cancelled: 'exceeded',
}
const STATUS_LABELS: Record<CommissionPayoutStatus, string> = {
  draft: 'Draft',
  pending_approval: 'Pending Approval',
  approved: 'Approved',
  paid: 'Paid',
  cancelled: 'Cancelled',
}
const TYPE_LABELS: Record<string, string> = {
  earning: 'Earning',
  clawback: 'Clawback',
}

function money(n: number): string {
  return n.toLocaleString('en-SG', { style: 'currency', currency: 'SGD' })
}

export default function CommissionPayoutsPage() {
  const [payouts, setPayouts] = useState<CommissionPayout[]>([])
  const [users, setUsers] = useState<CurrentUser[]>([])
  const [error, setError] = useState<string | null>(null)
  const [working, setWorking] = useState(false)
  const [docPanelId, setDocPanelId] = useState<string | null>(null)

  // Filters
  const now = new Date()
  const defaultMonth = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`
  const [filterMonth, setFilterMonth] = useState(defaultMonth)
  const [filterStatus, setFilterStatus] = useState('')

  // Generate form
  const [generateMonth, setGenerateMonth] = useState(defaultMonth)

  // Pay form
  const [payingId, setPayingId] = useState<string | null>(null)
  const [payDate, setPayDate] = useState(now.toISOString().slice(0, 10))
  const [payRef, setPayRef] = useState('')

  function refresh() {
    api
      .listCommissionPayouts({
        period_month: filterMonth || undefined,
        status: filterStatus || undefined,
      })
      .then(setPayouts)
      .catch((e) => setError(e.message))
    api.listUsers().then(setUsers)
  }

  useEffect(refresh, [filterMonth, filterStatus])

  const userName = (uid: string | null) =>
    users.find((u) => u.id === uid)?.full_name ?? uid?.slice(0, 8) ?? '-'

  async function onGenerate() {
    setError(null)
    setWorking(true)
    try {
      await api.generateCommissionPayouts(generateMonth)
      setFilterMonth(generateMonth)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to generate payouts')
    } finally {
      setWorking(false)
    }
  }

  async function onAction(
    action: 'submit' | 'approve' | 'reject' | 'cancel',
    payoutId: string,
  ) {
    setError(null)
    setWorking(true)
    try {
      if (action === 'submit') await api.submitCommissionPayout(payoutId)
      else if (action === 'approve') await api.approveCommissionPayout(payoutId)
      else if (action === 'reject') {
        const reason = window.prompt('Reason for rejection (optional):')
        await api.rejectCommissionPayout(payoutId, reason ?? undefined)
      } else if (action === 'cancel') await api.cancelCommissionPayout(payoutId)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : `Failed to ${action}`)
    } finally {
      setWorking(false)
    }
  }

  async function onSubmitAll() {
    if (!filterMonth) return
    setError(null)
    setWorking(true)
    try {
      await api.submitAllCommissionPayouts(filterMonth)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to submit all')
    } finally {
      setWorking(false)
    }
  }

  async function onApproveAll() {
    if (!filterMonth) return
    setError(null)
    setWorking(true)
    try {
      await api.approveAllCommissionPayouts(filterMonth)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to approve all')
    } finally {
      setWorking(false)
    }
  }

  async function onPay(payoutId: string) {
    setError(null)
    setWorking(true)
    try {
      await api.payCommissionPayout(payoutId, payDate, payRef || undefined)
      setPayingId(null)
      setPayRef('')
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to mark as paid')
    } finally {
      setWorking(false)
    }
  }

  // Summaries
  const draftCount = payouts.filter((p) => p.status === 'draft').length
  const pendingCount = payouts.filter((p) => p.status === 'pending_approval').length
  const totalEarnings = payouts
    .filter((p) => p.payout_type === 'earning' && p.status !== 'cancelled')
    .reduce((sum, p) => sum + p.amount_sgd, 0)
  const totalClawbacks = payouts
    .filter((p) => p.payout_type === 'clawback' && p.status !== 'cancelled')
    .reduce((sum, p) => sum + p.amount_sgd, 0)

  return (
    <div>
      <h1>Commission Payouts</h1>
      <p className="muted">
        Generate, approve, and pay commission to sales staff. Commission = rate %
        × gross profit, prorated by receipt allocation. Clawbacks are
        auto-created when an invoice is written off.
      </p>

      {error && <div className="error-banner">{error}</div>}

      {/* Generate payouts */}
      <div className="card">
        <h2>Generate Payouts</h2>
        <p className="muted">
          Calculate commission for all sales staff for a given month and create
          DRAFT payout records. Each salesperson gets one record.
        </p>
        <div style={{ display: 'flex', gap: 10, alignItems: 'flex-end', flexWrap: 'wrap' }}>
          <div className="form-row" style={{ margin: 0 }}>
            <label>Month (YYYY-MM)</label>
            <input
              type="month"
              value={generateMonth}
              onChange={(e) => setGenerateMonth(e.target.value)}
            />
          </div>
          <button onClick={onGenerate} disabled={working || !generateMonth}>
            Generate
          </button>
        </div>
      </div>

      {/* Filters + bulk actions */}
      <div className="card">
        <div className="filter-bar">
          <div className="form-row" style={{ margin: 0 }}>
            <label>Month</label>
            <input
              type="month"
              value={filterMonth}
              onChange={(e) => setFilterMonth(e.target.value)}
            />
          </div>
          <div className="form-row" style={{ margin: 0 }}>
            <label>Status</label>
            <select value={filterStatus} onChange={(e) => setFilterStatus(e.target.value)}>
              <option value="">All</option>
              <option value="draft">Draft</option>
              <option value="pending_approval">Pending Approval</option>
              <option value="approved">Approved</option>
              <option value="paid">Paid</option>
              <option value="cancelled">Cancelled</option>
            </select>
          </div>
          {draftCount > 0 && (
            <button className="secondary" onClick={onSubmitAll} disabled={working}>
              Submit All Drafts ({draftCount})
            </button>
          )}
          {pendingCount > 0 && (
            <button className="secondary" onClick={onApproveAll} disabled={working}>
              Approve All Pending ({pendingCount})
            </button>
          )}
        </div>

        {/* Summary tiles */}
        <div className="stat-grid" style={{ marginTop: 12, marginBottom: 12 }}>
          <div className="card stat-tile">
            <div className="stat-value stat-value-text">{money(totalEarnings)}</div>
            <div className="stat-label">Total Earnings</div>
          </div>
          <div className="card stat-tile">
            <div className="stat-value stat-value-text">{money(totalClawbacks)}</div>
            <div className="stat-label">Total Clawbacks</div>
          </div>
          <div className="card stat-tile">
            <div className="stat-value stat-value-text">{money(totalEarnings + totalClawbacks)}</div>
            <div className="stat-label">Net Payable</div>
          </div>
          <div className="card stat-tile">
            <div className="stat-value stat-value-text">{payouts.length}</div>
            <div className="stat-label">Records</div>
          </div>
        </div>

        {/* Payouts table */}
        <h2>Payouts ({payouts.length})</h2>
        <div style={{ overflowX: 'auto' }}>
          <table>
            <thead>
              <tr>
                <th>Number</th>
                <th>Month</th>
                <th>Salesperson</th>
                <th>Type</th>
                <th>Amount</th>
                <th>Rate</th>
                <th>Status</th>
                <th>Paid</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {payouts.map((p) => (
                <Fragment key={p.id}>
                <tr>
                  <td className="muted">{p.payout_number}</td>
                  <td>{p.period_month}</td>
                  <td>{userName(p.sales_staff_id)}</td>
                  <td>
                    {p.payout_type === 'clawback' ? (
                      <span className="badge exceeded">Clawback</span>
                    ) : (
                      <span className="muted">{TYPE_LABELS[p.payout_type] || p.payout_type}</span>
                    )}
                  </td>
                  <td style={{ color: p.amount_sgd < 0 ? '#c0392b' : undefined }}>
                    {money(p.amount_sgd)}
                  </td>
                  <td className="muted">{p.rate_percent}%</td>
                  <td>
                    <span className={`badge ${STATUS_BADGES[p.status] || ''}`}>
                      {STATUS_LABELS[p.status] || p.status}
                    </span>
                  </td>
                  <td>
                    {p.paid_date ? (
                      <>
                        {p.paid_date}
                        {p.paid_reference && (
                          <span className="muted" style={{ marginLeft: 4 }}>
                            ({p.paid_reference})
                          </span>
                        )}
                      </>
                    ) : (
                      <span className="muted">-</span>
                    )}
                  </td>
                  <td style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                    <button
                      className="secondary icon-button"
                      title="Attachments & Signatures"
                      aria-label="Attachments & Signatures"
                      onClick={() => setDocPanelId(docPanelId === p.id ? null : p.id)}
                      style={{ fontSize: 12, padding: '2px 8px' }}
                    >📎</button>
                    {p.status === 'draft' && (
                      <>
                        <button
                          className="secondary"
                          style={{ fontSize: 12, padding: '2px 8px' }}
                          onClick={() => onAction('submit', p.id)}
                          disabled={working}
                        >
                          Submit
                        </button>
                        <button
                          className="secondary"
                          style={{ fontSize: 12, padding: '2px 8px' }}
                          onClick={() => onAction('cancel', p.id)}
                          disabled={working}
                        >
                          Cancel
                        </button>
                      </>
                    )}
                    {p.status === 'pending_approval' && (
                      <>
                        <button
                          className="secondary"
                          style={{ fontSize: 12, padding: '2px 8px' }}
                          onClick={() => onAction('approve', p.id)}
                          disabled={working}
                        >
                          Approve
                        </button>
                        <button
                          className="secondary"
                          style={{ fontSize: 12, padding: '2px 8px' }}
                          onClick={() => onAction('reject', p.id)}
                          disabled={working}
                        >
                          Reject
                        </button>
                      </>
                    )}
                    {p.status === 'approved' && (
                      <>
                        {payingId === p.id ? (
                          <div style={{ display: 'flex', gap: 6, alignItems: 'center', flexWrap: 'wrap' }}>
                            <input
                              type="date"
                              value={payDate}
                              onChange={(e) => setPayDate(e.target.value)}
                              style={{ fontSize: 12, width: 130 }}
                            />
                            <input
                              placeholder="Reference"
                              value={payRef}
                              onChange={(e) => setPayRef(e.target.value)}
                              style={{ fontSize: 12, width: 100 }}
                            />
                            <button
                              style={{ fontSize: 12, padding: '2px 8px' }}
                              onClick={() => onPay(p.id)}
                              disabled={working}
                            >
                              Confirm
                            </button>
                            <button
                              className="secondary"
                              style={{ fontSize: 12, padding: '2px 8px' }}
                              onClick={() => setPayingId(null)}
                            >
                              ✕
                            </button>
                          </div>
                        ) : (
                          <button
                            className="secondary"
                            style={{ fontSize: 12, padding: '2px 8px' }}
                            onClick={() => setPayingId(p.id)}
                            disabled={working}
                          >
                            Mark Paid
                          </button>
                        )}
                      </>
                    )}
                    {p.clawback_reason && (
                      <span className="muted" style={{ fontSize: 11 }}>
                        {p.clawback_reason}
                      </span>
                    )}
                  </td>
                </tr>
                {docPanelId === p.id && (
                  <tr>
                    <td colSpan={9} style={{ padding: 16, background: 'var(--bg-muted, #f9f9f9)' }}>
                      <DocumentAttachmentsPanel entityType="commission_payout" entityId={p.id} />
                      <SignaturePanel entityType="commission_payout" entityId={p.id} />
                    </td>
                  </tr>
                )}
                </Fragment>
              ))}
              {payouts.length === 0 && (
                <tr>
                  <td colSpan={9} className="muted">
                    No payouts for this period. Use "Generate" to calculate commission.
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
