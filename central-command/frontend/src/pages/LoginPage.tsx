import { useState, type FormEvent } from 'react'
import { useAuth } from '../lib/AuthContext'
import { api } from '../lib/api'

type View = 'login' | 'otp' | 'forgot_password' | 'reset_password' | 'forgot_username'

export default function LoginPage() {
  const { login, verifyOtp } = useAuth()
  const [view, setView] = useState<View>('login')
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')

  // Login state
  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')

  // OTP state
  const [otpSession, setOtpSession] = useState('')
  const [otpCode, setOtpCode] = useState('')
  const [emailHint, setEmailHint] = useState<string | null>(null)
  const [devOtp, setDevOtp] = useState<string | null>(null)

  // Forgot password state
  const [fpUsername, setFpUsername] = useState('')
  const [fpOtp, setFpOtp] = useState('')
  const [fpNewPassword, setFpNewPassword] = useState('')
  const [fpConfirmPassword, setFpConfirmPassword] = useState('')
  const [fpDevOtp, setFpDevOtp] = useState<string | null>(null)
  const [fpEmailHint, setFpEmailHint] = useState<string | null>(null)

  // Forgot username state
  const [fuEmail, setFuEmail] = useState('')
  const [fuDevUsername, setFuDevUsername] = useState<string | null>(null)

  const inputStyle = {
    width: '100%',
    padding: '8px 10px',
    border: '1px solid #ccc',
    borderRadius: 6,
    fontSize: 14,
    boxSizing: 'border-box' as const,
  }
  const btnStyle = {
    width: '100%',
    padding: '10px 0',
    background: '#800020',
    color: '#fff',
    border: 'none',
    borderRadius: 6,
    fontSize: 14,
    fontWeight: 600,
    cursor: 'pointer',
  }
  const linkStyle = {
    background: 'none',
    border: 'none',
    color: '#800020',
    cursor: 'pointer',
    fontSize: 12,
    padding: 0,
    textDecoration: 'underline',
  }

  // ── Login submit ──────────────────────────────────────────────────
  async function onLoginSubmit(e: FormEvent) {
    e.preventDefault()
    setError('')
    try {
      const res = await login(username, password)
      if (res.status === 'otp_required') {
        setOtpSession(res.otp_session || '')
        setEmailHint(res.email_hint || null)
        setDevOtp(res._dev_otp || null)
        setOtpCode('')
        setView('otp')
      }
      // if status is 'ok', AuthContext already set the user
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Login failed')
    }
  }

  // ── OTP verify ────────────────────────────────────────────────────
  async function onOtpSubmit(e: FormEvent) {
    e.preventDefault()
    setError('')
    try {
      await verifyOtp(otpSession, otpCode)
      // AuthContext sets user, page will redirect
    } catch (err) {
      setError(err instanceof Error ? err.message : 'OTP verification failed')
    }
  }

  // ── Forgot password: request OTP ──────────────────────────────────
  async function onForgotPasswordSubmit(e: FormEvent) {
    e.preventDefault()
    setError('')
    setSuccess('')
    try {
      const res = await api.forgotPassword(fpUsername)
      setFpDevOtp(res._dev_otp || null)
      setFpEmailHint(res.email_hint || null)
      setSuccess(res.message)
      setView('reset_password')
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Request failed')
    }
  }

  // ── Reset password ────────────────────────────────────────────────
  async function onResetPasswordSubmit(e: FormEvent) {
    e.preventDefault()
    setError('')
    setSuccess('')
    if (fpNewPassword !== fpConfirmPassword) {
      setError('Passwords do not match')
      return
    }
    try {
      const res = await api.resetPassword(fpUsername, fpOtp, fpNewPassword)
      setSuccess(res.message)
      // Go back to login after a moment
      setTimeout(() => {
        resetAll()
        setView('login')
      }, 2000)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Reset failed')
    }
  }

  // ── Forgot username ──────────────────────────────────────────────
  async function onForgotUsernameSubmit(e: FormEvent) {
    e.preventDefault()
    setError('')
    setSuccess('')
    try {
      const res = await api.forgotUsername(fuEmail)
      setFuDevUsername(res._dev_username || null)
      setSuccess(res.message)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Request failed')
    }
  }

  function resetAll() {
    setError('')
    setSuccess('')
    setDevOtp(null)
    setFpDevOtp(null)
    setFuDevUsername(null)
    setOtpCode('')
    setFpOtp('')
    setFpNewPassword('')
    setFpConfirmPassword('')
  }

  function goBack() {
    resetAll()
    setView('login')
  }

  // ── Render ────────────────────────────────────────────────────────
  return (
    <div
      style={{
        minHeight: '100vh',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        background: '#1a1a2e',
      }}
    >
      <div
        style={{
          background: '#fff',
          padding: 40,
          borderRadius: 12,
          width: 380,
          boxShadow: '0 4px 24px rgba(0,0,0,0.3)',
        }}
      >
        <h1 style={{ fontSize: 20, margin: '0 0 4px', color: '#800020' }}>
          🖥️ Central Command
        </h1>
        <p style={{ color: '#666', fontSize: 13, margin: '0 0 24px' }}>
          Web Master Consultancy — Admin Portal
        </p>

        {error && (
          <div style={{ background: '#fdecea', color: '#c0392b', padding: '8px 12px', borderRadius: 6, fontSize: 13, marginBottom: 16 }}>
            {error}
          </div>
        )}
        {success && (
          <div style={{ background: '#eafaf1', color: '#27ae60', padding: '8px 12px', borderRadius: 6, fontSize: 13, marginBottom: 16 }}>
            {success}
          </div>
        )}

        {/* ── Login Form ───────────────────────────────────────────── */}
        {view === 'login' && (
          <form onSubmit={onLoginSubmit}>
            <div style={{ marginBottom: 14 }}>
              <label style={{ display: 'block', fontSize: 13, fontWeight: 500, marginBottom: 4 }}>Username</label>
              <input value={username} onChange={(e) => setUsername(e.target.value)} required style={inputStyle} />
            </div>
            <div style={{ marginBottom: 20 }}>
              <label style={{ display: 'block', fontSize: 13, fontWeight: 500, marginBottom: 4 }}>Password</label>
              <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} required style={inputStyle} />
            </div>
            <button type="submit" style={btnStyle}>Sign In</button>
            <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: 14 }}>
              <button type="button" onClick={() => { resetAll(); setView('forgot_password') }} style={linkStyle}>
                Forgot Password?
              </button>
              <button type="button" onClick={() => { resetAll(); setView('forgot_username') }} style={linkStyle}>
                Forgot Username?
              </button>
            </div>
          </form>
        )}

        {/* ── OTP Verification ─────────────────────────────────────── */}
        {view === 'otp' && (
          <form onSubmit={onOtpSubmit}>
            <div style={{ background: '#f0f4ff', padding: '10px 12px', borderRadius: 6, marginBottom: 16, fontSize: 12, color: '#444' }}>
              📧 A 6-digit OTP has been sent to <strong>{emailHint || 'your registered email'}</strong>.
              <br />Enter it below to complete login.
            </div>
            {devOtp && (
              <div style={{ background: '#fff3cd', padding: '8px 12px', borderRadius: 6, marginBottom: 12, fontSize: 12, color: '#856404' }}>
                🔧 <strong>Dev mode:</strong> OTP is <code style={{ fontSize: 14, fontWeight: 700, letterSpacing: 2 }}>{devOtp}</code>
              </div>
            )}
            <div style={{ marginBottom: 20 }}>
              <label style={{ display: 'block', fontSize: 13, fontWeight: 500, marginBottom: 4 }}>Enter OTP Code</label>
              <input
                value={otpCode}
                onChange={(e) => setOtpCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
                required
                maxLength={6}
                placeholder="000000"
                style={{ ...inputStyle, textAlign: 'center', fontSize: 20, letterSpacing: 8, fontWeight: 600 }}
              />
            </div>
            <button type="submit" style={btnStyle}>Verify OTP</button>
            <div style={{ marginTop: 12, textAlign: 'center' }}>
              <button type="button" onClick={goBack} style={linkStyle}>← Back to Login</button>
            </div>
          </form>
        )}

        {/* ── Forgot Password ──────────────────────────────────────── */}
        {view === 'forgot_password' && (
          <form onSubmit={onForgotPasswordSubmit}>
            <p style={{ fontSize: 13, color: '#555', margin: '0 0 16px' }}>
              Enter your username. If an email is registered, we'll send a reset OTP.
            </p>
            <div style={{ marginBottom: 20 }}>
              <label style={{ display: 'block', fontSize: 13, fontWeight: 500, marginBottom: 4 }}>Username</label>
              <input value={fpUsername} onChange={(e) => setFpUsername(e.target.value)} required style={inputStyle} />
            </div>
            <button type="submit" style={btnStyle}>Send Reset OTP</button>
            <div style={{ marginTop: 12, textAlign: 'center' }}>
              <button type="button" onClick={goBack} style={linkStyle}>← Back to Login</button>
            </div>
          </form>
        )}

        {/* ── Reset Password ───────────────────────────────────────── */}
        {view === 'reset_password' && (
          <form onSubmit={onResetPasswordSubmit}>
            <div style={{ background: '#f0f4ff', padding: '10px 12px', borderRadius: 6, marginBottom: 16, fontSize: 12, color: '#444' }}>
              📧 A reset OTP has been sent to <strong>{fpEmailHint || 'your registered email'}</strong>.
            </div>
            {fpDevOtp && (
              <div style={{ background: '#fff3cd', padding: '8px 12px', borderRadius: 6, marginBottom: 12, fontSize: 12, color: '#856404' }}>
                🔧 <strong>Dev mode:</strong> Reset OTP is <code style={{ fontSize: 14, fontWeight: 700, letterSpacing: 2 }}>{fpDevOtp}</code>
              </div>
            )}
            <div style={{ marginBottom: 14 }}>
              <label style={{ display: 'block', fontSize: 13, fontWeight: 500, marginBottom: 4 }}>Reset OTP Code</label>
              <input
                value={fpOtp}
                onChange={(e) => setFpOtp(e.target.value.replace(/\D/g, '').slice(0, 6))}
                required
                maxLength={6}
                placeholder="000000"
                style={{ ...inputStyle, textAlign: 'center', fontSize: 20, letterSpacing: 8, fontWeight: 600 }}
              />
            </div>
            <div style={{ marginBottom: 14 }}>
              <label style={{ display: 'block', fontSize: 13, fontWeight: 500, marginBottom: 4 }}>New Password</label>
              <input type="password" value={fpNewPassword} onChange={(e) => setFpNewPassword(e.target.value)} required minLength={8} style={inputStyle} />
              <p style={{ margin: '4px 0 0', fontSize: 11, color: '#888' }}>Min 8 characters, must contain letters and numbers</p>
            </div>
            <div style={{ marginBottom: 20 }}>
              <label style={{ display: 'block', fontSize: 13, fontWeight: 500, marginBottom: 4 }}>Confirm Password</label>
              <input type="password" value={fpConfirmPassword} onChange={(e) => setFpConfirmPassword(e.target.value)} required minLength={8} style={inputStyle} />
            </div>
            <button type="submit" style={btnStyle}>Reset Password</button>
            <div style={{ marginTop: 12, textAlign: 'center' }}>
              <button type="button" onClick={goBack} style={linkStyle}>← Back to Login</button>
            </div>
          </form>
        )}

        {/* ── Forgot Username ──────────────────────────────────────── */}
        {view === 'forgot_username' && (
          <form onSubmit={onForgotUsernameSubmit}>
            <p style={{ fontSize: 13, color: '#555', margin: '0 0 16px' }}>
              Enter your registered email address. If found, your username will be sent to it.
            </p>
            <div style={{ marginBottom: 20 }}>
              <label style={{ display: 'block', fontSize: 13, fontWeight: 500, marginBottom: 4 }}>Email Address</label>
              <input type="email" value={fuEmail} onChange={(e) => setFuEmail(e.target.value)} required style={inputStyle} />
            </div>
            {fuDevUsername && (
              <div style={{ background: '#fff3cd', padding: '8px 12px', borderRadius: 6, marginBottom: 12, fontSize: 12, color: '#856404' }}>
                🔧 <strong>Dev mode:</strong> Your username is <code style={{ fontSize: 14, fontWeight: 700 }}>{fuDevUsername}</code>
              </div>
            )}
            <button type="submit" style={btnStyle}>Recover Username</button>
            <div style={{ marginTop: 12, textAlign: 'center' }}>
              <button type="button" onClick={goBack} style={linkStyle}>← Back to Login</button>
            </div>
          </form>
        )}
      </div>
    </div>
  )
}
