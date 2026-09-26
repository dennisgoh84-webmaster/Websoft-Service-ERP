// Thin API client for the Websoft Service ERP Solution backend.
// Talks to FastAPI via the Vite dev-server proxy (/api -> :8000).

import { getDeviceId } from './deviceId'

const TOKEN_KEY = 'websoft_token'

export function getToken(): string | null {
  return localStorage.getItem(TOKEN_KEY)
}

export function setToken(token: string) {
  localStorage.setItem(TOKEN_KEY, token)
}

export function clearToken() {
  localStorage.removeItem(TOKEN_KEY)
}

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const token = getToken()
  const headers: Record<string, string> = {
    ...(options.body ? { 'Content-Type': 'application/json' } : {}),
    ...(token ? { Authorization: `Bearer ${token}` } : {}),
    'X-Device-Id': getDeviceId(),
  }
  const res = await fetch(`/api${path}`, { ...options, headers })
  if (!res.ok) {
    let detail = res.statusText
    try {
      const body = await res.json()
      detail = body.detail ?? detail
    } catch {
      /* ignore */
    }
    throw new Error(detail)
  }
  if (res.status === 204) return undefined as T
  return res.json() as Promise<T>
}

function qs(params: Record<string, string | number | boolean | undefined>): string {
  const entries = Object.entries(params).filter(([, v]) => v !== undefined && v !== '')
  if (entries.length === 0) return ''
  return '?' + new URLSearchParams(entries.map(([k, v]) => [k, String(v)])).toString()
}

async function requestBlob(path: string): Promise<Blob> {
  const token = getToken()
  const res = await fetch(`/api${path}`, {
    headers: {
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      'X-Device-Id': getDeviceId(),
    },
  })
  if (!res.ok) throw new Error('Export failed')
  return res.blob()
}

/** Triggers a browser download for an already-fetched file (CSV/Excel/
 * Word/...) -- the shared second half of every "Export" button. */
export function downloadBlob(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  document.body.appendChild(a)
  a.click()
  a.remove()
  URL.revokeObjectURL(url)
}

// Login sequence (2026-09-12: forced first-login password change + email
// OTP second factor; 2026-09-16: WhatsApp added as a second, optional OTP
// channel -- see backend AuthController::issueLoginResult()'s docblock for
// the three-case decision this shape encodes). Only one of
// access_token/change_token/otp_token/channel_token is ever set, matching
// `status`; Login.tsx drives the multi-step UI off this shape.
//
// `otp_channel_required` only ever happens when a user has BOTH email and
// WhatsApp available (see availableOtpChannels()) -- with only one channel
// available, the backend sends on it immediately and returns `otp_required`
// exactly as it always has, so this step is invisible to any account/install
// that isn't using WhatsApp OTP.
export interface LoginResult {
  status: 'ok' | 'must_change_password' | 'otp_required' | 'otp_channel_required'
  access_token?: string
  token_type?: string
  change_token?: string
  otp_token?: string
  channel_token?: string
  available_channels?: Array<'email' | 'whatsapp'>
  /** Set alongside otp_token: which channel the code was actually sent on. */
  channel?: 'email' | 'whatsapp'
}

export async function login(email: string, password: string): Promise<LoginResult> {
  const body = new URLSearchParams({ username: email, password })
  const res = await fetch('/api/auth/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Device-Id': getDeviceId() },
    body,
  })
  if (!res.ok) {
    let detail = 'Invalid email or password'
    try {
      const errBody = await res.json()
      detail = errBody.detail ?? detail
    } catch {
      /* ignore */
    }
    throw new Error(detail)
  }
  return res.json() as Promise<LoginResult>
}

export async function verifyOtp(otpToken: string, code: string): Promise<LoginResult> {
  return request<LoginResult>('/auth/verify-otp', {
    method: 'POST',
    body: JSON.stringify({ otp_token: otpToken, code }),
  })
}

/** The second half of the `otp_channel_required` step -- sends the code
 * on whichever channel the user picked. Returns the same `otp_required`
 * shape login() itself returns when there's only one channel, so
 * Login.tsx's advance() handles both the same way. */
export async function sendOtp(channelToken: string, channel: 'email' | 'whatsapp'): Promise<LoginResult> {
  return request<LoginResult>('/auth/send-otp', {
    method: 'POST',
    body: JSON.stringify({ channel_token: channelToken, channel }),
  })
}

export async function changePassword(changeToken: string, newPassword: string): Promise<LoginResult> {
  return request<LoginResult>('/auth/change-password', {
    method: 'POST',
    body: JSON.stringify({ change_token: changeToken, new_password: newPassword }),
  })
}

// "Forget password" (2026-09-12) -- a self-contained email+OTP pair,
// separate from the login sequence above. forgotPassword always
// resolves the same way (never reveals whether the email exists);
// resetPasswordWithOtp sets the new password directly once the code
// matches, no separate token exchange needed.
export interface MessageResponse {
  message: string
}

export async function forgotPassword(email: string): Promise<MessageResponse> {
  return request<MessageResponse>('/auth/forgot-password', {
    method: 'POST',
    body: JSON.stringify({ email }),
  })
}

export async function resetPasswordWithOtp(
  email: string,
  code: string,
  newPassword: string,
): Promise<MessageResponse> {
  return request<MessageResponse>('/auth/reset-password-otp', {
    method: 'POST',
    body: JSON.stringify({ email, code, new_password: newPassword }),
  })
}

// ---- Types (mirroring backend Pydantic schemas) ----
export type UserRole =
  | 'owner'
  | 'service_lead'
  | 'sales_manager'
  | 'sales_supervisor'
  | 'sales_staff'
  | 'support_engineer'
  | 'finance'

/** Display names for roles -- Staff Master, and wherever a role is shown. */
export const ROLE_LABELS: Record<UserRole, string> = {
  owner: 'Owner',
  service_lead: 'Service Lead',
  sales_manager: 'Sales Manager',
  sales_supervisor: 'Sales Supervisor',
  sales_staff: 'Sales Staff',
  support_engineer: 'Support Engineer',
  finance: 'Finance',
}

/** Owner, Sales Manager and Sales Supervisor see every prospect; everyone else their own. */
export function seesAllProspects(role: string | undefined): boolean {
  return role === 'owner' || role === 'sales_manager' || role === 'sales_supervisor'
}

export interface CurrentUser {
  id: string
  full_name: string
  email: string
  role: UserRole
  group_id: string | null
  company_id: string | null
  /** PDPA self-declaration for the AI Assistant (2026-09-15): true until
   * this user has ticked the one-time acknowledgement at login. */
  ai_data_consent_required: boolean
}

/** Login page branding (2026-09-12) -- deliberately just these two
 * fields, returned by the one unauthenticated company endpoint so
 * nothing sensitive (address, UEN, GST no.) is ever exposed pre-login. */
export interface PublicBranding {
  name: string
  logo: string | null
}

// ---- Announcements / ad banner (2026-09-12: "is there a place for me
// to set all these advertisements or latest updates and push
// publish") -- see PromoVideoPanel.tsx and AnnouncementsPage.tsx.
// Save = live immediately, no separate publish step.
export interface Announcement {
  id: string
  tag: string | null
  text: string
  sort_order: number
  is_active: boolean
  /** 'central' = pushed by Central Command, read-only here (one-way);
   * 'local' = this company's own announcement, editable and never sent back. */
  source: 'central' | 'local'
  created_at: string
}

/** The two independent promo-video settings (2026-09-16 split): `login`
 * is shown on the Login page before signing in, `app` is the banner
 * shown alongside the sidebar on every page after signing in. */
export type AdBannerSlot = 'login' | 'app'

/** Read by the Login page and the app-wide ad banner alike -- the one
 * unauthenticated view (only active announcements, already ordered). */
export interface PublicAdBanner {
  video_url: string | null
  items: Announcement[]
}

/** Maintenance -> Announcements & Ad Banner's admin view of the video slot --
 * either an uploaded file or an external URL, never both (see the backend's
 * AdBannerSettings model docblock for why). `video_url` here is already the
 * fully playable URL either way. */
export interface AdBannerSettingsInfo {
  video_url: string | null
  video_source: 'upload' | 'url' | 'none'
  video_original_filename: string | null
  video_file_size_bytes: number | null
  /** True while Central Command owns this slot's video: the form is locked
   * and every write to it is refused (403) until Central Command releases
   * the slot by pushing an empty URL. */
  managed_by_central_command: boolean
}

// ---- Company Setup / multi-company ----
export interface Company {
  id: string
  /** System-generated, C001 / C002 / ... in creation order; never edited. */
  code: string
  name: string
  country: string
  currency: string
  timezone: string
  logo: string | null
  address: string | null
  gst_registration_no: string | null
  phone: string | null
  website: string | null
  uen: string | null
  /** Null means "always require owner approval" -- no threshold set yet. */
  /** 1-12. Labelled by the year it ENDS in: 7 means FY2027 = Jul 2026 - Jun 2027. */
  financial_year_start_month: number
  // This company's own outbound mailbox, for the customer-facing "Email
  // X" document buttons. Separate from the system mailbox in .env that
  // sends login codes and password resets; neither falls back to the
  // other. The password is write-only: never returned, only its
  // presence is.
  smtp_host: string | null
  smtp_port: number
  smtp_username: string | null
  smtp_use_tls: boolean
  smtp_from_email: string | null
  smtp_from_name: string | null
  smtp_password_set: boolean
  is_active: boolean
  created_at: string
}

// ---- Staff Master ----
export interface StaffUser {
  id: string
  username: string
  full_name: string
  email: string
  role: UserRole
  group_id: string | null
  /** Data URI, e.g. "data:image/png;base64,..." -- shown on Staff Master and Support Monitoring. */
  photo: string | null
  /** Confirmed 2026-09-12: true for a new hire, or right after an admin
   * password reset, until they set their own password at next sign-in. */
  must_change_password: boolean
  /** Admin-forced password change on next login. */
  force_password_change_on_login: boolean
  is_active: boolean
  created_at: string
  /** PDPA self-declaration for the AI Assistant -- when this staff member
   * first acknowledged it, or null if not yet. Read-only: shown on Staff
   * Master, never editable there or anywhere else. */
  ai_data_consent_at: string | null
}

export interface AuditLogEntry {
  id: string
  entity_type: string
  entity_id: string
  action: string
  actor_user_id: string | null
  actor_name: string | null
  reason: string | null
  details: string | null
  old_value: string | null
  new_value: string | null
  ip_address: string | null
  user_agent: string | null
  device_id: string | null
  at: string
}

export interface EventLogFilters {
  entity_type?: string
  action?: string
  actor_user_id?: string
  date_from?: string
  date_to?: string
  q?: string
  [key: string]: string | number | undefined
}

/** One company a staff member may work in, and their Group there.
 * Group is per company -- see Company Setup / Group Authority. */
export interface UserCompanyAccess {
  company_id: string
  company_name: string
  group_id: string | null
  group_name: string | null
}

// ---- Group Authority ----
export type AccessLevel = 'none' | 'view' | 'edit' | 'full'

export interface GroupAuthority {
  module_key: string
  access_level: AccessLevel
}

export interface Group {
  id: string
  name: string
  description: string | null
  created_at: string
  authorities: GroupAuthority[]
  member_count: number
}

export type CompanyIndividualType = 'individual' | 'company'

export interface CompanyIndividualGroup {
  id: string
  name: string
  description: string | null
  is_active: boolean
  created_at: string
}

export interface CompanyIndividual {
  id: string
  customer_type: CompanyIndividualType
  name: string
  /** Tag linking this customer to others in the same group of
   * companies -- each stays its own full account. */
  customer_group_id: string | null
  /** The customer's code from the Odoo system being replaced -- manual,
   * for matching during the eventual historical-data migration. */
  legacy_customer_code: string | null
  contact_person: string | null
  uen: string | null
  gst_registration_no: string | null
  billing_email: string | null
  phone: string | null
  mobile: string | null
  website: string | null
  address_line1: string | null
  address_line2: string | null
  address_city: string | null
  address_state: string | null
  address_postal_code: string | null
  address_country: string | null
  tags: string | null
  /** Setup Lists code (list_type=industry) -- optional, for grouping/
   * filtering customers by industry. */
  industry_code: string | null
  /** Reserved -- nothing reads this yet, no automated emailing exists. */
  exclude_auto_sent: boolean
  terms_and_conditions: string | null
  /** Internal-only note, never shown on any customer-facing document. */
  memo: string | null
  /** Billing/AR-specific note (e.g. "requires PO number on invoice"). */
  billing_notes: string | null
  /** Days from invoice date. Terms vary per customer; null = not agreed yet. */
  payment_terms_days: number | null
  /** Approval limits for this party (2026-09-26). Null = the owner approves every one. */
  po_approval_limit_sgd: number | null
  credit_note_approval_limit_sgd: number | null
  /** The most this customer may owe at once -- a separate setting from the credit note limit. */
  credit_limit_sgd: number | null
  /** On the single-record read only: what it owes now, and whether that is over its credit limit. */
  outstanding_sgd?: number
  over_credit_limit?: boolean
  /** Role flags (2026-09-12): a record can be a customer, a supplier, or
   * both -- Purchase Order/Accounts Payable pick from is_supplier=true
   * records here rather than a separate supplier file. */
  is_customer: boolean
  is_supplier: boolean
  /** PDPA (2026-09-12): whether the PDPA Agreement has been e-signed,
   * and when -- `pdpa_consent_at` is stamped by the server, never set
   * directly (see api.setPdpaConsent). */
  pdpa_consent_given: boolean
  pdpa_consent_at: string | null
  /** The uploaded signed agreement itself -- a data URI (image or PDF),
   * or null if none has been uploaded yet. See api.setPdpaAgreementDocument. */
  pdpa_agreement_document: string | null
  /** After this date, all of this record's data should be archived
   * (see api.archiveCompanyIndividual). Null = no expiry agreed yet. */
  data_expiry_date: string | null
  /** Soft-archive-in-place -- the record and all its data stay in the
   * same database row, just hidden from normal lists. */
  is_archived: boolean
  archived_at: string | null
  is_active: boolean
  created_at: string
}

export interface Contact {
  id: string
  customer_id: string
  name: string
  email: string | null
  phone: string | null
  /** Direct dial line, distinct from the general phone (mobile/shared). */
  direct_line: string | null
  is_active: boolean
}

/**
 * The staff-side view of one Contact's Helpdesk Portal login
 * (docs/customer-portal-design.md, PORTAL-001..004). Portal users are a
 * separate auth realm -- they live in their own table and never in
 * staff `users` -- so this is the only place a portal login is created.
 */
export interface PortalAccess {
  enabled: boolean
  email: string | null
  must_change_password: boolean | null
  last_login_at: string | null
  /** True while the 5-wrong-passwords lockout is still running. */
  locked: boolean
  /**
   * Shown ONCE, immediately after enable or reset, and only when SMTP
   * is not configured -- otherwise the temporary password goes out by
   * email instead and this is null.
   */
  temporary_password: string | null
  invited_by_email: boolean
}

/**
 * One of the two system-level mailboxes (Maintenance -> System Email):
 * `otp` (sign-in codes, password resets, portal invites) and `helpdesk`
 * (the Outlook Add-in's Incident / Job Order acknowledgements). The
 * password is write-only; `source` says where the live settings for
 * this purpose currently come from.
 */
export interface SystemMailbox {
  purpose: 'otp' | 'helpdesk'
  host: string | null
  port: number
  username: string | null
  use_tls: boolean
  from_email: string | null
  from_name: string | null
  password_set: boolean
  configured: boolean
  source: 'database' | 'env' | 'none'
  // IMAP: reads this same mailbox, alongside the SMTP fields above that send from it.
  imap_host: string | null
  imap_port: number
  imap_username: string | null
  imap_use_ssl: boolean
  imap_password_set: boolean
  imap_configured: boolean
}

export interface ImapMailboxPayload {
  imap_host?: string | null
  imap_port?: number
  imap_username?: string | null
  imap_password?: string | null
  imap_use_ssl?: boolean
}

export interface Branch {
  id: string
  customer_id: string
  branch_name: string
  branch_code: string | null
  address_line1: string | null
  address_line2: string | null
  address_city: string | null
  address_state: string | null
  address_postal_code: string | null
  address_country: string | null
  phone: string | null
  is_active: boolean
}

export interface CompanyIndividualRelationship {
  id: string
  from_customer_id: string
  to_customer_id: string | null
  to_customer_name: string | null
  to_customer_type: CompanyIndividualType | null
  to_contact_id: string | null
  to_contact_name: string | null
  to_contact_customer_id: string | null
  to_contact_customer_name: string | null
  relationship_type: string
  note: string | null
  is_active: boolean
  created_at: string
}

export type CompanyIndividualFields = Partial<{
  customer_type: CompanyIndividualType
  name: string
  customer_group_id: string | null
  legacy_customer_code: string | null
  contact_person: string | null
  uen: string | null
  gst_registration_no: string | null
  billing_email: string | null
  phone: string | null
  mobile: string | null
  website: string | null
  address_line1: string | null
  address_line2: string | null
  address_city: string | null
  address_state: string | null
  address_postal_code: string | null
  address_country: string | null
  tags: string | null
  industry_code: string | null
  exclude_auto_sent: boolean
  terms_and_conditions: string | null
  memo: string | null
  billing_notes: string | null
  payment_terms_days: number | null
  po_approval_limit_sgd: number | null
  credit_note_approval_limit_sgd: number | null
  credit_limit_sgd: number | null
  is_customer: boolean
  is_supplier: boolean
  data_expiry_date: string | null
}>

export type ContractStatus = 'draft' | 'active' | 'exceeded' | 'expired' | 'renewed'

/** The contract type decides its "offset method": service_support
 * deducts hours, annual is time-coverage only (no hours), ad_hoc has
 * neither -- work is billed off the contract's reference hourly_rate_sgd. */
export type ContractKind = 'service_support' | 'annual' | 'ad_hoc'

export type LicenseDeploymentType = 'local' | 'rdp' | 'web'

export interface ContractProductCoverage {
  product_id: string
  product_name: string
  /** License tracking (2026-09-12): set only when this covered product
   * is a licensed software item. */
  license_type: LicenseDeploymentType | null
  number_of_licenses: number | null
}

// NEW FEATURE (not a Python->PHP conversion -- see
// docs/backlog.md / docs/planned-work.md): "Service Contract - To
// have selection of Sharing of Hours with multiple company".
export interface ContractSharedCustomer {
  id: string
  customer_id: string
  customer_name: string
}

export interface Contract {
  id: string
  contract_number: string
  customer_id: string
  status: ContractStatus
  contract_kind: ContractKind
  contracted_hours: number
  consumed_hours: number
  remaining_hours: number
  contract_value_sgd: number
  /** Reference rate for ad_hoc contracts only; null otherwise. */
  hourly_rate_sgd: number | null
  sales_staff_id: string | null
  start_date: string
  end_date: string
  renewed_from_contract_id: string | null
  products: ContractProductCoverage[]
  /** NEW FEATURE (not a Python->PHP conversion) -- see docs/backlog.md / docs/planned-work.md. */
  shared_customers: ContractSharedCustomer[]
  /** Legacy free-text reference from before the real link existed (2026-09-15); shown when set, no longer entered. */
  quotation_reference: string | null
  quotation_reference_set_at: string | null
  /** The Sales Quotation this contract came from -- set by accepting a quotation, or linked by hand. */
  quotation_id: string | null
  quotation_number: string | null
  /** The open renewal quotation raised from this contract, if any (Create renewal quotation). */
  renewal_quotation_id: string | null
  renewal_quotation_number: string | null
  renewal_quotation_status: QuotationStatus | null
  /** Coming due (date within SRV-014's 30 days, hours finishing, expired or exceeded) with no open renewal quotation. */
  renewal_quotation_eligible: boolean
  /** Why it is coming due, e.g. "expires on 2026-09-25 (10 days)" or "1.5 of 20.0 hours left"; null when it is not. */
  renewal_due_reason: string | null
}

export type JobOrderPriority = 'low' | 'normal' | 'high' | 'critical'
/** No manual "Resolved" step any more -- a Job Order auto-closes when
 * its most recent Service Record is Approved and marked Completed
 * ('C'). VOID is a manual dead-end for a job that should never have
 * been raised (duplicate, raised in error). */
export type JobOrderStatus = 'open' | 'assigned' | 'closed' | 'void'
export type JobOrderType = 'support' | 'project'
/** SRV-020: what an approved Service Record's time on this Job Order is. */
export type JobOrderBillingClassification = 'contract' | 'billable' | 'non_billable'

export type MilestoneType = 'installation' | 'training' | 'repeat_training' | 'handover' | 'completion_signoff'
export type MilestoneStatus = 'pending' | 'in_progress' | 'completed' | 'skipped'

export interface ProjectMilestone {
  id: string
  job_order_id: string
  milestone_type: MilestoneType
  label: string
  sort_order: number
  planned_start: string | null
  planned_end: string | null
  actual_start: string | null
  actual_end: string | null
  assigned_user_id: string | null
  status: MilestoneStatus
  notes: string | null
  created_at: string
}

export interface BudgetOverrunStatus {
  is_over_hours: boolean
  is_over_cost: boolean
  consumed_minutes: number
  contracted_minutes: number
  consumed_cost_sgd: number
  contract_value_sgd: number
}

// NEW FEATURE (not a Python->PHP conversion -- see
// docs/backlog.md / docs/planned-work.md): "Job Order - To allow
// choosing of multiple Products and Template to import according to
// Product".
export interface JobOrderProductSelection {
  product_id: string
  product_name: string
}

export type JobOrderImplementationTaskStatus = 'pending' | 'completed'

export interface JobOrderImplementationTask {
  id: string
  job_order_id: string
  source_product_id: string | null
  task_name: string
  description: string | null
  sort_order: number
  status: JobOrderImplementationTaskStatus
  completed_by_user_id: string | null
  completed_at: string | null
}

