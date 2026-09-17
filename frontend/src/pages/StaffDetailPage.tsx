import { useEffect, useState, type ChangeEvent, type FormEvent } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import CompanyAccessCard from '../components/CompanyAccessCard'
import { api, type AuditLogEntry, type StaffUser, type UserRole } from '../lib/api'
import { useAuth } from '../lib/AuthContext'
import { formatDate, formatDateTime } from '../lib/format'

const ROLES: UserRole[] = ['owner', 'service_lead', 'sales_manager', 'support_engineer', 'finance']
const MAX_PHOTO_BYTES = 300 * 1024

const ACTION_LABELS: Record<string, string> = {
  created: 'Account created',
  updated: 'Profile updated',
  deactivated: 'Deactivated',
  reactivated: 'Reactivated',
  password_reset: 'Password reset',
}

export default function StaffDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const { user: me } = useAuth()

  const [staff, setStaff] = useState<StaffUser | null>(null)
  const [allStaff, setAllStaff] = useState<StaffUser[]>([])
  const [auditLog, setAuditLog] = useState<AuditLogEntry[]>([])
  const [error, setError] = useState<string | null>(null)
  const [notFound, setNotFound] = useState(false)

  const [fullName, setFullName] = useState('')
  const [email, setEmail] = useState('')
  const [role, setRole] = useState<UserRole>('support_engineer')
  const [photo, setPhoto] = useState<string | null>(null)
  const [forcePasswordChange, setForcePasswordChange] = useState(false)
  const [saving, setSaving] = useState(false)

  const [newPassword, setNewPassword] = useState('')
  const [passwordMessage, setPasswordMessage] = useState<string | null>(null)

  function refresh() {
    if (!id) return
    api
      .getStaff(id)
      .then((u) => {
        setStaff(u)
        setFullName(u.full_name)
        setEmail(u.email)
        setRole(u.role)
        setPhoto(u.photo)
        setForcePasswordChange(u.force_password_change_on_login)
      })
      .catch(() => setNotFound(true))
    api.getStaffAuditLog(id).then(setAuditLog).catch((e) => setError(e.message))
  }

  useEffect(refresh, [id])
  useEffect(() => {
    api.listStaff(true).then(setAllStaff).catch((e) => setError(e.message))
  }, [])

  function actorName(actorId: string | null) {
    if (!actorId) return 'System'
    return allStaff.find((u) => u.id === actorId)?.full_name ?? actorId.slice(0, 8)
  }

  async function onSave(e: FormEvent) {
    e.preventDefault()
    if (!id) return
    setError(null)
    setSaving(true)
    try {
      const updated = await api.updateStaff(id, {
        full_name: fullName,
        email: email !== staff?.email ? email : undefined,
        role,
        photo,
        force_password_change_on_login: forcePasswordChange,
      })
      setStaff(updated)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to save changes')
    } finally {
      setSaving(false)
    }
  }

  function onPickPhoto(e: ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0]
    if (!file) return
    setError(null)
    if (!file.type.startsWith('image/')) {
      setError('Please choose an image file.')
      return
    }
    if (file.size > MAX_PHOTO_BYTES) {
      setError('That image is too large -- please use one under 300 KB.')
      return
    }
    const reader = new FileReader()
    reader.onload = () => setPhoto(reader.result as string)
    reader.onerror = () => setError('Could not read that file.')
    reader.readAsDataURL(file)
  }

  async function onResetPassword(e: FormEvent) {
    e.preventDefault()
    if (!id) return
    setError(null)
    setPasswordMessage(null)
    try {
      await api.resetStaffPassword(id, newPassword)
      setNewPassword('')
      setPasswordMessage('Password reset. Share the new password with the staff member securely.')
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to reset password')
    }
  }

  async function onToggleActive() {
    if (!id || !staff) return
    setError(null)
    try {
      if (staff.is_active) await api.deactivateStaff(id)
      else await api.reactivateStaff(id)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to update account status')
    }
  }

  if (notFound) return <p>Staff record not found. <Link to="/staff">Back to Staff Master</Link></p>
  if (!staff) return <p>Loading...</p>

  const isSelf = me?.id === staff.id

  return (
    <div>
      <p>
        <Link to="/staff">&larr; Staff Master</Link>
      </p>
      <h1>{staff.full_name}</h1>
      <p>
        <span className={`badge ${staff.is_active ? 'active' : 'draft'}`}>
          {staff.is_active ? 'Active' : 'Deactivated'}
        </span>{' '}
        <span className="muted">
          {staff.email} &middot; joined {formatDate(staff.created_at)}
        </span>
      </p>
      {error && <div className="error-banner">{error}</div>}

      <div className="card">
        <h2>Profile</h2>
        <form onSubmit={onSave}>
          <div style={{ display: 'flex', gap: 20, alignItems: 'flex-start', flexWrap: 'wrap', marginBottom: 14 }}>
            <div>
              <div className="form-row">
                <label>Photo (shown on Support Monitoring)</label>
                <div
                  style={{
                    width: 96,
                    height: 96,
                    border: '1px solid var(--border)',
                    borderRadius: '50%',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    overflow: 'hidden',
                    background: 'var(--bg)',
                  }}
                >
                  {photo ? (
                    <img
                      src={photo}
                      alt={`${staff.full_name} photo`}
                      style={{ width: '100%', height: '100%', objectFit: 'cover' }}
                    />
                  ) : (
                    <span className="muted" style={{ fontSize: 12 }}>
                      No photo
                    </span>
                  )}
                </div>
              </div>
              <input type="file" accept="image/*" onChange={onPickPhoto} />
              {photo && (
                <button
                  type="button"
                  className="secondary"
                  style={{ marginTop: 8, display: 'block' }}
                  onClick={() => setPhoto(null)}
                >
                  Remove photo
                </button>
              )}
            </div>
          </div>
          <div className="form-row">
            <label>Full name</label>
            <input value={fullName} onChange={(e) => setFullName(e.target.value)} required />
          </div>
          <div className="form-row">
            <label>Username (cannot be changed)</label>
            <input value={staff.username} disabled title="Username cannot be changed after account creation." />
          </div>
          <div className="form-row">
            <label>Email</label>
            <input
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              required
            />
          </div>
          <div className="form-row">
            <label>Role (named-responsibility rules only, e.g. SRV-004/SRV-011)</label>
            <select value={role} onChange={(e) => setRole(e.target.value as UserRole)}>
              {ROLES.map((r) => (
                <option key={r} value={r}>
                  {r}
                </option>
              ))}
            </select>
          </div>
          <label style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 14 }}>
            <input
              type="checkbox"
              checked={forcePasswordChange}
              onChange={(e) => setForcePasswordChange(e.target.checked)}
            />
            Force password change on next login
          </label>
          <p className="muted">
            Group Authority (general module access) is set per company below, since a Group belongs
            to a single company.
          </p>
          <button type="submit" disabled={saving}>
            {saving ? 'Saving...' : 'Save changes'}
          </button>
        </form>
      </div>

      {id && <CompanyAccessCard userId={id} />}

      <div className="card">
        <h2>Password</h2>
        <form onSubmit={onResetPassword}>
          <div className="form-row">
            <label>New password</label>
            <input
              type="password"
              value={newPassword}
              onChange={(e) => setNewPassword(e.target.value)}
              minLength={8}
              placeholder="Min. 8 characters"
              required
            />
          </div>
          <button type="submit">Reset password</button>
        </form>
        {passwordMessage && <p className="muted" style={{ marginTop: 10 }}>{passwordMessage}</p>}
      </div>

      <div className="card">
        <h2>Account status</h2>
        <p className="muted">
          Deactivating a staff account keeps their history intact but immediately blocks login and
          Group Authority access (per CLAUDE.md: never permanently delete business records).
        </p>
        <button
          className="secondary"
          disabled={isSelf}
          title={isSelf ? 'You cannot deactivate your own account.' : undefined}
          onClick={onToggleActive}
        >
          {staff.is_active ? 'Deactivate account' : 'Reactivate account'}
        </button>
      </div>

      <div className="card">
        <h2>AI Assistant PDPA notice</h2>
        <p className="muted" style={{ margin: 0 }}>
          {staff.ai_data_consent_at ? (
            <>
              Acknowledged {formatDateTime(staff.ai_data_consent_at)}. This date/time is recorded once, at their
              first login after the notice was introduced, and cannot be changed or cleared from here.
            </>
          ) : (
            'Not yet acknowledged -- they will be asked to at their next sign-in.'
          )}
        </p>
      </div>

      <div className="card">
        <h2>Recent activity</h2>
        <table>
          <thead>
            <tr>
              <th>When</th>
              <th>Action</th>
              <th>By</th>
              <th>Details</th>
            </tr>
          </thead>
          <tbody>
            {auditLog.map((entry) => (
              <tr key={entry.id}>
                <td>{formatDateTime(entry.at)}</td>
                <td>{ACTION_LABELS[entry.action] ?? entry.action}</td>
                <td>{actorName(entry.actor_user_id)}</td>
                <td className="muted">{entry.details ?? entry.reason ?? '-'}</td>
              </tr>
            ))}
            {auditLog.length === 0 && (
              <tr>
                <td colSpan={4} className="muted">
                  No activity recorded yet.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      <button className="secondary" onClick={() => navigate('/staff')}>
        Back to Staff Master
      </button>
    </div>
  )
}
