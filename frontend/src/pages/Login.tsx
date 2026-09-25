import { useEffect, useState, type FormEvent } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../lib/AuthContext'
import PromoVideoPanel from '../components/PromoVideoPanel'
import { api, changePassword, forgotPassword, resetPasswordWithOtp, sendOtp, verifyOtp } from '../lib/api'
import type { LoginResult, PublicBranding } from '../lib/api'

// Login sequence (2026-09-12: password complexity, forced first-login
// password change, email OTP second factor; 2026-09-16: WhatsApp OTP as a
// second, optional channel -- 'otp_channel' only ever appears for an
// account with both email and WhatsApp available, see LoginResult's
// docblock in lib/api.ts; "forget password" is a separate email+OTP pair
// -- see backend app/routers/auth.py's module docstring for the full
// sequence this page walks through step by step).
type Step = 'credentials' | 'otp_channel' | 'otp' | 'change_password' | 'forgot_email' | 'forgot_reset'

export default function Login() {
  const { login, completeLogin } = useAuth()
  const navigate = useNavigate()
  // Where RequireAuth sent the user from; only ever a path on this site.
  const from = (useLocation().state as { from?: string } | null)?.from
  const returnTo = from && from.startsWith('/') && !from.startsWith('//') && !from.startsWith('/login') ? from : '/'
  const [step, setStep] = useState<Step>('credentials')

  // Empty in every built bundle: a prefilled password shipped pre-typed
  // into every deployment's sign-in form, including the test server's,
  // whose demo password is published. Under `npm run dev` only, they
  // prefill the seeded demo owner as a local convenience (confirmed with
  // Dennis 2026-09-15). Vite substitutes `false` for import.meta.env.DEV
  // in `npm run build`, so neither demo string survives into a
  // production bundle rather than merely going unused.
  const [email, setEmail] = useState(import.meta.env.DEV ? 'dennis@websoft.example' : '')
  const [password, setPassword] = useState(import.meta.env.DEV ? 'demo1234' : '')

  const [otpToken, setOtpToken] = useState('')
  const [otpCode, setOtpCode] = useState('')
  const [otpChannel, setOtpChannel] = useState<'email' | 'whatsapp'>('email')

  const [channelToken, setChannelToken] = useState('')
  const [availableChannels, setAvailableChannels] = useState<Array<'email' | 'whatsapp'>>([])

  const [changeToken, setChangeToken] = useState('')
  const [newPassword, setNewPassword] = useState('')
  const [confirmPassword, setConfirmPassword] = useState('')

  // Forgot password (2026-09-12): a self-contained email + OTP + new
  // password flow, separate from the sign-in sequence above.
  const [forgotEmail, setForgotEmail] = useState('')
  const [resetCode, setResetCode] = useState('')
  const [resetNewPassword, setResetNewPassword] = useState('')
  const [resetConfirmPassword, setResetConfirmPassword] = useState('')
  const [info, setInfo] = useState<string | null>(null)

  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  // Company logo (2026-09-12): shown above the title, same branding a
  // signed-in user sees in the sidebar. No one is signed in yet at this
  // point, so this comes from the one unauthenticated company endpoint
  // -- see api.getPublicBranding. Failing quietly (no logo) beats
  // blocking the login form on a branding call.
  const [branding, setBranding] = useState<PublicBranding | null>(null)
  useEffect(() => {
    api.getPublicBranding().then(setBranding).catch(() => setBranding(null))
  }, [])

  /** Common tail of every sign-in step: "ok" signs the user in,
   * otherwise move to whichever step the backend says is next. */
  async function advance(result: LoginResult) {
    if (result.status === 'ok' && result.access_token) {
      await completeLogin(result.access_token)
      navigate(returnTo)
    } else if (result.status === 'otp_required' && result.otp_token) {
      setOtpToken(result.otp_token)
      setOtpCode('')
      setOtpChannel(result.channel ?? 'email')
      setStep('otp')
    } else if (result.status === 'otp_channel_required' && result.channel_token) {
      setChannelToken(result.channel_token)
      setAvailableChannels(result.available_channels ?? [])
      setStep('otp_channel')
    } else if (result.status === 'must_change_password' && result.change_token) {
      setChangeToken(result.change_token)
      setNewPassword('')
      setConfirmPassword('')
      setStep('change_password')
    }
  }

  async function onChooseOtpChannel(channel: 'email' | 'whatsapp') {
    setError(null)
    setSubmitting(true)
    try {
      await advance(await sendOtp(channelToken, channel))
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not send code')
    } finally {
      setSubmitting(false)
    }
  }

  function backToSignIn() {
    setError(null)
    setInfo(null)
    setStep('credentials')
  }

  async function onSubmitCredentials(e: FormEvent) {
    e.preventDefault()
    setError(null)
    setSubmitting(true)
    try {
      await advance(await login(email, password))
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Login failed')
    } finally {
      setSubmitting(false)
    }
  }

  async function onSubmitOtp(e: FormEvent) {
    e.preventDefault()
    setError(null)
    setSubmitting(true)
    try {
      await advance(await verifyOtp(otpToken, otpCode))
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
      await advance(await changePassword(changeToken, newPassword))
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not change password')
    } finally {
      setSubmitting(false)
    }
  }

  function onClickForgotPassword() {
    setError(null)
    setInfo(null)
    setForgotEmail(email)
    setStep('forgot_email')
  }

  async function onSubmitForgotEmail(e: FormEvent) {
    e.preventDefault()
    setError(null)
    setSubmitting(true)
    try {
      const result = await forgotPassword(forgotEmail)
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
      await resetPasswordWithOtp(forgotEmail, resetCode, resetNewPassword)
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

  return (
    <div className="login-shell">
      <div className="login-layout">
        <div className="card login-card">
          {branding?.logo && (
            <img className="login-logo" src={branding.logo} alt={`${branding.name} logo`} />
          )}
          <h1>Websoft Service ERP</h1>
          <p className="muted" style={{ marginBottom: 18 }}>
            Service Operations core -- demo build
          </p>

          {step === 'credentials' && (
            <form onSubmit={onSubmitCredentials}>
              <div className="form-row">
                <label>Email</label>
                {/* autoComplete lets a password manager fill these, which
                    is the convenience the removed hardcoded prefill was
                    standing in for -- and it fills the right account
                    rather than one guessed at build time. */}
                <input
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  type="email"
                  autoComplete="username"
                  autoFocus
                  required
                />
              </div>
              <div className="form-row">
                <label>Password</label>
                <input
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  type="password"
                  autoComplete="current-password"
                  required
                />
              </div>
              {info && <p className="muted">{info}</p>}
              {error && <div className="error-banner">{error}</div>}
              <button type="submit" disabled={submitting} style={{ width: '100%' }}>
                {submitting ? 'Signing in...' : 'Sign in'}
              </button>
              <button
                type="button"
                className="link-button"
                style={{ marginTop: 10 }}
                onClick={onClickForgotPassword}
              >
                Forgot password?
              </button>
            </form>
          )}

          {step === 'otp_channel' && (
            <div>
              <p className="muted" style={{ marginBottom: 18 }}>
                How would you like to receive your one-time code?
              </p>
              {error && <div className="error-banner">{error}</div>}
              <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                {availableChannels.includes('email') && (
                  <button
                    type="button"
                    disabled={submitting}
                    onClick={() => onChooseOtpChannel('email')}
                    style={{ width: '100%' }}
                  >
                    {submitting ? 'Sending...' : `Email me a code (${email})`}
                  </button>
                )}
                {availableChannels.includes('whatsapp') && (
                  <button
                    type="button"
                    disabled={submitting}
                    onClick={() => onChooseOtpChannel('whatsapp')}
                    style={{ width: '100%' }}
                  >
                    {submitting ? 'Sending...' : 'Send me a WhatsApp message'}
                  </button>
                )}
              </div>
            </div>
          )}

          {step === 'otp' && (
            <form onSubmit={onSubmitOtp}>
              <p className="muted" style={{ marginBottom: 18 }}>
                {otpChannel === 'whatsapp'
                  ? 'We sent a 6-digit code to your WhatsApp. Enter it below to finish signing in.'
                  : `We emailed a 6-digit code to ${email}. Enter it below to finish signing in.`}
              </p>
              <div className="form-row">
                <label>One-time code</label>
                <input
                  value={otpCode}
                  onChange={(e) => setOtpCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
                  inputMode="numeric"
                  autoComplete="one-time-code"
                  autoFocus
                  required
                />
              </div>
              {error && <div className="error-banner">{error}</div>}
              <button type="submit" disabled={submitting || otpCode.length !== 6} style={{ width: '100%' }}>
                {submitting ? 'Verifying...' : 'Verify code'}
              </button>
            </form>
          )}

          {step === 'change_password' && (
            <form onSubmit={onSubmitChangePassword}>
              <p className="muted" style={{ marginBottom: 18 }}>
                This is your first sign-in -- please set your own password to continue. At
                least 8 characters, with at least one letter and one number.
              </p>
              <div className="form-row">
                <label>New password</label>
                <input
                  value={newPassword}
                  onChange={(e) => setNewPassword(e.target.value)}
                  type="password"
                  minLength={8}
                  autoFocus
                  required
                />
              </div>
              <div className="form-row">
                <label>Confirm password</label>
                <input
                  value={confirmPassword}
                  onChange={(e) => setConfirmPassword(e.target.value)}
                  type="password"
                  minLength={8}
                  required
                />
              </div>
              {error && <div className="error-banner">{error}</div>}
              <button type="submit" disabled={submitting} style={{ width: '100%' }}>
                {submitting ? 'Saving...' : 'Set password and sign in'}
              </button>
            </form>
          )}

          {step === 'forgot_email' && (
            <form onSubmit={onSubmitForgotEmail}>
              <p className="muted" style={{ marginBottom: 18 }}>
                Enter your account email -- if it matches an active account, we'll email a
                one-time code to reset your password.
              </p>
              <div className="form-row">
                <label>Email</label>
                <input
                  value={forgotEmail}
                  onChange={(e) => setForgotEmail(e.target.value)}
                  type="email"
                  autoFocus
                  required
                />
              </div>
              {error && <div className="error-banner">{error}</div>}
              <button type="submit" disabled={submitting} style={{ width: '100%' }}>
                {submitting ? 'Sending...' : 'Send reset code'}
              </button>
              <button type="button" className="link-button" style={{ marginTop: 10 }} onClick={backToSignIn}>
                Back to sign in
              </button>
            </form>
          )}

          {step === 'forgot_reset' && (
            <form onSubmit={onSubmitForgotReset}>
              {info && (
                <p className="muted" style={{ marginBottom: 18 }}>
                  {info}
                </p>
              )}
              <div className="form-row">
                <label>One-time code</label>
                <input
                  value={resetCode}
                  onChange={(e) => setResetCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
                  inputMode="numeric"
                  autoComplete="one-time-code"
                  autoFocus
                  required
                />
              </div>
              <div className="form-row">
                <label>New password</label>
                <input
                  value={resetNewPassword}
                  onChange={(e) => setResetNewPassword(e.target.value)}
                  type="password"
                  minLength={8}
                  required
                />
              </div>
              <div className="form-row">
                <label>Confirm password</label>
                <input
                  value={resetConfirmPassword}
                  onChange={(e) => setResetConfirmPassword(e.target.value)}
                  type="password"
                  minLength={8}
                  required
                />
              </div>
              {error && <div className="error-banner">{error}</div>}
              <button
                type="submit"
                disabled={submitting || resetCode.length !== 6}
                style={{ width: '100%' }}
              >
                {submitting ? 'Saving...' : 'Reset password'}
              </button>
              <button type="button" className="link-button" style={{ marginTop: 10 }} onClick={backToSignIn}>
                Back to sign in
              </button>
            </form>
          )}
        </div>

        <PromoVideoPanel className="login-promo" slot="login" />
      </div>
    </div>
  )
}