export interface JobOrder {
  id: string
  job_order_number: string
  customer_id: string
  contract_id: string | null
  subject: string
  job_order_type: JobOrderType
  /** SRV-020: contract (the contract balance decides), billable (charged
   * outside the hour pool, never deducted) or non_billable (nothing
   * deducted, nothing billed). Staff never choose this per record. */
  billing_classification: JobOrderBillingClassification
  priority: JobOrderPriority
  status: JobOrderStatus
  /** "Tick as Urgent" -- suggests a x1.5 deduction-minutes multiplier on approval. */
  is_urgent: boolean
  assigned_to_user_id: string | null
  /** Manual, optional -- set by Sales/Coordinator after discussion with Support. */
  due_date: string | null
  void_reason: string | null
  budget_overrun_approved: boolean
  budget_overrun_approved_by: string | null
  budget_overrun_approved_at: string | null
  created_at: string
  closed_at: string | null
  milestones: ProjectMilestone[]
  budget_overrun: BudgetOverrunStatus | null
  products: JobOrderProductSelection[]
  implementation_tasks: JobOrderImplementationTask[]
}

// ---- Operations/Accounting Reports filters ----
export interface ContractReportFilters {
  /** Internal Companies, comma-separated (2026-09-25); none = the signed-in company. */
  company_ids?: string
  status?: ContractStatus
  contract_kind?: ContractKind
  customer_id?: string
  /** One or several, comma-separated (2026-09-24). */
  customer_ids?: string
  expiring_within_days?: number
  start_date?: string
  end_date?: string
  [key: string]: string | number | boolean | undefined
}

export interface JobOrderReportFilters {
  /** Internal Companies, comma-separated (2026-09-25); none = the signed-in company. */
  company_ids?: string
  status?: JobOrderStatus
  customer_id?: string
  /** One or several, comma-separated (2026-09-24). */
  customer_ids?: string
  assigned_to_user_id?: string
  overdue_only?: boolean
  start_date?: string
  end_date?: string
  [key: string]: string | number | boolean | undefined
}

export interface ServiceRecordReportFilters {
  /** Internal Companies, comma-separated (2026-09-25); none = the signed-in company. */
  company_ids?: string
  status?: ServiceRecordStatus
  outcome?: ServiceRecordOutcome
  customer_id?: string
  /** One or several, comma-separated (2026-09-24). */
  customer_ids?: string
  employee_user_id?: string
  start_date?: string
  end_date?: string
  [key: string]: string | number | boolean | undefined
}

/** Confirmed 2026-09-11: "check customer using which product" --
 * visibility only, one row per (customer, product) currently covered
 * under a contract's Product Coverage. */
export interface CompanyIndividualProductUsageFilters {
  /** Internal Companies, comma-separated (2026-09-25); none = the signed-in company. */
  company_ids?: string
  customer_id?: string
  /** One or several, comma-separated (2026-09-24). */
  customer_ids?: string
  product_id?: string
  industry_code?: string
  [key: string]: string | number | boolean | undefined
}

export interface CompanyIndividualProductUsageRow {
  company_id?: string
  company_name?: string
  customer_id: string
  customer_name: string
  industry_code: string | null
  industry_name: string
  product_id: string
  product_name: string
  contract_id: string
  contract_number: string
  contract_kind: ContractKind
  contract_status: ContractStatus
  start_date: string
  end_date: string
}

// ---- Support Monitoring ----
export interface StaffMonitoring {
  user_id: string
  full_name: string
  photo: string | null
  open_job_orders: number
  overdue_job_orders: number
  due_soon_job_orders: number
  pending_service_records: number
  untested_software_tasks: number
  cm_svc_records_month: number
  cm_svc_records_today: number
  cm_svc_hours_month: number
  cm_svc_hours_today: number
  avg_daily_contract_hours: number
}

export interface MonitoringSummary {
  total_job_orders: number
  total_open_job_orders: number
  total_overdue_job_orders: number
  unassigned_job_orders: number
  total_pending_service_records: number
  total_untested_software_tasks: number
}

export interface SupportMonitoring {
  as_at: string
  summary: MonitoringSummary
  staff: StaffMonitoring[]
  unassigned: StaffMonitoring
}

// ---- Software Task ----
export interface SoftwareTask {
  id: string
  title: string
  description: string | null
  modules_affected: string | null
  assigned_programmer_id: string | null
  programming_finish_date: string | null
  programming_hours: number | null
  tester_user_id: string | null
  is_tested: boolean
  /** Decision 12.1 (2026-09-26): Open -> Programming -> For Testing -> Tested -> Released. */
  status: SoftwareTaskStatus
  /** Where it may move next. */
  next_statuses: SoftwareTaskStatus[]
  released_at: string | null
  tested_at: string | null
  created_at: string
}

export type SoftwareTaskStatus = 'open' | 'programming' | 'for_testing' | 'tested' | 'released'

export interface ProgrammerCard {
  programmer_id: string | null
  name: string
  open: number
  awaiting_test: number
  overdue: number
  hours: number
}

// ---- Incident Module (2026-09-12) ----
export type IncidentSource = 'phone' | 'email' | 'other'
export type IncidentStatus = 'open' | 'pending_callback' | 'converted' | 'closed'

export interface Incident {
  id: string
  incident_number: string
  customer_id: string | null
  source: IncidentSource
  subject: string
  description: string | null
  sender_name: string | null
  sender_email: string | null
  sender_phone: string | null
  status: IncidentStatus
  assigned_to_user_id: string | null
  converted_quotation_id: string | null
  converted_job_order_id: string | null
  converted_software_task_id: string | null
  close_reason: string | null
  closed_at: string | null
  created_at: string
}

// AI Assistant (docs/planned-work.md #12, slice 1: incident triage).
export interface AiSettings {
  model: string
  redact_personal_data: boolean
  /** The assistant's name and face (slice 2). Avatar is a data URL or null. */
  assistant_name: string
  assistant_avatar: string | null
  /** Model tried once when the main one fails or declines (Backlog 2), or null for none. */
  fallback_model: string | null
  /** The signed-in company's monthly token cap (decision 12.2; per company since 2026-09-26), or null for unlimited. */
  monthly_token_cap: number | null
  /** The company's tokens used so far this calendar month (Asia/Singapore) -- what the cap above is checked against. */
  monthly_tokens_used: number
  /** The whole installation's tokens this month, shown beside the company's. */
  install_monthly_tokens_used: number
  api_key_set: boolean
  api_key_from_env: boolean
  updated_at: string | null
}

export interface AiUsageBucket {
  calls: number
  ok: number
  refused: number
  errors: number
  input_tokens: number
  output_tokens: number
}

export interface AiUsage {
  this_month: AiUsageBucket
  all_time: AiUsageBucket
  recent: {
    id: string
    created_at: string
    user_name: string
    channel: 'staff' | 'portal'
    feature: string
    entity_type: string | null
    entity_id: string | null
    model: string
    status: 'ok' | 'refused' | 'error'
    error: string | null
    input_tokens: number
    output_tokens: number
  }[]
}

export interface AiPersona {
  name: string
  avatar: string | null
}

export interface AiChatMessage {
  role: 'user' | 'assistant'
  content: string
}

export type AiChatContextType = 'incident' | 'customer' | 'contract' | 'job_order' | 'service_records'

export interface AiChatReply {
  answer: string
  refused: boolean
  tools_used: { name: string; summary: string }[]
  model: string
  input_tokens: number
  output_tokens: number
  interaction_id: string
}

export type IncidentTriageRoute = 'job_order' | 'quotation' | 'software_task' | 'callback' | 'close'

export interface IncidentTriageSuggestion {
  summary: string
  customer_id: string | null
  customer_name: string | null
  customer_confidence: 'high' | 'medium' | 'low' | 'none'
  customer_reason: string
  contract_id: string | null
  contract_number: string | null
  contract_hours_remaining: number | null
  priority: JobOrderPriority
  route: IncidentTriageRoute
  route_reason: string
  similar_incidents: {
    incident_id: string
    incident_number: string
    subject: string
    why_similar: string
    what_fixed_it: string | null
  }[]
  suggested_reply: string
  personal_data_redacted: boolean
}

export interface IncidentTriage {
  id: string
  incident_id: string
  created_at: string
  model: string
  status: 'ok' | 'refused' | 'error'
  error: string | null
  input_tokens: number
  output_tokens: number
  /** Null unless status is ok. */
  suggestion: IncidentTriageSuggestion | null
}

export interface IncidentFromEmailResult {
  incident: Incident
  job_order_created: boolean
  fallback_reason: string | null
}

export type ServiceRecordStatus = 'submitted' | 'approved'
export type ServiceRecordOutcome =
  | 'pending'
  | 'contract_deduction'
  | 'excess_usage'
  | 'not_hour_metered'
  /** SRV-020: the Job Order was classified billable / non-billable. */
  | 'billable'
  | 'non_billable'
/** 'C' = Completed (this visit finished the job), 'U' = Uncompleted
 * (another visit is needed) -- set by the submitter, drives Job Order
 * auto-close. */
export type ServiceRecordCompletion = 'C' | 'U'

export interface ServiceRecord {
  id: string
  service_record_number: string
  job_order_id: string
  employee_user_id: string
  work_date: string
  raw_minutes: number
  rounded_minutes: number
  /** Set by the approver at approval time; null until then. */
  deducted_minutes: number | null
  status: ServiceRecordStatus
  outcome: ServiceRecordOutcome
  completion_status: ServiceRecordCompletion
  is_after_hours: boolean
  is_late: boolean
  /** SRV-019: submitted_at + 7 days; null until submitted. */
  approval_due_at: string | null
  /** SRV-019: still Submitted more than a week after submission. */
  is_approval_overdue: boolean
  /** Free text describing the work done this session (2026-09-12) --
   * optional, spellchecked in the browser as it's typed. */
  work_description: string | null
}

/** One row on the Service Record Approval page -- a Submitted record
 * enriched with what the approver needs (job order, urgency, a
 * suggested deduction) without looking each thing up separately. */
export interface PendingServiceRecord {
  id: string
  service_record_number: string
  job_order_id: string
  job_order_number: string
  job_order_subject: string
  is_urgent: boolean
  employee_user_id: string
  employee_name: string
  work_date: string
  raw_minutes: number
  rounded_minutes: number
  completion_status: ServiceRecordCompletion
  is_after_hours: boolean
  suggested_deducted_minutes: number
  contract_remaining_minutes: number | null
  billing_classification: JobOrderBillingClassification
  is_late: boolean
  approval_due_at: string | null
  is_approval_overdue: boolean
}

export type ExcessTreatment =
  | 'billable'
  | 'approved_non_billable'
  | 'warranty_goodwill'
  | 'internal_write_off'
  | 'other'

export interface ExcessUsageRecord {
  id: string
  contract_id: string
  service_record_id: string
  excess_hours: number
  treatment: ExcessTreatment | null
  reason: string | null
  decided_by_user_id: string | null
  invoiced: boolean
}

export type InvoiceStatus = 'outstanding' | 'partially_paid' | 'paid' | 'written_off' | 'credited'

export interface InvoiceLine {
  id: string
  line_no: number
  description: string
  product_id: string | null
  stock_item_id: string | null
  warehouse_id: string | null
  quantity: number
  unit_of_measure: string | null
  unit_price_sgd: number
  line_amount_sgd: number
  /** Weighted average cost as at issue; null on a line moving no stock. */
  unit_cost_sgd: number | null
  cost_amount_sgd: number | null
}

/** A credit note against a Sales Invoice (BILL-003). The number is given when it is issued. */
export interface CreditNote {
  id: string
  credit_note_number: string | null
  invoice_id: string
  invoice_number: string
  customer_id: string
  customer_name: string
  reason: string
  amount_sgd: number
  tax_code: string | null
  gst_rate: number | null
  gst_amount_sgd: number
  total_amount_sgd: number
  status: 'pending_approval' | 'issued' | 'rejected' | 'withdrawn'
  raised_by: string | null
  raised_at: string
  decided_by: string | null
  decided_at: string | null
  decision_note: string | null
  issued_at: string | null
  /** The customer's credit note approval limit; null = the owner approves every one. */
  credit_note_limit_sgd: number | null
  needs_owner: boolean
  can_approve: boolean
  gl_status: 'posted' | 'not_posted'
  gl_voucher_number: string | null
}

export interface Invoice {
  id: string
  invoice_number: string
  customer_id: string
  contract_id: string | null
  invoice_type: string
  description: string
  /** Net of GST -- the revenue figure. */
  amount_sgd: number
  tax_code: string
  gst_rate: number
  gst_amount_sgd: number
  total_amount_sgd: number
  amount_paid_sgd: number
  /** Taken off by issued credit notes (BILL-003). */
  credited_sgd: number
  outstanding_sgd: number
  due_date: string | null
  status: InvoiceStatus
  is_disputed: boolean
  /** Brought in by Data Migration from ODOO or ZSOFT, as history. */
  migrated?: boolean
  dispute_note: string | null
  issued_at: string
  // GL posting (ACC-001)
  gl_status: 'posted' | 'reversed' | 'not_posted'
  gl_voucher_number: string | null
  /** Cost basis, when one is known. Null means unknown, not zero. */
  cost_sgd: number | null
  /**
   * Empty on every auto-issued invoice -- only a manually raised
   * Sales Invoice carries lines.
   */
  lines: InvoiceLine[]
}

// ---- Accounts Receivable ----
export interface PaymentAllocation {
  id: string
  invoice_id: string
  invoice_number: string | null
  amount_sgd: number
}

export interface Payment {
  id: string
  voucher_number: string
  /** Null on an Other receipt (bank interest and the like), which is against gl_account instead. */
  customer_id: string | null
  kind: 'customer' | 'other'
  gl_account_id: string | null
  /** "4910 Interest income" on an Other receipt. */
  gl_account: string | null
  payment_date: string
  amount_sgd: number
  allocated_sgd: number
  unallocated_sgd: number
  method: string
  reference: string | null
  notes: string | null
  allocations: PaymentAllocation[]
  // GL posting + Bank step (ACC-001..004)
  bank_account_id: string | null
  gl_status: 'posted' | 'reversed' | 'not_posted'
  gl_voucher_number: string | null
  bank_status: 'banked' | 'not_banked'
  bank_transaction_number: string | null
}

export interface AgingRow {
  /** Set by Accounting Reports, which can span several companies. */
  company_id?: string
  company_name?: string
  customer_id: string
  customer_name: string
  current: number
  days_1_30: number
  days_31_60: number
  days_61_90: number
  over_90: number
  total: number
}

export interface AgingReport {
  as_at: string
  /** Accounting Reports only: "C001 Name" for each company covered. */
  companies?: string[]
  rows: AgingRow[]
  current: number
  days_1_30: number
  days_31_60: number
  days_61_90: number
  over_90: number
  total: number
}

export interface StatementLine {
  invoice_id: string
  invoice_number: string
  description: string
  issued_on: string
  due_date: string | null
  total_amount_sgd: number
  amount_paid_sgd: number
  outstanding_sgd: number
  status: InvoiceStatus
  is_disputed: boolean
  days_overdue: number
}

export interface CompanyIndividualStatement {
  customer_id: string
  customer_name: string
  as_at: string
  payment_terms_days: number | null
  lines: StatementLine[]
  total_outstanding_sgd: number
  unallocated_credit_sgd: number
}

// ---- Chart of Accounts ----
export type AccountType = 'asset' | 'liability' | 'equity' | 'revenue' | 'expense'

export interface Account {
  id: string
  code: string
  name: string
  account_type: AccountType
  description: string | null
  is_active: boolean
}

// ---- GL Types ----
export interface GLType {
  id: string
  code: string
  name: string
  account_type: AccountType
  is_active: boolean
}

// ---- Reference Monitor (GL sub-codes under one Chart of Accounts row) ----
export interface ReferenceCode {
  id: string
  account_id: string
  account_code: string | null
  account_name: string | null
  code: string
  name: string
  is_active: boolean
  created_at: string
}

// ---- Setup Lists (Nationality / Country / State / Area Code / Currency / Industry) ----
export type SetupListType =
  | 'nationality'
  | 'country'
  | 'state'
  | 'city'
  | 'area_code'
  | 'currency'
  | 'industry'
  | 'product_category'
  | 'unit_of_measure'
  | 'relationship'

export interface SetupListItem {
  id: string
  list_type: SetupListType
  code: string
  name: string
  parent_code: string | null
  sort_order: number
  is_active: boolean
}

// ---- Currency Rate Table ----
export interface CurrencyRate {
  id: string
  currency_code: string
  rate_to_base: number
  effective_date: string
  is_active: boolean
}

// ---- Bank Master File ----
export interface BankAccount {
  id: string
  bank_name: string
  account_name: string
  account_number: string
  branch: string | null
  swift_code: string | null
  currency_code: string
  gl_account_id: string | null
  /** Bank Book (2026-09-12) -- see api.listBankTransactions/BankLedger.
   * A separate ledger from the General Ledger's Journal Vouchers. */
  opening_balance_sgd: number
  opening_balance_date: string | null
  /** Opening balance + every non-voided transaction to date -- computed
   * server-side, not something you set directly. */
  current_balance_sgd: number
  is_active: boolean
}

// ---- Bank Book: Bank Transactions + Bank Reconciliation ----
export interface BankTransaction {
  id: string
  bank_account_id: string
  transaction_number: string
  transaction_date: string
  description: string
  reference: string | null
  debit_sgd: number
  credit_sgd: number
  is_reconciled: boolean
  reconciled_at: string | null
  is_voided: boolean
  void_reason: string | null
  voided_at: string | null
  created_at: string
  running_balance_sgd: number
  // Set when the Bank step (ACC-002) created this line from a voucher.
  source_type: 'payment' | 'supplier_payment' | null
  source_id: string | null
}

export interface BankLedger {
  bank_account_id: string
  opening_balance_sgd: number
  opening_balance_date: string | null
  rows: BankTransaction[]
  closing_balance_sgd: number
  reconciled_balance_sgd: number
  unreconciled_count: number
}

export interface BankReconciliation {
  id: string
  bank_account_id: string
  statement_date: string
  statement_balance_sgd: number
  ledger_balance_sgd: number
  difference_sgd: number
  note: string | null
  reconciled_by_user_id: string | null
  reconciled_by_name: string | null
  created_at: string
}

// ---- Tax Type (Tax Code maintenance) ----
export interface TaxCode {
  id: string
  code: string
  name: string
  rate_percent: number
  is_active: boolean
  /** A supply code (sales: SR / ZR / ES / OS) or a purchase code (supplier bills: TX / ZP / EP / OP / NR). */
  kind: 'supply' | 'purchase'
  /** Where it counts in the IRAS Form 5 (decision 47.4): '1' | '2' | '3' | 'out_of_scope' for sales,
   * '5' | 'not_taxable' for purchases; null = the built-in placing. */
  form5_box: string | null
}

// ---- Document Control ----
export interface DocumentSequence {
  id: string
  doc_kind: string
  prefix: string
  year: number
  last_number: number
  next_number: string
}

/** Confirmed 2026-09-11: "customization of the running number
 * formatting and front alphabet." A doc_kind with is_custom=false is
 * showing the built-in default, not an explicit override. */
export interface DocumentNumberFormat {
  doc_kind: string
  prefix: string
  number_length: number
  include_year: boolean
  is_custom: boolean
  example: string
}

// ---- Accounting Periods / Year-End Closing ----
export type PeriodStatus = 'open' | 'closed'

export type PeriodDocType =
  | 'sales_invoice'
  | 'receipt_voucher'
  | 'payment_voucher'
  | 'purchase_bill'
  | 'journal_voucher'

export type PeriodOperation = 'update' | 'reverse' | 'bank' | 'unbank' | 'gl' | 'ungl'

export interface PeriodLock {
  id: string
  doc_type: PeriodDocType
  operation: PeriodOperation
  is_locked: boolean
  locked_by_user_id: string | null
  locked_at: string | null
}

export interface AccountingPeriod {
  id: string
  fiscal_year: number
  name: string
  period_start: string
  period_end: string
  status: PeriodStatus
  closed_at: string | null
  locks: PeriodLock[]
  /** The period's current saved GST Calculation, if one has been run. */
  gst: GstSummary | null
}

export interface GstSummary {
  id: string
  version: number
  calculated_at: string
  calculated_by_name: string | null
  output_tax_sgd: number
  input_tax_sgd: number
  net_gst_sgd: number
  /** Set once the return was submitted to IRAS -- the month is locked from then on. */
  submitted_at: string | null
  submitted_by_name: string | null
  /** Set when a submitted return is being revised: who opened the revision, when and why. */
  revision_opened_at: string | null
  revision_opened_by_name: string | null
  revision_reason: string | null
  /** On a revision: the version number of the submitted return it revises. */
  revises_version: number | null
}

export interface GstBox {
  box: number
  label: string
  amount_sgd: number
}

export interface GstReturnLine {
  direction: 'output' | 'input'
  document_type: string
  document_id: string
  document_number: string
  document_date: string
  party_name: string | null
  tax_code: string | null
  /** 1 / 2 / 3 / out_of_scope for sales; 5 / no_gst for purchases. */
  box: string
  net_sgd: number
  gst_sgd: number
}

/** One saved GST Calculation (IRAS Form 5) of a locked period. */
export interface GstReturnSaved {
  id: string
  accounting_period_id: string
  period_name: string | null
  period_start: string
  period_end: string
  version: number
  status: 'current' | 'superseded'
  calculated_at: string
  calculated_by_name: string | null
  submitted_at: string | null
  submitted_by_name: string | null
  /** Set when a submitted return is being revised: who opened the revision, when and why. */
  revision_opened_at: string | null
  revision_opened_by_name: string | null
  revision_reason: string | null
  /** On a revision: the version number of the submitted return it revises. */
  revises_version: number | null
  boxes: GstBox[]
  output_document_count: number
  input_document_count: number
  lines: GstReturnLine[]
}

export interface FiscalYearClosure {
  id: string
  fiscal_year: number
  retained_earnings_account_id: string
  closing_journal_entry_id: string
  closed_at: string
}

// ---- GST Return ----
export interface GSTReturnRow {
  tax_code: string
  net_sgd: number
  tax_sgd: number
  document_count: number
}

