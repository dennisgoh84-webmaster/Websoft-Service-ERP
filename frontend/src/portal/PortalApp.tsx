/**
 * Customer Helpdesk Portal (PORTAL-001..004, docs/customer-portal-design.md).
 *
 * Mounted at /portal/* outside the staff <Layout> (see App.tsx),
 * self-contained (own auth context/token, own styles) the same way
 * src/pages/MobileApp.tsx is for staff.
 *
 * DESKTOP-FIRST since 2026-09-15, at Dennis's request -- the design doc
 * originally said "phone-first; it will mostly be opened from a phone"
 * (docs/customer-portal-design.md §7), which turned out to be the wrong
 * guess: customers raise and chase support from a PC at their desk.
 * From ~900px the portal is a normal desktop application -- a left
 * sidebar, a wide content column, and Home's tiles side by side -- and
 * below that it collapses back to the single column and fixed bottom
 * tab bar it was, so a phone is still perfectly usable. That switch is
 * the ONE thing here done with a real stylesheet rather than the inline
 * style objects below: inline styles cannot carry a media query.
 *
 * A customer
 * signs in with email + password, then (if SMTP is configured) a
 * 6-digit email code, then sets their own password on first sign-in.
 * From there: hour balance + account balance + open incidents on Home,
 * their Contracts (+ service records logged against each), their Job
 * Orders (+ service records), their Invoices and Payments (PORTAL-005,
 * confirmed 2026-09-14 -- reverses the original "no money" design call
 * once Dennis asked for it explicitly), and Incidents (+ raise a new
 * one, with a friendlier status once routed to a Job Order).
 */
import { useEffect, useState, type ChangeEvent, type CSSProperties, type FormEvent } from 'react'
import { api } from '../lib/api'
import type { PublicBranding } from '../lib/api'
import { PortalAuthProvider, usePortalAuth } from '../lib/PortalAuthContext'
import {
  portalApi,
  portalChangePassword,
  portalForgotPassword,
  portalLogin,
  portalResetPasswordWithOtp,
  portalVerifyOtp,
  type PortalContract,
  type PortalIncident,
  type PortalIncidentAttachment,
  type PortalInvoice,
  type PortalJobOrder,
  type PortalJobOrderDetail,
  type PortalLoginResult,
  type PortalPayment,
  type PortalServiceRecord,
  PORTAL_ATTACHMENT_ACCEPT,
  PORTAL_ATTACHMENT_MAX_BYTES,
  PORTAL_ATTACHMENTS_PER_INCIDENT,
} from '../lib/portalApi'
import { formatDate, formatDateTime } from '../lib/format'
import PortalAiChatWidget, { type PortalAiContext } from './PortalAiChatWidget'

const MAROON = '#7a1f2e'
const WHITE = '#ffffff'
const LIGHT_BG = '#f8f6f5'
const INK = '#2a2226'
const MUTED = '#847478'
const BORDER = '#e6dcdd'
const OK = '#2c6b2f'
const OK_BG = '#e8f5e9'
const WARN = '#8a5a12'
const WARN_BG = '#fff4e5'
const DANGER = '#c0362c'
const DANGER_BG = '#fdecea'

const styles = {
  // Width, padding and the nav's placement are in PORTAL_CSS below --
  // they are the parts that differ between desktop and phone.
  shell: {
    minHeight: '100vh',
    background: LIGHT_BG,
    fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
    display: 'flex',
    flexDirection: 'column' as const,
    color: INK,
  } as CSSProperties,
  header: {
    background: MAROON,
    color: WHITE,
    padding: '16px 20px',
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'space-between',
    position: 'sticky' as const,
    top: 0,
    zIndex: 10,
  } as CSSProperties,
  headerTitle: { fontSize: 18, fontWeight: 700, margin: 0 } as CSSProperties,
  headerSub: { fontSize: 12, opacity: 0.85, margin: 0 } as CSSProperties,
  logoutBtn: {
    background: 'rgba(255,255,255,0.2)', border: 'none', color: WHITE,
    fontSize: 13, padding: '10px 14px', borderRadius: 6, cursor: 'pointer',
    whiteSpace: 'nowrap', flexShrink: 0,
  } as CSSProperties,
  content: { flex: 1 } as CSSProperties,
  card: {
    background: WHITE, borderRadius: 12, padding: 16,
    boxShadow: '0 1px 3px rgba(0,0,0,0.08)', border: `1px solid ${BORDER}`,
  } as CSSProperties,
  btn: {
    display: 'block', width: '100%', padding: '13px', border: 'none',
    borderRadius: 10, fontSize: 15, fontWeight: 600, cursor: 'pointer',
    textAlign: 'center' as const,
  } as CSSProperties,
  btnPrimary: { background: MAROON, color: WHITE } as CSSProperties,
  btnSecondary: { background: '#efe8e8', color: INK } as CSSProperties,
  input: {
    width: '100%', padding: '12px', border: `1px solid ${BORDER}`, borderRadius: 8,
    // 16px minimum: below it iPhone Safari zooms the page on focus.
    fontSize: 16, boxSizing: 'border-box' as const, fontFamily: 'inherit',
  } as CSSProperties,
  label: { display: 'block', fontSize: 13, fontWeight: 600, color: MUTED, marginBottom: 4 } as CSSProperties,
  errorBox: {
    background: DANGER_BG, color: DANGER, padding: '10px 14px',
    borderRadius: 8, margin: '0 16px 12px', fontSize: 14,
  } as CSSProperties,
  infoBox: {
    background: OK_BG, color: OK, padding: '10px 14px',
    borderRadius: 8, margin: '0 16px 12px', fontSize: 14,
  } as CSSProperties,
  badge: {
    display: 'inline-block', padding: '2px 9px', borderRadius: 999,
    fontSize: 11, fontWeight: 700, textTransform: 'uppercase' as const,
  } as CSSProperties,
}

/**
 * The desktop/phone switch. Everything else on this screen is an inline
 * style object; this is a stylesheet because a media query cannot be
 * expressed inline. Scoped under .portal-shell so it cannot reach the
 * staff app, which is never mounted at the same time anyway.
 *
 * Desktop (>= 900px) is the primary layout: sidebar nav, wide content,
 * Home's tiles in a grid. Below that everything falls back to the
 * original single column with a fixed bottom tab bar.
 */
