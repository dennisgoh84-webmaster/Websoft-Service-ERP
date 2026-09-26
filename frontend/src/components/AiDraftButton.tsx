// "Draft with AI" by a Service Record's work description (Dennis,
// 2026-09-26): the engineer's rough notes, in any language, come back
// as a proper English description in the same box, to read, change and
// save. "Undo" puts the notes back. Shown only when the AI Assistant
// module is on for the company and the person has access to it.
import { useState, type CSSProperties } from 'react'
import { useAuth } from '../lib/AuthContext'
import { api } from '../lib/api'

export default function AiDraftButton({
  notes,
  jobOrderId,
  onDraft,
  style,
}: {
  notes: string
  jobOrderId?: string | null
  onDraft: (text: string) => void
  style?: CSSProperties
}) {
  const { moduleAccess } = useAuth()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [before, setBefore] = useState<string | null>(null)

  if (moduleAccess.ai_assistant !== true) return null

  async function onClick() {
    if (!notes.trim() || busy) return
    setError(null)
    setBusy(true)
    try {
      const r = await api.draftWorkDescription(notes, jobOrderId ?? null)
      if (r.refused || !r.description) {
        setError(r.refusal_reason ?? 'The assistant could not draft this -- please write it yourself.')
        return
      }
      setBefore(notes)
      onDraft(r.description)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'The assistant could not draft this')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap', marginTop: 6, ...style }}>
      <button
        type="button"
        className="secondary"
        onClick={onClick}
        disabled={busy || !notes.trim()}
        title="Turns your notes, in any language, into a proper English description for you to check"
      >
        {busy ? 'Drafting...' : '✨ Draft with AI'}
      </button>
      {before !== null && !busy && (
        <button
          type="button"
          className="secondary"
          onClick={() => {
            onDraft(before)
            setBefore(null)
          }}
        >
          Undo
        </button>
      )}
      {before !== null && !busy && <span className="muted" style={{ fontSize: 12 }}>Check the draft before saving.</span>}
      {error && (
        <span className="error-text" style={{ color: 'var(--danger, #b42318)', fontSize: 13 }}>
          {error}
        </span>
      )}
    </div>
  )
}