export interface GSTReturn {
  period_start: string
  companies?: string[]
  period_end: string
  /** Form 5 boxes 1-13, summed from the saved GST Calculations in the range. */
  boxes: GstBox[]
  periods: {
    company_name: string
    period_name: string | null
    period_start: string
    period_end: string
    version: number
    calculated_at: string
    period_reopened: boolean
    submitted_at: string | null
    submitted_by_name: string | null
    net_gst_sgd: number
  }[]
  /** Periods in the range with no GST Calculation saved yet -- not summed. */
  missing_periods: { company_name: string; period_name: string; period_start: string; status: string }[]
  output_rows: GSTReturnRow[]
  input_rows: GSTReturnRow[]
  total_output_tax_sgd: number
  total_input_tax_sgd: number
  net_gst_payable_sgd: number
}

export interface GstSupportingRow {
  company_id: string
  company_name: string
  period: string | null
  direction: 'output' | 'input'
  document_number: string
  document_date: string
  party_name: string | null
  tax_code: string
  box: string
  net_sgd: number
  gst_sgd: number
}

// ---- Sales GP + Commission (2026-09-12) ----
export interface SalesGPRow {
  /** Set by Accounting Reports, which can span several companies. */
  company_id?: string
  company_name?: string
  invoice_id: string
  invoice_number: string
  issued_at: string
  customer_id: string
  customer_name: string
  revenue_sgd: number
  cost_sgd: number
  gp_sgd: number
  gp_percent: number
  has_cost_basis: boolean
}

export interface SalesGPReport {
  period_start: string
  companies?: string[]
  period_end: string
  rows: SalesGPRow[]
  total_revenue_sgd: number
  total_cost_sgd: number
  total_gp_sgd: number
  total_gp_percent: number
}

export interface CommissionRow {
  /** Set by Accounting Reports, which can span several companies. */
  company_id?: string
  company_name?: string
  month: string
  sales_staff_id: string | null
  sales_staff_name: string
  commission_sgd: number
}

export interface CommissionReport {
  period_start: string
  companies?: string[]
  period_end: string
  rate_percent: number
  rows: CommissionRow[]
  total_commission_sgd: number
}

// ---- Commission Payouts (6.3/6.4/6.5) ----
export type CommissionPayoutType = 'earning' | 'clawback'
export type CommissionPayoutStatus = 'draft' | 'pending_approval' | 'approved' | 'paid' | 'cancelled'

export interface CommissionPayout {
  id: string
  company_id: string
  payout_number: string
  payout_type: CommissionPayoutType
  status: CommissionPayoutStatus
  sales_staff_id: string
  period_month: string
  period_start: string
  period_end: string
  amount_sgd: number
  rate_percent: number
  clawback_invoice_id: string | null
  clawback_reason: string | null
  submitted_by_user_id: string | null
  submitted_at: string | null
  approved_by_user_id: string | null
  approved_at: string | null
  paid_date: string | null
  paid_reference: string | null
  paid_by_user_id: string | null
  notes: string | null
  created_at: string
  updated_at: string
}

// ---- Ops Dashboard (personal task tracker, confirmed 2026-09-11) ----
export type OpsTaskStatus = 'not_started' | 'in_progress' | 'watch' | 'blocked' | 'done'

export interface OpsTaskCategory {
  id: string
  owner_user_id: string
  name: string
  cadence_label: string | null
  sort_order: number
}

export interface OpsTask {
  id: string
  category_id: string
  owner_user_id: string
  title: string
  status: OpsTaskStatus
  next_action: string | null
  owner_label: string | null
  due_label: string | null
  follow_up_staff_id: string | null
  follow_up_staff_name: string | null
  follow_up_date: string | null
  is_sample: boolean
}

export interface OpsDashboardCategory {
  category: OpsTaskCategory
  tasks: OpsTask[]
}

export interface OpsRollupJobOrder {
  id: string
  job_order_number: string
  subject: string
  status: string
  due_date: string | null
}

export interface OpsRollupSoftwareTask {
  id: string
  title: string
  role: string
  is_tested: boolean
}

export interface OpsDashboard {
  staff_id: string
  staff_name: string
  can_view_others: boolean
  categories: OpsDashboardCategory[]
  total_tasks: number
  open_count: number
  in_progress_count: number
  blocked_count: number
  done_count: number
  my_job_orders: OpsRollupJobOrder[]
  my_software_tasks: OpsRollupSoftwareTask[]
}

// ---- General Ledger / vouchers ----
export type VoucherType = 'journal' | 'receipt' | 'payment' | 'sales_invoice' | 'purchase_invoice'
export type JournalStatus = 'draft' | 'posted' | 'reversed'

export interface JournalLine {
  id: string
  account_id: string
  account_code: string | null
  account_name: string | null
  debit_sgd: number
  credit_sgd: number
  description: string | null
}

export interface JournalEntry {
  id: string
  voucher_number: string
  voucher_type: VoucherType
  entry_date: string
  narration: string
  status: JournalStatus
  total_debit: number
  total_credit: number
  is_balanced: boolean
  reverses_entry_id: string | null
  lines: JournalLine[]
}

export interface TrialBalanceRow {
  account_id: string
  code: string
  name: string
  account_type: AccountType
  debit_sgd: number
  credit_sgd: number
  balance_sgd: number
}

export interface TrialBalance {
  as_at: string | null
  companies?: string[]
  rows: TrialBalanceRow[]
  total_debit: number
  total_credit: number
  is_balanced: boolean
}

export interface GLTransactionRow {
  line_id: string
  entry_id: string
  voucher_number: string
  voucher_type: string
  entry_date: string
  narration: string
  line_description: string | null
  debit_sgd: number
  credit_sgd: number
  balance_sgd: number
}

export interface GLTransactions {
  account_id: string
  account_code: string
  account_name: string
  account_type: string
  date_from: string | null
  date_to: string | null
  rows: GLTransactionRow[]
  total_debit: number
  total_credit: number
  closing_balance: number
}

// ---- Accounts Payable ----
export type PurchaseOrderStatus = 'draft' | 'pending_approval' | 'approved' | 'cancelled'
export type BillMatchStatus = 'not_matched' | 'matched' | 'exception'
export type BillStatus = 'awaiting_match' | 'exception' | 'approved' | 'partially_paid' | 'paid'

// Supplier is NOT a separate type (2026-09-12): a supplier is a CompanyIndividual
// (Company/Individual) record flagged is_supplier=true -- see the
// CompanyIndividual interface below. PurchaseOrder/SupplierInvoice/SupplierPayment
// keep the field name `supplier_id`, but it's a CompanyIndividual id.

export interface PurchaseOrder {
  id: string
  po_number: string
  supplier_id: string
  order_date: string
  description: string
  amount_sgd: number
  gst_amount_sgd: number
  total_amount_sgd: number
  status: PurchaseOrderStatus
  /** eApproval above the supplier's limit (Backlog 2): null when none was needed. */
  approval_status: 'pending' | 'approved' | 'rejected' | null
  approval_note: string | null
  cancel_reason: string | null
  imported_bill_id: string | null
  imported_bill_number: string | null
}

export interface SupplierInvoice {
  id: string
  bill_number: string
  supplier_invoice_no: string | null
  supplier_id: string
  purchase_order_id: string | null
  invoice_date: string
  due_date: string | null
  description: string
  amount_sgd: number
  tax_code: string | null
  gst_rate: number | null
  gst_amount_sgd: number
  total_amount_sgd: number
  amount_paid_sgd: number
  outstanding_sgd: number
  match_status: BillMatchStatus
  match_note: string | null
  status: BillStatus
  // GL posting (ACC-001)
  expense_account_id: string | null
  gl_status: 'posted' | 'reversed' | 'not_posted'
  gl_voucher_number: string | null
}

export interface SupplierPaymentAllocation {
  id: string
  supplier_invoice_id: string
  bill_number: string | null
  amount_sgd: number
}

export interface SupplierPayment {
  id: string
  voucher_number: string
  /** Null on an Other payment (bank charges and the like), which is against gl_account instead. */
  supplier_id: string | null
  kind: 'supplier' | 'other'
  gl_account_id: string | null
  /** "6500 Bank charges" on an Other payment. */
  gl_account: string | null
  notes: string | null
  payment_date: string
  amount_sgd: number
  allocated_sgd: number
  unallocated_sgd: number
  method: string
  reference: string | null
  allocations: SupplierPaymentAllocation[]
  // GL posting + Bank step (ACC-001..004)
  bank_account_id: string | null
  gl_status: 'posted' | 'reversed' | 'not_posted'
  gl_voucher_number: string | null
  bank_status: 'banked' | 'not_banked'
  bank_transaction_number: string | null
  /** Bank Authority approval (Backlog 2): null when the PV needed none. */
  approval_status: 'pending' | 'approved' | 'rejected' | null
  approval_note: string | null
}

export interface APAgingRow {
  /** Set by Accounting Reports, which can span several companies. */
  company_id?: string
  company_name?: string
  supplier_id: string
  supplier_name: string
  current: number
  days_1_30: number
  days_31_60: number
  days_61_90: number
  over_90: number
  total: number
}

export interface APAgingReport {
  as_at: string
  companies?: string[]
  rows: APAgingRow[]
  total: number
}

export type LicenseType = 'included' | 'add_on' | 'trial'

export interface ModuleInfo {
  key: string
  name: string
  description: string | null
  is_built: boolean
  enabled: boolean
  license_type: LicenseType
}

export interface DashboardSummary {
  active_contracts: number
  contracts_expiring_soon: number
  total_contracted_hours: number
  total_consumed_hours: number
  total_remaining_hours: number
  excess_awaiting_review: number
  open_job_orders: number
  missing_service_records: number
  service_records_awaiting_approval: number
  /** SRV-019: submitted more than a week ago and still not approved. */
  service_record_approvals_overdue: number
  invoices_total_sgd: number
  invoices_count: number
  ar_outstanding_sgd: number
  ar_overdue_sgd: number
  ap_outstanding_sgd: number
  ap_overdue_sgd: number
  gl_is_balanced: boolean
}

// ---- Sales Dashboard ----
// NEW FEATURE (not a Python->PHP conversion -- see
// docs/backlog.md / docs/planned-work.md): "Sales Dashboard - Display
// below Company Dashboard".
export interface SalesDashboardQuotationKpi {
  count: number
  not_available: boolean
  reason?: string
}

export interface SalesDashboardSummary {
  financial_year: number
  financial_year_is_calendar_year: boolean
  contracts_due_for_renewal: number
  ar_outstanding_total_sgd: number
  ar_outstanding_2_months_sgd: number
  ar_outstanding_3_months_sgd: number
  quotations_pending_approval: SalesDashboardQuotationKpi
  quotations_pending_confirmation: SalesDashboardQuotationKpi
}

export interface SalesDashboardArRow {
  invoice_id: string
  invoice_number: string
  customer_id: string
  customer_name: string
  due_date: string | null
  outstanding_sgd: number
  bucket: string
}

export interface SalesDashboardTopCustomerRow {
  customer_id: string
  customer_name: string
  invoice_count: number
  net_revenue_sgd: number
}

export interface SalesDashboardBottomCustomerRow {
  customer_id: string
  customer_name: string
}

// ---- Product / Service Catalog ----
export type ProductType = 'service' | 'product'

export interface Product {
  id: string
  product_type: ProductType
  name: string
  internal_reference: string | null
  product_category: string | null
  tags: string | null
  sales_price_sgd: number
  cost_sgd: number | null
  unit_of_measure: string | null
  tax_code: string
  default_reference_code_id: string | null
  is_stock: boolean
  is_active: boolean
  created_at: string
}

// ---- Sales Quotation ----
/**
 * draft -> pending_approval -> approved -> sent -> accepted / rejected /
 * expired, with send-back from pending_approval to draft. BILL-006: the
 * Sales Manager approves every quotation before it goes to the customer
 * (settled 2026-09-15).
 */
export type QuotationStatus =
  | 'draft'
  | 'pending_approval'
  | 'approved'
  | 'sent'
  /** The customer asked for changes; a revision (a new quotation) follows. */
  | 'to_revise'
  | 'accepted'
  | 'rejected'
  | 'expired'

export interface QuotationLine {
  id: string
  product_id: string | null
  description: string
  unit_of_measure: string | null
  quantity: number
  unit_price_sgd: number
  line_total_sgd: number
  reference_code_id: string | null
  /** Costing (2026-09-12): defaults from the chosen product's cost_sgd;
   * the only source of cost for a non-product (free-text) line. */
  cost_sgd: number | null
  /** Only while the quotation can be accepted (status sent): the line goes on the
   * Sales Invoice issued on acceptance (decision 11.2), and takes stock from a warehouse. */
  is_product_line?: boolean | null
  is_stock_line?: boolean | null
}

// ---- Prospect / Leads ----
export type ProspectStatus = 'new' | 'qualified' | 'proposal' | 'negotiation' | 'won' | 'lost'

/** What a prospect reports about itself (all SGD, GST-inclusive except the salesperson's own estimate). */
export interface ProspectAmounts {
  estimated_value_sgd: number | null
  quoted_amount_sgd: number
  billed_amount_sgd: number
  paid_amount_sgd: number
  outstanding_amount_sgd: number
}

export interface Prospect extends ProspectAmounts {
  id: string
  prospect_number: string
  title: string
  customer_id: string
  customer_name: string | null
  source: string | null
  status: ProspectStatus
  expected_close_date: string | null
  salesperson_user_id: string | null
  salesperson_name: string | null
  notes: string | null
  lost_reason: string | null
  created_by_name: string | null
  created_at: string
  updated_at: string
}

export interface ProspectDetail extends Prospect {
  activities: {
    id: string
    activity_type: string
    subject: string
    description: string | null
    activity_date: string | null
    status: string
    created_by_name: string | null
  }[]
  quotations: {
    id: string
    quotation_number: string
    quotation_date: string | null
    status: QuotationStatus
    total_amount_sgd: number
    counts_as_quoted: boolean
  }[]
  invoices: {
    id: string
    invoice_number: string
    status: string
    issued_at: string | null
    due_date: string | null
    total_amount_sgd: number
    amount_paid_sgd: number
    outstanding_sgd: number
  }[]
}

export interface ProspectActivity {
  id: string
  company_id: string
  prospect_id: string | null
  prospect_number: string | null
  prospect_title: string | null
  customer_id: string
  customer_name: string | null
  activity_type: string
  subject: string
  description: string | null
  activity_date: string | null
  status: string
  created_by_user_id: string
  created_by_name: string | null
  last_edited_by_user_id: string | null
  last_edited_by_name: string | null
  void_reason: string | null
  voided_at: string | null
  voided_by_name: string | null
  created_at: string
  updated_at: string
}

/** The sales pipeline, in order (Dennis, 2026-09-26). Won and Lost close it; the salesperson moves a prospect along by hand. */
export const PROSPECT_STATUSES: { value: ProspectStatus; label: string }[] = [
  { value: 'new', label: 'New' },
  { value: 'qualified', label: 'Qualified' },
  { value: 'proposal', label: 'Proposal' },
  { value: 'negotiation', label: 'Negotiation' },
  { value: 'won', label: 'Won' },
  { value: 'lost', label: 'Lost' },
]

/** Still in the pipeline: anything not yet Won or Lost. */
export const isProspectActive = (status: ProspectStatus) => status !== 'won' && status !== 'lost'

/** Badge style per prospect status (the classes in index.css). */
export const PROSPECT_BADGE: Record<ProspectStatus, string> = {
  new: 'draft',
  qualified: 'status-not-started',
  proposal: 'status-in-progress',
  negotiation: 'status-watch',
  won: 'active',
  lost: 'exceeded',
}

export const ACTIVITY_TYPES = [
  { value: 'call', label: 'Call' },
  { value: 'email', label: 'Email' },
  { value: 'meeting', label: 'Meeting' },
  { value: 'note', label: 'Note' },
  { value: 'follow_up', label: 'Follow-up' },
  { value: 'proposal', label: 'Proposal' },
  { value: 'demo', label: 'Demo' },
  { value: 'negotiation', label: 'Negotiation' },
]

export const ACTIVITY_STATUSES = [
  { value: 'planned', label: 'Planned' },
  { value: 'completed', label: 'Completed' },
  { value: 'pending', label: 'Pending' },
  { value: 'cancelled', label: 'Cancelled' },
]

/** Every status an activity can show -- VOID is reached only through the Void action, never picked from a list. */
export const ACTIVITY_STATUS_LABELS: { value: string; label: string }[] = [...ACTIVITY_STATUSES, { value: 'void', label: 'VOID' }]

export type ProspectPayload = {
  customer_id?: string
  title?: string
  source?: string | null
  status?: ProspectStatus
  estimated_value_sgd?: number | null
  expected_close_date?: string | null
  salesperson_user_id?: string | null
  notes?: string | null
  lost_reason?: string | null
}

export type ProspectActivityPayload = {
  prospect_id?: string
  activity_type?: string
  subject?: string
  description?: string | null
  activity_date?: string | null
  status?: string
}

export interface Quotation {
  id: string
  quotation_number: string
  customer_id: string
  prospect_id: string | null
  prospect_number: string | null
  prospect_title: string | null
  quotation_date: string
  valid_until: string | null
  status: QuotationStatus
  notes: string | null
  amount_sgd: number
  tax_code: string
  gst_rate: number
  gst_amount_sgd: number
  total_amount_sgd: number
  converted_contract_id: string | null
  converted_annual_contract_id: string | null
  /** The Sales Invoice issued for its product lines on acceptance (decision 11.2). */
  converted_invoice_id?: string | null
  converted_invoice_number?: string | null
  created_at: string
  submitted_at: string | null
  approved_at: string | null
  approved_by_user_id: string | null
  sent_at: string | null
  /** Why the approver sent it back to draft; cleared on the next submit. */
  returned_reason: string | null
  /** Set when this quotation was raised from a contract as its renewal: accepting it renews that contract. */
  renews_contract_id: string | null
  renews_contract_number: string | null
  /** To revise: what the customer asked to change, and the revision / original links. */
  to_revise_at: string | null
  revision_reason: string | null
  revised_from_quotation_id: string | null
  revised_from_quotation_number: string | null
  revision_id: string | null
  revision_number: string | null
  revision_status: QuotationStatus | null
  lines: QuotationLine[]
}

// ---- eDocument Attachments + eSignature ----

export type DocumentEntityType =
  | 'quotation'
  | 'invoice'
  | 'receipt_voucher'
  | 'payment_voucher'
  | 'purchase_order'
  | 'supplier_invoice'
  | 'journal_entry'
  | 'job_order'
  | 'service_record'
  | 'contract'
  | 'incident'
  | 'commission_payout'

export interface DocumentAttachment {
  id: string
  company_id: string
  entity_type: DocumentEntityType
  entity_id: string
  uploaded_by_user_id: string
  original_filename: string
  content_type: string
  file_size_bytes: number
  description: string | null
  uploaded_at: string
}

export interface DocumentSignature {
  id: string
  company_id: string
  entity_type: DocumentEntityType
  entity_id: string
  signer_user_id: string
  signer_name: string
  role_label: string | null
  signed_at: string
}

// ---- eApproval Master ----

export type ApprovalMode = 'any_one' | 'all_must'
export type ApprovalStatus = 'pending' | 'approved' | 'rejected'
export type ApprovalDecisionValue = 'approved' | 'rejected'

export interface ApprovalAuthorityMember {
  id: string
  authority_id: string
  user_id: string
  added_at: string
}

export interface ApprovalRule {
  id: string
  authority_id: string
  entity_type: DocumentEntityType
  threshold_amount: number | null
  priority: number
  is_active: boolean
  created_at: string
}

export interface ApprovalAuthority {
  id: string
  company_id: string
  name: string
  description: string | null
  mode: ApprovalMode
  bank_account_id: string | null
  is_active: boolean
  created_at: string
  members: ApprovalAuthorityMember[]
  rules: ApprovalRule[]
}

export interface ApprovalDecision {
  id: string
  request_id: string
  user_id: string
  decision: ApprovalDecisionValue
  comment: string | null
  decided_at: string
}

export interface ApprovalRequest {
  id: string
  company_id: string
  entity_type: DocumentEntityType
  entity_id: string
  rule_id: string
  authority_id: string
  status: ApprovalStatus
  requested_by_user_id: string
  /** What the approver is asked about, e.g. "Purchase Order PO-2026-0007, SGD 12,000.00 to ACME". */
  summary: string | null
  requested_at: string
  resolved_at: string | null
  decisions: ApprovalDecision[]
}