const PORTAL_CSS = `
.portal-shell { width: 100%; }
.portal-body { display: flex; align-items: flex-start; }
.portal-nav { display: flex; background: ${WHITE}; }
.portal-nav-item {
  background: none; border: none; cursor: pointer; font-weight: 600;
  display: flex; align-items: center; font-family: inherit;
}
.portal-nav-item:hover { background: #f4eeee; }
.portal-card { margin: 12px 16px; }

@media (min-width: 900px) {
  .portal-shell { max-width: 1240px; margin: 0 auto; }
  .portal-nav {
    flex-direction: column; width: 216px; flex: 0 0 216px;
    border-right: 1px solid ${BORDER}; position: sticky; top: 64px;
    align-self: flex-start; padding: 12px 0; gap: 2px;
    min-height: calc(100vh - 64px);
  }
  .portal-nav-item {
    gap: 10px; padding: 11px 20px; font-size: 14px; text-align: left;
    border-left: 3px solid transparent;
  }
  .portal-nav-item[aria-current='true'] { border-left-color: ${MAROON}; background: #f7f0f1; }
  .portal-nav-icon { font-size: 16px; width: 18px; text-align: center; }
  .portal-content { flex: 1; min-width: 0; padding: 8px 8px 40px; }
  .portal-card { margin: 16px; padding: 20px; }
  /* Home's summary tiles sit side by side rather than stacked. */
  .portal-home-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); }
  /* A full-width button is a phone idiom; on a desktop card it reads as
     a link-sized action instead. */
  .portal-card .portal-action { width: auto; min-width: 200px; padding: 10px 18px; }
  /* The sign-in card stays a centred column -- that IS the desktop
     convention -- under a full-width branded header band. */
  .portal-shell--login { max-width: 100%; }
  .portal-shell--login .portal-login-card { max-width: 440px; margin: 48px auto 0; width: 100%; }
}

@media (max-width: 899px) {
  .portal-shell { max-width: 480px; margin: 0 auto; }
  .portal-body { display: block; }
  .portal-content { padding-bottom: 76px; }
  .portal-nav {
    position: fixed; bottom: 0; left: 50%; transform: translateX(-50%);
    width: 100%; max-width: 480px; border-top: 1px solid ${BORDER};
    box-shadow: 0 -2px 8px rgba(0,0,0,0.06); z-index: 10;
  }
  .portal-nav-item {
    flex: 1; flex-direction: column; gap: 3px; padding: 10px 4px 12px; font-size: 11px;
    justify-content: center;
  }
  .portal-nav-icon { font-size: 18px; }
}
`

/** Injected once, at the top of whichever portal screen is showing. */
function PortalStyles() {
  return <style>{PORTAL_CSS}</style>
}

function badgeStyle(kind: 'ok' | 'warn' | 'danger' | 'neutral'): CSSProperties {
  switch (kind) {
    case 'ok': return { ...styles.badge, background: OK_BG, color: OK, border: `1px solid ${OK}33` }
    case 'warn': return { ...styles.badge, background: WARN_BG, color: WARN, border: `1px solid ${WARN}33` }
    case 'danger': return { ...styles.badge, background: DANGER_BG, color: DANGER, border: `1px solid ${DANGER}33` }
    default: return { ...styles.badge, background: '#eeeeee', color: '#555555' }
  }
}

function contractBadgeKind(status: string): 'ok' | 'warn' | 'danger' | 'neutral' {
  if (status === 'active') return 'ok'
  if (status === 'exceeded') return 'warn'
  if (status === 'expired') return 'neutral'
  return 'neutral'
}

function jobOrderBadgeKind(status: string): 'ok' | 'warn' | 'danger' | 'neutral' {
  if (status === 'closed' || status === 'resolved') return 'ok'
  if (status === 'open') return 'warn'
  if (status === 'void') return 'danger'
  return 'neutral'
}

function incidentBadgeKind(status: string): 'ok' | 'warn' | 'danger' | 'neutral' {
  if (status === 'closed' || status === 'converted') return 'ok'
  if (status === 'pending_callback') return 'warn'
  return 'neutral'
}

function invoiceBadgeKind(status: string): 'ok' | 'warn' | 'danger' | 'neutral' {
  if (status === 'paid' || status === 'credited') return 'ok'
  if (status === 'partially_paid') return 'warn'
  if (status === 'written_off') return 'neutral'
  return 'danger' // outstanding
}

function fmtMoney(n: number): string {
  return n.toLocaleString('en-SG', { style: 'currency', currency: 'SGD', minimumFractionDigits: 2 })
}

function fmtDate(iso: string | null): string {
  if (!iso) return '-'
  return formatDate(iso)
}

function fmtDateTime(iso: string): string {
  return formatDateTime(iso)
}

function fmtMinutes(m: number): string {
  const h = Math.floor(m / 60)
  const r = m % 60
  return h > 0 ? `${h}h ${r}m` : `${r}m`
}

function label(v: string): string {
  return v.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
}

// ── Login sequence ──────────────────────────────────────────────────

type LoginStep = 'credentials' | 'otp' | 'change_password' | 'forgot_email' | 'forgot_reset'

