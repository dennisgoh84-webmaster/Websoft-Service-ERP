/**
 * Reusable drawn-signature panel for any document type.
 *
 * Shows existing signatures and provides a canvas for drawing a new
 * signature (same UX as the mobile app's Service Record sign-off,
 * now available on any document type via eSignature).
 */
import { useCallback, useEffect, useRef, useState } from 'react'
import { api, type DocumentEntityType, type DocumentSignature } from '../lib/api'
import { useAuth } from '../lib/AuthContext'

interface Props {
  entityType: DocumentEntityType
  entityId: string
}

export default function SignaturePanel({ entityType, entityId }: Props) {
  const { user } = useAuth()
  const [signatures, setSignatures] = useState<DocumentSignature[]>([])
  const [showCanvas, setShowCanvas] = useState(false)
  const [signerName, setSignerName] = useState(user?.full_name || '')
  const [roleLabel, setRoleLabel] = useState('')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const canvasRef = useRef<HTMLCanvasElement>(null)
  const drawingRef = useRef(false)
  const lastPosRef = useRef({ x: 0, y: 0 })

  const load = useCallback(() => {
    api.listDocumentSignatures(entityType, entityId).then(setSignatures).catch(() => {})
  }, [entityType, entityId])

  useEffect(load, [load])

  useEffect(() => {
    if (showCanvas && canvasRef.current) {
      const ctx = canvasRef.current.getContext('2d')
      if (ctx) {
        ctx.fillStyle = '#fff'
        ctx.fillRect(0, 0, canvasRef.current.width, canvasRef.current.height)
      }
    }
  }, [showCanvas])

  const getPos = (e: React.MouseEvent | React.TouchEvent) => {
    const rect = canvasRef.current!.getBoundingClientRect()
    const clientX = 'touches' in e ? e.touches[0].clientX : e.clientX
    const clientY = 'touches' in e ? e.touches[0].clientY : e.clientY
    return { x: clientX - rect.left, y: clientY - rect.top }
  }

  const startDraw = (e: React.MouseEvent | React.TouchEvent) => {
    drawingRef.current = true
    lastPosRef.current = getPos(e)
  }

  const draw = (e: React.MouseEvent | React.TouchEvent) => {
    if (!drawingRef.current || !canvasRef.current) return
    e.preventDefault()
    const ctx = canvasRef.current.getContext('2d')!
    const pos = getPos(e)
    ctx.strokeStyle = '#000'
    ctx.lineWidth = 2
    ctx.lineCap = 'round'
    ctx.beginPath()
    ctx.moveTo(lastPosRef.current.x, lastPosRef.current.y)
    ctx.lineTo(pos.x, pos.y)
    ctx.stroke()
    lastPosRef.current = pos
  }

  const endDraw = () => {
    drawingRef.current = false
  }

  const clearCanvas = () => {
    const ctx = canvasRef.current?.getContext('2d')
    if (ctx && canvasRef.current) {
      ctx.fillStyle = '#fff'
      ctx.fillRect(0, 0, canvasRef.current.width, canvasRef.current.height)
    }
  }

  const handleSave = async () => {
    if (!canvasRef.current || !signerName.trim()) return
    setSaving(true)
    setError('')
    try {
      const dataUri = canvasRef.current.toDataURL('image/png')
      await api.addDocumentSignature(entityType, entityId, {
        signer_name: signerName.trim(),
        signature_data_uri: dataUri,
        role_label: roleLabel.trim() || undefined,
      })
      load()
      setShowCanvas(false)
      setRoleLabel('')
    } catch (err: any) {
      setError(err.message || 'Failed to save signature')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div style={{ marginTop: 24 }}>
      <h3>✍️ Signatures</h3>
      {error && <p className="error">{error}</p>}

      {signatures.length > 0 && (
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 16, marginBottom: 16 }}>
          {signatures.map((sig) => (
            <div
              key={sig.id}
              style={{
                border: '1px solid var(--border, #ddd)',
                borderRadius: 8,
                padding: 12,
                textAlign: 'center',
                minWidth: 180,
              }}
            >
              {sig.role_label && (
                <div style={{ fontSize: '0.8em', opacity: 0.7, marginBottom: 4 }}>
                  {sig.role_label}
                </div>
              )}
              <div style={{ fontWeight: 600, marginBottom: 4 }}>{sig.signer_name}</div>
              <div style={{ fontSize: '0.75em', opacity: 0.6 }}>
                {new Date(sig.signed_at).toLocaleString()}
              </div>
            </div>
          ))}
        </div>
      )}

      {!showCanvas ? (
        <button className="primary" onClick={() => setShowCanvas(true)}>
          ✍️ Add Signature
        </button>
      ) : (
        <div
          style={{
            border: '1px solid var(--border, #ddd)',
            borderRadius: 8,
            padding: 16,
            maxWidth: 420,
          }}
        >
          <div style={{ marginBottom: 8 }}>
            <label>
              <strong>Signer Name:</strong>
              <input
                type="text"
                value={signerName}
                onChange={(e) => setSignerName(e.target.value)}
                style={{ marginLeft: 8, width: 200 }}
              />
            </label>
          </div>
          <div style={{ marginBottom: 8 }}>
            <label>
              <strong>Role:</strong>
              <input
                type="text"
                value={roleLabel}
                onChange={(e) => setRoleLabel(e.target.value)}
                placeholder="e.g. Prepared by, Approved by"
                style={{ marginLeft: 8, width: 240 }}
              />
            </label>
          </div>

          <div style={{ marginBottom: 8 }}>
            <strong>Draw your signature:</strong>
          </div>
          <canvas
            ref={canvasRef}
            width={380}
            height={120}
            style={{
              border: '1px solid #999',
              borderRadius: 4,
              cursor: 'crosshair',
              touchAction: 'none',
              background: '#fff',
            }}
            onMouseDown={startDraw}
            onMouseMove={draw}
            onMouseUp={endDraw}
            onMouseLeave={endDraw}
            onTouchStart={startDraw}
            onTouchMove={draw}
            onTouchEnd={endDraw}
          />

          <div style={{ marginTop: 8, display: 'flex', gap: 8 }}>
            <button className="primary" onClick={handleSave} disabled={saving || !signerName.trim()}>
              {saving ? 'Saving…' : 'Save Signature'}
            </button>
            <button className="secondary" onClick={clearCanvas}>
              Clear
            </button>
            <button className="secondary" onClick={() => setShowCanvas(false)}>
              Cancel
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
