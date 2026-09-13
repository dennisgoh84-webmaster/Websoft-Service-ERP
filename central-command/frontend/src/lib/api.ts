/**
 * Central Command API client.
 */

const BASE = '/api'

function authHeaders(): Record<string, string> {
  const token = localStorage.getItem('cc_token')
  return token ? { Authorization: `Bearer ${token}` } : {}
}

async function request<T>(path: string, init?: RequestInit): Promise<T> {
  const res = await fetch(`${BASE}${path}`, {
    ...init,
    headers: { 'Content-Type': 'application/json', ...authHeaders(), ...init?.headers },
  })
  if (res.status === 401) {
    localStorage.removeItem('cc_token')
    window.location.href = '/login'
    throw new Error('Unauthorized')
  }
  if (!res.ok) {
    const body = await res.json().catch(() => ({}))
    throw new Error(body.detail || `HTTP ${res.status}`)
  }
  if (res.status === 204) return undefined as T
  return res.json()
}

// ── Types ────────────────────────────────────────────────────────────

export interface AdminUser {
  id: string
  username: string
  full_name: string
  email: string | null
  role: string
  is_active: boolean
  created_at: string | null
}

export interface Client {
  id: string
  name: string
  code: string
  db_host: string
  db_port: number
  db_name: string
  db_username: string
  db_use_tls: boolean
  status: string
  notes: string | null
  last_connected_at: string | null
  last_known_alembic_head: string | null
  created_at: string
  updated_at: string
}

export interface ClientSummary {
  id: string
  name: string
  code: string
  status: string
  max_licenses: number | null
  last_connected_at: string | null
  last_known_alembic_head: string | null
}

export interface ConnectionTestResult {
  success: boolean
  message: string
  alembic_head: string | null
  companies: { id: string; name: string; registration_number: string | null }[] | null
}

export interface Advertisement {
  id: string
  tag: string | null
  text: string
  sort_order: number
  is_active: boolean
  created_at: string
  assignments: { client_id: string; pushed_at: string | null }[]
}

export interface VideoSetting {
  id: string
  video_url: string | null
  label: string
  is_active: boolean
  created_at: string
}

export interface ClientModule {
  module_key: string
  module_name: string
  is_built: boolean
  enabled: boolean
  license_type: string
  notes: string | null
  updated_at: string | null
  company_id: string
  company_name: string
}

export interface ConfigUpdate {
  id: string
  title: string
  description: string | null
  sql_statement: string
  status: string
  created_at: string
  updated_at: string
  push_logs: {
    id: string
    client_id: string
    success: boolean
    error_message: string | null
    pushed_at: string
  }[]
}

export interface PushLogEntry {
  id: string
  client_id: string
  push_type: string
  detail: string
  success: boolean
  error_message: string | null
  pushed_at: string
  pushed_by: string | null
}

export interface DashboardStats {
  total_clients: number
  active_clients: number
  suspended_clients: number
  total_ads: number
  active_ads: number
  total_config_updates: number
  pending_pushes: number
  recent_pushes: PushLogEntry[]
}

export interface PushResult {
  results: { client: string; success: boolean; error?: string; count?: number }[]
}

// ── Version Control ──────────────────────────────────────────────
export interface ERPVersion {
  id: string
  version_number: string
  alembic_head: string
  release_notes: string | null
  status: string
  is_latest: boolean
  released_at: string | null
  created_at: string
}

export interface ClientVersionInfo {
  client_id: string
  client_name: string
  client_code: string
  current_alembic_head: string | null
  current_version: string | null
  latest_version: string | null
  is_up_to_date: boolean
  status: string
}

export interface UpgradeLog {
  id: string
  client_id: string
  from_version: string | null
  to_version: string
  to_alembic_head: string
  success: boolean
  error_message: string | null
  upgraded_at: string
  upgraded_by: string | null
}

// ── Staff / Support Logins ───────────────────────────────────────
export interface SupportLogin {
  id: string
  admin_user_id: string
  client_id: string
  login_email: string
  client_user_id: string | null
  status: string
  reason: string | null
  pushed_at: string
  pushed_by: string | null
  revoked_at: string | null
}

