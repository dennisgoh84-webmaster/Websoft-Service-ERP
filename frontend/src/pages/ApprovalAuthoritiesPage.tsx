/**
 * eApproval Master — Approval Authority setup page.
 *
 * Admin screen where owners/admins can:
 * - Create/edit approval authorities (name, mode, description)
 * - Assign staff as authority members
 * - Create rules binding authorities to document types + value thresholds
 */
import { useEffect, useState } from 'react'
import {
  api,
  type ApprovalAuthority,
  type ApprovalMode,
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

const MODE_LABELS: Record<ApprovalMode, string> = {
  any_one: 'Any One',
  all_must: 'All Must Approve',
}

export default function ApprovalAuthoritiesPage() {
  const [authorities, setAuthorities] = useState<ApprovalAuthority[]>([])
  const [staff, setStaff] = useState<StaffUser[]>([])
  const [expandedId, setExpandedId] = useState<string | null>(null)
  const [error, setError] = useState('')
  const [showCreate, setShowCreate] = useState(false)

  // Create form
  const [newName, setNewName] = useState('')
  const [newDesc, setNewDesc] = useState('')
  const [newMode, setNewMode] = useState<ApprovalMode>('any_one')

  // Rule form
  const [ruleEntityType, setRuleEntityType] = useState<DocumentEntityType>('purchase_order')
  const [ruleThreshold, setRuleThreshold] = useState('')
  const [rulePriority, setRulePriority] = useState('0')

  // Member form
  const [selectedUserId, setSelectedUserId] = useState('')

  const load = () => {
    api.listApprovalAuthorities().then(setAuthorities).catch(() => {})
    api.listStaff().then(setStaff).catch(() => {})
  }

  useEffect(load, [])

  const staffName = (userId: string) => {
    const s = staff.find((u) => u.id === userId)
    return s ? s.full_name : userId.slice(0, 8)
  }

  const handleCreate = async () => {
    if (!newName.trim()) return
    setError('')
    try {
      await api.createApprovalAuthority({
        name: newName.trim(),
        description: newDesc.trim() || undefined,
        mode: newMode,
      })
      setNewName('')
      setNewDesc('')
      setNewMode('any_one')
      setShowCreate(false)
      load()
    } catch (err: any) {
      setError(err.message)
    }
  }

  const handleToggleActive = async (auth: ApprovalAuthority) => {
    try {
      await api.updateApprovalAuthority(auth.id, { is_active: !auth.is_active })
      load()
    } catch (err: any) {
      setError(err.message)
    }
  }

  const handleAddMember = async (authorityId: string) => {
    if (!selectedUserId) return
    try {
      await api.addApprovalMember(authorityId, selectedUserId)
      setSelectedUserId('')
      load()
    } catch (err: any) {
      setError(err.message)
    }
  }

  const handleRemoveMember = async (authorityId: string, memberId: string) => {
    if (!confirm('Remove this member?')) return
    try {
      await api.removeApprovalMember(authorityId, memberId)
      load()
    } catch (err: any) {
      setError(err.message)
    }
  }

  const handleAddRule = async (authorityId: string) => {
    try {
      await api.createApprovalRule({
        authority_id: authorityId,
        entity_type: ruleEntityType,
        threshold_amount: ruleThreshold ? parseFloat(ruleThreshold) : undefined,
        priority: parseInt(rulePriority) || 0,
      })
      setRuleThreshold('')
      setRulePriority('0')
      load()
    } catch (err: any) {
      setError(err.message)
    }
  }

  const handleDeleteRule = async (ruleId: string) => {
    if (!confirm('Deactivate this rule?')) return
    try {
      await api.deleteApprovalRule(ruleId)
      load()
    } catch (err: any) {
      setError(err.message)
    }
  }

  return (
    <div style={{ padding: 24 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h2>🔐 Approval Authority</h2>
        <button className="primary" onClick={() => setShowCreate(!showCreate)}>
          {showCreate ? 'Cancel' : '+ New Authority'}
        </button>
      </div>

      {error && <p className="error">{error}</p>}

      {showCreate && (
        <div
          style={{
            border: '1px solid var(--border, #ddd)',
            borderRadius: 8,
            padding: 16,
            marginBottom: 16,
          }}
        >
          <h3>Create Authority</h3>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 8, maxWidth: 400 }}>
            <input
              type="text"
              placeholder="Authority Name"
              value={newName}
              onChange={(e) => setNewName(e.target.value)}
            />
            <input
              type="text"
              placeholder="Description (optional)"
              value={newDesc}
              onChange={(e) => setNewDesc(e.target.value)}
            />
            <select value={newMode} onChange={(e) => setNewMode(e.target.value as ApprovalMode)}>
              <option value="any_one">Any One</option>
              <option value="all_must">All Must Approve</option>
            </select>
            <button className="primary" onClick={handleCreate} disabled={!newName.trim()}>
              Create
            </button>
          </div>
        </div>
      )}

      {authorities.length === 0 ? (
        <p style={{ opacity: 0.6 }}>No approval authorities configured yet.</p>
      ) : (
        authorities.map((auth) => (
          <div
            key={auth.id}
            style={{
              border: '1px solid var(--border, #ddd)',
              borderRadius: 8,
              marginBottom: 12,
              overflow: 'hidden',
            }}
          >
            <div
              style={{
                display: 'flex',
                justifyContent: 'space-between',
                alignItems: 'center',
                padding: '12px 16px',
                cursor: 'pointer',
                background: 'var(--surface, #fafafa)',
              }}
              onClick={() => setExpandedId(expandedId === auth.id ? null : auth.id)}
            >
              <div>
                <strong>{auth.name}</strong>
                <span
                  style={{
                    marginLeft: 8,
                    fontSize: '0.85em',
                    padding: '2px 6px',
                    borderRadius: 4,
                    background: auth.is_active ? '#27ae60' : '#999',
                    color: '#fff',
                  }}
                >
                  {auth.is_active ? 'Active' : 'Inactive'}
                </span>
                <span style={{ marginLeft: 8, fontSize: '0.85em', opacity: 0.7 }}>
                  {MODE_LABELS[auth.mode]} · {auth.members.length} member(s) · {auth.rules.length} rule(s)
                </span>
              </div>
              <span style={{ fontSize: '1.2em' }}>{expandedId === auth.id ? '▾' : '▸'}</span>
            </div>

            {expandedId === auth.id && (
              <div style={{ padding: 16, borderTop: '1px solid var(--border, #ddd)' }}>
                {auth.description && <p style={{ opacity: 0.7, marginBottom: 12 }}>{auth.description}</p>}

                <button
                  className="secondary"
                  style={{ marginBottom: 12, fontSize: '0.85em' }}
                  onClick={() => handleToggleActive(auth)}
                >
                  {auth.is_active ? 'Deactivate' : 'Activate'}
                </button>

                {/* Members */}
                <h4>👥 Members</h4>
                {auth.members.length > 0 && (
                  <ul style={{ margin: '8px 0', paddingLeft: 20 }}>
                    {auth.members.map((m) => (
                      <li key={m.id} style={{ marginBottom: 4 }}>
                        {staffName(m.user_id)}
                        <button
                          className="secondary"
                          style={{ marginLeft: 8, fontSize: '0.75em', padding: '1px 6px' }}
                          onClick={() => handleRemoveMember(auth.id, m.id)}
                        >
                          ✕
                        </button>
                      </li>
                    ))}
                  </ul>
                )}
                <div style={{ display: 'flex', gap: 8, marginBottom: 16 }}>
                  <select value={selectedUserId} onChange={(e) => setSelectedUserId(e.target.value)}>
                    <option value="">Select staff...</option>
                    {staff
                      .filter((s) => !auth.members.some((m) => m.user_id === s.id))
                      .map((s) => (
                        <option key={s.id} value={s.id}>
                          {s.full_name}
                        </option>
                      ))}
                  </select>
                  <button className="primary" onClick={() => handleAddMember(auth.id)} disabled={!selectedUserId}>
                    Add Member
                  </button>
                </div>

                {/* Rules */}
                <h4>📋 Rules</h4>
                {auth.rules.filter((r) => r.is_active).length > 0 && (
                  <table className="data-table" style={{ fontSize: '0.9em', marginBottom: 12 }}>
                    <thead>
                      <tr>
                        <th>Document Type</th>
                        <th>Threshold (≥)</th>
                        <th>Priority</th>
                        <th>Action</th>
                      </tr>
                    </thead>
                    <tbody>
                      {auth.rules
                        .filter((r) => r.is_active)
                        .map((r) => (
                          <tr key={r.id}>
                            <td>{DOC_TYPE_LABELS[r.entity_type]}</td>
                            <td>{r.threshold_amount !== null ? `SGD ${r.threshold_amount.toLocaleString()}` : '—'}</td>
                            <td>{r.priority}</td>
                            <td>
                              <button
                                className="secondary"
                                style={{ fontSize: '0.8em', padding: '1px 6px' }}
                                onClick={() => handleDeleteRule(r.id)}
                              >
                                Remove
                              </button>
                            </td>
                          </tr>
                        ))}
                    </tbody>
                  </table>
                )}
                <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                  <select
                    value={ruleEntityType}
                    onChange={(e) => setRuleEntityType(e.target.value as DocumentEntityType)}
                  >
                    {Object.entries(DOC_TYPE_LABELS).map(([val, label]) => (
                      <option key={val} value={val}>
                        {label}
                      </option>
                    ))}
                  </select>
                  <input
                    type="number"
                    placeholder="Threshold (optional)"
                    value={ruleThreshold}
                    onChange={(e) => setRuleThreshold(e.target.value)}
                    style={{ width: 140 }}
                  />
                  <input
                    type="number"
                    placeholder="Priority"
                    value={rulePriority}
                    onChange={(e) => setRulePriority(e.target.value)}
                    style={{ width: 80 }}
                  />
                  <button className="primary" onClick={() => handleAddRule(auth.id)}>
                    Add Rule
                  </button>
                </div>
              </div>
            )}
          </div>
        ))
      )}
    </div>
  )
}
