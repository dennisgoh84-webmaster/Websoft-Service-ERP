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
    ...(options.body ? { 'Content-Type': 'application/json' } : {}),
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
  outstanding_sgd: number
  status: 'outstanding' | 'partially_paid' | 'paid' | 'written_off'
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
  invoices: () => request<PortalInvoice[]>('/invoices'),
  payments: () => request<PortalPayment[]>('/payments'),
}