function PortalLogin() {
  const { completeLogin, portalUser, refresh } = usePortalAuth()
  const [step, setStep] = useState<LoginStep>('credentials')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [otpToken, setOtpToken] = useState('')
  const [otpCode, setOtpCode] = useState('')
  const [newPassword, setNewPassword] = useState('')
  const [confirmPassword, setConfirmPassword] = useState('')
  const [forgotEmail, setForgotEmail] = useState('')
  const [resetCode, setResetCode] = useState('')
  const [resetNewPassword, setResetNewPassword] = useState('')
  const [resetConfirmPassword, setResetConfirmPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [info, setInfo] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)
  // Also true when a page reload lands here with an already-valid token
  // whose PortalUser still has must_change_password set (design §4 step
  // 3 is a UI redirect, not a second token gate -- see portal.py's
  // module docstring) -- otherwise a refresh mid-flow would show the
  // credentials form again instead of picking the forced step back up.
  const [pendingChangePassword, setPendingChangePassword] = useState(!!portalUser?.must_change_password)
  const [branding, setBranding] = useState<PublicBranding | null>(null)

  useEffect(() => {
    api.getPublicBranding().then(setBranding).catch(() => setBranding(null))
  }, [])

  async function advance(result: PortalLoginResult) {
    if (result.status === 'ok' && result.portal_token) {
      await completeLogin(result.portal_token)
      if (result.must_change_password) {
        setPendingChangePassword(true)
        setNewPassword('')
        setConfirmPassword('')
        setStep('change_password')
      }
    } else if (result.status === 'otp_required' && result.otp_token) {
      setOtpToken(result.otp_token)
      setOtpCode('')
      setStep('otp')
    }
  }

  async function onSubmitCredentials(e: FormEvent) {
    e.preventDefault()
    setError(null)
    setSubmitting(true)
    try {
      await advance(await portalLogin(email, password))
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Sign in failed')
    } finally {
      setSubmitting(false)
    }
  }

  async function onSubmitOtp(e: FormEvent) {
    e.preventDefault()
    setError(null)
    setSubmitting(true)
    try {
      await advance(await portalVerifyOtp(otpToken, otpCode))
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Incorrect code')
    } finally {
      setSubmitting(false)
    }
  }

  async function onSubmitChangePassword(e: FormEvent) {
    e.preventDefault()
    setError(null)
    if (newPassword !== confirmPassword) {
      setError('Passwords do not match.')
      return
    }
    setSubmitting(true)
    try {
      await portalChangePassword(newPassword)
      await refresh() // updates portalUser.must_change_password -> PortalRoot renders the signed-in app
      setPendingChangePassword(false)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not change password')
    } finally {
      setSubmitting(false)
    }
  }

  async function onSubmitForgotEmail(e: FormEvent) {
    e.preventDefault()
    setError(null)
    setSubmitting(true)
    try {
      const result = await portalForgotPassword(forgotEmail)
      setResetCode('')
      setResetNewPassword('')
      setResetConfirmPassword('')
      setInfo(result.message)
      setStep('forgot_reset')
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not request a reset code')
    } finally {
      setSubmitting(false)
    }
  }

  async function onSubmitForgotReset(e: FormEvent) {
    e.preventDefault()
    setError(null)
    if (resetNewPassword !== resetConfirmPassword) {
      setError('Passwords do not match.')
      return
    }
    setSubmitting(true)
    try {
      await portalResetPasswordWithOtp(forgotEmail, resetCode, resetNewPassword)
      setEmail(forgotEmail)
      setPassword('')
      setInfo('Password updated. Please sign in with your new password.')
      setStep('credentials')
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not reset password')
    } finally {
      setSubmitting(false)
    }
  }

  // If we've completed OTP/login but still owe a forced password change,
  // stay on this screen for that one extra step instead of flashing the
  // signed-in app underneath (PortalAuthContext already has a token).
  if (pendingChangePassword) {
    return (
      <div className="portal-shell portal-shell--login" style={styles.shell}>
        <PortalStyles />
        <div style={{ ...styles.header, justifyContent: 'center' }}>
          <h1 style={styles.headerTitle}>Set your password</h1>
        </div>
        <form onSubmit={onSubmitChangePassword} style={{ padding: 20 }}>
          <div className="portal-card portal-login-card" style={styles.card}>
            <p style={{ margin: '0 0 16px', fontSize: 14, color: MUTED }}>
              This is your first sign-in to the Helpdesk Portal -- please set your own
              password to continue. At least 8 characters, with a letter and a number.
            </p>
            {error && <div style={{ ...styles.errorBox, margin: '0 0 12px' }}>{error}</div>}
            <div style={{ marginBottom: 12 }}>
              <label style={styles.label}>New password</label>
              <input type="password" value={newPassword} onChange={(e) => setNewPassword(e.target.value)} minLength={8} autoFocus required style={styles.input} />
            </div>
            <div style={{ marginBottom: 16 }}>
              <label style={styles.label}>Confirm password</label>
              <input type="password" value={confirmPassword} onChange={(e) => setConfirmPassword(e.target.value)} minLength={8} required style={styles.input} />
            </div>
            <button type="submit" disabled={submitting} style={{ ...styles.btn, ...styles.btnPrimary }}>
              {submitting ? 'Saving...' : 'Set password and continue'}
            </button>
          </div>
        </form>
      </div>
    )
  }

  return (
    <div className="portal-shell portal-shell--login" style={styles.shell}>
      <PortalStyles />
      <div style={{ ...styles.header, justifyContent: 'center', flexDirection: 'column', gap: 4 }}>
        {branding?.logo && (
          <img src={branding.logo} alt={`${branding.name} logo`} style={{ maxHeight: 40, maxWidth: 160, objectFit: 'contain', marginBottom: 4 }} />
        )}
        <h1 style={styles.headerTitle}>Helpdesk Portal</h1>
        <p style={styles.headerSub}>{branding?.name ?? 'Websoft Service ERP'}</p>
      </div>

      {step === 'credentials' && (
        <form onSubmit={onSubmitCredentials} style={{ padding: 20 }}>
          <div className="portal-card portal-login-card" style={styles.card}>
            <h2 style={{ margin: '0 0 16px', fontSize: 18 }}>Sign in</h2>
            {info && <p style={{ fontSize: 13, color: MUTED, margin: '0 0 12px' }}>{info}</p>}
            {error && <div style={{ ...styles.errorBox, margin: '0 0 12px' }}>{error}</div>}
            <div style={{ marginBottom: 12 }}>
              <label style={styles.label}>Email</label>
              <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} required autoComplete="email" style={styles.input} />
            </div>
            <div style={{ marginBottom: 16 }}>
              <label style={styles.label}>Password</label>
              <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} required autoComplete="current-password" style={styles.input} />
            </div>
            <button type="submit" disabled={submitting} style={{ ...styles.btn, ...styles.btnPrimary }}>
              {submitting ? 'Signing in...' : 'Sign in'}
            </button>
            <button
              type="button"
              onClick={() => { setError(null); setInfo(null); setForgotEmail(email); setStep('forgot_email') }}
              style={{ background: 'none', border: 'none', color: MAROON, fontSize: 13, textDecoration: 'underline', cursor: 'pointer', display: 'block', width: '100%', textAlign: 'center', marginTop: 12, padding: 6 }}
            >
              Forgot password?
            </button>
          </div>
        </form>
      )}

      {step === 'otp' && (
        <form onSubmit={onSubmitOtp} style={{ padding: 20 }}>
          <div className="portal-card portal-login-card" style={styles.card}>
            <p style={{ margin: '0 0 16px', fontSize: 14, color: MUTED }}>
              We emailed a 6-digit code to {email}. Enter it below to finish signing in.
            </p>
            {error && <div style={{ ...styles.errorBox, margin: '0 0 12px' }}>{error}</div>}
            <div style={{ marginBottom: 16 }}>
              <label style={styles.label}>One-time code</label>
              <input
                value={otpCode}
                onChange={(e) => setOtpCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
                inputMode="numeric" autoComplete="one-time-code" autoFocus required style={styles.input}
              />
            </div>
            <button type="submit" disabled={submitting || otpCode.length !== 6} style={{ ...styles.btn, ...styles.btnPrimary }}>
              {submitting ? 'Verifying...' : 'Verify code'}
            </button>
          </div>
        </form>
      )}

      {step === 'forgot_email' && (
        <form onSubmit={onSubmitForgotEmail} style={{ padding: 20 }}>
          <div className="portal-card portal-login-card" style={styles.card}>
            <p style={{ margin: '0 0 16px', fontSize: 14, color: MUTED }}>
              Enter your account email -- if it matches an active portal login, we'll email a
              one-time code to reset your password.
            </p>
            {error && <div style={{ ...styles.errorBox, margin: '0 0 12px' }}>{error}</div>}
            <div style={{ marginBottom: 16 }}>
              <label style={styles.label}>Email</label>
              <input type="email" value={forgotEmail} onChange={(e) => setForgotEmail(e.target.value)} autoFocus required style={styles.input} />
            </div>
            <button type="submit" disabled={submitting} style={{ ...styles.btn, ...styles.btnPrimary }}>
              {submitting ? 'Sending...' : 'Send reset code'}
            </button>
            <button type="button" onClick={() => { setError(null); setInfo(null); setStep('credentials') }} style={{ background: 'none', border: 'none', color: MAROON, fontSize: 13, textDecoration: 'underline', cursor: 'pointer', display: 'block', width: '100%', textAlign: 'center', marginTop: 12, padding: 6 }}>
              Back to sign in
            </button>
          </div>
        </form>
      )}

      {step === 'forgot_reset' && (
        <form onSubmit={onSubmitForgotReset} style={{ padding: 20 }}>
          <div className="portal-card portal-login-card" style={styles.card}>
            {info && <p style={{ margin: '0 0 16px', fontSize: 14, color: MUTED }}>{info}</p>}
            {error && <div style={{ ...styles.errorBox, margin: '0 0 12px' }}>{error}</div>}
            <div style={{ marginBottom: 12 }}>
              <label style={styles.label}>One-time code</label>
              <input value={resetCode} onChange={(e) => setResetCode(e.target.value.replace(/\D/g, '').slice(0, 6))} inputMode="numeric" autoComplete="one-time-code" autoFocus required style={styles.input} />
            </div>
            <div style={{ marginBottom: 12 }}>
              <label style={styles.label}>New password</label>
              <input type="password" value={resetNewPassword} onChange={(e) => setResetNewPassword(e.target.value)} minLength={8} required style={styles.input} />
            </div>
            <div style={{ marginBottom: 16 }}>
              <label style={styles.label}>Confirm password</label>
              <input type="password" value={resetConfirmPassword} onChange={(e) => setResetConfirmPassword(e.target.value)} minLength={8} required style={styles.input} />
            </div>
            <button type="submit" disabled={submitting || resetCode.length !== 6} style={{ ...styles.btn, ...styles.btnPrimary }}>
              {submitting ? 'Saving...' : 'Reset password'}
            </button>
            <button type="button" onClick={() => { setError(null); setInfo(null); setStep('credentials') }} style={{ background: 'none', border: 'none', color: MAROON, fontSize: 13, textDecoration: 'underline', cursor: 'pointer', display: 'block', width: '100%', textAlign: 'center', marginTop: 12, padding: 6 }}>
              Back to sign in
            </button>
          </div>
        </form>
      )}
    </div>
  )
}

