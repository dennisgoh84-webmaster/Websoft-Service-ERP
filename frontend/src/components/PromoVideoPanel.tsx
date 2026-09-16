import { useEffect, useState } from 'react'
import { api } from '../lib/api'
import type { AdBannerSlot, PublicAdBanner } from '../lib/api'

// The "What's New" items are shared across both slots (global
// announcements); the video is not -- each slot (`login` / `app`) has
// its own independent promo video, split 2026-09-16 at Dennis's direct
// request ("the setting should be separate for login page and inside
// side menu advert video"). Shown tall and wide beside the Login form
// (slot="login"), and smaller beside every page after signing in
// (slot="app"; 2026-09-12: "after login successfully, all modules, hide
// menu bar, then have smaller tall banner on the right for
// advertisement video"). Content is admin-editable from the
// Announcements screen under Maintenance (2026-09-12: "is there a
// place for me to set all these advertisements or latest updates and
// push publish") -- Save there is live immediately, so this always
// reads the current published content via the one unauthenticated
// endpoint per slot (non-sensitive, identical for every viewer, and the
// Login page needs it before anyone has signed in anyway).
export default function PromoVideoPanel({ className, slot }: { className: string; slot: AdBannerSlot }) {
  const [banner, setBanner] = useState<PublicAdBanner | null>(null)
  // If the video can't load (blocked network, bad URL, browser codec
  // support), fall back to the plain gradient panel + items instead of
  // showing a broken black box.
  const [videoFailed, setVideoFailed] = useState(false)

  useEffect(() => {
    setVideoFailed(false)
    api
      .getPublicAdBanner(slot)
      .then(setBanner)
      .catch(() => setBanner(null))
  }, [slot])

  const videoUrl = banner?.video_url ?? null

  return (
    <aside className={className}>
      {videoUrl && !videoFailed && (
        <video
          className="promo-video"
          src={videoUrl}
          autoPlay
          muted
          loop
          playsInline
          onError={() => setVideoFailed(true)}
        />
      )}
      {banner && banner.items.length > 0 && (
        <div className="promo-items">
          {banner.items.map((item) => (
            <div className="promo-item" key={item.id}>
              {item.tag && <span className="promo-item-tag">{item.tag}</span>}
              <p>{item.text}</p>
            </div>
          ))}
        </div>
      )}
    </aside>
  )
}
