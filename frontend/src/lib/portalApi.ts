// API client for the Customer Helpdesk Portal (PORTAL-001..004,
// docs/customer-portal-design.md). Deliberately separate from
// src/lib/api.ts: its own token, its own storage key, and it only ever
// talks to /api/portal/* -- see PortalAuthContext.tsx's docstring for
// why staff and portal sessions must never share a token.
import { getDeviceId } from './deviceId'

const PORTAL_TOKEN_KEY = 'websoft_portal_token'

export function getPortalToken(): string | null {
  return localStorage.getItem(PORTAL_TOKEN_KEY)
}

export function setPortalToken(token: string) {
  localStorage.setItem(PORTAL_TOKEN_KEY, token)
}

export function clearPortalToken() {
  localStorage.removeItem(PORTAL_TOKEN_KEY)
}

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const token = getPortalToken()
  const headers: Record<string, string> = {
    // A file upload (FormData) sets its own multipart content type.
    ...(options.body && !(options.body instanceof FormData) ? { 'Content-Type': 'application/json' } : {}),
    ...(token ? { Authorization: `Bearer ${token}` } : {}),
    'X-Device-Id': getDeviceId(),
  }
  const res = await fetch(`/api/portal${path}`, { ...options, headers })
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

// ---- Auth (design §4) ---------------------------------------------------

export interface PortalLoginResult {
  status: 'ok' | 'otp_required'
  otp_token?: string
  portal_token?: string
  must_change_password?: boolean
}

export async function portalLogin(email: string, password: string): Promise<PortalLoginResult> {
  return request<PortalLoginResult>('/auth/login', {
    method: 'POST',
    body: JSON.stringify({ email, password }),
  })
}

export async function portalVerifyOtp(otpToken: string, code: string): Promise<PortalLoginResult> {
  return request<PortalLoginResult>('/auth/verify-otp', {
    method: 'POST',
    body: JSON.stringify({ otp_token: otpToken, code }),
  })
}

export interface PortalMessageResponse {
  message: string
}

export async function portalChangePassword(newPassword: string): Promise<PortalMessageResponse> {
  return request<PortalMessageResponse>('/auth/change-password', {
    method: 'POST',
    body: JSON.stringify({ new_password: newPassword }),
  })
}

export async function portalForgotPassword(email: string): Promise<PortalMessageResponse> {
  return request<PortalMessageResponse>('/auth/forgot-password', {
    method: 'POST',
    body: JSON.stringify({ email }),
  })
}

export async function portalResetPasswordWithOtp(
  email: string,
  code: string,
  newPassword: string,
): Promise<PortalMessageResponse> {
  return request<PortalMessageResponse>('/auth/reset-password-otp', {
    method: 'POST',
    body: JSON.stringify({ email, code, new_password: newPassword }),
  })
}

export interface PortalMe {
  contact_name: string
  email: string
  customer_name: string
  must_change_password: boolean
}

// ---- Data (design §6) ----------------------------------------------------

export interface PortalContract {
  id: string
  contract_number: string
  contract_kind: 'service_support' | 'annual' | 'ad_hoc'
  status: 'draft' | 'active' | 'exceeded' | 'expired' | 'renewed'
  contracted_hours: number
  consumed_hours: number
  remaining_hours: number
  start_date: string
  end_date: string
}

export interface PortalJobOrder {
  id: string
  job_order_number: string
  subject: string
  job_order_type: 'support' | 'project'
  status: string
  assigned_engineer_name: string | null
  due_date: string | null
  contract_id: string | null
  contract_number: string | null
}

export interface PortalServiceRecord {
  id: string
  service_record_number: string
  job_order_id: string
  job_order_number: string
  work_date: string
  engineer_name: string
  minutes: number
  completion_status: string
  status: string
  contract_id: string | null
  contract_number: string | null
}

export interface PortalJobOrderDetail extends PortalJobOrder {
  service_records: PortalServiceRecord[]
}

export interface PortalIncident {
  id: string
  incident_number: string
  subject: string
  description: string | null
  status: 'open' | 'pending_callback' | 'converted' | 'closed'
  created_at: string
  converted_job_order_number: string | null
}

// Files a customer attaches to an incident (Backlog 2, 2026-09-26):
// photos, screenshots and PDFs, up to 10 MB each and 5 per incident.
export interface PortalIncidentAttachment {
  id: string
  original_filename: string
  content_type: string
  file_size_bytes: number
  uploaded_at: string | null
}