// ── Signed-in shell: header + bottom nav ────────────────────────────

type Tab = 'home' | 'contracts' | 'jobOrders' | 'billing' | 'incidents'

function PortalHeader({ title }: { title: string }) {
  const { portalUser, logout } = usePortalAuth()
  return (
    <div style={styles.header}>
      <div>
        <p style={styles.headerTitle}>{title}</p>
        {portalUser && <p style={styles.headerSub}>{portalUser.customer_name}</p>}
      </div>
      <button onClick={logout} style={styles.logoutBtn}>Sign out</button>
    </div>
  )
}

/**
 * A left sidebar on a desktop screen, the original fixed bottom tab bar
 * on a phone -- one set of buttons, placed by PORTAL_CSS. aria-current
 * carries the selected tab so the stylesheet can mark it without a
 * second class name, and it is the right attribute for a nav anyway.
 */
function PortalNav({ tab, onChange }: { tab: Tab; onChange: (t: Tab) => void }) {
  const items: { key: Tab; label: string; icon: string }[] = [
    { key: 'home', label: 'Home', icon: '⌂' },
    { key: 'contracts', label: 'Contracts', icon: '⌗' },
    { key: 'jobOrders', label: 'Job Orders', icon: '⚙' },
    { key: 'billing', label: 'Billing', icon: '$' },
    { key: 'incidents', label: 'Incidents', icon: '⚠' },
  ]
  return (
    <nav className="portal-nav">
      {items.map((it) => (
        <button
          key={it.key}
          className="portal-nav-item"
          aria-current={tab === it.key}
          onClick={() => onChange(it.key)}
          style={{ color: tab === it.key ? MAROON : MUTED }}
        >
          <span className="portal-nav-icon">{it.icon}</span>
          {it.label}
        </button>
      ))}
    </nav>
  )
}

// ── Home ─────────────────────────────────────────────────────────────

function PortalHome({ onGoTab }: { onGoTab: (t: Tab) => void }) {
  const [contracts, setContracts] = useState<PortalContract[] | null>(null)
  const [incidents, setIncidents] = useState<PortalIncident[] | null>(null)
  const [jobOrders, setJobOrders] = useState<PortalJobOrder[] | null>(null)
  const [invoices, setInvoices] = useState<PortalInvoice[] | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    Promise.all([portalApi.contracts(), portalApi.incidents(), portalApi.jobOrders(), portalApi.invoices()])
      .then(([c, i, j, inv]) => { setContracts(c); setIncidents(i); setJobOrders(j); setInvoices(inv) })
      .catch((e) => setError(e instanceof Error ? e.message : 'Failed to load'))
  }, [])

  const activeHourContracts = (contracts ?? []).filter((c) => c.contract_kind === 'service_support')
  const openIncidents = (incidents ?? []).filter((i) => i.status === 'open' || i.status === 'pending_callback')
  const recentJobOrders = (jobOrders ?? []).slice(0, 3)
  const outstandingBalance = (invoices ?? [])
    .filter((inv) => inv.status !== 'written_off')
    .reduce((sum, inv) => sum + inv.outstanding_sgd, 0)

  return (
    <div>
      {error && <div style={styles.errorBox}>{error}</div>}

      {/* Side by side on a desktop screen, stacked on a phone -- PORTAL_CSS. */}
      <div className="portal-home-grid">
      {activeHourContracts.map((c) => (
        <div key={c.id} className="portal-card" style={styles.card}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
            <div>
              <div style={{ fontSize: 12, color: MUTED, fontWeight: 600, textTransform: 'uppercase' }}>Support hours</div>
              <div style={{ fontSize: 13, color: MUTED }}>{c.contract_number}</div>
            </div>
            <span style={badgeStyle(contractBadgeKind(c.status))}>{label(c.status)}</span>
          </div>
          <div style={{ display: 'flex', alignItems: 'baseline', gap: 6, marginTop: 10 }}>
            <span style={{ fontSize: 30, fontWeight: 700, color: c.remaining_hours > 0 ? OK : DANGER }}>
              {c.remaining_hours.toFixed(1)}
            </span>
            <span style={{ fontSize: 14, color: MUTED }}>of {c.contracted_hours.toFixed(1)} hrs remaining</span>
          </div>
          <div style={{ height: 8, borderRadius: 4, background: BORDER, overflow: 'hidden', marginTop: 8 }}>
            <div style={{ height: '100%', width: `${Math.min(100, (c.consumed_hours / c.contracted_hours) * 100)}%`, background: MAROON }} />
          </div>
          <div style={{ fontSize: 12, color: MUTED, marginTop: 6 }}>Expires {fmtDate(c.end_date)}</div>
        </div>
      ))}

      <div className="portal-card" style={styles.card}>
        <div style={{ fontSize: 12, color: MUTED, fontWeight: 600, textTransform: 'uppercase' }}>Account balance</div>
        <div style={{ fontSize: 28, fontWeight: 700, marginTop: 4, color: outstandingBalance > 0 ? INK : OK }}>
          {fmtMoney(outstandingBalance)}
        </div>
        <div style={{ fontSize: 13, color: MUTED, marginTop: 2 }}>
          {outstandingBalance > 0 ? 'Outstanding across all invoices' : 'Nothing outstanding'}
        </div>
        <button onClick={() => onGoTab('billing')} className="portal-action" style={{ ...styles.btn, ...styles.btnSecondary, marginTop: 12 }}>
          View invoices &amp; payments
        </button>
      </div>

      <div className="portal-card" style={styles.card}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <div style={{ fontSize: 12, color: MUTED, fontWeight: 600, textTransform: 'uppercase' }}>Open incidents</div>
          <span style={{ fontSize: 22, fontWeight: 700 }}>{openIncidents.length}</span>
        </div>
        <button onClick={() => onGoTab('incidents')} className="portal-action" style={{ ...styles.btn, ...styles.btnSecondary, marginTop: 12 }}>
          View incidents
        </button>
      </div>

      <div className="portal-card" style={styles.card}>
        <div style={{ fontSize: 12, color: MUTED, fontWeight: 600, textTransform: 'uppercase', marginBottom: 8 }}>Recent job orders</div>
        {recentJobOrders.length === 0 && <div style={{ fontSize: 14, color: MUTED }}>No job orders yet.</div>}
        {recentJobOrders.map((jo) => (
          <div key={jo.id} style={{ padding: '8px 0', borderTop: `1px solid ${BORDER}` }}>
            <div style={{ display: 'flex', justifyContent: 'space-between' }}>
              <div style={{ fontWeight: 600, fontSize: 14 }}>{jo.job_order_number}</div>
              <span style={badgeStyle(jobOrderBadgeKind(jo.status))}>{label(jo.status)}</span>
            </div>
            <div style={{ fontSize: 13, color: MUTED, marginTop: 2 }}>{jo.subject}</div>
          </div>
        ))}
        <button onClick={() => onGoTab('jobOrders')} className="portal-action" style={{ ...styles.btn, ...styles.btnSecondary, marginTop: 12 }}>
          View all job orders
        </button>
      </div>
      </div>
    </div>
  )
}

