// Announcements / Ad Banner -- admin screen for the two promo videos
// (Login page and, smaller, every page after signing in) and the
// shared "What's New" items (see components/PromoVideoPanel.tsx).
// Confirmed 2026-09-12: "is there a place for me to set all these
// advertisements or latest updates and push publish" -- Save = live
// immediately here, same as every other admin screen in this system
// (Company Setup, Module Control, Tax Types, ...); there is no
// separate draft/publish step.
//
// The video used to be one shared setting; split 2026-09-16 at
// Dennis's direct request ("the setting should be separate for login
// page and inside side menu advert video") into two independent cards,
// one per App\Models\AdBannerSettings::SLOT_*.
//
// Anything Central Command pushed is ONE-WAY (2026-09-24: "when it's
// pushed to the client, they cannot amend it"): a video slot it owns
// is shown locked, and its platform announcements are listed
// read-only above the company's own, which stay fully editable and
// never flow back.
import { useEffect, useRef, useState, type ChangeEvent, type FormEvent } from 'react'
import { api, type AdBannerSettingsInfo, type AdBannerSlot, type Announcement } from '../lib/api'

function formatBytes(n: number): string {
  if (n < 1024 * 1024) return `${Math.round(n / 1024)} KB`
  return `${(n / (1024 * 1024)).toFixed(1)} MB`
}

function VideoSlotCard({ slot, title, description }: { slot: AdBannerSlot; title: string; description: string }) {
  const [videoSettings, setVideoSettings] = useState<AdBannerSettingsInfo | null>(null)
  const [videoUrlInput, setVideoUrlInput] = useState('')
  const [savingVideoUrl, setSavingVideoUrl] = useState(false)
  const [uploadingVideo, setUploadingVideo] = useState(false)
  const [videoSaved, setVideoSaved] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const videoFileInputRef = useRef<HTMLInputElement>(null)

  function refresh() {
    api
      .getAdBannerSettings(slot)
      .then((s) => {
        setVideoSettings(s)
        setVideoUrlInput(s.video_source === 'url' ? s.video_url ?? '' : '')
      })
      .catch((e) => setError(e.message))
  }

  useEffect(refresh, [slot])

  async function onUploadVideo(e: ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0]
    if (!file) return
    setError(null)
    setVideoSaved(false)
    setUploadingVideo(true)
    try {
      const s = await api.uploadAdBannerVideo(slot, file)
      setVideoSettings(s)
      setVideoUrlInput('')
      setVideoSaved(true)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to upload the video')
    } finally {
      setUploadingVideo(false)
      if (videoFileInputRef.current) videoFileInputRef.current.value = ''
    }
  }

  async function onSaveVideoUrl(e: FormEvent) {
    e.preventDefault()
    setError(null)
    setSavingVideoUrl(true)
    setVideoSaved(false)
    try {
      const s = await api.updateAdBannerSettings(slot, videoUrlInput || null)
      setVideoSettings(s)
      setVideoSaved(true)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to save the video URL')
    } finally {
      setSavingVideoUrl(false)
    }
  }

  async function onRemoveVideo() {
    setError(null)
    setVideoSaved(false)
    try {
      const s = await api.updateAdBannerSettings(slot, null)
      setVideoSettings(s)
      setVideoUrlInput('')
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to remove the video')
    }
  }

  const locked = videoSettings?.managed_by_central_command === true

  return (
    <div className="card">
      <h2>{title}</h2>
      <p className="muted">{description}</p>
      {locked && (
        <div className="error-banner" style={{ background: '#fff7e6', borderColor: '#f0c36d', color: '#7a4b00' }}>
          This video is managed by Central Command and cannot be changed here. It will unlock if
          Central Command clears the video for this slot.
        </div>
      )}
      <p className="muted">
        Upload an .mp4 or .webm file (up to 100 MB), or paste a direct link to one hosted
        elsewhere instead -- not a YouTube / Vimeo / Google Drive page link, which will not
        play. Only one is ever live: uploading a file replaces a saved URL and vice versa.
        Leave both empty to show just the items below on a plain colour panel, no video.
      </p>
      {error && <div className="error-banner">{error}</div>}

      <p className="muted" style={{ marginBottom: 14 }}>
        {videoSettings?.video_source === 'upload' &&
          `Currently: uploaded file "${videoSettings.video_original_filename}"` +
            (videoSettings.video_file_size_bytes != null ? ` (${formatBytes(videoSettings.video_file_size_bytes)})` : '')}
        {videoSettings?.video_source === 'url' && `Currently: linked to ${videoSettings.video_url}`}
        {videoSettings?.video_source === 'none' && 'Currently: no video set.'}
      </p>

      <div className="form-row">
        <label>Upload a video file</label>
        <input
          ref={videoFileInputRef}
          type="file"
          accept="video/mp4,video/webm"
          onChange={onUploadVideo}
          disabled={uploadingVideo || locked}
        />
        {uploadingVideo && <span className="muted" style={{ marginLeft: 10 }}>Uploading...</span>}
      </div>

      <form onSubmit={onSaveVideoUrl} style={{ marginTop: 14 }}>
        <div className="form-row">
          <label>Or a video URL</label>
          <input
            value={videoUrlInput}
            onChange={(e) => {
              setVideoUrlInput(e.target.value)
              setVideoSaved(false)
            }}
            placeholder="https://..."
            style={{ minWidth: 360 }}
            disabled={locked}
          />
        </div>
        <button type="submit" disabled={savingVideoUrl || locked}>
          {savingVideoUrl ? 'Saving...' : 'Save video URL'}
        </button>
        {videoSettings?.video_source !== 'none' && !locked && (
          <button type="button" className="secondary" style={{ marginLeft: 8 }} onClick={onRemoveVideo}>
            Remove video
          </button>
        )}
        {videoSaved && <span className="muted" style={{ marginLeft: 10 }}>Saved.</span>}
      </form>
    </div>
  )
}

