// Announcements / Ad Banner -- admin screen for the promo video and
// "What's New" items shown on the Login page and, smaller, on every
// page after signing in (see components/PromoVideoPanel.tsx).
// Confirmed 2026-09-12: "is there a place for me to set all these
// advertisements or latest updates and push publish" -- Save = live
// immediately here, same as every other admin screen in this system
// (Company Setup, Module Control, Tax Types, ...); there is no
// separate draft/publish step.
import { useEffect, useState, type FormEvent } from 'react'
import { api, type Announcement } from '../lib/api'

export default function AnnouncementsPage() {
  const [videoUrl, setVideoUrl] = useState('')
  const [savingVideo, setSavingVideo] = useState(false)
  const [videoSaved, setVideoSaved] = useState(false)

  const [announcements, setAnnouncements] = useState<Announcement[]>([])
  const [tag, setTag] = useState('')
  const [text, setText] = useState('')
  const [creating, setCreating] = useState(false)
  const [editingText, setEditingText] = useState<Record<string, string>>({})

  const [error, setError] = useState<string | null>(null)

  function refresh() {
    api.getAdBannerSettings().then((s) => setVideoUrl(s.video_url ?? '')).catch((e) => setError(e.message))
    api.listAnnouncements().then(setAnnouncements).catch((e) => setError(e.message))
  }

  useEffect(refresh, [])

  async function onSaveVideo(e: FormEvent) {
    e.preventDefault()
    setError(null)
    setSavingVideo(true)
    setVideoSaved(false)
    try {
      await api.updateAdBannerSettings(videoUrl || null)
      setVideoSaved(true)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to save the video URL')
    } finally {
      setSavingVideo(false)
    }
  }

  async function onCreate(e: FormEvent) {
    e.preventDefault()
    setError(null)
    setCreating(true)
    try {
      await api.createAnnouncement({
        tag: tag || undefined,
        text,
        sort_order: announcements.length,
      })
      setTag('')
      setText('')
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to add announcement')
    } finally {
      setCreating(false)
    }
  }

  async function onSaveText(a: Announcement) {
    const newText = editingText[a.id]
    if (newText === undefined || newText === a.text) return
    setError(null)
    try {
      await api.updateAnnouncement(a.id, { text: newText })
      setEditingText((prev) => ({ ...prev, [a.id]: '' }))
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to update announcement')
    }
  }

  async function onToggleActive(a: Announcement) {
    setError(null)
    try {
      await api.updateAnnouncement(a.id, { is_active: !a.is_active })
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to update announcement')
    }
  }

  async function onMove(a: Announcement, direction: -1 | 1) {
    const sorted = [...announcements].sort((x, y) => x.sort_order - y.sort_order)
    const index = sorted.findIndex((x) => x.id === a.id)
    const swapWith = sorted[index + direction]
    if (!swapWith) return
    setError(null)
    try {
      await Promise.all([
        api.updateAnnouncement(a.id, { sort_order: swapWith.sort_order }),
        api.updateAnnouncement(swapWith.id, { sort_order: a.sort_order }),
      ])
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to reorder announcements')
    }
  }

  async function onDelete(a: Announcement) {
    setError(null)
    try {
      await api.deleteAnnouncement(a.id)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to delete announcement')
    }
  }

  const sorted = [...announcements].sort((a, b) => a.sort_order - b.sort_order)

  return (
    <div>
      <h1>Announcements &amp; Ad Banner</h1>
      <p className="muted">
        Controls the promo video and "What's New" items shown on the Login page and, smaller, on
        every page after signing in. Saving here takes effect immediately -- there is no separate
        publish step, and a viewer sees the update the next time that panel loads (an already-open
        tab won't refresh it live).
      </p>
      {error && <div className="error-banner">{error}</div>}

      <div className="card">
        <h2>Advertisement video</h2>
        <p className="muted">
          A <strong>direct link to a video file</strong> (an .mp4 or .webm URL that plays when
          pasted into a browser tab) -- not an upload, and not a YouTube / Vimeo / Google Drive
          page link, which will not play. Video files are far too large to store the way a logo
          or photo is; point this at wherever your video is hosted. Leave blank to show just the
          items below on a plain colour panel, no video.
        </p>
        <p className="muted">
          No size or resolution limit is enforced -- the viewer's browser streams it straight
          from the link. It plays muted, looped, in a narrow column: 220px wide on the Login
          page, 150px on every other page, so 720p is more than enough and anything larger only
          costs bandwidth on every page load. A short loop of a few MB is ideal; H.264 .mp4 plays
          everywhere. If it fails to load, the panel quietly hides the video rather than showing
          an error.
        </p>
        <form onSubmit={onSaveVideo}>
          <div className="form-row">
            <label>Video URL</label>
            <input
              value={videoUrl}
              onChange={(e) => {
                setVideoUrl(e.target.value)
                setVideoSaved(false)
              }}
              placeholder="https://..."
              style={{ minWidth: 360 }}
            />
          </div>
          <button type="submit" disabled={savingVideo}>
            {savingVideo ? 'Saving...' : 'Save video URL'}
          </button>
          {videoSaved && <span className="muted" style={{ marginLeft: 10 }}>Saved.</span>}
        </form>
      </div>

      <div className="card">
        <h2>What's New items ({sorted.length})</h2>
        <table>
          <thead>
            <tr>
              <th>Tag</th>
              <th>Text</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {sorted.map((a, i) => (
              <tr key={a.id} style={{ opacity: a.is_active ? 1 : 0.6 }}>
                <td className="muted">{a.tag ?? '-'}</td>
                <td>
                  <input
                    value={editingText[a.id] ?? a.text}
                    onChange={(e) => setEditingText((prev) => ({ ...prev, [a.id]: e.target.value }))}
                    onBlur={() => onSaveText(a)}
                    style={{ width: '100%', minWidth: 320 }}
                  />
                </td>
                <td>
                  <span className={`badge ${a.is_active ? 'active' : 'draft'}`}>
                    {a.is_active ? 'Active' : 'Hidden'}
                  </span>
                </td>
                <td style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                  <button className="secondary" disabled={i === 0} onClick={() => onMove(a, -1)}>
                    &uarr;
                  </button>
                  <button
                    className="secondary"
                    disabled={i === sorted.length - 1}
                    onClick={() => onMove(a, 1)}
                  >
                    &darr;
                  </button>
                  <button className="secondary" onClick={() => onToggleActive(a)}>
                    {a.is_active ? 'Hide' : 'Show'}
                  </button>
                  <button className="secondary" onClick={() => onDelete(a)}>
                    Delete
                  </button>
                </td>
              </tr>
            ))}
            {sorted.length === 0 && (
              <tr>
                <td colSpan={4} className="muted">
                  No announcements yet.
                </td>
              </tr>
            )}
          </tbody>
        </table>

        <form onSubmit={onCreate} style={{ marginTop: 14 }}>
          <div className="form-row">
            <label>Tag (optional)</label>
            <input value={tag} onChange={(e) => setTag(e.target.value)} placeholder="e.g. New, Update, Add-on" />
          </div>
          <div className="form-row">
            <label>Text</label>
            <input
              value={text}
              onChange={(e) => setText(e.target.value)}
              placeholder="e.g. Forgot password + email OTP is now live."
              required
              style={{ minWidth: 360 }}
            />
          </div>
          <button type="submit" disabled={creating || !text}>
            {creating ? 'Adding...' : 'Add announcement'}
          </button>
        </form>
      </div>
    </div>
  )
}