// ── Contracts ────────────────────────────────────────────────────────

function PortalContracts({ onViewServiceRecords }: { onViewServiceRecords: (contractId: string, contractNumber: string) => void }) {
  const [contracts, setContracts] = useState<PortalContract[] | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    portalApi.contracts().then(setContracts).catch((e) => setError(e instanceof Error ? e.message : 'Failed to load'))
  }, [])

  return (
    <div>
      {error && <div style={styles.errorBox}>{error}</div>}
      {contracts?.length === 0 && <div style={{ padding: '20px 16px', color: MUTED, fontSize: 14 }}>No contracts on file.</div>}
      {contracts?.map((c) => (
        <div key={c.id} className="portal-card" style={styles.card}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
            <div>
              <div style={{ fontWeight: 700, fontSize: 15 }}>{c.contract_number}</div>
              <div style={{ fontSize: 13, color: MUTED, marginTop: 2 }}>{label(c.contract_kind)}</div>
            </div>
            <span style={badgeStyle(contractBadgeKind(c.status))}>{label(c.status)}</span>
          </div>
          {c.contract_kind === 'service_support' && (
            <>
              <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 13, marginTop: 12 }}>
                <span style={{ color: MUTED }}>Contracted</span>
                <span>{c.contracted_hours.toFixed(1)} hrs</span>
              </div>
              <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 13, marginTop: 4 }}>
                <span style={{ color: MUTED }}>Consumed</span>
                <span>{c.consumed_hours.toFixed(1)} hrs</span>
              </div>
              <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 13, marginTop: 4, fontWeight: 700 }}>
                <span style={{ color: MUTED, fontWeight: 400 }}>Remaining</span>
                <span style={{ color: c.remaining_hours > 0 ? OK : DANGER }}>{c.remaining_hours.toFixed(1)} hrs</span>
              </div>
              <div style={{ height: 8, borderRadius: 4, background: BORDER, overflow: 'hidden', marginTop: 8 }}>
                <div style={{ height: '100%', width: `${Math.min(100, (c.consumed_hours / c.contracted_hours) * 100)}%`, background: MAROON }} />
              </div>
            </>
          )}
          <div style={{ fontSize: 12, color: MUTED, marginTop: 10 }}>
            {fmtDate(c.start_date)} — {fmtDate(c.end_date)}
          </div>
          <button
            onClick={() => onViewServiceRecords(c.id, c.contract_number)}
            style={{ ...styles.btn, ...styles.btnSecondary, marginTop: 12 }}
          >
            View service records
          </button>
        </div>
      ))}
    </div>
  )
}

// PORTAL-006: the service records logged against one specific contract
// -- reached from a contract card above, not its own bottom-nav tab.
function PortalContractServiceRecords({
  contractId, contractNumber, onBack,
}: { contractId: string; contractNumber: string; onBack: () => void }) {
  const [records, setRecords] = useState<PortalServiceRecord[] | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    portalApi.serviceRecords(contractId).then(setRecords).catch((e) => setError(e instanceof Error ? e.message : 'Failed to load'))
  }, [contractId])

  return (
    <div>
      <div style={{ padding: '12px 16px 0' }}>
        <button onClick={onBack} style={{ background: 'none', border: 'none', color: MAROON, fontSize: 14, cursor: 'pointer', padding: 0 }}>
          &larr; Back to contracts
        </button>
      </div>
      {error && <div style={styles.errorBox}>{error}</div>}
      <div className="portal-card" style={styles.card}>
        <div style={{ fontSize: 12, color: MUTED, fontWeight: 600, textTransform: 'uppercase', marginBottom: 4 }}>
          Service records for {contractNumber}
        </div>
        {records?.length === 0 && <div style={{ fontSize: 14, color: MUTED, padding: '8px 0' }}>No service records logged against this contract yet.</div>}
        {records?.map((r) => <ServiceRecordRow key={r.id} r={r} />)}
      </div>
    </div>
  )
}

// ── Job Orders ───────────────────────────────────────────────────────

function PortalJobOrders({ onSelect }: { onSelect: (id: string) => void }) {
  const [jobOrders, setJobOrders] = useState<PortalJobOrder[] | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    portalApi.jobOrders().then(setJobOrders).catch((e) => setError(e instanceof Error ? e.message : 'Failed to load'))
  }, [])

  return (
    <div>
      {error && <div style={styles.errorBox}>{error}</div>}
      {jobOrders?.length === 0 && <div style={{ padding: '20px 16px', color: MUTED, fontSize: 14 }}>No job orders yet.</div>}
      {jobOrders?.map((jo) => (
        <div key={jo.id} className="portal-card" style={{ ...styles.card, cursor: 'pointer' }} onClick={() => onSelect(jo.id)}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
            <div style={{ flex: 1 }}>
              <div style={{ fontWeight: 700, fontSize: 15 }}>{jo.job_order_number}</div>
              <div style={{ fontSize: 14, marginTop: 2 }}>{jo.subject}</div>
              {jo.assigned_engineer_name && (
                <div style={{ fontSize: 13, color: MUTED, marginTop: 6 }}>Engineer: {jo.assigned_engineer_name}</div>
              )}
              {jo.due_date && <div style={{ fontSize: 12, color: MUTED, marginTop: 2 }}>Due: {fmtDate(jo.due_date)}</div>}
            </div>
            <span style={badgeStyle(jobOrderBadgeKind(jo.status))}>{label(jo.status)}</span>
          </div>
        </div>
      ))}
    </div>
  )
}

