// Maintenance -> AI Assistant (docs/planned-work.md #12, slice 1 built
// 2026-09-15). Install-level settings for the model provider -- the
// API key (write-only), the model, and whether personal data is
// masked before any text leaves the system (decision 12.1) -- plus the
// usage record every call writes (decision 12.3), which is the
// evidence for the cost question (12.2). The feature itself is a paid
// add-on: the `ai_assistant` module key under Module Control (12.4).
import { useEffect, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { api, type AiSettings, type AiUsage } from '../lib/api'
import { formatDateTime } from '../lib/format'

export default function AiAssistantPage() {
  const [settings, setSettings] = useState<AiSettings | null>(null)
  const [usage, setUsage] = useState<AiUsage | null>(null)
  const [apiKey, setApiKey] = useState('')
  const [model, setModel] = useState('claude-opus-5')
  const [fallbackModel, setFallbackModel] = useState('')
  const [redact, setRedact] = useState(true)
  const [assistantName, setAssistantName] = useState('Websoft AI')
  const [avatar, setAvatar] = useState<string | null>(null)
  const [avatarChanged, setAvatarChanged] = useState(false)
  const [tokenCap, setTokenCap] = useState('')
  const [saving, setSaving] = useState(false)
  const [testing, setTesting] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [message, setMessage] = useState<string | null>(null)

  function refresh() {
    api
      .getAiSettings()
      .then((s) => {
        setSettings(s)
        setModel(s.model)
        setFallbackModel(s.fallback_model ?? '')
        setRedact(s.redact_personal_data)
        setAssistantName(s.assistant_name)
        setAvatar(s.assistant_avatar)
        setAvatarChanged(false)
        setTokenCap(s.monthly_token_cap ? String(s.monthly_token_cap) : '')
      })
      .catch((e) => setError(e.message))
    api.getAiUsage().then(setUsage).catch(() => setUsage(null))
  }
  useEffect(refresh, [])

  async function onSave(e: FormEvent) {
    e.preventDefault()
    setError(null)
    setMessage(null)
    setSaving(true)
    const trimmedCap = tokenCap.trim()
    if (trimmedCap && (!/^\d+$/.test(trimmedCap) || Number(trimmedCap) < 1)) {
      setError('The monthly token cap must be a whole number of 1 or more, or left blank for unlimited.')
      setSaving(false)
      return
    }
    try {
      await api.updateAiSettings({
        ...(apiKey ? { api_key: apiKey } : {}),
        model,
        fallback_model: fallbackModel.trim() || null,
        redact_personal_data: redact,
        assistant_name: assistantName.trim() || 'Websoft AI',
        ...(avatarChanged ? { assistant_avatar: avatar } : {}),
        monthly_token_cap: trimmedCap ? Number(trimmedCap) : null,
      })
      setApiKey('')
      setMessage('Settings saved.')
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to save')
    } finally {
      setSaving(false)
    }
  }

  async function onClearKey() {
    if (!confirm('Remove the stored API key? The .env value, if any, will be used instead.')) return
    setError(null)
    try {
      await api.updateAiSettings({ api_key: null })
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to clear the key')
    }
  }

  async function onTest() {
    setError(null)
    setMessage(null)
    setTesting(true)
    try {
      const r = await api.testAiConnection()
      setMessage(
        r.ok
          ? `Connected to ${r.model}: "${r.greeting ?? ''}" (${r.input_tokens} in / ${r.output_tokens} out tokens).`
          : `The model declined the test request (${r.model}).`,
      )
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Connection test failed')
    } finally {
      setTesting(false)
    }
  }

  function onAvatarFile(file: File | null) {
    if (!file) return
    if (file.size > 300 * 1024) {
      setError('The avatar image must be 300 KB or smaller -- resize it first.')
      return
    }
    const reader = new FileReader()
    reader.onload = () => {
      setAvatar(String(reader.result))
      setAvatarChanged(true)
    }
    reader.readAsDataURL(file)
  }

  const IMAGINE_PROMPT =
    'friendly professional Singaporean female IT support assistant avatar for a business software product, ' +
    'mid-20s, warm confident smile, neat shoulder-length dark hair, smart navy blouse, small headset, ' +
    'clean flat vector illustration style, soft blue gradient background, head-and-shoulders portrait, ' +
    'centered, looking at the viewer, high detail, no text, no logo --ar 1:1 --style raw'

  const bucket = (b: AiUsage['this_month']) => (
    <>
      <td>{b.calls}</td>
      <td>{b.ok}</td>
      <td>{b.refused}</td>
      <td>{b.errors}</td>
      <td>{b.input_tokens.toLocaleString()}</td>
      <td>{b.output_tokens.toLocaleString()}</td>
    </>
  )

  return (
    <div>
      <h1>AI Assistant</h1>
      <p className="muted">
        The assistant reads the system's own records and proposes -- it never changes a record itself, and never
        works out hours or money (those come from the same services every screen uses). Two features: incident
        triage on the Incidents page (customer, contract, priority and route, similar past incidents and what
        fixed them, a draft reply), and a chat panel on the Incidents, Company / Individual, Contract, Job Order
        and Service Records screens that answers questions in any language -- English, 中文, Bahasa Melayu,
        தமிழ் -- looking records up with the asking person's own permissions. Each company must have the <strong>AI Assistant</strong>{' '}
        module enabled under <Link to="/modules">Module Control</Link> -- it is a paid add-on and the module key is
        the licence.
      </p>
      {error && <div className="error-banner">{error}</div>}
      {message && (
        <p className="muted" style={{ marginBottom: 12 }}>
          {message}
        </p>
      )}

      <div className="card">
        <h2>Model provider</h2>
        <form onSubmit={onSave}>
          <div className="form-row">
            <label>API key</label>
            <input
              type="password"
              value={apiKey}
              onChange={(e) => setApiKey(e.target.value)}
              placeholder={
                settings?.api_key_set
                  ? settings.api_key_from_env
                    ? 'Using ANTHROPIC_API_KEY from .env -- enter one here to override'
                    : 'Set (hidden) -- enter a new one to replace'
                  : 'Not set'
              }
              autoComplete="off"
            />
            <span className="muted">
              An Anthropic API key. Stored encrypted and never shown again. {settings?.api_key_set && !settings.api_key_from_env && (
                <button type="button" className="secondary" onClick={onClearKey} style={{ marginLeft: 6 }}>
                  Remove stored key
                </button>
              )}
            </span>
          </div>
          <div className="form-row">
            <label>Model</label>
            <input value={model} onChange={(e) => setModel(e.target.value)} required />
            <span className="muted">Default claude-opus-5. Change only if Anthropic retires it or you want a cheaper model.</span>
          </div>
          <div className="form-row">
            <label htmlFor="ai-fallback-model">Fallback model</label>
            <input
              id="ai-fallback-model"
              value={fallbackModel}
              onChange={(e) => setFallbackModel(e.target.value)}
              placeholder="None"
            />
            <span className="muted">
              Tried once when the model above fails (busy, retired or unreachable) or declines to answer. The Usage list
              shows which model answered each call. Leave blank for none.
            </span>
          </div>
          <div className="form-row">
            <label>
              <input type="checkbox" checked={redact} onChange={(e) => setRedact(e.target.checked)} /> Mask personal data before
              sending
            </label>
            <span className="muted">
              With this on, email addresses, telephone numbers and people's names are replaced by [email] / [phone] /
              [name] in everything sent to the model provider; company names and email domains are kept so the
              incident can still be matched to a customer. Turn it off only if you have decided customer personal
              data may leave the system (PDPA -- see open decision 12.1).
            </span>
          </div>
          <div className="form-row">
            <label>Monthly token cap</label>
            <input
              type="text"
              inputMode="numeric"
              value={tokenCap}
              onChange={(e) => setTokenCap(e.target.value)}
              placeholder="Unlimited"
              style={{ maxWidth: 160 }}
            />
            <span className="muted">
              Once this many tokens (input + output combined) have been used <strong>by this company</strong> in a
              calendar month, every AI Assistant feature refuses this company's further calls until the next month
              starts, or the cap is raised here. Each company has its own cap -- switch company to set another's. Measured in tokens, not SGD, because Anthropic's per-token price differs by model and
              can change -- see open decision #44. Leave blank for unlimited (the default).
            </span>
            {settings && (
              <span className="muted">
                This company: {settings.monthly_tokens_used.toLocaleString()} tokens used so far this month
                {settings.monthly_token_cap ? ` of ${settings.monthly_token_cap.toLocaleString()} cap` : ' (no cap set)'}. Whole
                installation: {settings.install_monthly_tokens_used.toLocaleString()} tokens.
                {settings.monthly_token_cap !== null && settings.monthly_tokens_used >= settings.monthly_token_cap && (
                  <span className="badge exceeded" style={{ marginLeft: 6 }}>
                    Cap reached -- calls are being refused
                  </span>
                )}
              </span>
            )}
          </div>
          <h3 style={{ marginTop: 18 }}>Name and face</h3>
          <div className="form-row">
            <label>Assistant's name</label>
            <input value={assistantName} onChange={(e) => setAssistantName(e.target.value)} maxLength={40} required />
            <span className="muted">Shown on every chat panel ("Ask Sofia") and used when she introduces herself.</span>
          </div>
          <div className="form-row">
            <label>Avatar</label>
            <div style={{ display: 'flex', gap: 12, alignItems: 'center', flexWrap: 'wrap' }}>
              {avatar ? (
                <img src={avatar} alt="Assistant avatar" style={{ width: 72, height: 72, borderRadius: '50%', objectFit: 'cover' }} />
              ) : (
                <span className="muted">No image yet -- a plain placeholder is shown.</span>
              )}
              <input type="file" accept="image/png,image/jpeg,image/webp,image/gif" onChange={(e) => onAvatarFile(e.target.files?.[0] ?? null)} />
              {avatar && (
                <button
                  type="button"
                  className="secondary"
                  onClick={() => {
                    setAvatar(null)
                    setAvatarChanged(true)
                  }}
                >
                  Remove
                </button>
              )}
            </div>
            <span className="muted">
              PNG / JPEG / WebP / GIF, square, up to 300 KB. Generate one with an image tool and upload it here -- a
              ready-made prompt for Midjourney's /imagine (or similar):
            </span>
            <textarea readOnly value={IMAGINE_PROMPT} rows={3} style={{ width: '100%', fontSize: 12 }} />
            <span className="muted">
              She is an assistant, not a colleague: keep her labelled as the AI assistant wherever she appears, and never
              present her to customers as a staff member.
            </span>
          </div>
          <div style={{ display: 'flex', gap: 8 }}>
            <button type="submit" disabled={saving}>
              {saving ? 'Saving...' : 'Save settings'}
            </button>
            <button type="button" className="secondary" onClick={onTest} disabled={testing || !settings?.api_key_set}>
              {testing ? 'Testing...' : 'Test connection'}
            </button>
          </div>
        </form>
      </div>

      <div className="card">
        <h2>Usage (this company)</h2>
        <p className="muted">
          Every call is recorded here and in Event Logs: who asked, about which record, the model, and the tokens
          it cost. This is the figure to judge the running cost against.
        </p>
        {usage ? (
          <>
            <table>
              <thead>
                <tr>
                  <th></th>
                  <th>Calls</th>
                  <th>OK</th>
                  <th>Declined</th>
                  <th>Errors</th>
                  <th>Input tokens</th>
                  <th>Output tokens</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td>This month</td>
                  {bucket(usage.this_month)}
                </tr>
                <tr>
                  <td>All time</td>
                  {bucket(usage.all_time)}
                </tr>
              </tbody>
            </table>
            <h3 style={{ marginTop: 16 }}>Recent calls</h3>
            <table>
              <thead>
                <tr>
                  <th>When</th>
                  <th>Who</th>
                  <th>Feature</th>
                  <th>Record</th>
                  <th>Model</th>
                  <th>Status</th>
                  <th>Tokens</th>
                </tr>
              </thead>
              <tbody>
                {usage.recent.map((r) => (
                  <tr key={r.id}>
                    <td>{formatDateTime(r.created_at)}</td>
                    <td>{r.user_name}</td>
                    <td>{r.feature.replace('_', ' ')}</td>
                    <td>
                      {r.entity_type === 'incident' && r.entity_id ? <Link to="/incidents">incident</Link> : r.entity_type ?? '-'}
                    </td>
                    <td>{r.model}</td>
                    <td>
                      <span className={`badge ${r.status === 'ok' ? 'active' : 'exceeded'}`}>{r.status}</span>
                      {r.error && <span className="muted" style={{ marginLeft: 6 }}>{r.error}</span>}
                    </td>
                    <td>
                      {r.input_tokens} / {r.output_tokens}
                    </td>
                  </tr>
                ))}
                {usage.recent.length === 0 && (
                  <tr>
                    <td colSpan={7} className="muted">
                      No calls yet.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </>
        ) : (
          <p className="muted">Usage unavailable.</p>
        )}
      </div>
    </div>
  )
}
