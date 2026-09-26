// The AI Assistant's chat widget on the Customer Helpdesk Portal
// (docs/planned-work.md #12 Tier 2 item 6, built 2026-09-15 -- "Yes on
// helpdesk portal is good"). A floating bubble, visible on every tab,
// so a customer can ask about their own contracts, hours, job orders,
// service records, invoices, payments and incidents in any language.
// Deliberately its own component, its own API surface
// (lib/portalApi.ts's /portal/ai/* calls, the portal's own token) --
// never the staff AiChatPanel with a portal flag bolted on.
import { useEffect, useRef, useState, type FormEvent } from 'react'
import { portalApi, type PortalAiChatMessage, type PortalAiContextType, type PortalAiPersona } from '../lib/portalApi'

const MAROON = '#7a1f2e'
const WHITE = '#ffffff'
const LIGHT_BG = '#f8f6f5'
const INK = '#2a2226'
const MUTED = '#847478'
const BORDER = '#e6dcdd'
const DANGER_BG = '#fdecea'

const DEFAULT_AVATAR =
  'data:image/svg+xml;utf8,' +
  encodeURIComponent(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><circle cx="32" cy="32" r="32" fill="#7a1f2e"/><circle cx="32" cy="26" r="11" fill="#fff"/><path d="M12 56c3-12 11-17 20-17s17 5 20 17" fill="#fff"/><circle cx="27" cy="25" r="1.8" fill="#7a1f2e"/><circle cx="37" cy="25" r="1.8" fill="#7a1f2e"/></svg>',
  )

type Turn = PortalAiChatMessage & { tools?: { name: string; summary: string }[]; refused?: boolean }

export type PortalAiContext = { type: PortalAiContextType; id?: string | null; label?: string } | null

let personaPromise: Promise<PortalAiPersona | null> | null = null
function loadPersona(): Promise<PortalAiPersona | null> {
  personaPromise ??= portalApi.aiPersona().catch(() => null)
  return personaPromise
}