export default function AnnouncementsPage() {
  const [announcements, setAnnouncements] = useState<Announcement[]>([])
  const [tag, setTag] = useState('')
  const [text, setText] = useState('')
  const [creating, setCreating] = useState(false)
  const [editingText, setEditingText] = useState<Record<string, string>>({})

  const [error, setError] = useState<string | null>(null)

  function refresh() {
    api.listAnnouncements().then(setAnnouncements).catch((e) => setError(e.message))
  }

  useEffect(refresh, [])

  async function onCreate(e: FormEvent) {
    e.preventDefault()
    setError(null)
    setCreating(true)
    try {
      await api.createAnnouncement({
        tag: tag || undefined,
        text,
        sort_order: local.length,
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

  // Reorders within the company's own list only. Every row whose
  // position changed gets its index written back, which also repairs
  // any ties left over from earlier inserts.
  async function onMove(a: Announcement, direction: -1 | 1) {
    const index = local.findIndex((x) => x.id === a.id)
    const target = index + direction
    if (target < 0 || target >= local.length) return
    const next = [...local]
    ;[next[index], next[target]] = [next[target], next[index]]
    setError(null)
    try {
      await Promise.all(
        next
          .map((row, i) => (row.sort_order === i ? null : api.updateAnnouncement(row.id, { sort_order: i })))
          .filter((p): p is Promise<Announcement> => p !== null),
      )
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

  const bySort = (a: Announcement, b: Announcement) => a.sort_order - b.sort_order
  const central = announcements.filter((a) => a.source === 'central').sort(bySort)
  const local = announcements.filter((a) => a.source !== 'central').sort(bySort)

  return (
    <div>
      <h1>Announcements &amp; Ad Banner</h1>
      <p className="muted">
        Controls the two promo videos -- one for the Login page, one for the banner shown
        alongside every page after signing in -- and the "What's New" items shown with both.
        Saving here takes effect immediately -- there is no separate publish step, and a viewer
        sees the update the next time that panel loads (an already-open tab won't refresh it
        live).
      </p>
      {error && <div className="error-banner">{error}</div>}

      <VideoSlotCard
        slot="login"
        title="Login page video"
        description="Shown tall and wide beside the sign-in form, before anyone has signed in. It plays
          muted, looped, in a 220px-wide column, so 720p is more than enough."
      />

      <VideoSlotCard
        slot="app"
        title="In-app banner video"
        description="Shown smaller, in a 150px-wide column, alongside the sidebar on every page after
          signing in. Independent from the Login page video above -- set it separately."
      />

      <div className="card">
        <h2>Platform announcements from Central Command ({central.length})</h2>
        <p className="muted">
          Pushed by Web Master Consultancy and shown first, with both videos above. Read-only here
          -- one-way from Central Command. Hidden ones are listed greyed out.
        </p>
        <table>
          <thead>
            <tr>
              <th>Tag</th>
              <th>Text</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            {central.map((a) => (
              <tr key={a.id} style={{ opacity: a.is_active ? 1 : 0.6 }}>
                <td className="muted">{a.tag ?? '-'}</td>
                <td>{a.text}</td>
                <td>
                  <span className={`badge ${a.is_active ? 'active' : 'draft'}`}>
                    {a.is_active ? 'Active' : 'Hidden'}
                  </span>
                </td>
              </tr>
            ))}
            {central.length === 0 && (
              <tr>
                <td colSpan={3} className="muted">
                  Nothing pushed from Central Command yet.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      <div className="card">
        <h2>Company announcements ({local.length})</h2>
        <p className="muted">
          Your own items, shown after the platform announcements above with both videos. Fully
          editable here and never sent to Central Command.
        </p>
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
            {local.map((a, i) => (
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
                    disabled={i === local.length - 1}
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
            {local.length === 0 && (
              <tr>
                <td colSpan={4} className="muted">
                  No company announcements yet.
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