export const PORTAL_ATTACHMENT_ACCEPT = 'image/*,application/pdf'
export const PORTAL_ATTACHMENT_MAX_BYTES = 10 * 1024 * 1024
export const PORTAL_ATTACHMENTS_PER_INCIDENT = 5

// PORTAL-005 (confirmed 2026-09-14): a customer's own Invoices and
// Payments -- same figures as the PDF copy, never GP/cost internals.
export interface PortalInvoice {
  id: string
  invoice_number: string
  invoice_type: 'contract_annual' | 'excess_usage'
  description: string
  contract_id: string | null
  contract_number: string | null
  amount_sgd: number
  gst_amount_sgd: number
  total_amount_sgd: number
  amount_paid_sgd: number
  /** Taken off by issued credit notes (BILL-003). */
  credited_sgd: number
  outstanding_sgd: number
  status: 'outstanding' | 'partially_paid' | 'paid' | 'written_off' | 'credited'
  due_date: string | null
  issued_at: string
}

export interface PortalPaymentAllocation {
  invoice_id: string
  invoice_number: string | null
  amount_sgd: number
}

export interface PortalPayment {
  id: string
  voucher_number: string
  payment_date: string
  amount_sgd: number
  method: string
  reference: string | null
  allocations: PortalPaymentAllocation[]
}

// AI Assistant slice 3 (docs/planned-work.md #12 Tier 2 item 6): the
// chat panel on the portal. Its own auth realm, its own narrower
// read-only scope -- always the signed-in customer's own records.
export interface PortalAiPersona {
  name: string
  avatar: string | null
  /** Whether this customer has ticked the one-time AI declaration (2026-09-26). */
  consent_given: boolean
}

export interface PortalAiChatMessage {
  role: 'user' | 'assistant'
  content: string
}

export type PortalAiContextType = 'contract' | 'job_order' | 'billing' | 'incidents'

export interface PortalAiChatReply {
  answer: string
  refused: boolean
  tools_used: { name: string; summary: string }[]
  model: string
  input_tokens: number
  output_tokens: number
}

export const portalApi = {
  me: () => request<PortalMe>('/me'),
  contracts: () => request<PortalContract[]>('/contracts'),
  jobOrders: (contractId?: string) =>
    request<PortalJobOrder[]>(`/job-orders${contractId ? `?contract_id=${contractId}` : ''}`),
  jobOrderDetail: (id: string) => request<PortalJobOrderDetail>(`/job-orders/${id}`),
  serviceRecords: (contractId?: string) =>
    request<PortalServiceRecord[]>(`/service-records${contractId ? `?contract_id=${contractId}` : ''}`),
  incidents: () => request<PortalIncident[]>('/incidents'),
  createIncident: (subject: string, description: string) =>
    request<PortalIncident>('/incidents', {
      method: 'POST',
      body: JSON.stringify({ subject, description: description || null }),
    }),
  incidentAttachments: (incidentId: string) => request<PortalIncidentAttachment[]>(`/incidents/${incidentId}/attachments`),
  uploadIncidentAttachment: (incidentId: string, file: File) => {
    const form = new FormData()
    form.append('file', file)
    return request<PortalIncidentAttachment>(`/incidents/${incidentId}/attachments`, { method: 'POST', body: form })
  },
  downloadIncidentAttachment: async (incidentId: string, attachmentId: string): Promise<Blob> => {
    const token = getPortalToken()
    const res = await fetch(`/api/portal/incidents/${incidentId}/attachments/${attachmentId}`, {
      headers: { ...(token ? { Authorization: `Bearer ${token}` } : {}), 'X-Device-Id': getDeviceId() },
    })
    if (!res.ok) throw new Error('Could not open the file')
    return res.blob()
  },
  invoices: () => request<PortalInvoice[]>('/invoices'),
  payments: () => request<PortalPayment[]>('/payments'),
  aiPersona: () => request<PortalAiPersona>('/ai/persona'),
  aiConsent: () => request<{ consent_given: boolean }>('/ai/consent', { method: 'POST', body: JSON.stringify({ accepted: true }) }),
  aiChat: (messages: PortalAiChatMessage[], context?: { type: PortalAiContextType; id?: string | null } | null) =>
    request<PortalAiChatReply>('/ai/chat', { method: 'POST', body: JSON.stringify({ messages, context: context ?? null }) }),
}
