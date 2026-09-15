// The AI Assistant's chat panel (docs/planned-work.md #12, slice 2).
// Mounted on the Incidents, Company/Individual, Contract, Job Order
// and Service Records screens. Ask in any language -- English, 中文,
// Bahasa Melayu, தமிழ் -- and the assistant answers in the same one,
// looking records up through read-only tools that run with the
// signed-in user's own permissions. It never changes a record.
//
// The conversation lives in this component (the browser) and is sent
// whole on every turn; the server keeps only the tokens/tools record.
// A 403 on the persona probe means the company is not licensed for
// the AI Assistant module: the panel renders nothing rather than nag.
import { useEffect, useRef, useState, type FormEvent } from 'react'
import { api, type AiChatContextType, type AiChatMessage, type AiPersona } from '../lib/api'

type Props = {
  context?: { type: AiChatContextType; id?: string | null; label?: string } | null
}

// One probe per page load, shared by every panel on the page.
let personaPromise: Promise<AiPersona | null> | null = null
function loadPersona(): Promise<AiPersona | null> {
  personaPromise ??= api.getAiPersona().catch(() => null)
  return personaPromise
}

const DEFAULT_AVATAR =
  'data:image/svg+xml;utf8,' +
  encodeURIComponent(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><circle cx="32" cy="32" r="32" fill="#2f6fed"/><circle cx="32" cy="26" r="11" fill="#fff"/><path d="M12 56c3-12 11-17 20-17s17 5 20 17" fill="#fff"/><circle cx="27" cy="25" r="1.8" fill="#2f6fed"/><circle cx="37" cy="25" r="1.8" fill="#2f6fed"/></svg>',
  )

type Turn = AiChatMessage & { tools?: { name: string; summary: string }[]; refused?: boolean }

export default function AiChatPanel({ context }: Props) {
  const [persona, setPersona] = useState<AiPersona | null | undefined>(undefined)
  const [open, setOpen] = useState(false)
  const [turns, setTurns] = useState<Turn[]>([])
  const [input, setInput] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const bottomRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    loadPersona().then(setPersona)
  }, [])
  useEffect(() => {
    bottomRef.current?.scrollIntoView({ block: 'nearest' })
  }, [turns, busy])

  if (persona === undefined || persona === null) return null

  const name = persona.name
  const avatar = persona.avatar ?? DEFAULT_AVATAR

  async function onSend(e: FormEvent) {
    e.preventDefault()
    const text = input.trim()
    if (!text || busy) return
    setError(null)
    const history: AiChatMessage[] = [...turns.map(({ role, content }) => ({ role, content })), { role: 'user', content: text }]
    setTurns((t) => [...t, { role: 'user', content: text }])
    setInput('')
    setBusy(true)
    try {
      const reply = await api.aiChat(history, context ? { type: context.type, id: context.id ?? null } : null)
      setTurns((t) => [...t, { role: 'assistant', content: reply.answer, tools: reply.tools_used, refused: reply.refused }])
    } catch (err) {
      setError(err instanceof Error ? err.message : 'The assistant could not answer')
      // Keep the question in the box so it can be re-sent.
      setTurns((t) => t.slice(0, -1))
      setInput(text)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="card" style={{ padding: open ? undefined : '8px 14px' }}>
      <div
        style={{ display: 'flex', alignItems: 'center', gap: 10, cursor: 'pointer' }}
        onClick={() => setOpen((o) => !o)}
        role="button"
        aria-expanded={open}
      >
        <img src={avatar} alt="" style={{ width: 36, height: 36, borderRadius: '50%', objectFit: 'cover' }} />
        <div style={{ flex: 1 }}>
          <strong>Ask {name}</strong>
          <div className="muted" style={{ fontSize: 12 }}>
            {context?.label ? `About ${context.label} -- ` : ''}English, 中文, Bahasa Melayu, தமிழ்... Read-only: {name} looks
            things up with your own permissions and never changes a record.
          </div>
        </div>
        <span className="muted">{open ? '▾' : '▸'}</span>
      </div>

      {open && (
        <div style={{ marginTop: 10 }}>
          <div style={{ maxHeight: 360, overflowY: 'auto', display: 'flex', flexDirection: 'column', gap: 8, padding: '4px 0' }}>
            {turns.length === 0 && (
              <p className="muted" style={{ margin: 0, fontSize: 13 }}>
                Try: "How many hours does this customer have left?", "Acme 这个月有哪些 job order?", "Senarai job order yang
                belum selesai untuk saya", "இந்த வாடிக்கையாளர் எவ்வளவு பாக்கி வைத்துள்ளார்?"
              </p>
            )}
            {turns.map((t, i) => (
              <div key={i} style={{ display: 'flex', gap: 8, alignItems: 'flex-start', flexDirection: t.role === 'user' ? 'row-reverse' : 'row' }}>
                {t.role === 'assistant' && <img src={avatar} alt="" style={{ width: 26, height: 26, borderRadius: '50%', objectFit: 'cover' }} />}
                <div
                  style={{
                    maxWidth: '80%',
                    background: t.role === 'user' ? '#e8f0fe' : t.refused ? '#fdecea' : '#f3f4f6',
                    borderRadius: 10,
                    padding: '8px 12px',
                    whiteSpace: 'pre-wrap',
                    fontSize: 14,
                  }}
                >
                  {t.content}
                  {t.tools && t.tools.length > 0 && (
                    <div className="muted" style={{ fontSize: 11, marginTop: 6 }}>
                      Looked up: {t.tools.map((x) => x.summary).join('; ')}
                    </div>
                  )}
                </div>
              </div>
            ))}
            {busy && (
              <div className="muted" style={{ fontSize: 13 }}>
                {name} is looking...
              </div>
            )}
            <div ref={bottomRef} />
          </div>
          {error && <div className="error-banner">{error}</div>}
          <form onSubmit={onSend} style={{ display: 'flex', gap: 8, marginTop: 8 }}>
            <input
              value={input}
              onChange={(e) => setInput(e.target.value)}
              placeholder={`Ask ${name} anything about this screen...`}
              style={{ flex: 1 }}
              disabled={busy}
              maxLength={6000}
            />
            <button type="submit" disabled={busy || !input.trim()}>
              Send
            </button>
            {turns.length > 0 && (
              <button type="button" className="secondary" onClick={() => setTurns([])} disabled={busy}>
                Clear
              </button>
            )}
          </form>
        </div>
      )}
    </div>
  )
}