// ── API methods ──────────────────────────────────────────────────────

// ── Auth response types ─────────────────────────────────────────────
export interface LoginResponse {
  status: 'ok' | 'otp_required'
  access_token?: string
  full_name?: string
  otp_session?: string
  email_sent?: boolean
  email_hint?: string | null
  _dev_otp?: string
}

export interface VerifyOTPResponse {
  status: 'ok'
  access_token: string
  full_name: string
}

export interface ForgotPasswordResponse {
  status: string
  message: string
  email_hint?: string | null
  _dev_otp?: string
}

export interface ForgotUsernameResponse {
  status: string
  message: string
  _dev_username?: string
}

export const api = {
  // Auth
  login: (username: string, password: string) =>
    request<LoginResponse>('/auth/login', {
      method: 'POST',
      body: JSON.stringify({ username, password }),
    }),
  verifyOtp: (otp_session: string, otp_code: string) =>
    request<VerifyOTPResponse>('/auth/verify-otp', {
      method: 'POST',
      body: JSON.stringify({ otp_session, otp_code }),
    }),
  forgotPassword: (username: string) =>
    request<ForgotPasswordResponse>('/auth/forgot-password', {
      method: 'POST',
      body: JSON.stringify({ username }),
    }),
  resetPassword: (username: string, otp_code: string, new_password: string) =>
    request<{ status: string; message: string }>('/auth/reset-password', {
      method: 'POST',
      body: JSON.stringify({ username, otp_code, new_password }),
    }),
  forgotUsername: (email: string) =>
    request<ForgotUsernameResponse>('/auth/forgot-username', {
      method: 'POST',
      body: JSON.stringify({ email }),
    }),
  me: () => request<AdminUser>('/auth/me'),

  // Dashboard
  getDashboard: () => request<DashboardStats>('/dashboard/'),

  // Clients
  listClients: () => request<ClientSummary[]>('/clients/'),
  getClient: (id: string) => request<Client>(`/clients/${id}`),
  createClient: (data: Partial<Client>) =>
    request<Client>('/clients/', { method: 'POST', body: JSON.stringify(data) }),
  updateClient: (id: string, data: Partial<Client>) =>
    request<Client>(`/clients/${id}`, { method: 'PATCH', body: JSON.stringify(data) }),
  deleteClient: (id: string) =>
    request<void>(`/clients/${id}`, { method: 'DELETE' }),
  testConnection: (id: string) =>
    request<ConnectionTestResult>(`/clients/${id}/test-connection`, { method: 'POST' }),

  // Advertisements
  listAds: () => request<Advertisement[]>('/advertisements/'),
  createAd: (data: { tag?: string; text: string; sort_order?: number; client_ids?: string[] }) =>
    request<Advertisement>('/advertisements/', { method: 'POST', body: JSON.stringify(data) }),
  updateAd: (id: string, data: Partial<Advertisement & { client_ids?: string[] }>) =>
    request<Advertisement>(`/advertisements/${id}`, { method: 'PATCH', body: JSON.stringify(data) }),
  deleteAd: (id: string) =>
    request<void>(`/advertisements/${id}`, { method: 'DELETE' }),
  pushAd: (id: string) =>
    request<PushResult>(`/advertisements/${id}/push`, { method: 'POST' }),
  pushAllAds: () =>
    request<PushResult>('/advertisements/push-all', { method: 'POST' }),

  // Videos
  listVideos: () => request<VideoSetting[]>('/advertisements/videos'),
  createVideo: (data: { video_url?: string; label: string; client_ids?: string[] }) =>
    request<VideoSetting>('/advertisements/videos', { method: 'POST', body: JSON.stringify(data) }),
  pushVideo: (id: string) =>
    request<PushResult>(`/advertisements/videos/${id}/push`, { method: 'POST' }),

  // Licenses
  getClientModules: (clientId: string) =>
    request<ClientModule[]>(`/licenses/${clientId}/modules`),
  setModuleLicense: (clientId: string, companyId: string, data: { module_key: string; enabled: boolean; license_type?: string; notes?: string }) =>
    request<{ success: boolean }>(`/licenses/${clientId}/companies/${companyId}/modules`, {
      method: 'POST',
      body: JSON.stringify(data),
    }),
  updateLicenseLimit: (clientId: string, maxLicenses: number | null) =>
    request<{ success: boolean; max_licenses: number | null }>(`/licenses/${clientId}/license-limit`, {
      method: 'PATCH',
      body: JSON.stringify({ max_licenses: maxLicenses }),
    }),
  pushLicenseLimit: (clientId: string) =>
    request<{ success: boolean; max_licenses: number | null }>(`/licenses/${clientId}/license-limit/push`, {
      method: 'POST',
    }),

  // Config Updates
  listConfigUpdates: () => request<ConfigUpdate[]>('/config-updates/'),
  getConfigUpdate: (id: string) => request<ConfigUpdate>(`/config-updates/${id}`),
  createConfigUpdate: (data: { title: string; description?: string; sql_statement: string }) =>
    request<ConfigUpdate>('/config-updates/', { method: 'POST', body: JSON.stringify(data) }),
  updateConfigUpdate: (id: string, data: Partial<ConfigUpdate>) =>
    request<ConfigUpdate>(`/config-updates/${id}`, { method: 'PATCH', body: JSON.stringify(data) }),
  pushConfigUpdate: (id: string) =>
    request<PushResult & { total: number; successes: number }>(`/config-updates/${id}/push`, { method: 'POST' }),
  pushConfigToClient: (updateId: string, clientId: string) =>
    request<{ success: boolean }>(`/config-updates/${updateId}/push/${clientId}`, { method: 'POST' }),

  // Version Control
  listVersions: () => request<ERPVersion[]>('/versions/'),
  createVersion: (data: { version_number: string; alembic_head: string; release_notes?: string }) =>
    request<ERPVersion>('/versions/', { method: 'POST', body: JSON.stringify(data) }),
  updateVersion: (id: string, data: Partial<ERPVersion>) =>
    request<ERPVersion>(`/versions/${id}`, { method: 'PATCH', body: JSON.stringify(data) }),
  deleteVersion: (id: string) =>
    request<void>(`/versions/${id}`, { method: 'DELETE' }),
  getClientVersions: () => request<ClientVersionInfo[]>('/versions/clients'),
  upgradeClient: (clientId: string, versionId: string) =>
    request<{ success: boolean; client: string; to_version: string }>(`/versions/clients/${clientId}/upgrade`, {
      method: 'POST',
      body: JSON.stringify({ version_id: versionId }),
    }),
  getUpgradeLogs: (clientId?: string) =>
    request<UpgradeLog[]>(`/versions/upgrade-logs${clientId ? `?client_id=${clientId}` : ''}`),

  // Staff Management
  listStaff: () => request<AdminUser[]>('/staff/'),
  createStaff: (data: { username: string; full_name: string; email?: string; password: string; role: string }) =>
    request<AdminUser>('/staff/', { method: 'POST', body: JSON.stringify(data) }),
  updateStaff: (id: string, data: Partial<{ full_name: string; email: string; role: string; is_active: boolean; password: string }>) =>
    request<AdminUser>(`/staff/${id}`, { method: 'PATCH', body: JSON.stringify(data) }),
  deleteStaff: (id: string) =>
    request<void>(`/staff/${id}`, { method: 'DELETE' }),

  // Support Logins
  listSupportLogins: (clientId?: string) =>
    request<SupportLogin[]>(`/staff/support-logins${clientId ? `?client_id=${clientId}` : ''}`),
  pushSupportLogin: (data: { client_id: string; admin_user_id: string; login_email: string; login_password: string; reason?: string }) =>
    request<{ success: boolean; client: string; login_email: string }>('/staff/support-logins/push', {
      method: 'POST',
      body: JSON.stringify(data),
    }),
  revokeSupportLogin: (loginId: string) =>
    request<{ success: boolean }>(`/staff/support-logins/${loginId}/revoke`, { method: 'POST' }),
}