function ServiceRecordRow({ r }: { r: PortalServiceRecord }) {
  return (
    <div style={{ padding: '10px 0', borderTop: `1px solid ${BORDER}` }}>
      <div style={{ display: 'flex', justifyContent: 'space-between' }}>
        <div style={{ fontWeight: 600, fontSize: 13 }}>{r.service_record_number}</div>
        <div style={{ fontSize: 13, color: MUTED }}>{fmtDate(r.work_date)}</div>
      </div>
      <div style={{ fontSize: 13, color: MUTED, marginTop: 2 }}>{r.engineer_name} · {fmtMinutes(r.minutes)}</div>
      <div style={{ display: 'flex', gap: 6, marginTop: 6 }}>
        <span style={badgeStyle(r.status === 'approved' ? 'ok' : 'neutral')}>{label(r.status)}</span>
        <span style={badgeStyle(r.completion_status === 'completed' ? 'ok' : 'warn')}>
          {r.completion_status === 'completed' ? 'Completed' : 'Follow-up needed'}
        </span>
      </div>
    </div>
  )
}

function PortalJobOrderDetailView({ id, onBack }: { id: string; onBack: () => void }) {
  const [detail, setDetail] = useState<PortalJobOrderDetail | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    portalApi.jobOrderDetail(id).then(setDetail).catch((e) => setError(e instanceof Error ? e.message : 'Failed to load'))
  }, [id])

  return (
    <div>
      <div style={{ padding: '12px 16px 0' }}>
        <button onClick={onBack} style={{ background: 'none', border: 'none', color: MAROON, fontSize: 14, cursor: 'pointer', padding: '10px 0' }}>
          &larr; Back to job orders
        </button>
      </div>
      {error && <div style={styles.errorBox}>{error}</div>}
      {detail && (
        <>
          <div className="portal-card" style={styles.card}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
              <div>
                <div style={{ fontWeight: 700, fontSize: 16 }}>{detail.job_order_number}</div>
                <div style={{ fontSize: 14, marginTop: 4 }}>{detail.subject}</div>
              </div>
              <span style={badgeStyle(jobOrderBadgeKind(detail.status))}>{label(detail.status)}</span>
            </div>
            {detail.assigned_engineer_name && (
              <div style={{ fontSize: 13, color: MUTED, marginTop: 10 }}>Engineer: {detail.assigned_engineer_name}</div>
            )}
            {detail.due_date && <div style={{ fontSize: 13, color: MUTED, marginTop: 2 }}>Due: {fmtDate(detail.due_date)}</div>}
          </div>
          <div className="portal-card" style={styles.card}>
            <div style={{ fontSize: 12, color: MUTED, fontWeight: 600, textTransform: 'uppercase', marginBottom: 4 }}>Service records</div>
            {detail.service_records.length === 0 && <div style={{ fontSize: 14, color: MUTED, padding: '8px 0' }}>No service records logged yet.</div>}
            {detail.service_records.map((r) => <ServiceRecordRow key={r.id} r={r} />)}
          </div>
        </>
      )}
    </div>
  )
}

// ── Billing: Invoices + Payments (PORTAL-005) ───────────────────────

function InvoiceCard({ inv }: { inv: PortalInvoice }) {
  return (
    <div className="portal-card" style={styles.card}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
        <div>
          <div style={{ fontWeight: 700, fontSize: 15 }}>{inv.invoice_number}</div>
          <div style={{ fontSize: 13, color: MUTED, marginTop: 2 }}>{inv.description}</div>
          {inv.contract_number && <div style={{ fontSize: 12, color: MUTED, marginTop: 2 }}>{inv.contract_number}</div>}
        </div>
        <span style={badgeStyle(invoiceBadgeKind(inv.status))}>{label(inv.status)}</span>
      </div>
      <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 13, marginTop: 12 }}>
        <span style={{ color: MUTED }}>Net</span>
        <span>{fmtMoney(inv.amount_sgd)}</span>
      </div>
      <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 13, marginTop: 4 }}>
        <span style={{ color: MUTED }}>GST</span>
        <span>{fmtMoney(inv.gst_amount_sgd)}</span>
      </div>
      <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 13, marginTop: 4, fontWeight: 700 }}>
        <span style={{ color: MUTED, fontWeight: 400 }}>Total</span>
        <span>{fmtMoney(inv.total_amount_sgd)}</span>
      </div>
      {inv.credited_sgd > 0 && (
        <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 13, marginTop: 4 }}>
          <span style={{ color: MUTED }}>Credit notes</span>
          <span>{fmtMoney(-inv.credited_sgd)}</span>
        </div>
      )}
      {inv.outstanding_sgd > 0 && (
        <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 13, marginTop: 4, fontWeight: 700 }}>
          <span style={{ color: MUTED, fontWeight: 400 }}>Outstanding</span>
          <span style={{ color: DANGER }}>{fmtMoney(inv.outstanding_sgd)}</span>
        </div>
      )}
      <div style={{ fontSize: 12, color: MUTED, marginTop: 10 }}>
        Issued {fmtDate(inv.issued_at)}{inv.due_date && ` · Due ${fmtDate(inv.due_date)}`}
      </div>
    </div>
  )
}

function PaymentCard({ p }: { p: PortalPayment }) {
  return (
    <div className="portal-card" style={styles.card}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
        <div>
          <div style={{ fontWeight: 700, fontSize: 15 }}>{p.voucher_number}</div>
          <div style={{ fontSize: 13, color: MUTED, marginTop: 2 }}>{label(p.method)}{p.reference ? ` · ${p.reference}` : ''}</div>
        </div>
        <span style={{ fontSize: 18, fontWeight: 700, color: OK }}>{fmtMoney(p.amount_sgd)}</span>
      </div>
      {p.allocations.length > 0 ? (
        <div style={{ marginTop: 10 }}>
          <div style={{ fontSize: 12, color: MUTED, fontWeight: 600, textTransform: 'uppercase', marginBottom: 4 }}>Applied to</div>
          {p.allocations.map((a) => (
            <div key={a.invoice_id} style={{ display: 'flex', justifyContent: 'space-between', fontSize: 13, padding: '2px 0' }}>
              <span>{a.invoice_number ?? 'Invoice'}</span>
              <span>{fmtMoney(a.amount_sgd)}</span>
            </div>
          ))}
        </div>
      ) : (
        <div style={{ fontSize: 13, color: MUTED, marginTop: 10 }}>Not yet applied to an invoice.</div>
      )}
      <div style={{ fontSize: 12, color: MUTED, marginTop: 10 }}>{fmtDate(p.payment_date)}</div>
    </div>
  )
}