// ---- Stock / Inventory types ----
export interface Warehouse {
  id: string; company_id: string; code: string; name: string
  address: string | null; is_active: boolean
  created_at: string; updated_at: string
}
export interface StockItemAttachmentRow {
  id: string; stock_item_id: string; filename: string
  content_type: string | null; file_size: number | null
  created_at: string
}
export interface StockItemRow {
  id: string; company_id: string; code: string; name: string
  description: string | null; category: string | null
  unit_of_measure: string; product_id: string | null
  reorder_level: number; is_active: boolean
  // extended fields
  category_id: string | null; group_id: string | null
  brand_id: string | null; model_id: string | null; usage_id: string | null
  barcode: string | null; part_number: string | null
  invoice_description: string | null; memo: string | null; notes: string | null
  dimensions: string | null
  // joined names
  category_name: string | null; group_name: string | null
  brand_name: string | null; model_name: string | null; usage_name: string | null
  attachments: StockItemAttachmentRow[]
  created_at: string; updated_at: string
}
export interface StockSetupRow {
  id: string; code?: string; name: string; is_active: boolean; created_at: string
  company_id?: string; brand_id?: string
}
export interface StockBrandRow {
  id: string; company_id: string; name: string; is_active: boolean; created_at: string
}
export interface StockLevelRow {
  id: string; stock_item_id: string; warehouse_id: string
  quantity: number; avg_cost: number
  item_code: string; item_name: string
  warehouse_code: string; warehouse_name: string
}
export interface StockMovementRow {
  id: string; stock_item_id: string; warehouse_id: string
  company_id?: string; company_name?: string
  item_code?: string | null; item_name?: string | null; warehouse_code?: string | null
  movement_type: string; quantity: number
  unit_cost: number; total_cost: number
  reference_type: string | null; reference_id: string | null
  notes: string | null; created_at: string
}
export interface GRNLineRow {
  id: string; stock_item_id: string; quantity: number
  unit_cost: number; total_cost: number; notes: string | null
}
export interface GRNRow {
  id: string; grn_number: string; warehouse_id: string
  supplier_id: string | null; purchase_order_id: string | null
  receive_date: string; status: string
  notes: string | null; lines: GRNLineRow[]
  created_at: string
}
export interface GRNCreatePayload {
  warehouse_id: string; supplier_id?: string; purchase_order_id?: string
  receive_date?: string; notes?: string
  lines: { stock_item_id: string; quantity: number; unit_cost: number }[]
}
export interface GTNLineRow {
  id: string; stock_item_id: string; quantity: number; notes: string | null
}
export interface GTNRow {
  id: string; gtn_number: string
  from_warehouse_id: string; to_warehouse_id: string
  transfer_date: string; status: string
  notes: string | null; lines: GTNLineRow[]
  created_at: string
}
export interface GTNCreatePayload {
  from_warehouse_id: string; to_warehouse_id: string
  transfer_date?: string; notes?: string
  lines: { stock_item_id: string; quantity: number; notes?: string }[]
}
export interface GINLineRow {
  id: string; stock_item_id: string; quantity: number
  /** Null until confirmed: stock leaves at the item's average cost, stamped on confirm. */
  unit_cost: number | null; total_cost: number | null; notes: string | null
}
export interface GINRow {
  id: string; gin_number: string; warehouse_id: string
  customer_id: string | null; job_order_id: string | null; issue_date: string
  reason: string | null; status: string
  notes: string | null; lines: GINLineRow[]
  created_at: string
}
export interface GRTNLineRow {
  id: string; stock_item_id: string; quantity: number
  unit_cost: number; total_cost: number; notes: string | null
}
export interface GRTNRow {
  id: string; grtn_number: string; warehouse_id: string
  supplier_id: string | null; return_date: string
  reason: string | null; status: string
  notes: string | null; lines: GRTNLineRow[]
  created_at: string
}
export interface GRTNCreatePayload {
  warehouse_id: string; supplier_id?: string
  return_date?: string; reason?: string; notes?: string
  lines: { stock_item_id: string; quantity: number; unit_cost: number }[]
}
export interface AdjustmentLineRow {
  id: string; stock_item_id: string; quantity_change: number; notes: string | null
}
export interface AdjustmentRow {
  id: string; adj_number: string; warehouse_id: string
  adjustment_date: string; reason: string | null
  status: string; approved_by: string | null
  approved_at: string | null; lines: AdjustmentLineRow[]
  created_at: string
}
export interface AdjustmentCreatePayload {
  warehouse_id: string; adjustment_date?: string; reason?: string
  lines: { stock_item_id: string; quantity_change: number; notes?: string }[]
}
export interface StockValuationReport {
  items: { company_id?: string; company_name?: string; item_code: string; item_name: string; warehouse_code: string; warehouse_name: string; quantity: number; avg_cost: number; total_value: number }[]
  total_value: number
}
export interface ReorderItem {
  company_id?: string; company_name?: string
  item_code: string; item_name: string; unit_of_measure: string
  reorder_level: number; current_stock: number; shortfall: number
}


/** Accounting Reports filters (2026-09-24). The *_ids are comma-separated;
 * company_ids defaults server-side to the user's current company. */
export type AccountingReportFilters = {
  company_ids?: string
  customer_ids?: string
  supplier_ids?: string
  sales_staff_ids?: string
  as_at?: string
  period_start?: string
  period_end?: string
}

export interface ReportFilterOption {
  id: string
  name: string
}

/** Which internal company a report row belongs to (2026-09-25). */
export interface ReportCompanyTag {
  company_id?: string
  company_name?: string
}
export type ContractReportRow = Contract & ReportCompanyTag & { customer_name?: string }
export type JobOrderReportRow = JobOrder & ReportCompanyTag & { customer_name?: string; assigned_to_name?: string | null }
export type ServiceRecordReportRow = ServiceRecord & ReportCompanyTag & { customer_id?: string | null; customer_name?: string; employee_name?: string }

export interface OperationsFilterOptions {
  company_individuals: ReportFilterOption[]
  staff: ReportFilterOption[]
  products: ReportFilterOption[]
}
export interface StockFilterOptions {
  warehouses: ReportFilterOption[]
  items: ReportFilterOption[]
}

export interface ReportFilterOptions {
  /** Every Company / Individual -- one list, since a customer can also be a supplier. */
  company_individuals: ReportFilterOption[]
  sales_staff: ReportFilterOption[]
}

// ---- Data Migration (Maintenance -> Data Migration, docs/data-migration.md) ----

export type MigrationSource = 'odoo' | 'zsoft'

export type MigrationModuleStatus =
  | 'not_started' | 'uploaded' | 'checking' | 'has_errors' | 'ready_to_import'
  | 'importing' | 'partly_imported' | 'complete'

export interface MigrationModule {
  order: number
  source: MigrationSource
  source_label: string
  entity: string
  /** The module's name in the old system, e.g. "Contacts". */
  their_name: string
  /** The module's name here, e.g. "Company / Individual". */
  label: string
  posts_to_gl: boolean
  total: number
  imported: number
  failed: number
  in_progress: number
  status: MigrationModuleStatus
  last_activity_at: string | null
  latest_batch_id: string | null
  latest_batch_number: string | null
  last_import_batch_id: string | null
  last_import_batch_number: string | null
  field_gap: { columns: number; undecided: number; gaps: number; signed_off: boolean; signed_off_at: string | null }
}

export interface MigrationOverview {
  company: { id: string; code: string; name: string }
  modules: MigrationModule[]
  totals: {
    modules: number
    complete: number
    importing: number
    rows_with_errors: number
    records_total: number
    records_done: number
    percent: number
  }
  as_at: string
}

export interface MigrationField {
  key: string
  label: string
  required: boolean
  hint: string | null
}

export type MigrationColumnState = 'mapped' | 'left_out' | 'field_gap' | 'undecided'

export interface MigrationMappingInfo {
  source: MigrationSource
  entity: string
  label: string
  their_name: string
  fields: MigrationField[]
  columns: { header: string; field: string | null; field_label: string | null; state: MigrationColumnState }[]
  undecided: number
  gaps: number
  missing_required: string[]
  can_sign_off: boolean
  signed_off: boolean
  signed_off_at: string | null
  signed_off_by: string | null
}

export type MigrationOutcome = 'created' | 'linked' | 'already_imported' | 'skipped' | 'failed' | 'needs_decision'

export interface MigrationReportRow {
  row: number
  source_ref?: string
  outcome: MigrationOutcome
  message?: string
  warnings?: string[]
  candidates?: { id: string; name: string; detail: string }[]
}

export type MigrationBatchStatus = 'uploaded' | 'running' | 'dry_run' | 'failed' | 'succeeded' | 'rolled_back'

export interface MigrationBatch {
  id: string
  batch_number: string
  /** Transactions dated before this (YYYY-MM-DD) were left out unless still open; null = everything. */
  cutoff_date: string | null
  /** Whether this module takes a cut-off date (transactions only). */
  cutoff_applies: boolean
  company_id: string
  company_name: string | null
  source: MigrationSource
  source_label: string
  entity: string
  module_label: string
  their_name: string | null
  source_filename: string
  status: MigrationBatchStatus
  status_label: string
  mode: 'dry_run' | 'commit' | null
  rows_read: number
  rows_created: number
  rows_linked: number
  rows_already_imported: number
  rows_skipped: number
  rows_failed: number
  rows_needs_decision: number
  progress_done: number
  progress_total: number
  error_message: string | null
  started_by: string | null
  started_at: string | null
  finished_at: string | null
  imported_at: string | null
  rolled_back_at: string | null
  rolled_back_by: string | null
  rollback_reason: string | null
  // Detail only
  headers?: string[]
  sample_row?: Record<string, string>
  mapping?: Record<string, string | null>
  decisions?: Record<string, string>
  problems?: MigrationReportRow[]
  problems_total?: number
  sample_created?: MigrationReportRow[]
  rollback_report?: { refused_at?: string; blockers?: { record: string; reason: string }[]; blocked_count?: number; removable_count?: number; removed_count?: number; linked_kept?: number } | null
}

export interface MigrationRollbackResult {
  rolled_back: boolean
  removed: number
  blockers: { record: string; reason: string }[]
  batch: MigrationBatch
}

export interface MigrationBatchFilters {
  company_ids?: string
  source?: string
  entity?: string
  status?: string
  date_from?: string
  date_to?: string
}

/** Detail of a non-2xx response: the backend's `detail` plus the parsed body. */
export class ApiError extends Error {
  status: number
  body: unknown
  constructor(message: string, status: number, body: unknown) {
    super(message)
    this.status = status
    this.body = body
  }
}

async function requestWithBody<T>(path: string, options: RequestInit = {}): Promise<T> {
  const token = getToken()
  const headers: Record<string, string> = {
    ...(options.body && !(options.body instanceof FormData) ? { 'Content-Type': 'application/json' } : {}),
    ...(token ? { Authorization: `Bearer ${token}` } : {}),
    'X-Device-Id': getDeviceId(),
  }
  const res = await fetch(`/api${path}`, { ...options, headers })
  let body: unknown = null
  try {
    body = await res.json()
  } catch {
    /* no body */
  }
  if (!res.ok) {
    const detail = (body as { detail?: string } | null)?.detail ?? res.statusText
    throw new ApiError(detail, res.status, body)
  }
  return body as T
}

// ---- Email Inbox: the helpdesk mailbox read by the server ----
export type InboxEmailStatus = 'new' | 'logged' | 'dismissed'

export interface InboxEmail {
  id: string
  from_name: string | null
  from_email: string
  subject: string
  received_at: string
  body_text: string | null
  attachment_names: string[]
  status: InboxEmailStatus
  /** The Company / Individual the sender's address matches, if any (new emails only). */
  matched_company_individual: string | null
  incident_id: string | null
  incident_number: string | null
  job_order_id: string | null
  job_order_number: string | null
  handled_by_name: string | null
  handled_at: string | null
  dismiss_reason: string | null
  /** Set on Convert to Job Order when no Job Order could be opened. */
  fallback_reason?: string | null
}

export interface InboxMailbox {
  configured: boolean
  address: string | null
  last_checked_at: string | null
  last_error: string | null
  added: number
}

// ---- Sales Dashboard: per-salesperson cards (decision 12.2) ----
export interface SalespersonCard {
  salesperson_user_id: string | null
  kind: 'salesperson' | 'no_salesperson' | 'no_prospect'
  name: string
  role: string | null
  prospects_by_stage: Record<ProspectStatus, number>
  open_prospects: number
  /** Financial year to date. */
  quoted_sgd: number
  billed_sgd: number
  paid_sgd: number
  /** This calendar month (Dennis, 2026-09-26: "This month, with the year beside it"). */
  quoted_month_sgd: number
  billed_month_sgd: number
  paid_month_sgd: number
}