export default function PortalAiChatWidget({ context }: { context: PortalAiContext }) {
  const [persona, setPersona] = useState<PortalAiPersona | null | undefined>(undefined)
  const [open, setOpen] = useState(false)
  const [turns, setTurns] = useState<Turn[]>([])
  const [input, setInput] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const bottomRef = useRef<HTMLDivElement>(null)
  // The one-time AI declaration (2026-09-26): ticked once, before the
  // first chat, and recorded against this login on the server.
  const [consented, setConsented] = useState(false)
  const [ticked, setTicked] = useState(false)

  useEffect(() => {
    loadPersona().then((p) => {
      setPersona(p)
      setConsented(p?.consent_given === true)
    })
  }, [])
  useEffect(() => {
    bottomRef.current?.scrollIntoView({ block: 'nearest' })
  }, [turns, busy, open])

  if (persona === undefined || persona === null) return null
  const name = persona.name
  const avatar = persona.avatar ?? DEFAULT_AVATAR

  async function onAccept() {
    if (!ticked || busy) return
    setError(null)
    setBusy(true)
    try {
      await portalApi.aiConsent()
      setConsented(true)
      if (persona) persona.consent_given = true
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not record your agreement')
    } finally {
      setBusy(false)
    }
  }

  async function onSend(e: FormEvent) {
    e.preventDefault()
    const text = input.trim()
    if (!text || busy) return
    setError(null)
    const history: PortalAiChatMessage[] = [...turns.map(({ role, content }) => ({ role, content })), { role: 'user', content: text }]
    setTurns((t) => [...t, { role: 'user', content: text }])
    setInput('')
    setBusy(true)
    try {
      const reply = await portalApi.aiChat(history, context ? { type: context.type, id: context.id ?? null } : null)
      setTurns((t) => [...t, { role: 'assistant', content: reply.answer, tools: reply.tools_used, refused: reply.refused }])
    } catch (err) {
      setError(err instanceof Error ? err.message : 'The assistant could not answer')
      setTurns((t) => t.slice(0, -1))
      setInput(text)
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      {!open && (
        <button
          onClick={() => setOpen(true)}
          aria-label={`Ask ${name}`}
          style={{
            position: 'fixed',
            right: 18,
            bottom: 84,
            zIndex: 60,
            width: 56,
            height: 56,
            borderRadius: '50%',
            border: `2px solid ${WHITE}`,
            padding: 0,
            overflow: 'hidden',
            boxShadow: '0 4px 14px rgba(0,0,0,0.25)',
            cursor: 'pointer',
          }}
        >
          <img src={avatar} alt="" style={{ width: '100%', height: '100%', objectFit: 'cover' }} />
        </button>
      )}

      {open && (
        <div
          style={{
            position: 'fixed',
            right: 12,
            bottom: 12,
            left: 12,
            maxWidth: 380,
            marginLeft: 'auto',
            zIndex: 60,
            background: WHITE,
            border: `1px solid ${BORDER}`,
            borderRadius: 14,
            boxShadow: '0 10px 30px rgba(0,0,0,0.25)',
            display: 'flex',
            flexDirection: 'column',
            maxHeight: '72vh',
          }}
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '12px 14px', borderBottom: `1px solid ${BORDER}`, background: LIGHT_BG, borderRadius: '14px 14px 0 0' }}>
            <img src={avatar} alt="" style={{ width: 32, height: 32, borderRadius: '50%', objectFit: 'cover' }} />
            <div style={{ flex: 1 }}>
              <strong style={{ color: INK }}>{name}</strong>
              <div style={{ fontSize: 11, color: MUTED }}>Your AI assistant -- looks up your own account only</div>
            </div>
            <button onClick={() => setOpen(false)} aria-label="Close" style={{ background: 'none', border: 'none', fontSize: 18, color: MUTED, cursor: 'pointer', padding: 4 }}>
              ✕
            </button>
          </div>

          {!consented ? (
            <div style={{ padding: '12px 14px', overflowY: 'auto', fontSize: 13, color: INK }} data-testid="portal-ai-declaration">
              <strong>Before you start</strong>
              <p style={{ margin: '6px 0' }}>
                {name} answers by sending your question, and the details from your own account it needs, to Anthropic, an AI
                provider hosted in the United States.
              </p>
              <ul style={{ margin: '6px 0', paddingLeft: 18 }}>
                <li>Personal details such as email addresses and phone numbers are masked before anything is sent.</li>
                <li>It only looks up your own company's records, and never changes anything.</li>
                <li>Your agreement is recorded once, with the date and time.</li>
              </ul>
              <label style={{ display: 'flex', gap: 8, alignItems: 'flex-start', margin: '10px 0', cursor: 'pointer' }}>
                <input type="checkbox" checked={ticked} onChange={(e) => setTicked(e.target.checked)} style={{ marginTop: 2 }} />
                <span>I understand and agree that my questions and the account details needed to answer them may be sent to a US-hosted AI provider.</span>
              </label>
              {error && <div style={{ color: '#c0362c', fontSize: 12, marginBottom: 8 }}>{error}</div>}
              <button
                type="button"
                onClick={onAccept}
                disabled={!ticked || busy}
                style={{ background: MAROON, color: WHITE, border: 'none', borderRadius: 8, padding: '8px 14px', fontSize: 13.5, cursor: 'pointer', opacity: !ticked || busy ? 0.6 : 1 }}
              >
                {busy ? 'Saving...' : 'Agree and start'}
              </button>
            </div>
          ) : (
          <>
          <div style={{ flex: 1, overflowY: 'auto', padding: '10px 14px', display: 'flex', flexDirection: 'column', gap: 8 }}>
            {turns.length === 0 && (
              <p style={{ margin: 0, fontSize: 13, color: MUTED }}>
                Ask about your contracts and hours, job orders, invoices or incidents -- in English, 中文, Bahasa Melayu,
                தமிழ், or any language. e.g. "How many hours do I have left?", "我最近的账单是什么状态？"
              </p>
            )}
            {turns.map((t, i) => (
              <div key={i} style={{ display: 'flex', gap: 8, alignItems: 'flex-start', flexDirection: t.role === 'user' ? 'row-reverse' : 'row' }}>
                {t.role === 'assistant' && <img src={avatar} alt="" style={{ width: 24, height: 24, borderRadius: '50%', objectFit: 'cover' }} />}
                <div
                  style={{
                    maxWidth: '82%',
                    background: t.role === 'user' ? MAROON : t.refused ? DANGER_BG : LIGHT_BG,
                    color: t.role === 'user' ? WHITE : INK,
                    borderRadius: 10,
                    padding: '8px 12px',
                    whiteSpace: 'pre-wrap',
                    fontSize: 13.5,
                  }}
                >
                  {t.content}
                  {t.tools && t.tools.length > 0 && (
                    <div style={{ fontSize: 10.5, marginTop: 6, color: t.role === 'user' ? '#f0dede' : MUTED }}>
                      Looked up: {t.tools.map((x) => x.summary).join('; ')}
                    </div>
                  )}
                </div>
              </div>
            ))}
            {busy && <div style={{ fontSize: 13, color: MUTED }}>{name} is looking...</div>}
            <div ref={bottomRef} />
          </div>

          {error && <div style={{ margin: '0 14px 8px', color: '#c0362c', fontSize: 12 }}>{error}</div>}
          <form onSubmit={onSend} style={{ display: 'flex', gap: 8, padding: '10px 14px', borderTop: `1px solid ${BORDER}` }}>
            <input
              value={input}
              onChange={(e) => setInput(e.target.value)}
              placeholder={`Ask ${name}...`}
              disabled={busy}
              maxLength={6000}
              style={{ flex: 1, border: `1px solid ${BORDER}`, borderRadius: 8, padding: '8px 10px', fontSize: 13.5 }}
            />
            <button
              type="submit"
              disabled={busy || !input.trim()}
              style={{ background: MAROON, color: WHITE, border: 'none', borderRadius: 8, padding: '8px 14px', fontSize: 13.5, cursor: 'pointer' }}
            >
              Send
            </button>
          </form>
          </>
          )}
        </div>
      )}
    </>
  )
}