function PortalBilling() {
  const [view, setView] = useState<'invoices' | 'payments'>('invoices')
  const [invoices, setInvoices] = useState<PortalInvoice[] | null>(null)
  const [payments, setPayments] = useState<PortalPayment[] | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    Promise.all([portalApi.invoices(), portalApi.payments()])
      .then(([inv, pay]) => { setInvoices(inv); setPayments(pay) })
      .catch((e) => setError(e instanceof Error ? e.message : 'Failed to load'))
  }, [])

  return (
    <div>
      <div style={{ display: 'flex', gap: 8, padding: '12px 16px 0' }}>
        <button
          onClick={() => setView('invoices')}
          style={{ ...styles.btn, flex: 1, padding: '10px', ...(view === 'invoices' ? styles.btnPrimary : styles.btnSecondary) }}
        >
          Invoices
        </button>
        <button
          onClick={() => setView('payments')}
          style={{ ...styles.btn, flex: 1, padding: '10px', ...(view === 'payments' ? styles.btnPrimary : styles.btnSecondary) }}
        >
          Payments
        </button>
      </div>
      {error && <div style={styles.errorBox}>{error}</div>}
      {view === 'invoices' && (
        invoices?.length === 0
          ? <div style={{ padding: '20px 16px', color: MUTED, fontSize: 14 }}>No invoices yet.</div>
          : invoices?.map((inv) => <InvoiceCard key={inv.id} inv={inv} />)
      )}
      {view === 'payments' && (
        payments?.length === 0
          ? <div style={{ padding: '20px 16px', color: MUTED, fontSize: 14 }}>No payments recorded yet.</div>
          : payments?.map((p) => <PaymentCard key={p.id} p={p} />)
      )}
    </div>
  )
}

// ── Incidents ────────────────────────────────────────────────────────

function PortalIncidents({ onNew }: { onNew: () => void }) {
  const [incidents, setIncidents] = useState<PortalIncident[] | null>(null)
  const [error, setError] = useState<string | null>(null)

  function load() {
    portalApi.incidents().then(setIncidents).catch((e) => setError(e instanceof Error ? e.message : 'Failed to load'))
  }
  useEffect(load, [])

  return (
    <div>
      <div style={{ padding: '12px 16px 0' }}>
        <button onClick={onNew} style={{ ...styles.btn, ...styles.btnPrimary }}>+ Raise an incident</button>
      </div>
      {error && <div style={styles.errorBox}>{error}</div>}
      {incidents?.length === 0 && <div style={{ padding: '20px 16px', color: MUTED, fontSize: 14 }}>No incidents raised yet.</div>}
      {incidents?.map((i) => (
        <div key={i.id} className="portal-card" style={styles.card}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
            <div>
              <div style={{ fontWeight: 700, fontSize: 14 }}>{i.incident_number}</div>
              <div style={{ fontSize: 14, marginTop: 2 }}>{i.subject}</div>
            </div>
            <span style={badgeStyle(incidentBadgeKind(i.status))}>{label(i.status)}</span>
          </div>
          {i.converted_job_order_number && (
            <div style={{ fontSize: 13, color: MAROON, fontWeight: 600, marginTop: 8 }}>
              Routed to Job Order {i.converted_job_order_number}
            </div>
          )}
          {i.description && <div style={{ fontSize: 13, color: MUTED, marginTop: 8 }}>{i.description}</div>}
          <div style={{ fontSize: 12, color: MUTED, marginTop: 8 }}>{fmtDateTime(i.created_at)}</div>
          <PortalIncidentFiles incident={i} />
        </div>
      ))}
    </div>
  )
}

/** A file the customer picked, checked against the portal's limits before anything is sent. */
function checkPortalFile(file: File): string | null {
  if (!(file.type.startsWith('image/') || file.type === 'application/pdf')) return `${file.name}: only photos, screenshots and PDF files can be attached.`
  if (file.size > PORTAL_ATTACHMENT_MAX_BYTES) return `${file.name} is larger than 10 MB.`
  return null
}

function fmtSize(bytes: number): string {
  return bytes >= 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`
}

/** The files on one incident: open them, and add more while the incident is open. */
function PortalIncidentFiles({ incident }: { incident: PortalIncident }) {
  const [files, setFiles] = useState<PortalIncidentAttachment[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [uploading, setUploading] = useState(false)
  const canAdd = incident.status === 'open' || incident.status === 'pending_callback'

  function load() {
    portalApi.incidentAttachments(incident.id).then(setFiles).catch((e) => setError(e instanceof Error ? e.message : 'Failed to load files'))
  }
  useEffect(load, [incident.id])

  async function onAdd(e: ChangeEvent<HTMLInputElement>) {
    const picked = Array.from(e.target.files ?? [])
    e.target.value = ''
    if (picked.length === 0) return
    setError(null)
    const room = PORTAL_ATTACHMENTS_PER_INCIDENT - (files?.length ?? 0)
    if (picked.length > room) {
      setError(`An incident can have up to ${PORTAL_ATTACHMENTS_PER_INCIDENT} files -- ${room} more can be added.`)
      return
    }
    const problem = picked.map(checkPortalFile).find(Boolean)
    if (problem) {
      setError(problem)
      return
    }
    setUploading(true)
    try {
      for (const f of picked) await portalApi.uploadIncidentAttachment(incident.id, f)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not attach the file')
    } finally {
      setUploading(false)
      load()
    }
  }

  async function onOpen(a: PortalIncidentAttachment) {
    try {
      const blob = await portalApi.downloadIncidentAttachment(incident.id, a.id)
      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = a.original_filename
      link.click()
      window.setTimeout(() => URL.revokeObjectURL(url), 10000)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not open the file')
    }
  }

  if (files === null && !error) return null
  if ((files?.length ?? 0) === 0 && !canAdd) return null
  return (
    <div style={{ marginTop: 10, borderTop: `1px solid #eee`, paddingTop: 8 }} data-testid="portal-incident-files">
      {(files ?? []).map((a) => (
        <div key={a.id} style={{ fontSize: 13, display: 'flex', justifyContent: 'space-between', gap: 8, padding: '3px 0' }}>
          <button type="button" onClick={() => onOpen(a)} style={{ background: 'none', border: 'none', padding: 0, color: MAROON, cursor: 'pointer', textAlign: 'left', wordBreak: 'break-all' }}>
            📎 {a.original_filename}
          </button>
          <span style={{ color: MUTED, whiteSpace: 'nowrap' }}>{fmtSize(a.file_size_bytes)}</span>
        </div>
      ))}
      {canAdd && (files?.length ?? 0) < PORTAL_ATTACHMENTS_PER_INCIDENT && (
        <label style={{ ...styles.btn, ...styles.btnSecondary, display: 'inline-block', width: 'auto', padding: '6px 12px', fontSize: 13, marginTop: 6, cursor: 'pointer' }}>
          {uploading ? 'Attaching...' : '+ Add photo or PDF'}
          <input type="file" accept={PORTAL_ATTACHMENT_ACCEPT} multiple onChange={onAdd} disabled={uploading} style={{ display: 'none' }} />
        </label>
      )}
      {error && <div style={{ ...styles.errorBox, margin: '8px 0 0' }}>{error}</div>}
    </div>
  )
}