export const api = {
  me: () => request<CurrentUser>('/auth/me'),
  acknowledgeAiDataConsent: () => request<{ ai_data_consent_at: string; ai_data_consent_required: boolean }>('/auth/ai-consent', { method: 'POST', body: JSON.stringify({ accepted: true }) }),
  /** A one-time code the Gmail add-on trades for a sign-in (docs/outlook-addin.md). */
  addinConnectCode: () =>
    request<{ code: string; expires_at: string; expires_in_minutes: number }>('/auth/addin-connect-code', { method: 'POST' }),
  listUsers: () => request<CurrentUser[]>('/users'),

  // Company Setup / multi-company
  listMyCompanies: () => request<Company[]>('/companies'),
  /** Login page logo/name (2026-09-12) -- the only unauthenticated call
   * in this client; works before signing in. */
  getPublicBranding: () => request<PublicBranding>('/companies/public-branding'),

  // Announcements / ad banner -- getPublicAdBanner is the only
  // unauthenticated call here (used by both the Login page and the
  // app-wide banner, each passing its own slot); the rest back the
  // Announcements admin screen.
  getPublicAdBanner: (slot: AdBannerSlot) => request<PublicAdBanner>(`/announcements/public/${slot}`),
  // Maintenance -> System Email (the two system mailboxes).
  getSystemMail: () => request<{ otp: SystemMailbox; helpdesk: SystemMailbox }>('/system-mail'),
  updateSystemMail: (
    purpose: 'otp' | 'helpdesk',
    payload: {
      host?: string | null
      port?: number
      username?: string | null
      /** Omit to leave the stored password alone; null clears it. */
      password?: string | null
      use_tls?: boolean
      from_email?: string | null
      from_name?: string | null
    },
  ) => request<SystemMailbox>(`/system-mail/${purpose}`, { method: 'PATCH', body: JSON.stringify(payload) }),
  /** IMAP: reads this same mailbox -- a separate call from updateSystemMail's SMTP send settings. */
  updateSystemMailImap: (purpose: 'otp' | 'helpdesk', payload: ImapMailboxPayload) =>
    request<SystemMailbox>(`/system-mail/${purpose}`, { method: 'PATCH', body: JSON.stringify(payload) }),
  /** Connects, logs in, logs out with the saved IMAP credentials. Reads nothing. */
  testSystemMailImap: (purpose: 'otp' | 'helpdesk') =>
    request<{ ok: true }>(`/system-mail/${purpose}/test-imap`, { method: 'POST' }),
  testSystemMail: (purpose: 'otp' | 'helpdesk', toEmail: string) =>
    request<{ sent: boolean; to: string }>(`/system-mail/${purpose}/test-email`, {
      method: 'POST',
      body: JSON.stringify({ to_email: toEmail }),
    }),

  getAdBannerSettings: (slot: AdBannerSlot) => request<AdBannerSettingsInfo>(`/announcements/settings/${slot}`),
  updateAdBannerSettings: (slot: AdBannerSlot, videoUrl: string | null) =>
    request<AdBannerSettingsInfo>(`/announcements/settings/${slot}`, {
      method: 'PATCH',
      body: JSON.stringify({ video_url: videoUrl }),
    }),
  /** Replaces whichever of a URL / a previous upload was live for THIS slot -- only one video is ever active per slot. */
  uploadAdBannerVideo: async (slot: AdBannerSlot, file: File): Promise<AdBannerSettingsInfo> => {
    const formData = new FormData()
    formData.append('video', file)
    const token = getToken()
    const res = await fetch(`/api/announcements/settings/${slot}/video`, {
      method: 'POST',
      headers: {
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
        'X-Device-Id': getDeviceId(),
      },
      body: formData,
    })
    if (!res.ok) {
      let detail = res.statusText
      try { const b = await res.json(); detail = b.detail ?? detail } catch { /* */ }
      throw new Error(detail)
    }
    return res.json()
  },
  listAnnouncements: () => request<Announcement[]>('/announcements'),
  createAnnouncement: (payload: { tag?: string | null; text: string; sort_order?: number }) =>
    request<Announcement>('/announcements', { method: 'POST', body: JSON.stringify(payload) }),
  updateAnnouncement: (
    id: string,
    payload: Partial<{ tag: string | null; text: string; sort_order: number; is_active: boolean }>,
  ) => request<Announcement>(`/announcements/${id}`, { method: 'PATCH', body: JSON.stringify(payload) }),
  deleteAnnouncement: (id: string) => request<void>(`/announcements/${id}`, { method: 'DELETE' }),
  createCompany: (payload: {
    name: string
    country?: string
    currency?: string
    timezone?: string
    logo?: string | null
  }) => request<Company>('/companies', { method: 'POST', body: JSON.stringify(payload) }),
  updateCompany: (
    id: string,
    payload: {
      name?: string
      country?: string
      currency?: string
      timezone?: string
      logo?: string | null
      address?: string | null
      gst_registration_no?: string | null
      phone?: string | null
      website?: string | null
      uen?: string | null
      financial_year_start_month?: number
      smtp_host?: string | null
      smtp_port?: number
      smtp_username?: string | null
      /** Omit to leave the stored password alone; null clears it. */
      smtp_password?: string | null
      smtp_use_tls?: boolean
      smtp_from_email?: string | null
      smtp_from_name?: string | null
      is_active?: boolean
    },
  ) => request<Company>(`/companies/${id}`, { method: 'PATCH', body: JSON.stringify(payload) }),
  /** Sends a real message through the company's own mailbox, to prove the settings at setup time. */
  testCompanyEmail: (id: string, toEmail: string) =>
    request<{ sent: boolean; to: string }>(`/companies/${id}/test-email`, {
      method: 'POST',
      body: JSON.stringify({ to_email: toEmail }),
    }),
  switchCompany: (id: string) => request<Company>(`/companies/${id}/switch`, { method: 'POST' }),

  // Staff Master (full CRUD; distinct from the plain listUsers directory above)
  listStaff: (includeInactive = false) =>
    request<StaffUser[]>(`/users${includeInactive ? '?include_inactive=true' : ''}`),
  exportStaffCsv: (includeInactive = false) =>
    requestBlob(`/users/export.csv${includeInactive ? '?include_inactive=true' : ''}`),
  exportStaffExcel: (includeInactive = false) =>
    requestBlob(`/users/export.xlsx${includeInactive ? '?include_inactive=true' : ''}`),
  getStaff: (id: string) => request<StaffUser>(`/users/${id}`),
  createStaff: (payload: {
    username: string
    full_name: string
    email: string
    password: string
    role: UserRole
    group_id?: string | null
  }) => request<StaffUser>('/users', { method: 'POST', body: JSON.stringify(payload) }),
  updateStaff: (
    id: string,
    payload: {
      full_name?: string
      email?: string
      role?: UserRole
      group_id?: string | null
      photo?: string | null
      force_password_change_on_login?: boolean
    },
  ) => request<StaffUser>(`/users/${id}`, { method: 'PATCH', body: JSON.stringify(payload) }),
  deactivateStaff: (id: string) => request<StaffUser>(`/users/${id}/deactivate`, { method: 'POST' }),
  reactivateStaff: (id: string) => request<StaffUser>(`/users/${id}/reactivate`, { method: 'POST' }),
  resetStaffPassword: (
    id: string,
    new_password: string,
    force_password_change_on_login?: boolean,
  ) =>
    request<StaffUser>(`/users/${id}/reset-password`, {
      method: 'POST',
      body: JSON.stringify({ new_password, force_password_change_on_login }),
    }),
  getStaffAuditLog: (id: string) => request<AuditLogEntry[]>(`/users/${id}/audit-log`),
  getStaffCompanyAccess: (id: string) =>
    request<UserCompanyAccess[]>(`/users/${id}/company-access`),
  setStaffCompanyAccess: (
    id: string,
    access: { company_id: string; group_id: string | null }[],
  ) =>
    request<UserCompanyAccess[]>(`/users/${id}/company-access`, {
      method: 'PUT',
      body: JSON.stringify({ access }),
    }),

  // Group Authority
  listGroups: (companyId?: string) =>
    request<Group[]>(`/groups${companyId ? `?company_id=${companyId}` : ''}`),
  exportGroupsCsv: (companyId?: string) =>
    requestBlob(`/groups/export.csv${companyId ? `?company_id=${companyId}` : ''}`),
  exportGroupsExcel: (companyId?: string) =>
    requestBlob(`/groups/export.xlsx${companyId ? `?company_id=${companyId}` : ''}`),
  createGroup: (name: string, description?: string) =>
    request<Group>('/groups', { method: 'POST', body: JSON.stringify({ name, description }) }),
  updateGroup: (id: string, payload: { name?: string; description?: string }) =>
    request<Group>(`/groups/${id}`, { method: 'PATCH', body: JSON.stringify(payload) }),
  deleteGroup: (id: string) => request<void>(`/groups/${id}`, { method: 'DELETE' }),
  setGroupAuthorities: (id: string, authorities: GroupAuthority[]) =>
    request<Group>(`/groups/${id}/authorities`, {
      method: 'PUT',
      body: JSON.stringify({ authorities }),
    }),

  dashboardSummary: () => request<DashboardSummary>('/dashboard/summary'),

  /** Read-only module catalog — used by Group Authority setup. */
  listModules: () => request<ModuleInfo[]>('/modules'),
  /** module_key -> can the current user reach it right now (Group Authority AND
   * module enablement both say yes)? Drives which nav links show at all.
   * Module management (toggle on/off) is handled from Central Command → Client Control. */
  myModuleAccess: () => request<Record<string, boolean>>('/modules/my-access'),

  /** Bank Portal Testing -- a module-gated placeholder (docs/backlog.md
   * "Bank Portal / ZSOFT HP Agency"), invisible unless the
   * `bank_portal_testing` module is switched on for this company. */
  bankPortalStatus: () => request<{ enabled: boolean; message: string }>('/bank-portal/status'),

  // Dynamic filter: free-text `q` matches name/email/phone/mobile/UEN/
  // legacy code/tags; customer_group_id pulls up a whole group of
  // companies together; industry_code narrows to one industry
  // (confirmed 2026-09-11: customer grouping by industry); includeInactive
  // reveals deactivated customers.
  listCompanyIndividuals: (
    filters: {
      q?: string
      customer_group_id?: string
      industry_code?: string
      include_inactive?: boolean
      /** 2026-09-12: Purchase Order/AP pick suppliers from this same
       * Company/Individual list, filtered to is_supplier=true. */
      is_supplier?: boolean
      /** PDPA (2026-09-12): archived records are hidden from every
       * normal list even with include_inactive -- opt in explicitly. */
      include_archived?: boolean
    } = {},
  ) =>
    request<CompanyIndividual[]>(
      `/company-individuals${qs({
        q: filters.q,
        customer_group_id: filters.customer_group_id,
        industry_code: filters.industry_code,
        include_inactive: filters.include_inactive ? 'true' : undefined,
        is_supplier: filters.is_supplier === undefined ? undefined : filters.is_supplier ? 'true' : 'false',
        include_archived: filters.include_archived ? 'true' : undefined,
      })}`,
    ),
  exportCompanyIndividualsCsv: (
    filters: { q?: string; customer_group_id?: string; industry_code?: string; include_inactive?: boolean } = {},
  ) =>
    requestBlob(
      `/company-individuals/export.csv${qs({
        q: filters.q,
        customer_group_id: filters.customer_group_id,
        industry_code: filters.industry_code,
        include_inactive: filters.include_inactive ? 'true' : undefined,
      })}`,
    ),
  exportCompanyIndividualsExcel: (
    filters: { q?: string; customer_group_id?: string; industry_code?: string; include_inactive?: boolean } = {},
  ) =>
    requestBlob(
      `/company-individuals/export.xlsx${qs({
        q: filters.q,
        customer_group_id: filters.customer_group_id,
        industry_code: filters.industry_code,
        include_inactive: filters.include_inactive ? 'true' : undefined,
      })}`,
    ),
  getCompanyIndividual: (id: string) => request<CompanyIndividual>(`/company-individuals/${id}`),
  createCompanyIndividual: (payload: CompanyIndividualFields & { name: string }) =>
    request<CompanyIndividual>('/company-individuals', { method: 'POST', body: JSON.stringify(payload) }),
  updateCompanyIndividual: (id: string, payload: CompanyIndividualFields) =>
    request<CompanyIndividual>(`/company-individuals/${id}`, { method: 'PATCH', body: JSON.stringify(payload) }),
  deactivateCompanyIndividual: (id: string) => request<CompanyIndividual>(`/company-individuals/${id}/deactivate`, { method: 'POST' }),
  reactivateCompanyIndividual: (id: string) => request<CompanyIndividual>(`/company-individuals/${id}/reactivate`, { method: 'POST' }),
  getCompanyIndividualAuditLog: (id: string) => request<AuditLogEntry[]>(`/company-individuals/${id}/audit-log`),
  /** PDPA (2026-09-12): ticks/unticks "PDPA Agreement e-signed" -- the
   * date/time is stamped server-side, never sent from here. */
  setPdpaConsent: (id: string, given: boolean) =>
    request<CompanyIndividual>(`/company-individuals/${id}/pdpa-consent`, {
      method: 'POST',
      body: JSON.stringify({ given }),
    }),
  /** Uploads (data URI, image or PDF) or removes (pass null) the
   * scanned/photographed signed PDPA Agreement itself. */
  setPdpaAgreementDocument: (id: string, document: string | null) =>
    request<CompanyIndividual>(`/company-individuals/${id}/pdpa-agreement-document`, {
      method: 'POST',
      body: JSON.stringify({ document }),
    }),
  /** Soft-archive-in-place -- all data stays, just hidden from normal lists. */
  archiveCompanyIndividual: (id: string, reason?: string) =>
    request<CompanyIndividual>(`/company-individuals/${id}/archive`, { method: 'POST', body: JSON.stringify({ reason: reason ?? '' }) }),
  unarchiveCompanyIndividual: (id: string, reason: string) =>
    request<CompanyIndividual>(`/company-individuals/${id}/unarchive`, { method: 'POST', body: JSON.stringify({ reason }) }),

  // CompanyIndividual Groups (tag linking separate companies in one group)
  listCompanyIndividualGroups: (includeInactive = false) =>
    request<CompanyIndividualGroup[]>(`/company-individual-groups${includeInactive ? '?include_inactive=true' : ''}`),
  createCompanyIndividualGroup: (payload: { name: string; description?: string }) =>
    request<CompanyIndividualGroup>('/company-individual-groups', { method: 'POST', body: JSON.stringify(payload) }),
  updateCompanyIndividualGroup: (id: string, payload: { name?: string; description?: string; is_active?: boolean }) =>
    request<CompanyIndividualGroup>(`/company-individual-groups/${id}`, { method: 'PATCH', body: JSON.stringify(payload) }),

  listContacts: (customerId: string, includeInactive = false) =>
    request<Contact[]>(`/company-individuals/${customerId}/contacts${includeInactive ? '?include_inactive=true' : ''}`),
  createContact: (
    customerId: string,
    payload: { name: string; email?: string; phone?: string; direct_line?: string },
  ) => request<Contact>(`/company-individuals/${customerId}/contacts`, { method: 'POST', body: JSON.stringify(payload) }),
  updateContact: (
    customerId: string,
    contactId: string,
    payload: { name?: string; email?: string | null; phone?: string | null; direct_line?: string | null },
  ) =>
    request<Contact>(`/company-individuals/${customerId}/contacts/${contactId}`, {
      method: 'PATCH',
      body: JSON.stringify(payload),
    }),
  deactivateContact: (customerId: string, contactId: string) =>
    request<Contact>(`/company-individuals/${customerId}/contacts/${contactId}/deactivate`, { method: 'POST' }),
  reactivateContact: (customerId: string, contactId: string) =>
    request<Contact>(`/company-individuals/${customerId}/contacts/${contactId}/reactivate`, { method: 'POST' }),

  // Helpdesk Portal logins, granted per Contact. The backend refuses
  // without a contact email, without PDPA consent on file, or on an
  // archived customer, and archiving disables every login under the
  // customer (PORTAL-004) -- all enforced there, not here.
  getPortalAccess: (customerId: string, contactId: string) =>
    request<PortalAccess>(`/company-individuals/${customerId}/contacts/${contactId}/portal-access`),
  enablePortalAccess: (customerId: string, contactId: string) =>
    request<PortalAccess>(`/company-individuals/${customerId}/contacts/${contactId}/portal-access`, {
      method: 'POST',
    }),
  resetPortalAccessPassword: (customerId: string, contactId: string) =>
    request<PortalAccess>(
      `/company-individuals/${customerId}/contacts/${contactId}/portal-access/reset-password`,
      { method: 'POST' },
    ),
  disablePortalAccess: (customerId: string, contactId: string) =>
    request<PortalAccess>(`/company-individuals/${customerId}/contacts/${contactId}/portal-access/disable`, {
      method: 'POST',
    }),

  listBranches: (customerId: string, includeInactive = false) =>
    request<Branch[]>(`/company-individuals/${customerId}/branches${includeInactive ? '?include_inactive=true' : ''}`),
  createBranch: (
    customerId: string,
    payload: {
      branch_name: string
      branch_code?: string
      address_line1?: string
      address_line2?: string
      address_city?: string
      address_state?: string
      address_postal_code?: string
      address_country?: string
      phone?: string
    },
  ) => request<Branch>(`/company-individuals/${customerId}/branches`, { method: 'POST', body: JSON.stringify(payload) }),
  updateBranch: (
    customerId: string,
    branchId: string,
    payload: Partial<{
      branch_name: string
      branch_code: string | null
      address_line1: string | null
      address_line2: string | null
      address_city: string | null
      address_state: string | null
      address_postal_code: string | null
      address_country: string | null
      phone: string | null
    }>,
  ) =>
    request<Branch>(`/company-individuals/${customerId}/branches/${branchId}`, {
      method: 'PATCH',
      body: JSON.stringify(payload),
    }),
  deactivateBranch: (customerId: string, branchId: string) =>
    request<Branch>(`/company-individuals/${customerId}/branches/${branchId}/deactivate`, { method: 'POST' }),
  reactivateBranch: (customerId: string, branchId: string) =>
    request<Branch>(`/company-individuals/${customerId}/branches/${branchId}/reactivate`, { method: 'POST' }),

  listCompanyIndividualRelationships: (customerId: string) =>
    request<CompanyIndividualRelationship[]>(`/company-individuals/${customerId}/relationships`),
  createCompanyIndividualRelationship: (
    customerId: string,
    payload: { to_customer_id?: string; to_contact_id?: string; relationship_type: string; note?: string },
  ) =>
    request<CompanyIndividualRelationship>(`/company-individuals/${customerId}/relationships`, {
      method: 'POST',
      body: JSON.stringify(payload),
    }),
  deactivateCompanyIndividualRelationship: (customerId: string, relationshipId: string) =>
    request<CompanyIndividualRelationship>(`/company-individuals/${customerId}/relationships/${relationshipId}/deactivate`, {
      method: 'POST',
    }),

  listContracts: (
    filters: {
      status?: string
      customer_id?: string
      contract_kind?: ContractKind
      sales_staff_id?: string
      product_id?: string
      coverage_start?: string
      coverage_end?: string
      // NEW FEATURE (not a Python->PHP conversion) -- see
      // docs/backlog.md / docs/planned-work.md.
      remaining_hours_lt?: number
      expiry_from?: string
      expiry_to?: string
    } = {},
  ) => request<Contract[]>(`/contracts${qs(filters)}`),
  exportContractsCsv: (filters: Record<string, string | undefined> = {}) =>
    requestBlob(`/contracts/export.csv${qs(filters)}`),
  exportContractsExcel: (filters: Record<string, string | undefined> = {}) =>
    requestBlob(`/contracts/export.xlsx${qs(filters)}`),
  getContract: (id: string) => request<Contract>(`/contracts/${id}`),
  createContract: (payload: {
    customer_id: string
    contract_kind?: ContractKind
    contracted_hours: number
    contract_value_sgd: number
    start_date: string
    hourly_rate_sgd?: number | null
    sales_staff_id?: string | null
    product_ids?: string[]
  }) => request<Contract>('/contracts', { method: 'POST', body: JSON.stringify(payload) }),
  updateContract: (id: string, payload: { sales_staff_id?: string | null; product_ids?: string[] }) =>
    request<Contract>(`/contracts/${id}`, { method: 'PATCH', body: JSON.stringify(payload) }),
  updateContractProductLicense: (
    contractId: string,
    productId: string,
    payload: { license_type?: LicenseDeploymentType | null; number_of_licenses?: number | null },
  ) =>
    request<Contract>(`/contracts/${contractId}/products/${productId}`, {
      method: 'PATCH',
      body: JSON.stringify(payload),
    }),
  activateContract: (id: string) => request<Contract>(`/contracts/${id}/activate`, { method: 'POST' }),
  renewContract: (
    id: string,
    payload: {
      contracted_hours: number
      contract_value_sgd: number
      force_start_date?: string
      hourly_rate_sgd?: number | null
      quotation_id?: string | null
    },
  ) => request<Contract>(`/contracts/${id}/renew`, { method: 'POST', body: JSON.stringify(payload) }),
  listContractExcessUsage: (id: string) =>
    request<ExcessUsageRecord[]>(`/contracts/${id}/excess-usage`),

  // NEW FEATURES (not Python->PHP conversions) -- see
  // docs/backlog.md / docs/planned-work.md.
  setContractQuotationReference: (id: string, quotation_reference: string) =>
    request<Contract>(`/contracts/${id}/quotation-reference`, { method: 'POST', body: JSON.stringify({ quotation_reference }) }),
  /** Link an existing Sales Quotation (same customer) to this contract. */
  linkContractQuotation: (id: string, quotation_id: string) =>
    request<Contract>(`/contracts/${id}/quotation`, { method: 'POST', body: JSON.stringify({ quotation_id }) }),
  /** Raise a draft renewal quotation from an expiring or expired contract; accepting it renews the contract. */
  createContractRenewalQuotation: (id: string) =>
    request<{ contract: Contract; quotation_id: string; quotation_number: string }>(
      `/contracts/${id}/renewal-quotation`,
      { method: 'POST' },
    ),
  addContractSharedCustomer: (id: string, customer_id: string) =>
    request<Contract>(`/contracts/${id}/shared-customers`, { method: 'POST', body: JSON.stringify({ customer_id }) }),
  removeContractSharedCustomer: (id: string, sharedCustomerId: string) =>
    request<Contract>(`/contracts/${id}/shared-customers/${sharedCustomerId}`, { method: 'DELETE' }),

  getProductImplementationTemplate: (productId: string) =>
    request<{ product_id: string; tasks: { id: string; task_name: string; description: string | null; sort_order: number }[] }>(
      `/catalog/${productId}/implementation-template`,
    ),
  setProductImplementationTemplate: (productId: string, tasks: { task_name: string; description?: string | null }[]) =>
    request<{ product_id: string; tasks: { id: string; task_name: string; description: string | null; sort_order: number }[] }>(
      `/catalog/${productId}/implementation-template`,
      { method: 'PUT', body: JSON.stringify({ tasks }) },
    ),

  addJobOrderProducts: (jobOrderId: string, product_ids: string[]) =>
    request<JobOrder>(`/job-orders/${jobOrderId}/products`, { method: 'POST', body: JSON.stringify({ product_ids }) }),
  completeJobOrderImplementationTask: (jobOrderId: string, taskId: string) =>
    request<JobOrderImplementationTask>(`/job-orders/${jobOrderId}/implementation-tasks/${taskId}/complete`, { method: 'POST' }),
  reopenJobOrderImplementationTask: (jobOrderId: string, taskId: string) =>
    request<JobOrderImplementationTask>(`/job-orders/${jobOrderId}/implementation-tasks/${taskId}/reopen`, { method: 'POST' }),

  // ---- Contract Operation Report (Expiry / Renewal Due Listings) ----
  reportContractExpiryListing: (filters: { expiry_from?: string; expiry_to?: string; company_ids?: string } = {}) =>
    request<ContractReportRow[]>(`/reports/operations/contracts/expiry-listing${qs(filters)}`),
  exportContractExpiryListingCsv: (filters: { expiry_from?: string; expiry_to?: string; company_ids?: string } = {}) =>
    requestBlob(`/reports/operations/contracts/expiry-listing/export.csv${qs(filters)}`),
  exportContractExpiryListingExcel: (filters: { expiry_from?: string; expiry_to?: string; company_ids?: string } = {}) =>
    requestBlob(`/reports/operations/contracts/expiry-listing/export.xlsx${qs(filters)}`),

  reportContractRenewalDueListing: (filters: { as_of?: string; company_ids?: string } = {}) =>
    request<ContractReportRow[]>(`/reports/operations/contracts/renewal-due-listing${qs(filters)}`),
  exportContractRenewalDueListingCsv: (filters: { as_of?: string; company_ids?: string } = {}) =>
    requestBlob(`/reports/operations/contracts/renewal-due-listing/export.csv${qs(filters)}`),
  exportContractRenewalDueListingExcel: (filters: { as_of?: string; company_ids?: string } = {}) =>
    requestBlob(`/reports/operations/contracts/renewal-due-listing/export.xlsx${qs(filters)}`),

  // ---- Sales Dashboard ----
  salesDashboardSummary: (year?: number) => request<SalesDashboardSummary>(`/sales-dashboard/summary${qs({ year })}`),
  salesDashboardSalespeople: (year?: number) =>
    request<{ financial_year: number; sees_all: boolean; month_label: string; cards: SalespersonCard[] }>(`/sales-dashboard/salespeople${qs({ year })}`),
  salesDashboardArBreakdown: (bucket: string) =>
    request<SalesDashboardArRow[]>(`/sales-dashboard/ar-breakdown${qs({ bucket })}`),
  exportSalesDashboardArBreakdownCsv: (bucket: string) => requestBlob(`/sales-dashboard/ar-breakdown/export.csv${qs({ bucket })}`),
  exportSalesDashboardArBreakdownExcel: (bucket: string) => requestBlob(`/sales-dashboard/ar-breakdown/export.xlsx${qs({ bucket })}`),
  salesDashboardTopBillingCustomers: (year?: number) =>
    request<SalesDashboardTopCustomerRow[]>(`/sales-dashboard/top-billing-customers${qs({ year })}`),
  exportSalesDashboardTopBillingCustomersCsv: (year?: number) =>
    requestBlob(`/sales-dashboard/top-billing-customers/export.csv${qs({ year })}`),
  exportSalesDashboardTopBillingCustomersExcel: (year?: number) =>
    requestBlob(`/sales-dashboard/top-billing-customers/export.xlsx${qs({ year })}`),
  salesDashboardBottomNonActiveCustomers: (year?: number) =>
    request<SalesDashboardBottomCustomerRow[]>(`/sales-dashboard/bottom-non-active-customers${qs({ year })}`),
  exportSalesDashboardBottomNonActiveCustomersCsv: (year?: number) =>
    requestBlob(`/sales-dashboard/bottom-non-active-customers/export.csv${qs({ year })}`),
  exportSalesDashboardBottomNonActiveCustomersExcel: (year?: number) =>
    requestBlob(`/sales-dashboard/bottom-non-active-customers/export.xlsx${qs({ year })}`),

  listJobOrders: (
    filters: { status?: string; priority?: string; customer_id?: string; contract_id?: string; job_order_type?: string } = {},
  ) => request<JobOrder[]>(`/job-orders${qs(filters)}`),
  exportJobOrdersCsv: (
    filters: { status?: string; priority?: string; customer_id?: string; contract_id?: string } = {},
  ) => requestBlob(`/job-orders/export.csv${qs(filters)}`),
  exportJobOrdersExcel: (
    filters: { status?: string; priority?: string; customer_id?: string; contract_id?: string } = {},
  ) => requestBlob(`/job-orders/export.xlsx${qs(filters)}`),
  getJobOrder: (id: string) => request<JobOrder>(`/job-orders/${id}`),
  createJobOrder: (payload: {
    customer_id: string
    contract_id: string
    subject: string
    job_order_type?: JobOrderType
    billing_classification?: JobOrderBillingClassification
    priority?: JobOrderPriority
    due_date?: string | null
    is_urgent?: boolean
    // NEW FEATURE (not a Python->PHP conversion) -- see
    // docs/backlog.md / docs/planned-work.md.
    product_ids?: string[]
  }) => request<JobOrder>('/job-orders', { method: 'POST', body: JSON.stringify(payload) }),
  setJobOrderBillingClassification: (id: string, billing_classification: JobOrderBillingClassification) =>
    request<JobOrder>(`/job-orders/${id}/billing-classification`, {
      method: 'POST',
      body: JSON.stringify({ billing_classification }),
    }),
  assignJobOrder: (id: string, assigned_to_user_id: string) =>
    request<JobOrder>(`/job-orders/${id}/assign`, { method: 'POST', body: JSON.stringify({ assigned_to_user_id }) }),
  setJobOrderDueDate: (id: string, due_date: string | null) =>
    request<JobOrder>(`/job-orders/${id}/due-date`, { method: 'POST', body: JSON.stringify({ due_date }) }),
  setJobOrderUrgent: (id: string, is_urgent: boolean) =>
    request<JobOrder>(`/job-orders/${id}/urgent`, { method: 'POST', body: JSON.stringify({ is_urgent }) }),
  voidJobOrder: (id: string, reason: string) =>
    request<JobOrder>(`/job-orders/${id}/void`, { method: 'POST', body: JSON.stringify({ reason }) }),
  reopenJobOrder: (id: string) => request<JobOrder>(`/job-orders/${id}/reopen`, { method: 'POST' }),
  approveBudgetOverrun: (id: string) =>
    request<JobOrder>(`/job-orders/${id}/approve-overrun`, { method: 'POST' }),

  // ---- Project Milestones ----
  listMilestones: (jobOrderId: string) =>
    request<ProjectMilestone[]>(`/job-orders/${jobOrderId}/milestones`),
  addMilestone: (jobOrderId: string, payload: {
    milestone_type: MilestoneType
    label: string
    sort_order?: number
    planned_start?: string | null
    planned_end?: string | null
    assigned_user_id?: string | null
    notes?: string | null
  }) => request<ProjectMilestone>(`/job-orders/${jobOrderId}/milestones`, { method: 'POST', body: JSON.stringify(payload) }),
  updateMilestone: (jobOrderId: string, milestoneId: string, payload: {
    label?: string
    sort_order?: number
    planned_start?: string | null
    planned_end?: string | null
    actual_start?: string | null
    actual_end?: string | null
    assigned_user_id?: string | null
    status?: MilestoneStatus
    notes?: string | null
  }) => request<ProjectMilestone>(`/job-orders/${jobOrderId}/milestones/${milestoneId}`, { method: 'PUT', body: JSON.stringify(payload) }),
  deleteMilestone: (jobOrderId: string, milestoneId: string) =>
    request<void>(`/job-orders/${jobOrderId}/milestones/${milestoneId}`, { method: 'DELETE' }),
  initMilestoneTemplate: (jobOrderId: string) =>
    request<ProjectMilestone[]>(`/job-orders/${jobOrderId}/milestones/init-template`, { method: 'POST' }),

  supportMonitoring: () => request<SupportMonitoring>('/monitoring/support'),

  // Software Task
  listSoftwareTasks: (
    filters: { assigned_programmer_id?: string; tester_user_id?: string; untested_only?: boolean; status?: SoftwareTaskStatus } = {},
  ) =>
    request<SoftwareTask[]>(
      `/software-tasks${qs({
        assigned_programmer_id: filters.assigned_programmer_id,
        tester_user_id: filters.tester_user_id,
        untested_only: filters.untested_only ? 'true' : undefined,
        status: filters.status,
      })}`,
    ),
  exportSoftwareTasksCsv: (
    filters: { assigned_programmer_id?: string; tester_user_id?: string; untested_only?: boolean } = {},
  ) =>
    requestBlob(
      `/software-tasks/export.csv${qs({
        assigned_programmer_id: filters.assigned_programmer_id,
        tester_user_id: filters.tester_user_id,
        untested_only: filters.untested_only ? 'true' : undefined,
      })}`,
    ),
  exportSoftwareTasksExcel: (
    filters: { assigned_programmer_id?: string; tester_user_id?: string; untested_only?: boolean } = {},
  ) =>
    requestBlob(
      `/software-tasks/export.xlsx${qs({
        assigned_programmer_id: filters.assigned_programmer_id,
        tester_user_id: filters.tester_user_id,
        untested_only: filters.untested_only ? 'true' : undefined,
      })}`,
    ),
  createSoftwareTask: (payload: {
    title: string
    description?: string
    modules_affected?: string
    assigned_programmer_id?: string
    programming_finish_date?: string
    programming_hours?: number
    tester_user_id?: string
  }) => request<SoftwareTask>('/software-tasks', { method: 'POST', body: JSON.stringify(payload) }),
  updateSoftwareTask: (
    id: string,
    payload: Partial<{
      title: string
      description: string | null
      modules_affected: string | null
      assigned_programmer_id: string | null
      programming_finish_date: string | null
      programming_hours: number | null
      tester_user_id: string | null
    }>,
  ) => request<SoftwareTask>(`/software-tasks/${id}`, { method: 'PATCH', body: JSON.stringify(payload) }),
  moveSoftwareTask: (id: string, status: SoftwareTaskStatus) =>
    request<SoftwareTask>(`/software-tasks/${id}/status`, { method: 'POST', body: JSON.stringify({ status }) }),
  softwareTaskProgrammers: () => request<ProgrammerCard[]>('/software-tasks/programmers'),
  markSoftwareTaskTested: (id: string) =>
    request<SoftwareTask>(`/software-tasks/${id}/mark-tested`, { method: 'POST' }),
  reopenSoftwareTaskTesting: (id: string) =>
    request<SoftwareTask>(`/software-tasks/${id}/reopen-testing`, { method: 'POST' }),

  // ---- Incident Module ----
  emailInbox: (status: InboxEmailStatus = 'new') =>
    request<{ mailbox: InboxMailbox; counts: { new: number }; emails: InboxEmail[] }>(`/email-inbox${qs({ status })}`),
  checkEmailInbox: () => request<{ mailbox: InboxMailbox }>('/email-inbox/check', { method: 'POST' }),
  logInboxEmail: (id: string) => request<InboxEmail>(`/email-inbox/${id}/log-incident`, { method: 'POST' }),
  convertInboxEmail: (id: string) => request<InboxEmail>(`/email-inbox/${id}/convert-to-job-order`, { method: 'POST' }),
  dismissInboxEmail: (id: string, reason: string) =>
    request<InboxEmail>(`/email-inbox/${id}/dismiss`, { method: 'POST', body: JSON.stringify({ reason }) }),
  restoreInboxEmail: (id: string) => request<InboxEmail>(`/email-inbox/${id}/restore`, { method: 'POST' }),
  listIncidents: (filters: { status?: IncidentStatus; customer_id?: string } = {}) =>
    request<Incident[]>(`/incidents${qs(filters)}`),
  getIncident: (id: string) => request<Incident>(`/incidents/${id}`),
  createIncident: (payload: {
    customer_id?: string | null
    source?: IncidentSource
    subject: string
    description?: string
    sender_name?: string
    sender_email?: string
    sender_phone?: string
  }) => request<Incident>('/incidents', { method: 'POST', body: JSON.stringify(payload) }),
  setIncidentCustomer: (id: string, customer_id: string) =>
    request<Incident>(`/incidents/${id}/customer`, { method: 'PATCH', body: JSON.stringify({ customer_id }) }),
  setIncidentCallback: (id: string, assigned_to_user_id: string) =>
    request<Incident>(`/incidents/${id}/callback`, { method: 'POST', body: JSON.stringify({ assigned_to_user_id }) }),
  closeIncident: (id: string, reason: string) =>
    request<Incident>(`/incidents/${id}/close`, { method: 'POST', body: JSON.stringify({ reason }) }),
  convertIncidentToQuotation: (id: string, quotation_date: string) =>
    request<Quotation>(`/incidents/${id}/convert-to-quotation`, { method: 'POST', body: JSON.stringify({ quotation_date }) }),
  convertIncidentToJobOrder: (id: string, contract_id: string, priority: JobOrderPriority = 'normal') =>
    request<JobOrder>(`/incidents/${id}/convert-to-job-order`, { method: 'POST', body: JSON.stringify({ contract_id, priority }) }),
  convertIncidentToSoftwareTask: (id: string, assigned_programmer_id?: string | null) =>
    request<SoftwareTask>(`/incidents/${id}/convert-to-software-task`, {
      method: 'POST',
      body: JSON.stringify({ assigned_programmer_id: assigned_programmer_id ?? null }),
    }),

  // AI Assistant
  getAiSettings: () => request<AiSettings>('/ai/settings'),
  updateAiSettings: (
    payload: Partial<{
      api_key: string | null
      model: string
      redact_personal_data: boolean
      assistant_name: string
      assistant_avatar: string | null
      monthly_token_cap: number | null
      fallback_model: string | null
    }>,
  ) =>
    request<AiSettings>('/ai/settings', { method: 'PATCH', body: JSON.stringify(payload) }),
  draftWorkDescription: (notes: string, job_order_id?: string | null) =>
    request<{ description: string | null; refused: boolean; refusal_reason: string | null; model: string }>(
      '/ai/draft-work-description',
      { method: 'POST', body: JSON.stringify({ notes, job_order_id: job_order_id ?? null }) },
    ),
  testAiConnection: () =>
    request<{ ok: boolean; model: string; greeting: string | null; input_tokens: number; output_tokens: number }>('/ai/settings/test', {
      method: 'POST',
      body: '{}',
    }),
  getAiUsage: () => request<AiUsage>('/ai/usage'),
  getAiPersona: () => request<AiPersona>('/ai/persona'),
  aiChat: (messages: AiChatMessage[], context?: { type: AiChatContextType; id?: string | null } | null) =>
    request<AiChatReply>('/ai/chat', { method: 'POST', body: JSON.stringify({ messages, context: context ?? null }) }),
  getIncidentTriage: (incidentId: string) => request<IncidentTriage | null>(`/ai/incidents/${incidentId}/triage`),
  runIncidentTriage: (incidentId: string) =>
    request<IncidentTriage>(`/ai/incidents/${incidentId}/triage`, { method: 'POST', body: '{}' }),

  listServiceRecords: (filters: { job_order_id?: string; employee_user_id?: string; status?: string } = {}) =>
    request<ServiceRecord[]>(`/service-records${qs(filters)}`),
  exportServiceRecordsCsv: (filters: { job_order_id?: string; employee_user_id?: string; status?: string } = {}) =>
    requestBlob(`/service-records/export.csv${qs(filters)}`),
  exportServiceRecordsExcel: (filters: { job_order_id?: string; employee_user_id?: string; status?: string } = {}) =>
    requestBlob(`/service-records/export.xlsx${qs(filters)}`),
  submitServiceRecord: (payload: {
    job_order_id: string
    employee_user_id: string
    work_date: string
    raw_minutes: number
    completion_status?: ServiceRecordCompletion
    is_after_hours?: boolean
    work_description?: string | null
  }) => request<ServiceRecord>('/service-records', { method: 'POST', body: JSON.stringify(payload) }),
  listPendingServiceRecordApprovals: () => request<PendingServiceRecord[]>('/service-records/pending-approval'),
  rejectServiceRecord: (id: string, reason: string) =>
    request<ServiceRecord>(`/service-records/${id}/reject`, { method: 'POST', body: JSON.stringify({ reason }) }),
  approveServiceRecord: (id: string, deducted_minutes: number) =>
    request<ServiceRecord>(`/service-records/${id}/approve`, {
      method: 'POST',
      body: JSON.stringify({ deducted_minutes }),
    }),
  getServiceRecord: (id: string) => request<ServiceRecord>(`/service-records/${id}`),
  exportServiceRecordDocx: (id: string) => requestBlob(`/service-records/${id}/export.docx`),
  emailServiceRecord: (id: string) =>
    request<{ sent: boolean; to: string }>(`/service-records/${id}/email`, { method: 'POST' }),

  listExcessUsage: (pendingOnly = false) =>
    request<ExcessUsageRecord[]>(`/excess-usage${pendingOnly ? '?pending_only=true' : ''}`),
  exportExcessUsageCsv: (pendingOnly = false) =>
    requestBlob(`/excess-usage/export.csv${pendingOnly ? '?pending_only=true' : ''}`),
  exportExcessUsageExcel: (pendingOnly = false) =>
    requestBlob(`/excess-usage/export.xlsx${pendingOnly ? '?pending_only=true' : ''}`),
  decideExcessUsage: (id: string, treatment: ExcessTreatment, reason: string) =>
    request<ExcessUsageRecord>(`/excess-usage/${id}/decide`, {
      method: 'POST',
      body: JSON.stringify({ treatment, reason }),
    }),

  listInvoices: (filters: { customer_id?: string; contract_id?: string } = {}) =>
    request<Invoice[]>(`/invoices${qs(filters)}`),
  getInvoice: (id: string) => request<Invoice>(`/invoices/${id}`),
  /**
   * Raise a Sales Invoice by hand. A line naming a stock item deducts
   * it at weighted average cost on issue, and the whole invoice is
   * refused if any line asks for more than the warehouse holds.
   */
  createSalesInvoice: (payload: {
    customer_id: string
    description?: string
    lines: {
      description: string
      quantity: number
      unit_price_sgd: number
      product_id?: string
      stock_item_id?: string
      warehouse_id?: string
      unit_of_measure?: string
    }[]
  }) => request<Invoice>('/invoices', { method: 'POST', body: JSON.stringify(payload) }),
  exportInvoicesCsv: (filters: { customer_id?: string; contract_id?: string } = {}) =>
    requestBlob(`/invoices/export.csv${qs(filters)}`),
  exportInvoicesExcel: (filters: { customer_id?: string; contract_id?: string } = {}) =>
    requestBlob(`/invoices/export.xlsx${qs(filters)}`),
  exportInvoiceDocx: (id: string) => requestBlob(`/invoices/${id}/export.docx`),

  // ---- Credit Notes (BILL-003) ----
  listCreditNotes: (filters: { status?: string; customer_id?: string; invoice_id?: string } = {}) =>
    request<CreditNote[]>(`/credit-notes${qs(filters)}`),
  getCreditNote: (id: string) => request<CreditNote>(`/credit-notes/${id}`),
  raiseCreditNote: (payload: { invoice_id: string; amount_sgd: number; reason: string }) =>
    request<CreditNote>('/credit-notes', { method: 'POST', body: JSON.stringify(payload) }),
  approveCreditNote: (id: string) => request<CreditNote>(`/credit-notes/${id}/approve`, { method: 'POST' }),
  rejectCreditNote: (id: string, reason: string) =>
    request<CreditNote>(`/credit-notes/${id}/reject`, { method: 'POST', body: JSON.stringify({ reason }) }),
  withdrawCreditNote: (id: string) => request<CreditNote>(`/credit-notes/${id}/withdraw`, { method: 'POST' }),
  exportCreditNotes: (filters: { status?: string }, format: 'csv' | 'xlsx') =>
    requestBlob(`/credit-notes/export.${format}${qs(filters)}`),
  exportCreditNoteDocx: (id: string) => requestBlob(`/credit-notes/${id}/export.docx`),
  emailInvoice: (id: string) => request<{ sent: boolean; to: string }>(`/invoices/${id}/email`, { method: 'POST' }),

  // Accounts Receivable
  listPayments: (filters: { customer_id?: string; unallocated_only?: boolean } = {}) =>
    request<Payment[]>(
      `/accounts-receivable/payments${qs({
        customer_id: filters.customer_id,
        unallocated_only: filters.unallocated_only ? 'true' : undefined,
      })}`,
    ),
  recordPayment: (payload: {
    /** One of customer_id / gl_account_id; an Other receipt needs notes saying what it is. */
    customer_id?: string
    gl_account_id?: string
    payment_date: string
    amount_sgd: number
    bank_account_id: string
    method?: string
    reference?: string
    notes?: string
    allocations?: { invoice_id: string; amount_sgd: number }[]
  }) =>
    request<Payment>('/accounts-receivable/payments', {
      method: 'POST',
      body: JSON.stringify(payload),
    }),
  exportPaymentsCsv: (filters: { customer_id?: string; unallocated_only?: boolean } = {}) =>
    requestBlob(
      `/accounts-receivable/payments/export.csv${qs({
        customer_id: filters.customer_id,
        unallocated_only: filters.unallocated_only ? 'true' : undefined,
      })}`,
    ),
  exportPaymentsExcel: (filters: { customer_id?: string; unallocated_only?: boolean } = {}) =>
    requestBlob(
      `/accounts-receivable/payments/export.xlsx${qs({
        customer_id: filters.customer_id,
        unallocated_only: filters.unallocated_only ? 'true' : undefined,
      })}`,
    ),
  getPayment: (id: string) => request<Payment>(`/accounts-receivable/payments/${id}`),
  exportPaymentDocx: (id: string) => requestBlob(`/accounts-receivable/payments/${id}/export.docx`),
  emailReceipt: (id: string) =>
    request<{ sent: boolean; to: string }>(`/accounts-receivable/payments/${id}/email`, { method: 'POST' }),
  allocatePayment: (id: string, allocations: { invoice_id: string; amount_sgd: number }[]) =>
    request<Payment>(`/accounts-receivable/payments/${id}/allocate`, {
      method: 'POST',
      body: JSON.stringify({ allocations }),
    }),
  // General Ledger
  listVouchers: (filters: { voucher_type?: string; status?: string } = {}) =>
    request<JournalEntry[]>(`/ledger/vouchers${qs(filters)}`),
  exportVouchersCsv: (filters: { voucher_type?: string; status?: string } = {}) =>
    requestBlob(`/ledger/vouchers/export.csv${qs(filters)}`),
  exportVouchersExcel: (filters: { voucher_type?: string; status?: string } = {}) =>
    requestBlob(`/ledger/vouchers/export.xlsx${qs(filters)}`),
  getVoucher: (id: string) => request<JournalEntry>(`/ledger/vouchers/${id}`),
  createJournalVoucher: (payload: {
    entry_date: string
    narration: string
    post?: boolean
    lines: { account_id: string; debit_sgd?: number; credit_sgd?: number; description?: string }[]
  }) => request<JournalEntry>('/ledger/vouchers', { method: 'POST', body: JSON.stringify(payload) }),
  postVoucher: (id: string) => request<JournalEntry>(`/ledger/vouchers/${id}/post`, { method: 'POST' }),
  reverseVoucher: (id: string, reason: string) =>
    request<JournalEntry>(`/ledger/vouchers/${id}/reverse`, {
      method: 'POST',
      body: JSON.stringify({ reason }),
    }),
  trialBalance: (as_at?: string) => request<TrialBalance>(`/ledger/trial-balance${qs({ as_at })}`),
  exportTrialBalanceCsv: (as_at?: string) => requestBlob(`/ledger/trial-balance/export.csv${qs({ as_at })}`),
  exportTrialBalanceExcel: (as_at?: string) => requestBlob(`/ledger/trial-balance/export.xlsx${qs({ as_at })}`),

  // GL Transaction Ledger (account drill-down)
  glTransactions: (accountId: string, filters: { date_from?: string; date_to?: string } = {}) =>
    request<GLTransactions>(`/ledger/transactions/${accountId}${qs(filters)}`),
  exportGlTransactionsCsv: (accountId: string, filters: { date_from?: string; date_to?: string } = {}) =>
    requestBlob(`/ledger/transactions/${accountId}/export.csv${qs(filters)}`),
  exportGlTransactionsExcel: (accountId: string, filters: { date_from?: string; date_to?: string } = {}) =>
    requestBlob(`/ledger/transactions/${accountId}/export.xlsx${qs(filters)}`),

  // Accounts Payable -- suppliers are managed via listCompanyIndividuals/
  // createCompanyIndividual/updateCompanyIndividual above (is_supplier=true), not here.
  listPurchaseOrders: (filters: { supplier_id?: string; status?: string } = {}) =>
    request<PurchaseOrder[]>(`/accounts-payable/purchase-orders${qs(filters)}`),
  getPurchaseOrder: (id: string) => request<PurchaseOrder>(`/accounts-payable/purchase-orders/${id}`),
  exportPurchaseOrdersCsv: (filters: { supplier_id?: string; status?: string } = {}) =>
    requestBlob(`/accounts-payable/purchase-orders/export.csv${qs(filters)}`),
  exportPurchaseOrdersExcel: (filters: { supplier_id?: string; status?: string } = {}) =>
    requestBlob(`/accounts-payable/purchase-orders/export.xlsx${qs(filters)}`),
  exportPurchaseOrderDocx: (id: string) => requestBlob(`/accounts-payable/purchase-orders/${id}/export.docx`),
  createPurchaseOrder: (payload: {
    supplier_id: string
    order_date: string
    description: string
    amount_sgd: number
  }) => request<PurchaseOrder>('/accounts-payable/purchase-orders', { method: 'POST', body: JSON.stringify(payload) }),
  approvePurchaseOrder: (id: string) =>
    request<PurchaseOrder>(`/accounts-payable/purchase-orders/${id}/approve`, { method: 'POST' }),
  importPurchaseOrderToAP: (id: string) =>
    request<SupplierInvoice>(`/accounts-payable/purchase-orders/${id}/import-to-ap`, { method: 'POST' }),
  emailPurchaseOrder: (id: string) =>
    request<{ sent: boolean; to: string }>(`/accounts-payable/purchase-orders/${id}/email`, { method: 'POST' }),

  listBills: (filters: { supplier_id?: string; status?: string } = {}) =>
    request<SupplierInvoice[]>(`/accounts-payable/bills${qs(filters)}`),
  exportBillsCsv: (filters: { supplier_id?: string; status?: string } = {}) =>
    requestBlob(`/accounts-payable/bills/export.csv${qs(filters)}`),
  exportBillsExcel: (filters: { supplier_id?: string; status?: string } = {}) =>
    requestBlob(`/accounts-payable/bills/export.xlsx${qs(filters)}`),
  createBill: (payload: {
    supplier_id: string
    purchase_order_id?: string | null
    supplier_invoice_no?: string
    invoice_date: string
    description: string
    amount_sgd: number
    /** A purchase tax code; GST is worked out from its rate, never keyed in. Default TX. */
    tax_code?: string
    expense_account_id?: string | null
  }) => request<SupplierInvoice>('/accounts-payable/bills', { method: 'POST', body: JSON.stringify(payload) }),

  listSupplierPayments: (supplierId?: string) =>
    request<SupplierPayment[]>(`/accounts-payable/payments${qs({ supplier_id: supplierId })}`),
  exportSupplierPaymentsCsv: (supplierId?: string) =>
    requestBlob(`/accounts-payable/payments/export.csv${qs({ supplier_id: supplierId })}`),
  exportSupplierPaymentsExcel: (supplierId?: string) =>
    requestBlob(`/accounts-payable/payments/export.xlsx${qs({ supplier_id: supplierId })}`),
  getSupplierPayment: (id: string) => request<SupplierPayment>(`/accounts-payable/payments/${id}`),
  exportSupplierPaymentDocx: (id: string) => requestBlob(`/accounts-payable/payments/${id}/export.docx`),
  emailSupplierPayment: (id: string) =>
    request<{ sent: boolean; to: string }>(`/accounts-payable/payments/${id}/email`, { method: 'POST' }),
  recordSupplierPayment: (payload: {
    /** One of supplier_id / gl_account_id; an Other payment needs notes saying what it is. */
    supplier_id?: string
    gl_account_id?: string
    payment_date: string
    amount_sgd: number
    bank_account_id: string
    method?: string
    reference?: string
    notes?: string
    allocations?: { supplier_invoice_id: string; amount_sgd: number }[]
  }) => request<SupplierPayment>('/accounts-payable/payments', { method: 'POST', body: JSON.stringify(payload) }),
  allocateSupplierPayment: (id: string, allocations: { supplier_invoice_id: string; amount_sgd: number }[]) =>
    request<SupplierPayment>(`/accounts-payable/payments/${id}/allocate`, {
      method: 'POST',
      body: JSON.stringify({ allocations }),
    }),
  apAging: () => request<APAgingReport>('/accounts-payable/aging'),
  exportApAgingCsv: (as_at?: string) => requestBlob(`/accounts-payable/aging/export.csv${qs({ as_at })}`),
  exportApAgingExcel: (as_at?: string) => requestBlob(`/accounts-payable/aging/export.xlsx${qs({ as_at })}`),

  listAccounts: (filters: { include_inactive?: boolean; account_type?: string } = {}) =>
    request<Account[]>(
      `/accounts${qs({
        include_inactive: filters.include_inactive ? 'true' : undefined,
        account_type: filters.account_type,
      })}`,
    ),
  exportAccountsCsv: (filters: { include_inactive?: boolean; account_type?: string } = {}) =>
    requestBlob(
      `/accounts/export.csv${qs({
        include_inactive: filters.include_inactive ? 'true' : undefined,
        account_type: filters.account_type,
      })}`,
    ),
  exportAccountsExcel: (filters: { include_inactive?: boolean; account_type?: string } = {}) =>
    requestBlob(
      `/accounts/export.xlsx${qs({
        include_inactive: filters.include_inactive ? 'true' : undefined,
        account_type: filters.account_type,
      })}`,
    ),
  createAccount: (payload: { code: string; name: string; account_type: AccountType }) =>
    request<Account>('/accounts', { method: 'POST', body: JSON.stringify(payload) }),
  updateAccount: (
    id: string,
    payload: { code?: string; name?: string; account_type?: AccountType; is_active?: boolean },
  ) => request<Account>(`/accounts/${id}`, { method: 'PATCH', body: JSON.stringify(payload) }),

  // Reference Monitor -- GL sub-codes under one Chart of Accounts row.
  listReferenceCodes: (filters: { include_inactive?: boolean; account_id?: string } = {}) =>
    request<ReferenceCode[]>(
      `/reference-codes${qs({
        include_inactive: filters.include_inactive ? 'true' : undefined,
        account_id: filters.account_id,
      })}`,
    ),
  exportReferenceCodesCsv: (filters: { include_inactive?: boolean; account_id?: string } = {}) =>
    requestBlob(
      `/reference-codes/export.csv${qs({
        include_inactive: filters.include_inactive ? 'true' : undefined,
        account_id: filters.account_id,
      })}`,
    ),
  exportReferenceCodesExcel: (filters: { include_inactive?: boolean; account_id?: string } = {}) =>
    requestBlob(
      `/reference-codes/export.xlsx${qs({
        include_inactive: filters.include_inactive ? 'true' : undefined,
        account_id: filters.account_id,
      })}`,
    ),
  createReferenceCode: (payload: { account_id: string; code: string; name: string }) =>
    request<ReferenceCode>('/reference-codes', { method: 'POST', body: JSON.stringify(payload) }),
  updateReferenceCode: (
    id: string,
    payload: Partial<{ account_id: string; code: string; name: string; is_active: boolean }>,
  ) => request<ReferenceCode>(`/reference-codes/${id}`, { method: 'PATCH', body: JSON.stringify(payload) }),

  arAging: () => request<AgingReport>('/accounts-receivable/aging'),
  exportArAgingCsv: (as_at?: string) => requestBlob(`/accounts-receivable/aging/export.csv${qs({ as_at })}`),
  exportArAgingExcel: (as_at?: string) => requestBlob(`/accounts-receivable/aging/export.xlsx${qs({ as_at })}`),
  customerStatement: (customerId: string) =>
    request<CompanyIndividualStatement>(`/accounts-receivable/statement/${customerId}`),
  exportCompanyIndividualStatementDocx: (customerId: string) =>
    requestBlob(`/accounts-receivable/statement/${customerId}/export.docx`),
  emailCompanyIndividualStatement: (customerId: string) =>
    request<{ sent: boolean; to: string }>(`/accounts-receivable/statement/${customerId}/email`, { method: 'POST' }),
  // GL posting + Bank step (ACC-001..004, docs/gl-posting-design.md).
  // Posting is automatic on create; these are the explicit reversible actions.
  unglInvoice: (invoiceId: string, reason: string) =>
    request<{ status: string; reversal_voucher: string }>(`/accounts-receivable/invoices/${invoiceId}/ungl`, {
      method: 'POST', body: JSON.stringify({ reason }),
    }),
  bankReceipt: (paymentId: string) =>
    request<{ status: string; transaction_number: string }>(`/accounts-receivable/payments/${paymentId}/bank`, { method: 'POST' }),
  unbankReceipt: (paymentId: string, reason: string) =>
    request<{ status: string; transaction_number: string }>(`/accounts-receivable/payments/${paymentId}/unbank`, {
      method: 'POST', body: JSON.stringify({ reason }),
    }),
  unglReceipt: (paymentId: string, reason: string) =>
    request<{ status: string; reversal_voucher: string }>(`/accounts-receivable/payments/${paymentId}/ungl`, {
      method: 'POST', body: JSON.stringify({ reason }),
    }),
  /** Re-check a bill flagged as a matching exception against its PO (open item 4.5). */
  rematchBill: (billId: string) => request<SupplierInvoice>(`/accounts-payable/bills/${billId}/rematch`, { method: 'POST' }),
  unglBill: (billId: string, reason: string) =>
    request<{ status: string; reversal_voucher: string }>(`/accounts-payable/bills/${billId}/ungl`, {
      method: 'POST', body: JSON.stringify({ reason }),
    }),
  bankSupplierPayment: (paymentId: string) =>
    request<{ status: string; transaction_number: string }>(`/accounts-payable/payments/${paymentId}/bank`, { method: 'POST' }),
  unbankSupplierPayment: (paymentId: string, reason: string) =>
    request<{ status: string; transaction_number: string }>(`/accounts-payable/payments/${paymentId}/unbank`, {
      method: 'POST', body: JSON.stringify({ reason }),
    }),
  unglSupplierPayment: (paymentId: string, reason: string) =>
    request<{ status: string; reversal_voucher: string }>(`/accounts-payable/payments/${paymentId}/ungl`, {
      method: 'POST', body: JSON.stringify({ reason }),
    }),

  writeOffInvoice: (invoiceId: string, reason: string) =>
    request<Invoice>(`/accounts-receivable/invoices/${invoiceId}/write-off`, {
      method: 'POST',
      body: JSON.stringify({ reason }),
    }),
  flagInvoiceDispute: (invoiceId: string, is_disputed: boolean, note?: string) =>
    request<Invoice>(`/accounts-receivable/invoices/${invoiceId}/dispute`, {
      method: 'POST',
      body: JSON.stringify({ is_disputed, note }),
    }),

  // Event Logs
  listEventLogs: (filters: EventLogFilters & { limit?: number; offset?: number } = {}) =>
    request<AuditLogEntry[]>(`/event-logs${qs(filters)}`),
  exportEventLogsCsv: (filters: EventLogFilters = {}) => requestBlob(`/event-logs/export.csv${qs(filters)}`),
  exportEventLogsExcel: (filters: EventLogFilters = {}) => requestBlob(`/event-logs/export.xlsx${qs(filters)}`),

  // Product / Service Catalog
  listCatalog: (includeInactive = false) =>
    request<Product[]>(`/catalog${includeInactive ? '?include_inactive=true' : ''}`),
  exportCatalogCsv: (includeInactive = false) =>
    requestBlob(`/catalog/export.csv${includeInactive ? '?include_inactive=true' : ''}`),
  exportCatalogExcel: (includeInactive = false) =>
    requestBlob(`/catalog/export.xlsx${includeInactive ? '?include_inactive=true' : ''}`),
  createCatalogItem: (payload: {
    product_type: ProductType
    name: string
    internal_reference?: string
    product_category?: string
    tags?: string
    sales_price_sgd: number
    cost_sgd?: number
    unit_of_measure?: string
    tax_code?: string
    default_reference_code_id?: string | null
    is_stock?: boolean
  }) => request<Product>('/catalog', { method: 'POST', body: JSON.stringify(payload) }),
  updateCatalogItem: (
    id: string,
    payload: Partial<{
      product_type: ProductType
      name: string
      internal_reference: string | null
      product_category: string | null
      tags: string | null
      sales_price_sgd: number
      cost_sgd: number | null
      unit_of_measure: string | null
      tax_code: string
      default_reference_code_id: string | null
      is_stock: boolean
      is_active: boolean
    }>,
  ) => request<Product>(`/catalog/${id}`, { method: 'PATCH', body: JSON.stringify(payload) }),

  // Sales Quotation
  listQuotations: (filters: { customer_id?: string; status?: string } = {}) =>
    request<Quotation[]>(`/quotations${qs(filters)}`),
  exportQuotationsCsv: (filters: { customer_id?: string; status?: string } = {}) =>
    requestBlob(`/quotations/export.csv${qs(filters)}`),
  exportQuotationsExcel: (filters: { customer_id?: string; status?: string } = {}) =>
    requestBlob(`/quotations/export.xlsx${qs(filters)}`),
  getQuotation: (id: string) => request<Quotation>(`/quotations/${id}`),
  exportQuotationDocx: (id: string) => requestBlob(`/quotations/${id}/export.docx`),
  emailQuotation: (id: string) => request<{ sent: boolean; to: string }>(`/quotations/${id}/email`, { method: 'POST' }),
  createQuotation: (payload: {
    customer_id: string
    prospect_id?: string | null
    quotation_date: string
    valid_until?: string
    notes?: string
    lines: {
      product_id?: string | null
      description: string
      unit_of_measure?: string
      quantity: number
      unit_price_sgd: number
      reference_code_id?: string | null
      cost_sgd?: number | null
    }[]
  }) => request<Quotation>('/quotations', { method: 'POST', body: JSON.stringify(payload) }),
  /** Put a quotation under a prospect (or off one, with null); its invoices move with it. */
  linkQuotationProspect: (id: string, prospectId: string | null) =>
    request<Quotation>(`/quotations/${id}/prospect`, { method: 'POST', body: JSON.stringify({ prospect_id: prospectId }) }),

  // Prospect / Leads
  listProspects: (filters: { status?: string; customer_id?: string; salesperson_user_id?: string; q?: string } = {}) =>
    request<Prospect[]>(`/prospects${qs(filters)}`),
  exportProspectsCsv: (filters: { status?: string; customer_id?: string; salesperson_user_id?: string; q?: string } = {}) =>
    requestBlob(`/prospects/export.csv${qs(filters)}`),
  exportProspectsExcel: (filters: { status?: string; customer_id?: string; salesperson_user_id?: string; q?: string } = {}) =>
    requestBlob(`/prospects/export.xlsx${qs(filters)}`),
  getProspect: (id: string) => request<ProspectDetail>(`/prospects/${id}`),
  createProspect: (payload: ProspectPayload) =>
    request<Prospect>('/prospects', { method: 'POST', body: JSON.stringify(payload) }),
  updateProspect: (id: string, payload: ProspectPayload) =>
    request<Prospect>(`/prospects/${id}`, { method: 'PATCH', body: JSON.stringify(payload) }),
  listProspectActivities: (filters: { prospect_id?: string; customer_id?: string; activity_type?: string; status?: string; created_by_user_id?: string } = {}) =>
    request<ProspectActivity[]>(`/prospect-activities${qs(filters)}`),
  getProspectActivity: (id: string) => request<ProspectActivity>(`/prospect-activities/${id}`),
  createProspectActivity: (payload: ProspectActivityPayload) =>
    request<ProspectActivity>('/prospect-activities', { method: 'POST', body: JSON.stringify(payload) }),
  updateProspectActivity: (id: string, payload: ProspectActivityPayload) =>
    request<ProspectActivity>(`/prospect-activities/${id}`, { method: 'PATCH', body: JSON.stringify(payload) }),
  voidProspectActivity: (id: string, reason: string) =>
    request<ProspectActivity>(`/prospect-activities/${id}/void`, { method: 'POST', body: JSON.stringify({ reason }) }),

  submitQuotation: (id: string) => request<Quotation>(`/quotations/${id}/submit`, { method: 'POST' }),
  approveQuotation: (id: string) => request<Quotation>(`/quotations/${id}/approve`, { method: 'POST' }),
  sendBackQuotation: (id: string, reason: string) =>
    request<Quotation>(`/quotations/${id}/send-back`, { method: 'POST', body: JSON.stringify({ reason }) }),
  sendQuotation: (id: string) => request<Quotation>(`/quotations/${id}/send`, { method: 'POST' }),
  toReviseQuotation: (id: string, reason: string) =>
    request<Quotation>(`/quotations/${id}/to-revise`, { method: 'POST', body: JSON.stringify({ reason }) }),
  /** Raises the revision: a new draft copy of a to-revise quotation, linked back to it. */
  reviseQuotation: (id: string) => request<Quotation>(`/quotations/${id}/revise`, { method: 'POST' }),
  acceptQuotation: (id: string, warehouseId?: string) =>
    request<{ quotation: Quotation; message: string }>(`/quotations/${id}/accept`, {
      method: 'POST',
      body: JSON.stringify(warehouseId ? { warehouse_id: warehouseId } : {}),
    }),
  rejectQuotation: (id: string) => request<Quotation>(`/quotations/${id}/reject`, { method: 'POST' }),

  // ---- Operations Reports ----
  reportContracts: (filters: ContractReportFilters = {}) =>
    request<ContractReportRow[]>(`/reports/operations/contracts${qs(filters)}`),
  exportContractsReportCsv: (filters: ContractReportFilters = {}) =>
    requestBlob(`/reports/operations/contracts/export.csv${qs(filters)}`),
  exportContractsReportExcel: (filters: ContractReportFilters = {}) =>
    requestBlob(`/reports/operations/contracts/export.xlsx${qs(filters)}`),

  reportJobOrders: (filters: JobOrderReportFilters = {}) =>
    request<JobOrderReportRow[]>(`/reports/operations/job-orders${qs(filters)}`),
  exportJobOrdersReportCsv: (filters: JobOrderReportFilters = {}) =>
    requestBlob(`/reports/operations/job-orders/export.csv${qs(filters)}`),
  exportJobOrdersReportExcel: (filters: JobOrderReportFilters = {}) =>
    requestBlob(`/reports/operations/job-orders/export.xlsx${qs(filters)}`),

  reportServiceRecords: (filters: ServiceRecordReportFilters = {}) =>
    request<ServiceRecordReportRow[]>(`/reports/operations/service-records${qs(filters)}`),
  operationsFilterOptions: (company_ids: string) =>
    request<OperationsFilterOptions>(`/reports/operations/filter-options${qs({ company_ids })}`),
  exportServiceRecordsReportCsv: (filters: ServiceRecordReportFilters = {}) =>
    requestBlob(`/reports/operations/service-records/export.csv${qs(filters)}`),
  exportServiceRecordsReportExcel: (filters: ServiceRecordReportFilters = {}) =>
    requestBlob(`/reports/operations/service-records/export.xlsx${qs(filters)}`),

  reportCompanyIndividualProductUsage: (filters: CompanyIndividualProductUsageFilters = {}) =>
    request<CompanyIndividualProductUsageRow[]>(`/reports/operations/customer-product-usage${qs(filters)}`),
  exportCompanyIndividualProductUsageCsv: (filters: CompanyIndividualProductUsageFilters = {}) =>
    requestBlob(`/reports/operations/customer-product-usage/export.csv${qs(filters)}`),
  exportCompanyIndividualProductUsageExcel: (filters: CompanyIndividualProductUsageFilters = {}) =>
    requestBlob(`/reports/operations/customer-product-usage/export.xlsx${qs(filters)}`),

  // ---- Accounting Reports ----
  reportArAging: (f: AccountingReportFilters = {}) => request<AgingReport>(`/reports/accounting/ar-aging${qs(f)}`),
  exportArAgingReportCsv: (f: AccountingReportFilters = {}) => requestBlob(`/reports/accounting/ar-aging/export.csv${qs(f)}`),
  exportArAgingReportExcel: (f: AccountingReportFilters = {}) => requestBlob(`/reports/accounting/ar-aging/export.xlsx${qs(f)}`),

  reportApAging: (f: AccountingReportFilters = {}) => request<APAgingReport>(`/reports/accounting/ap-aging${qs(f)}`),
  exportApAgingReportCsv: (f: AccountingReportFilters = {}) => requestBlob(`/reports/accounting/ap-aging/export.csv${qs(f)}`),
  exportApAgingReportExcel: (f: AccountingReportFilters = {}) => requestBlob(`/reports/accounting/ap-aging/export.xlsx${qs(f)}`),

  reportTrialBalance: (f: AccountingReportFilters = {}) => request<TrialBalance>(`/reports/accounting/trial-balance${qs(f)}`),
  exportTrialBalanceReportCsv: (f: AccountingReportFilters = {}) => requestBlob(`/reports/accounting/trial-balance/export.csv${qs(f)}`),
  exportTrialBalanceReportExcel: (f: AccountingReportFilters = {}) => requestBlob(`/reports/accounting/trial-balance/export.xlsx${qs(f)}`),

  reportGstSupporting: (f: AccountingReportFilters & { direction?: 'output' | 'input' } = {}) =>
    request<{ period_start: string; period_end: string; companies?: string[]; rows: GstSupportingRow[]; missing_periods: GSTReturn['missing_periods'] }>(
      `/reports/accounting/gst-supporting${qs(f)}`,
    ),
  exportGstSupportingCsv: (f: AccountingReportFilters & { direction?: 'output' | 'input' } = {}) =>
    requestBlob(`/reports/accounting/gst-supporting/export.csv${qs(f)}`),
  exportGstSupportingExcel: (f: AccountingReportFilters & { direction?: 'output' | 'input' } = {}) =>
    requestBlob(`/reports/accounting/gst-supporting/export.xlsx${qs(f)}`),
  reportGstReturn: (f: AccountingReportFilters = {}) => request<GSTReturn>(`/reports/accounting/gst-return${qs(f)}`),
  exportGstReturnCsv: (f: AccountingReportFilters = {}) => requestBlob(`/reports/accounting/gst-return/export.csv${qs(f)}`),
  exportGstReturnExcel: (f: AccountingReportFilters = {}) => requestBlob(`/reports/accounting/gst-return/export.xlsx${qs(f)}`),

  reportSalesGP: (f: AccountingReportFilters = {}) => request<SalesGPReport>(`/reports/accounting/sales-gp${qs(f)}`),
  exportSalesGPReportCsv: (f: AccountingReportFilters = {}) => requestBlob(`/reports/accounting/sales-gp/export.csv${qs(f)}`),
  exportSalesGPReportExcel: (f: AccountingReportFilters = {}) => requestBlob(`/reports/accounting/sales-gp/export.xlsx${qs(f)}`),

  reportCommission: (f: AccountingReportFilters = {}) => request<CommissionReport>(`/reports/accounting/commission${qs(f)}`),
  exportCommissionReportCsv: (f: AccountingReportFilters = {}) => requestBlob(`/reports/accounting/commission/export.csv${qs(f)}`),
  exportCommissionReportExcel: (f: AccountingReportFilters = {}) => requestBlob(`/reports/accounting/commission/export.xlsx${qs(f)}`),

  reportFilterOptions: (company_ids: string) =>
    request<ReportFilterOptions>(`/reports/accounting/filter-options${qs({ company_ids })}`),
  getCommissionSettings: () => request<{ rate_percent: number }>('/reports/accounting/commission-settings'),
  updateCommissionSettings: (rate_percent: number) =>
    request<{ rate_percent: number }>('/reports/accounting/commission-settings', {
      method: 'PUT',
      body: JSON.stringify({ rate_percent }),
    }),

  // ---- Commission Payouts (6.3/6.4/6.5) ----
  generateCommissionPayouts: (period_month: string) =>
    request<CommissionPayout[]>('/commissions/payouts/generate', {
      method: 'POST',
      body: JSON.stringify({ period_month }),
    }),
  listCommissionPayouts: (filters: { period_month?: string; status?: string; sales_staff_id?: string } = {}) =>
    request<CommissionPayout[]>(`/commissions/payouts${qs(filters)}`),
  getCommissionPayout: (id: string) =>
    request<CommissionPayout>(`/commissions/payouts/${id}`),
  submitCommissionPayout: (id: string) =>
    request<CommissionPayout>(`/commissions/payouts/${id}/submit`, { method: 'POST' }),
  approveCommissionPayout: (id: string) =>
    request<CommissionPayout>(`/commissions/payouts/${id}/approve`, { method: 'POST' }),
  rejectCommissionPayout: (id: string, reason?: string) =>
    request<CommissionPayout>(`/commissions/payouts/${id}/reject`, {
      method: 'POST',
      body: JSON.stringify({ reason }),
    }),
  payCommissionPayout: (id: string, paid_date: string, paid_reference?: string) =>
    request<CommissionPayout>(`/commissions/payouts/${id}/pay`, {
      method: 'POST',
      body: JSON.stringify({ paid_date, paid_reference }),
    }),
  cancelCommissionPayout: (id: string) =>
    request<CommissionPayout>(`/commissions/payouts/${id}/cancel`, { method: 'POST' }),
  submitAllCommissionPayouts: (period_month: string) =>
    request<CommissionPayout[]>('/commissions/payouts/submit-all', {
      method: 'POST',
      body: JSON.stringify({ period_month }),
    }),
  approveAllCommissionPayouts: (period_month: string) =>
    request<CommissionPayout[]>('/commissions/payouts/approve-all', {
      method: 'POST',
      body: JSON.stringify({ period_month }),
    }),

  // ---- GL Types ----
  listGLTypes: (includeInactive = false) =>
    request<GLType[]>(`/gl-types${includeInactive ? '?include_inactive=true' : ''}`),
  createGLType: (payload: { code: string; name: string; account_type: AccountType }) =>
    request<GLType>('/gl-types', { method: 'POST', body: JSON.stringify(payload) }),
  updateGLType: (id: string, payload: Partial<{ code: string; name: string; account_type: AccountType; is_active: boolean }>) =>
    request<GLType>(`/gl-types/${id}`, { method: 'PATCH', body: JSON.stringify(payload) }),

  // ---- Setup Lists ----
  listSetupItems: (filters: { list_type?: SetupListType; include_inactive?: boolean; parent_code?: string } = {}) =>
    request<SetupListItem[]>(`/setup-lists${qs(filters)}`),
  createSetupItem: (payload: {
    list_type: SetupListType
    code: string
    name: string
    parent_code?: string | null
    sort_order?: number
  }) => request<SetupListItem>('/setup-lists', { method: 'POST', body: JSON.stringify(payload) }),
  updateSetupItem: (
    id: string,
    payload: Partial<{ code: string; name: string; parent_code: string | null; sort_order: number; is_active: boolean }>,
  ) => request<SetupListItem>(`/setup-lists/${id}`, { method: 'PATCH', body: JSON.stringify(payload) }),
  exportSetupItemsCsv: (filters: { list_type?: SetupListType; include_inactive?: boolean } = {}) =>
    requestBlob(`/setup-lists/export.csv${qs(filters)}`),
  exportSetupItemsExcel: (filters: { list_type?: SetupListType; include_inactive?: boolean } = {}) =>
    requestBlob(`/setup-lists/export.xlsx${qs(filters)}`),

  // ---- Currency Rate Table ----
  listCurrencyRates: (currency_code?: string) =>
    request<CurrencyRate[]>(`/currency-rates${qs({ currency_code })}`),
  createCurrencyRate: (payload: { currency_code: string; rate_to_base: number; effective_date: string }) =>
    request<CurrencyRate>('/currency-rates', { method: 'POST', body: JSON.stringify(payload) }),
  updateCurrencyRate: (id: string, payload: Partial<{ rate_to_base: number; is_active: boolean }>) =>
    request<CurrencyRate>(`/currency-rates/${id}`, { method: 'PATCH', body: JSON.stringify(payload) }),

  // ---- Bank Master File ----
  listBankAccounts: (includeInactive = false) =>
    request<BankAccount[]>(`/bank-accounts${includeInactive ? '?include_inactive=true' : ''}`),
  getBankAccount: (id: string) => request<BankAccount>(`/bank-accounts/${id}`),
  createBankAccount: (payload: {
    bank_name: string
    account_name: string
    account_number: string
    branch?: string
    swift_code?: string
    currency_code?: string
    gl_account_id?: string | null
    opening_balance_sgd?: number
    opening_balance_date?: string | null
  }) => request<BankAccount>('/bank-accounts', { method: 'POST', body: JSON.stringify(payload) }),
  updateBankAccount: (
    id: string,
    payload: Partial<{
      bank_name: string
      account_name: string
      account_number: string
      branch: string | null
      swift_code: string | null
      currency_code: string
      gl_account_id: string | null
      opening_balance_sgd: number
      opening_balance_date: string | null
      is_active: boolean
    }>,
  ) => request<BankAccount>(`/bank-accounts/${id}`, { method: 'PATCH', body: JSON.stringify(payload) }),
  exportBankAccountsCsv: (includeInactive = false) =>
    requestBlob(`/bank-accounts/export.csv${includeInactive ? '?include_inactive=true' : ''}`),
  exportBankAccountsExcel: (includeInactive = false) =>
    requestBlob(`/bank-accounts/export.xlsx${includeInactive ? '?include_inactive=true' : ''}`),

  // ---- Bank Book: Bank Transactions (debit/credit + running ledger balance) ----
  // Separate from the General Ledger's Journal Vouchers -- confirmed with Dennis, 2026-09-12.
  listBankTransactions: (bankAccountId: string) =>
    request<BankLedger>(`/bank-accounts/${bankAccountId}/transactions`),
  // No createBankTransaction: lines come from a Receipt / Payment Voucher's Bank step (#49 / 31.1).
  voidBankTransaction: (transactionId: string, reason: string) =>
    request<BankTransaction>(`/bank-transactions/${transactionId}/void`, {
      method: 'POST',
      body: JSON.stringify({ reason }),
    }),
  toggleBankTransactionReconciled: (transactionId: string) =>
    request<BankTransaction>(`/bank-transactions/${transactionId}/toggle-reconciled`, { method: 'POST' }),

  // ---- Bank Book: Bank Reconciliation ----
  listBankReconciliations: (bankAccountId: string) =>
    request<BankReconciliation[]>(`/bank-accounts/${bankAccountId}/reconciliations`),
  createBankReconciliation: (
    bankAccountId: string,
    payload: {
      statement_date: string
      statement_balance_sgd: number
      reconciled_transaction_ids: string[]
      note?: string | null
    },
  ) =>
    request<BankReconciliation>(`/bank-accounts/${bankAccountId}/reconciliations`, {
      method: 'POST',
      body: JSON.stringify(payload),
    }),

  // ---- Tax Type (Tax Code maintenance) ----
  listTaxCodes: (includeInactive = false, kind?: 'supply' | 'purchase') =>
    request<TaxCode[]>(`/tax-codes${qs({ include_inactive: includeInactive ? 'true' : undefined, kind })}`),
  createTaxCode: (payload: { code: string; name: string; rate_percent: number; kind?: 'supply' | 'purchase'; form5_box?: string | null }) =>
    request<TaxCode>('/tax-codes', { method: 'POST', body: JSON.stringify(payload) }),
  updateTaxCode: (id: string, payload: Partial<{ code: string; name: string; rate_percent: number; is_active: boolean; kind: 'supply' | 'purchase'; form5_box: string | null }>) =>
    request<TaxCode>(`/tax-codes/${id}`, { method: 'PATCH', body: JSON.stringify(payload) }),
  exportTaxCodesCsv: (includeInactive = false) =>
    requestBlob(`/tax-codes/export.csv${includeInactive ? '?include_inactive=true' : ''}`),
  exportTaxCodesExcel: (includeInactive = false) =>
    requestBlob(`/tax-codes/export.xlsx${includeInactive ? '?include_inactive=true' : ''}`),

  // ---- Document Control ----
  listDocumentSequences: () => request<DocumentSequence[]>('/document-control'),
  updateDocumentSequence: (id: string, payload: { last_number: number; reason: string }) =>
    request<DocumentSequence>(`/document-control/${id}`, { method: 'PATCH', body: JSON.stringify(payload) }),
  listDocumentNumberFormats: () => request<DocumentNumberFormat[]>('/document-control/formats'),
  updateDocumentNumberFormat: (
    docKind: string,
    payload: { prefix: string; number_length: number; include_year: boolean; reason: string },
  ) =>
    request<DocumentNumberFormat>(`/document-control/formats/${docKind}`, {
      method: 'PUT',
      body: JSON.stringify(payload),
    }),

  // ---- Accounting Periods / Year-End Closing ----
  listAccountingPeriods: (fiscal_year?: number) =>
    request<AccountingPeriod[]>(`/accounting-periods${qs({ fiscal_year })}`),
  createAccountingPeriod: (payload: { fiscal_year: number; name: string; period_start: string; period_end: string }) =>
    request<AccountingPeriod>('/accounting-periods', { method: 'POST', body: JSON.stringify(payload) }),
  togglePeriodLock: (id: string, payload: { doc_type: PeriodDocType; operation: PeriodOperation; locked: boolean }) =>
    request<AccountingPeriod>(`/accounting-periods/${id}/toggle-lock`, { method: 'POST', body: JSON.stringify(payload) }),
  closeAccountingPeriod: (id: string) =>
    request<AccountingPeriod>(`/accounting-periods/${id}/close`, { method: 'POST' }),
  periodGst: (id: string) =>
    request<{ period: AccountingPeriod; current: GstReturnSaved | null; history: GstReturnSaved[] }>(`/accounting-periods/${id}/gst`),
  calculatePeriodGst: (id: string) => request<GstReturnSaved>(`/accounting-periods/${id}/gst-calculate`, { method: 'POST' }),
  submitPeriodGst: (id: string) => request<GstReturnSaved>(`/accounting-periods/${id}/gst-submit`, { method: 'POST' }),
  revisePeriodGst: (id: string, reason: string) =>
    request<GstReturnSaved>(`/accounting-periods/${id}/gst-revise`, { method: 'POST', body: JSON.stringify({ reason }) }),
  reopenAccountingPeriod: (id: string) =>
    request<AccountingPeriod>(`/accounting-periods/${id}/reopen`, { method: 'POST' }),
  listFiscalYearClosures: () => request<FiscalYearClosure[]>('/accounting-periods/closures'),
  closeFiscalYear: (payload: { fiscal_year: number; retained_earnings_account_id: string }) =>
    request<FiscalYearClosure>('/accounting-periods/close-fiscal-year', {
      method: 'POST',
      body: JSON.stringify(payload),
    }),

  // ---- Ops Dashboard ----
  getOpsDashboard: (staffId?: string) => request<OpsDashboard>(`/ops-dashboard${qs({ staff_id: staffId })}`),
  createOpsTaskCategory: (payload: { name: string; cadence_label?: string; owner_user_id?: string }) =>
    request<OpsTaskCategory>('/ops-dashboard/categories', { method: 'POST', body: JSON.stringify(payload) }),
  createOpsTask: (payload: {
    category_id: string
    title: string
    status?: OpsTaskStatus
    next_action?: string
    owner_label?: string
    due_label?: string
    follow_up_staff_id?: string
    follow_up_date?: string
  }) => request<OpsTask>('/ops-dashboard/tasks', { method: 'POST', body: JSON.stringify(payload) }),
  updateOpsTask: (
    id: string,
    payload: Partial<{
      title: string
      status: OpsTaskStatus
      next_action: string
      owner_label: string
      due_label: string
      follow_up_staff_id: string
      follow_up_date: string
      clear_follow_up_staff: boolean
      clear_follow_up_date: boolean
    }>,
  ) => request<OpsTask>(`/ops-dashboard/tasks/${id}`, { method: 'PATCH', body: JSON.stringify(payload) }),
  archiveOpsTask: (id: string) => request<OpsTask>(`/ops-dashboard/tasks/${id}/archive`, { method: 'POST' }),

  // ---- eDocument Attachments ----
  uploadDocumentAttachment: async (entityType: DocumentEntityType, entityId: string, file: File, description?: string): Promise<DocumentAttachment> => {
    const formData = new FormData()
    formData.append('file', file)
    if (description) formData.append('description', description)
    const token = getToken()
    const res = await fetch(`/api/documents/${entityType}/${entityId}/attachments`, {
      method: 'POST',
      headers: {
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
        'X-Device-Id': getDeviceId(),
      },
      body: formData,
    })
    if (!res.ok) {
      let detail = res.statusText
      try { const b = await res.json(); detail = b.detail ?? detail } catch { /* */ }
      throw new Error(detail)
    }
    return res.json()
  },
  listDocumentAttachments: (entityType: DocumentEntityType, entityId: string) =>
    request<DocumentAttachment[]>(`/documents/${entityType}/${entityId}/attachments`),
  downloadDocumentAttachmentUrl: (entityType: DocumentEntityType, entityId: string, attachmentId: string) =>
    `/api/documents/${entityType}/${entityId}/attachments/${attachmentId}/download`,
  deleteDocumentAttachment: (entityType: DocumentEntityType, entityId: string, attachmentId: string) =>
    request<void>(`/documents/${entityType}/${entityId}/attachments/${attachmentId}`, { method: 'DELETE' }),

  // ---- eSignature ----
  addDocumentSignature: (entityType: DocumentEntityType, entityId: string, payload: {
    signer_name: string
    signature_data_uri: string
    role_label?: string
  }) =>
    request<DocumentSignature>(`/documents/${entityType}/${entityId}/signatures`, {
      method: 'POST',
      body: JSON.stringify({ entity_type: entityType, entity_id: entityId, ...payload }),
    }),
  listDocumentSignatures: (entityType: DocumentEntityType, entityId: string) =>
    request<DocumentSignature[]>(`/documents/${entityType}/${entityId}/signatures`),

  // ---- eApproval Master ----
  listApprovalAuthorities: () => request<ApprovalAuthority[]>('/approvals/authorities'),
  getApprovalAuthority: (id: string) => request<ApprovalAuthority>(`/approvals/authorities/${id}`),
  createApprovalAuthority: (payload: { name: string; description?: string; mode?: ApprovalMode; bank_account_id?: string }) =>
    request<ApprovalAuthority>('/approvals/authorities', { method: 'POST', body: JSON.stringify(payload) }),
  updateApprovalAuthority: (id: string, payload: Partial<{ name: string; description: string; mode: ApprovalMode; bank_account_id: string; is_active: boolean }>) =>
    request<ApprovalAuthority>(`/approvals/authorities/${id}`, { method: 'PATCH', body: JSON.stringify(payload) }),
  addApprovalMember: (authorityId: string, userId: string) =>
    request<ApprovalAuthorityMember>(`/approvals/authorities/${authorityId}/members`, {
      method: 'POST',
      body: JSON.stringify({ user_id: userId }),
    }),
  removeApprovalMember: (authorityId: string, memberId: string) =>
    request<void>(`/approvals/authorities/${authorityId}/members/${memberId}`, { method: 'DELETE' }),
  createApprovalRule: (payload: { authority_id: string; entity_type: DocumentEntityType; threshold_amount?: number; priority?: number }) =>
    request<ApprovalRule>('/approvals/rules', { method: 'POST', body: JSON.stringify(payload) }),
  updateApprovalRule: (id: string, payload: Partial<{ entity_type: DocumentEntityType; threshold_amount: number; priority: number; is_active: boolean }>) =>
    request<ApprovalRule>(`/approvals/rules/${id}`, { method: 'PATCH', body: JSON.stringify(payload) }),
  deleteApprovalRule: (id: string) => request<void>(`/approvals/rules/${id}`, { method: 'DELETE' }),
  submitForApproval: (payload: { entity_type: DocumentEntityType; entity_id: string; amount?: number }) =>
    request<ApprovalRequest[]>('/approvals/submit', { method: 'POST', body: JSON.stringify(payload) }),
  recordApprovalDecision: (requestId: string, payload: { decision: ApprovalDecisionValue; comment?: string }) =>
    request<ApprovalRequest>(`/approvals/requests/${requestId}/decide`, { method: 'POST', body: JSON.stringify(payload) }),
  listPendingApprovals: () => request<ApprovalRequest[]>('/approvals/pending'),
  listApprovalsForEntity: (entityType: DocumentEntityType, entityId: string) =>
    request<ApprovalRequest[]>(`/approvals/entity/${entityType}/${entityId}`),

  // ---- Stock / Inventory ----
  listWarehouses: () => request<Warehouse[]>('/stock/warehouses'),
  createWarehouse: (data: { code: string; name: string; address?: string }) =>
    request<Warehouse>('/stock/warehouses', { method: 'POST', body: JSON.stringify(data) }),
  updateWarehouse: (id: string, data: Partial<{ code: string; name: string; address: string; is_active: boolean }>) =>
    request<Warehouse>(`/stock/warehouses/${id}`, { method: 'PATCH', body: JSON.stringify(data) }),

  listStockItems: () => request<StockItemRow[]>('/stock/items'),
  getStockItem: (id: string) => request<StockItemRow>(`/stock/items/${id}`),
  createStockItem: (data: { code: string; name: string; description?: string; category?: string; unit_of_measure?: string; reorder_level?: number }) =>
    request<StockItemRow>('/stock/items', { method: 'POST', body: JSON.stringify(data) }),
  updateStockItem: (id: string, data: Record<string, unknown>) =>
    request<StockItemRow>(`/stock/items/${id}`, { method: 'PATCH', body: JSON.stringify(data) }),

  // Stock item attachments
  uploadStockItemAttachment: async (itemId: string, file: File) => {
    const fd = new FormData(); fd.append('file', file)
    const token = getToken()
    const res = await fetch(`/api/stock/items/${itemId}/attachments`, {
      method: 'POST', body: fd, headers: token ? { Authorization: `Bearer ${token}` } : {},
    })
    if (!res.ok) throw new Error(await res.text())
    return res.json() as Promise<StockItemAttachmentRow>
  },
  deleteStockItemAttachment: (itemId: string, attId: string) =>
    request<void>(`/stock/items/${itemId}/attachments/${attId}`, { method: 'DELETE' }),

  // Stock setup masters
  listStockCategories: () => request<StockSetupRow[]>('/stock/categories'),
  createStockCategory: (data: { code: string; name: string }) =>
    request<StockSetupRow>('/stock/categories', { method: 'POST', body: JSON.stringify(data) }),
  updateStockCategory: (id: string, data: { code: string; name: string }) =>
    request<StockSetupRow>(`/stock/categories/${id}`, { method: 'PATCH', body: JSON.stringify(data) }),
  toggleStockCategory: (id: string) =>
    request<StockSetupRow>(`/stock/categories/${id}/toggle`, { method: 'PATCH' }),

  listStockGroups: () => request<StockSetupRow[]>('/stock/groups'),
  createStockGroup: (data: { code: string; name: string }) =>
    request<StockSetupRow>('/stock/groups', { method: 'POST', body: JSON.stringify(data) }),
  updateStockGroup: (id: string, data: { code: string; name: string }) =>
    request<StockSetupRow>(`/stock/groups/${id}`, { method: 'PATCH', body: JSON.stringify(data) }),
  toggleStockGroup: (id: string) =>
    request<StockSetupRow>(`/stock/groups/${id}/toggle`, { method: 'PATCH' }),

  listStockBrands: () => request<StockBrandRow[]>('/stock/brands'),
  createStockBrand: (data: { name: string }) =>
    request<StockBrandRow>('/stock/brands', { method: 'POST', body: JSON.stringify(data) }),
  updateStockBrand: (id: string, data: { name: string }) =>
    request<StockBrandRow>(`/stock/brands/${id}`, { method: 'PATCH', body: JSON.stringify(data) }),
  toggleStockBrand: (id: string) =>
    request<StockBrandRow>(`/stock/brands/${id}/toggle`, { method: 'PATCH' }),

  listStockModels: (brandId: string) => request<StockSetupRow[]>(`/stock/brands/${brandId}/models`),
  createStockModel: (data: { brand_id: string; name: string }) =>
    request<StockSetupRow>('/stock/models', { method: 'POST', body: JSON.stringify(data) }),
  updateStockModel: (id: string, data: { brand_id: string; name: string }) =>
    request<StockSetupRow>(`/stock/models/${id}`, { method: 'PATCH', body: JSON.stringify(data) }),
  toggleStockModel: (id: string) =>
    request<StockSetupRow>(`/stock/models/${id}/toggle`, { method: 'PATCH' }),

  listStockUsages: () => request<StockSetupRow[]>('/stock/usages'),
  createStockUsage: (data: { code: string; name: string }) =>
    request<StockSetupRow>('/stock/usages', { method: 'POST', body: JSON.stringify(data) }),
  updateStockUsage: (id: string, data: { code: string; name: string }) =>
    request<StockSetupRow>(`/stock/usages/${id}`, { method: 'PATCH', body: JSON.stringify(data) }),
  toggleStockUsage: (id: string) =>
    request<StockSetupRow>(`/stock/usages/${id}/toggle`, { method: 'PATCH' }),

  listStockLevels: (warehouseId?: string) =>
    request<StockLevelRow[]>(`/stock/levels${qs({ warehouse_id: warehouseId })}`),
  listStockMovements: (filters?: { stock_item_id?: string; warehouse_id?: string; limit?: number; company_ids?: string }) =>
    request<StockMovementRow[]>(`/stock/movements${qs(filters ?? {})}`),

  listGRNs: () => request<GRNRow[]>('/stock/grn'),
  createGRN: (data: GRNCreatePayload) =>
    request<GRNRow>('/stock/grn', { method: 'POST', body: JSON.stringify(data) }),
  confirmGRN: (id: string) => request<GRNRow>(`/stock/grn/${id}/confirm`, { method: 'POST' }),

  listGTNs: () => request<GTNRow[]>('/stock/gtn'),
  createGTN: (data: GTNCreatePayload) =>
    request<GTNRow>('/stock/gtn', { method: 'POST', body: JSON.stringify(data) }),
  confirmGTN: (id: string) => request<GTNRow>(`/stock/gtn/${id}/confirm`, { method: 'POST' }),

  listGINs: () => request<GINRow[]>('/stock/gin'),
  createGIN: (data: {
    warehouse_id: string
    customer_id?: string
    job_order_id?: string
    issue_date?: string
    reason?: string
    notes?: string
    lines: { stock_item_id: string; quantity: number; notes?: string }[]
  }) => request<GINRow>('/stock/gin', { method: 'POST', body: JSON.stringify(data) }),
  confirmGIN: (id: string) => request<GINRow>(`/stock/gin/${id}/confirm`, { method: 'POST' }),
  listGRTNs: () => request<GRTNRow[]>('/stock/grtn'),
  createGRTN: (data: GRTNCreatePayload) =>
    request<GRTNRow>('/stock/grtn', { method: 'POST', body: JSON.stringify(data) }),
  confirmGRTN: (id: string) => request<GRTNRow>(`/stock/grtn/${id}/confirm`, { method: 'POST' }),

  listAdjustments: () => request<AdjustmentRow[]>('/stock/adjustments'),
  createAdjustment: (data: AdjustmentCreatePayload) =>
    request<AdjustmentRow>('/stock/adjustments', { method: 'POST', body: JSON.stringify(data) }),
  submitAdjustment: (id: string) => request<AdjustmentRow>(`/stock/adjustments/${id}/submit`, { method: 'POST' }),
  approveAdjustment: (id: string) => request<AdjustmentRow>(`/stock/adjustments/${id}/approve`, { method: 'POST' }),
  rejectAdjustment: (id: string) => request<AdjustmentRow>(`/stock/adjustments/${id}/reject`, { method: 'POST' }),

  stockValuationReport: (warehouseId?: string, company_ids?: string) =>
    request<StockValuationReport>(`/stock/reports/valuation${qs({ warehouse_id: warehouseId, company_ids })}`),
  reorderReport: (company_ids?: string) => request<ReorderItem[]>(`/stock/reports/reorder${qs({ company_ids })}`),
  stockReportFilterOptions: (company_ids: string) =>
    request<StockFilterOptions>(`/stock/reports/filter-options${qs({ company_ids })}`),

  // Generic request helper for dynamic API calls
  request: <T,>(method: string, path: string, params?: Record<string, string>, body?: unknown): Promise<T> => {
    const url = params ? `${path}${qs(params)}` : path
    const options: RequestInit = {
      method,
      ...(body ? { body: JSON.stringify(body) } : {}),
    }
    return request<T>(url, options)
  },

  // Helper to get current user info (mirrors /auth/me)
  getCurrentUser: () => request<CurrentUser>('/auth/me'),


  // ---- Data Migration ----
  getMigrationOverview: (companyId?: string) =>
    request<MigrationOverview>(`/data-migration/overview${qs({ company_id: companyId })}`),
  getMigrationMapping: (source: MigrationSource, entity: string, companyId?: string) =>
    request<MigrationMappingInfo>(`/data-migration/modules/${source}/${entity}/mapping${qs({ company_id: companyId })}`),
  updateMigrationMapping: (source: MigrationSource, entity: string, companyId: string, mapping: Record<string, string | null>) =>
    request<MigrationMappingInfo>(`/data-migration/modules/${source}/${entity}/mapping`, {
      method: 'PUT',
      body: JSON.stringify({ company_id: companyId, mapping }),
    }),
  signOffMigrationMapping: (source: MigrationSource, entity: string, companyId: string) =>
    request<MigrationMappingInfo>(`/data-migration/modules/${source}/${entity}/sign-off`, {
      method: 'POST',
      body: JSON.stringify({ company_id: companyId }),
    }),
  exportMigrationFieldGap: (source: MigrationSource, entity: string, companyId: string, format: 'csv' | 'xlsx') =>
    requestBlob(`/data-migration/modules/${source}/${entity}/field-gap.${format}${qs({ company_id: companyId })}`),
  uploadMigrationFile: (companyId: string, source: MigrationSource, entity: string, file: File) => {
    const form = new FormData()
    form.append('company_id', companyId)
    form.append('source', source)
    form.append('entity', entity)
    form.append('file', file)
    return requestWithBody<MigrationBatch>('/data-migration/batches', { method: 'POST', body: form })
  },
  listMigrationBatches: (filters: MigrationBatchFilters = {}) =>
    request<MigrationBatch[]>(`/data-migration/batches${qs({ ...filters })}`),
  exportMigrationBatches: (filters: MigrationBatchFilters, format: 'csv' | 'xlsx') =>
    requestBlob(`/data-migration/batches/export.${format}${qs({ ...filters })}`),
  getMigrationBatch: (id: string) => request<MigrationBatch>(`/data-migration/batches/${id}`),
  getMigrationBatchProgress: (id: string) =>
    request<Pick<MigrationBatch, 'id' | 'status' | 'mode' | 'progress_done' | 'progress_total'>>(`/data-migration/batches/${id}/progress`),
  saveMigrationDecisions: (id: string, decisions: Record<string, string>) =>
    request<MigrationBatch>(`/data-migration/batches/${id}/decisions`, { method: 'PUT', body: JSON.stringify({ decisions }) }),
  dryRunMigrationBatch: (id: string, cutoff_date?: string | null) =>
    request<MigrationBatch>(`/data-migration/batches/${id}/dry-run`, { method: 'POST', body: JSON.stringify({ cutoff_date: cutoff_date ?? null }) }),
  importMigrationBatch: (id: string) => request<MigrationBatch>(`/data-migration/batches/${id}/import`, { method: 'POST' }),
  /** 200 when rolled back; 409 (an ApiError whose body is the result) when something blocks it. */
  rollbackMigrationBatch: (id: string, reason: string) =>
    requestWithBody<MigrationRollbackResult>(`/data-migration/batches/${id}/rollback`, {
      method: 'POST',
      body: JSON.stringify({ reason }),
    }),
  exportMigrationModules: (companyId: string, source: string, format: 'csv' | 'xlsx') =>
    requestBlob(`/data-migration/modules/export.${format}${qs({ company_id: companyId, source })}`),
  exportMigrationProblems: (id: string, format: 'csv' | 'xlsx') =>
    requestBlob(`/data-migration/batches/${id}/problems.${format}`),
}
