// Square photo crop (Backlog 2, 2026-09-26: "Crop on upload: a square
// crop box when a photo is uploaded on Staff Master"). The chosen picture
// shows in a square box; drag it (mouse or finger) to place it and use
// the slider to zoom. "Use this crop" draws the square onto a canvas and
// hands back a 400 x 400 JPEG data URL, small enough for the server's
// photo limit whatever size the original was. No extra library.
import { useEffect, useRef, useState, type PointerEvent } from 'react'

const OUT_SIZE = 400
// The server keeps a photo of up to 400,000 characters (UserController).
const MAX_DATA_URL = 390_000

export default function PhotoCropper({
  src,
  onDone,
  onCancel,
}: {
  src: string
  onDone: (dataUrl: string) => void
  onCancel: () => void
}) {
  const boxRef = useRef<HTMLDivElement>(null)
  const [img, setImg] = useState<HTMLImageElement | null>(null)
  const [box, setBox] = useState(260)
  const [zoom, setZoom] = useState(1)
  // Offset of the picture's centre from the box's centre, in box pixels.
  const [off, setOff] = useState({ x: 0, y: 0 })
  const drag = useRef<{ x: number; y: number; ox: number; oy: number } | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    const i = new Image()
    i.onload = () => setImg(i)
    i.onerror = () => setError('Could not open that picture.')
    i.src = src
  }, [src])

  useEffect(() => {
    const measure = () => setBox(Math.min(260, Math.floor((boxRef.current?.parentElement?.clientWidth ?? 260) - 4)))
    measure()
    window.addEventListener('resize', measure)
    return () => window.removeEventListener('resize', measure)
  }, [])

  // At zoom 1 the picture's short side just fills the box.
  const base = img ? box / Math.min(img.naturalWidth, img.naturalHeight) : 1
  const w = img ? img.naturalWidth * base * zoom : box
  const h = img ? img.naturalHeight * base * zoom : box

  function clamp(o: { x: number; y: number }, width = w, height = h) {
    const mx = (width - box) / 2
    const my = (height - box) / 2
    return { x: Math.max(-mx, Math.min(mx, o.x)), y: Math.max(-my, Math.min(my, o.y)) }
  }

  function onZoom(z: number) {
    setZoom(z)
    if (img) setOff((o) => clamp(o, img.naturalWidth * base * z, img.naturalHeight * base * z))
  }

  function onPointerDown(e: PointerEvent<HTMLDivElement>) {
    e.currentTarget.setPointerCapture(e.pointerId)
    drag.current = { x: e.clientX, y: e.clientY, ox: off.x, oy: off.y }
  }
  function onPointerMove(e: PointerEvent<HTMLDivElement>) {
    const d = drag.current
    if (!d) return
    setOff(clamp({ x: d.ox + e.clientX - d.x, y: d.oy + e.clientY - d.y }))
  }
  function onPointerUp() {
    drag.current = null
  }

  function onUse() {
    if (!img) return
    const scale = base * zoom // box pixels per source pixel
    const side = box / scale
    const sx = img.naturalWidth / 2 - (box / 2 + off.x) / scale
    const sy = img.naturalHeight / 2 - (box / 2 + off.y) / scale
    const canvas = document.createElement('canvas')
    canvas.width = OUT_SIZE
    canvas.height = OUT_SIZE
    const ctx = canvas.getContext('2d')
    if (!ctx) {
      setError('This browser cannot crop pictures.')
      return
    }
    ctx.fillStyle = '#fff'
    ctx.fillRect(0, 0, OUT_SIZE, OUT_SIZE)
    ctx.drawImage(img, sx, sy, side, side, 0, 0, OUT_SIZE, OUT_SIZE)
    for (const q of [0.85, 0.7, 0.55, 0.4]) {
      const url = canvas.toDataURL('image/jpeg', q)
      if (url.length <= MAX_DATA_URL) {
        onDone(url)
        return
      }
    }
    setError('That picture is still too large after cropping -- please try another.')
  }

  return (
    <div className="card" style={{ padding: 12, maxWidth: 300 }} data-testid="photo-cropper">
      <div className="muted" style={{ marginBottom: 6 }}>
        Drag the picture to place it, and zoom to fit.
      </div>
      <div
        ref={boxRef}
        onPointerDown={onPointerDown}
        onPointerMove={onPointerMove}
        onPointerUp={onPointerUp}
        onPointerCancel={onPointerUp}
        style={{
          width: box,
          height: box,
          position: 'relative',
          overflow: 'hidden',
          background: 'var(--bg)',
          border: '1px solid var(--border)',
          touchAction: 'none',
          cursor: 'grab',
          userSelect: 'none',
        }}
      >
        {img && (
          <img
            src={src}
            alt="Photo to crop"
            draggable={false}
            style={{
              position: 'absolute',
              width: w,
              height: h,
              maxWidth: 'none',
              left: box / 2 - w / 2 + off.x,
              top: box / 2 - h / 2 + off.y,
              pointerEvents: 'none',
            }}
          />
        )}
        {/* The round outline shows how the photo appears as an avatar. */}
        <div
          style={{
            position: 'absolute',
            inset: 0,
            borderRadius: '50%',
            boxShadow: '0 0 0 9999px rgba(0,0,0,0.35)',
            pointerEvents: 'none',
          }}
        />
      </div>
      <div className="form-row" style={{ marginTop: 8 }}>
        <label htmlFor="photo-zoom">Zoom</label>
        <input
          id="photo-zoom"
          type="range"
          min={1}
          max={4}
          step={0.05}
          value={zoom}
          onChange={(e) => onZoom(Number(e.target.value))}
        />
      </div>
      {error && <div className="error-banner">{error}</div>}
      <div style={{ display: 'flex', gap: 6 }}>
        <button type="button" onClick={onUse} disabled={!img}>
          Use this crop
        </button>
        <button type="button" className="secondary" onClick={onCancel}>
          Cancel
        </button>
      </div>
    </div>
  )
}
