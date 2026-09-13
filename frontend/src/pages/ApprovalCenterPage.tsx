/**
 * Approval Center — pending approvals dashboard.
 *
 * Shows all pending approval requests assigned to the current user.
 * Each request has Approve/Reject buttons with an optional comment.
 */
import { useEffect, useState } from 'react'
import {
  api,
  type ApprovalDecisionValue,
  type ApprovalRequest,
  type DocumentEntityType,
  type StaffUser,
} from '../lib/api'

const DOC_TYPE_LABELS: Record<DocumentEntityType, string> = {
  quotation: 'Quotation',
  invoice: 'Invoice',
  receipt_voucher: 'Receipt Voucher',
  payment_voucher: 'Payment Voucher',
  purchase_order: 'Purchase Order',
  supplier_invoice: 'Supplier Invoice',
  journal_entry: 'Journal Entry',
  job_order: 'Job Order',
  service_record: 'Service Record',
  contract: 'Contract',
  incident: 'Incident',
}

const STATUS_BADGES: Record<string, { bg: string; label: string }> = {
  pending: { bg: '#f39c12', label: 'Pending' },
  approved: { bg: '#27ae60', label: 'Approved' },
  rejected: { bg: '#e74c3c', label: 'Rejected' },
}

export default function ApprovalCenterPage() {
  const [pending, setPending] = useState<ApprovalRequest[]>([])
  const [staff, setStaff] = useState<StaffUser[]>([])
  const [comments, setComments] = useState<Record<string, string>>({})
  const [deciding, setDeciding] = useState<string | null>(null)
  const [error, setError] = useState('')

  const load = () => {
    api.listPendingApprovals().then(setPending).catch(() => {})
    api.listStaff().then(setStaff).catch(() => {})
  }

  useEffect(load, [])

  const staffName = (userId: string) => {
    const s = staff.find((u) => u.id === userId)
    return s ? s.full_name : userId.slice(0, 8)
  }

  const handleDecide = async (requestId: string, decision: ApprovalDecisionValue) => {
    setDeciding(requestId)
    setError('')
    try {
      await api.recordApprovalDecision(requestId, {
        decision,
        comment: comments[requestId]?.trim() || undefined,
      })
      setComments((prev) => {
        const next = { ...prev }
        delete next[requestId]
        return next
      })
      load()
    } catch (err: any) {
      setError(err.message)
    } finally {
      setDeciding(null)
    }
  }

  return (
    <div style={{ padding: 24 }}>
      <h2>✅ Approval Center</h2>
      <p style={{ opacity: 0.7, marginBottom: 16 }}>
        Pending approval requests assigned to you. Approve or reject each with an optional comment.
      </p>

      {error && <p className="error">{error}</p>}

      {pending.length === 0 ? (
        <div style={{ textAlign: 'center', padding: 40, opacity: 0.6 }}>
          <p style={{ fontSize: '1.2em' }}>🎉 No pending approvals</p>
          <p>All caught up!</p>
        </div>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
          {pending.map((req) => {
            const badge = STATUS_BADGES[req.status] || STATUS_BADGES.pending
            return (
              <div
                key={req.id}
                style={{
                  border: '1px solid var(--border, #ddd)',
                  borderRadius: 8,
                  padding: 16,
                }}
              >
                <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 8 }}>
                  <div>
                    <strong>{DOC_TYPE_LABELS[req.entity_type]}</strong>
                    <span
                      style={{
                        marginLeft: 8,
                        fontSize: '0.85em',
                        padding: '2px 6px',
                        borderRadius: 4,
                        background: badge.bg,
                        color: '#fff',
                      }}
                    >
                      {badge.label}
                    </span>
                  </div>
                  <div style={{ fontSize: '0.85em', opacity: 0.6 }}>
                    {new Date(req.requested_at).toLocaleDateString()}
                  </div>
                </div>

                <div style={{ fontSize: '0.9em', opacity: 0.7, marginBottom: 8 }}>
                  Requested by: <strong>{staffName(req.requested_by_user_id)}</strong>
                  <span style={{ marginLeft: 12 }}>
                    Entity: {req.entity_id.slice(0, 8)}…
                  </span>
                </div>

                {req.decisions.length > 0 && (
                  <div style={{ fontSize: '0.85em', marginBottom: 8 }}>
                    <strong>Decisions so far:</strong>
                    {req.decisions.map((d) => (
                      <span
                        key={d.id}
                        style={{
                          marginLeft: 8,
                          padding: '1px 6px',
                          borderRadius: 3,
                          background: d.decision === 'approved' ? '#27ae60' : '#e74c3c',
                          color: '#fff',
                          fontSize: '0.9em',
                        }}
                      >
                        {staffName(d.user_id)}: {d.decision}
                      </span>
                    ))}
                  </div>
                )}

                <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                  <input
                    type="text"
                    placeholder="Comment (optional)"
                    value={comments[req.id] || ''}
                    onChange={(e) => setComments((prev) => ({ ...prev, [req.id]: e.target.value }))}
                    style={{ flex: 1, minWidth: 200 }}
                  />
                  <button
                    className="primary"
                    disabled={deciding === req.id}
                    onClick={() => handleDecide(req.id, 'approved')}
                    style={{ background: '#27ae60', borderColor: '#27ae60' }}
                  >
                    ✓ Approve
                  </button>
                  <button
                    className="secondary"
                    disabled={deciding === req.id}
                    onClick={() => handleDecide(req.id, 'rejected')}
                    style={{ color: '#e74c3c', borderColor: '#e74c3c' }}
                  >
                    ✕ Reject
                  </button>
                </div>
              </div>
            )
          })}
        </div>
      )}
    </div>
  )
}