function PortalNewIncident({ onDone }: { onDone: () => void }) {
  const [subject, setSubject] = useState('')
  const [description, setDescription] = useState('')
  const [files, setFiles] = useState<File[]>([])
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  function onPick(e: ChangeEvent<HTMLInputElement>) {
    const picked = Array.from(e.target.files ?? [])
    e.target.value = ''
    setError(null)
    const problem = picked.map(checkPortalFile).find(Boolean)
    if (problem) {
      setError(problem)
      return
    }
    const next = [...files, ...picked]
    if (next.length > PORTAL_ATTACHMENTS_PER_INCIDENT) {
      setError(`An incident can have up to ${PORTAL_ATTACHMENTS_PER_INCIDENT} files.`)
      return
    }
    setFiles(next)
  }

  async function onSubmit(e: FormEvent) {
    e.preventDefault()
    setError(null)
    setSubmitting(true)
    let created: PortalIncident | null = null
    try {
      created = await portalApi.createIncident(subject, description)
      for (const f of files) await portalApi.uploadIncidentAttachment(created.id, f)
      onDone()
    } catch (err) {
      const why = err instanceof Error ? err.message : 'Could not raise incident'
      // The incident itself was raised: say so, and let the files be added from the list.
      setError(created ? `Incident ${created.incident_number} was raised, but a file could not be attached (${why}). Add it from the incident list.` : why)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <form onSubmit={onSubmit} style={{ padding: '12px 16px' }}>
      <button type="button" onClick={onDone} style={{ background: 'none', border: 'none', color: MAROON, fontSize: 14, cursor: 'pointer', padding: 0, marginBottom: 12 }}>
        &larr; Cancel
      </button>
      <div className="portal-card" style={styles.card}>
        <h2 style={{ margin: '0 0 16px', fontSize: 17 }}>Raise an incident</h2>
        {error && <div style={{ ...styles.errorBox, margin: '0 0 12px' }}>{error}</div>}
        <div style={{ marginBottom: 12 }}>
          <label style={styles.label}>Subject</label>
          <input value={subject} onChange={(e) => setSubject(e.target.value)} required maxLength={255} autoFocus style={styles.input} placeholder="e.g. Printer not working" />
        </div>
        <div style={{ marginBottom: 16 }}>
          <label style={styles.label}>Description (optional)</label>
          <textarea value={description} onChange={(e) => setDescription(e.target.value)} rows={4} style={{ ...styles.input, resize: 'vertical' as const }} placeholder="Any extra detail that would help our team" />
        </div>
        <div style={{ marginBottom: 16 }}>
          <label style={styles.label} htmlFor="portal-incident-files">Photos, screenshots or PDF (optional, up to 5, 10 MB each)</label>
          <input id="portal-incident-files" type="file" accept={PORTAL_ATTACHMENT_ACCEPT} multiple onChange={onPick} style={{ fontSize: 13 }} />
          {files.map((f, idx) => (
            <div key={`${f.name}-${idx}`} style={{ fontSize: 13, display: 'flex', justifyContent: 'space-between', gap: 8, marginTop: 6 }}>
              <span style={{ wordBreak: 'break-all' }}>📎 {f.name} <span style={{ color: MUTED }}>({fmtSize(f.size)})</span></span>
              <button type="button" onClick={() => setFiles(files.filter((_, j) => j !== idx))} style={{ background: 'none', border: 'none', color: MAROON, cursor: 'pointer' }} aria-label={`Remove ${f.name}`}>
                Remove
              </button>
            </div>
          ))}
        </div>
        <button type="submit" disabled={submitting || !subject.trim()} style={{ ...styles.btn, ...styles.btnPrimary }}>
          {submitting ? 'Submitting...' : 'Submit incident'}
        </button>
      </div>
    </form>
  )
}

// ── Signed-in app ────────────────────────────────────────────────────

function PortalSignedInApp() {
  const [tab, setTab] = useState<Tab>('home')
  const [selectedJobOrderId, setSelectedJobOrderId] = useState<string | null>(null)
  const [showNewIncident, setShowNewIncident] = useState(false)
  const [contractDrilldown, setContractDrilldown] = useState<{ id: string; number: string } | null>(null)

  // AI Assistant slice 3: what the widget should treat as "currently looking at".
  const aiContext: PortalAiContext = (() => {
    if (tab === 'jobOrders' && selectedJobOrderId) return { type: 'job_order', id: selectedJobOrderId }
    if (tab === 'contracts' && contractDrilldown) return { type: 'contract', id: contractDrilldown.id, label: contractDrilldown.number }
    if (tab === 'billing') return { type: 'billing' }
    if (tab === 'incidents') return { type: 'incidents' }
    return null
  })()

  function goTab(t: Tab) {
    setSelectedJobOrderId(null)
    setShowNewIncident(false)
    setContractDrilldown(null)
    setTab(t)
  }

  const titles: Record<Tab, string> = {
    home: 'Home',
    contracts: contractDrilldown ? 'Service Records' : 'Contracts',
    jobOrders: selectedJobOrderId ? 'Job Order' : 'Job Orders',
    billing: 'Billing',
    incidents: showNewIncident ? 'New Incident' : 'Incidents',
  }

  return (
    <div className="portal-shell" style={styles.shell}>
      <PortalStyles />
      <PortalHeader title={titles[tab]} />
      <div className="portal-body">
        <PortalNav tab={tab} onChange={goTab} />
        <div className="portal-content" style={styles.content}>
        {tab === 'home' && <PortalHome onGoTab={goTab} />}
        {tab === 'contracts' && (
          contractDrilldown
            ? (
              <PortalContractServiceRecords
                contractId={contractDrilldown.id}
                contractNumber={contractDrilldown.number}
                onBack={() => setContractDrilldown(null)}
              />
            )
            : <PortalContracts onViewServiceRecords={(id, number) => setContractDrilldown({ id, number })} />
        )}
        {tab === 'jobOrders' && (
          selectedJobOrderId
            ? <PortalJobOrderDetailView id={selectedJobOrderId} onBack={() => setSelectedJobOrderId(null)} />
            : <PortalJobOrders onSelect={setSelectedJobOrderId} />
        )}
        {tab === 'billing' && <PortalBilling />}
        {tab === 'incidents' && (
          showNewIncident
            ? <PortalNewIncident onDone={() => setShowNewIncident(false)} />
            : <PortalIncidents onNew={() => setShowNewIncident(true)} />
        )}
        </div>
      </div>
      <PortalAiChatWidget context={aiContext} />
    </div>
  )
}

function PortalRoot() {
  const { portalUser, loading } = usePortalAuth()
  if (loading) return <div className="portal-shell" style={{ ...styles.shell, alignItems: 'center', justifyContent: 'center' }}>Loading...</div>
  if (!portalUser) return <PortalLogin />
  if (portalUser.must_change_password) return <PortalLogin />
  return <PortalSignedInApp />
}

export default function PortalApp() {
  return (
    <PortalAuthProvider>
      <PortalRoot />
    </PortalAuthProvider>
  )
}
