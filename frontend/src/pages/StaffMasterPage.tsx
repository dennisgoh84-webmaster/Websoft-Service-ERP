import { useEffect, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import ExportControl from '../components/ExportControl'
import StaffAvatar from '../components/StaffAvatar'
import { api, downloadBlob, type Group, type StaffUser, type UserRole } from '../lib/api'
import { formatDate } from '../lib/format'

const ROLES: UserRole[] = ['owner', 'service_lead', 'sales_manager', 'support_engineer', 'finance']

export default function StaffMasterPage() {
  const [staff, setStaff] = useState<StaffUser[]>([])
  const [groups, setGroups] = useState<Group[]>([])
  const [showInactive, setShowInactive] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const [username, setUsername] = useState('')
  const [fullName, setFullName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [role, setRole] = useState<UserRole>('support_engineer')
  const [groupId, setGroupId] = useState('')

  function refresh() {
    api
      .listStaff(showInactive)
      .then(setStaff)
      .catch((e) => setError(e.message))
  }

  useEffect(refresh, [showInactive])
  useEffect(() => {
    api.listGroups().then(setGroups).catch((e) => setError(e.message))
  }, [])

  function groupName(id: string | null) {
    if (!id) return <span className="muted">No group</span>
    return groups.find((g) => g.id === id)?.name ?? <span className="muted">Unknown group</span>
  }

  async function onCreate(e: FormEvent) {
    e.preventDefault()
    setError(null)
    try {
      await api.createStaff({
        username,
        full_name: fullName,
        email,
        password,
        role,
        group_id: groupId || null,
      })
      setUsername('')
      setFullName('')
      setEmail('')
      setPassword('')
      setRole('support_engineer')
      setGroupId('')
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to create staff account')
    }
  }

  async function onExport(format: string) {
    setError(null)
    if (format === 'csv') {
      downloadBlob(await api.exportStaffCsv(showInactive), 'staff-master.csv')
    } else {
      downloadBlob(await api.exportStaffExcel(showInactive), 'staff-master.xlsx')
    }
  }

  return (
    <div>
      <h1>Staff Master</h1>
      <p className="muted">
        Every staff account, their Role (used only for the specific named-responsibility rules,
        e.g. who decides excess usage) and their Group (Group Authority -- general module access).
        Open a staff record for the full profile, password reset, and activity history.
      </p>
      {error && <div className="error-banner">{error}</div>}

      <div className="card">
        <h2>Add staff</h2>
        <form onSubmit={onCreate}>
          <div className="form-row">
            <label>Full name</label>
            <input value={fullName} onChange={(e) => setFullName(e.target.value)} required />
          </div>
          <div className="form-row">
            <label>Username (3-20 characters, letters/numbers/underscore/hyphen)</label>
            <input
              value={username}
              onChange={(e) => setUsername(e.target.value.toLowerCase())}
              pattern="^[a-zA-Z0-9_-]{3,20}$"
              required
            />
          </div>
          <div className="form-row">
            <label>Email</label>
            <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} required />
          </div>
          <div className="form-row">
            <label>Temporary password</label>
            <input
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              minLength={8}
              required
            />
          </div>
          <div className="form-row">
            <label>Role (named-responsibility rules only)</label>
            <select value={role} onChange={(e) => setRole(e.target.value as UserRole)}>
              {ROLES.map((r) => (
                <option key={r} value={r}>
                  {r}
                </option>
              ))}
            </select>
          </div>
          <div className="form-row">
            <label>Group (Group Authority)</label>
            <select value={groupId} onChange={(e) => setGroupId(e.target.value)}>
              <option value="">No group</option>
              {groups.map((g) => (
                <option key={g.id} value={g.id}>
                  {g.name}
                </option>
              ))}
            </select>
          </div>
          <button type="submit">Add staff</button>
        </form>
      </div>

      <div className="card">
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <h2>All staff</h2>
          <label style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 13 }}>
            <input
              type="checkbox"
              checked={showInactive}
              onChange={(e) => setShowInactive(e.target.checked)}
            />
            Show deactivated
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
        <table>
          <thead>
            <tr>
              <th>Name</th>
              <th>Email</th>
              <th>Role</th>
              <th>Group</th>
              <th>Joined</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            {staff.map((u) => (
              <tr key={u.id} style={{ opacity: u.is_active ? 1 : 0.6 }}>
                <td>
                  <Link to={`/staff/${u.id}`} style={{ display: 'inline-flex', alignItems: 'center', gap: 8 }}>
                    <StaffAvatar photo={u.photo} fullName={u.full_name} size={32} />
                    {u.full_name}
                  </Link>
                </td>
                <td>{u.email}</td>
                <td>{u.role}</td>
                <td>{groupName(u.group_id)}</td>
                <td>{formatDate(u.created_at)}</td>
                <td>
                  <span className={`badge ${u.is_active ? 'active' : 'draft'}`}>
                    {u.is_active ? 'Active' : 'Deactivated'}
                  </span>{' '}
                  {u.must_change_password && (
                    <span className="badge draft" title="Hasn't set their own password yet">
                      Password pending
                    </span>
                  )}
                </td>
              </tr>
            ))}
            {staff.length === 0 && (
              <tr>
                <td colSpan={6} className="muted">
                  No staff found.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  )
}
