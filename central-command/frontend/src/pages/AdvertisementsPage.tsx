import { useEffect, useState, type FormEvent } from 'react'
import { api, type Advertisement, type ClientSummary, type PushResult } from '../lib/api'

export default function AdvertisementsPage() {
  const [ads, setAds] = useState<Advertisement[]>([])
  const [clients, setClients] = useState<ClientSummary[]>([])
  const [showForm, setShowForm] = useState(false)
  const [form, setForm] = useState({ tag: '', text: '', sort_order: 0, client_ids: [] as string[] })
  const [pushResult, setPushResult] = useState<PushResult | null>(null)
  const [error, setError] = useState('')

  const refresh = () => {
    api.listAds().then(setAds)
    api.listClients().then(setClients)
  }
  useEffect(() => { refresh() }, [])

  const clientName = (id: string) => clients.find((c) => c.id === id)?.name ?? id.slice(0, 8)

  async function onCreate(e: FormEvent) {
    e.preventDefault()
    setError('')
    try {
      await api.createAd(form)
      setShowForm(false)
      setForm({ tag: '', text: '', sort_order: 0, client_ids: [] })
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed')
    }
  }

  async function onPush(adId: string) {
    setPushResult(null)
    try {
      const res = await api.pushAd(adId)
      setPushResult(res)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Push failed')
    }
  }

  async function onPushAll() {
    setPushResult(null)
    try {
      const res = await api.pushAllAds()
      setPushResult(res)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Push failed')
    }
  }

  async function onDelete(adId: string) {
    if (!window.confirm('Delete this advertisement?')) return
    await api.deleteAd(adId)
    refresh()
  }

  function toggleClient(cid: string) {
    setForm((f) => ({
      ...f,
      client_ids: f.client_ids.includes(cid)
        ? f.client_ids.filter((x) => x !== cid)
        : [...f.client_ids, cid],
    }))
  }

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h1 style={{ margin: 0, fontSize: 22 }}>Advertisements</h1>
        <div style={{ display: 'flex', gap: 8 }}>
          <button
            onClick={onPushAll}
            style={{ background: '#27ae60', color: '#fff', border: 'none', padding: '8px 16px', borderRadius: 6, cursor: 'pointer', fontWeight: 600 }}
          >
            Push All Active
          </button>
          <button
            onClick={() => setShowForm(!showForm)}
            style={{ background: '#800020', color: '#fff', border: 'none', padding: '8px 16px', borderRadius: 6, cursor: 'pointer', fontWeight: 600 }}
          >
            {showForm ? 'Cancel' : '+ New Ad'}
          </button>
        </div>
      </div>

      {error && <div style={{ background: '#fdecea', color: '#c0392b', padding: '6px 12px', borderRadius: 4, fontSize: 13, marginBottom: 12 }}>{error}</div>}

      {pushResult && (
        <div style={{ background: '#eafaf1', border: '1px solid #27ae60', borderRadius: 6, padding: 12, marginBottom: 16, fontSize: 13 }}>
          <strong>Push Results:</strong>
          <ul style={{ margin: '6px 0 0', paddingLeft: 16 }}>
            {pushResult.results.map((r, i) => (
              <li key={i} style={{ color: r.success ? '#27ae60' : '#e74c3c' }}>
                {r.client}: {r.success ? `✓ OK${r.count ? ` (${r.count} items)` : ''}` : `✗ ${r.error}`}
              </li>
            ))}
          </ul>
        </div>
      )}

      {showForm && (
        <div style={{ background: '#fff', padding: 20, borderRadius: 8, border: '1px solid #e0e0e0', marginBottom: 20 }}>
          <form onSubmit={onCreate}>
            <div style={{ display: 'grid', gridTemplateColumns: '120px 1fr', gap: 12, marginBottom: 12 }}>
              <div>
                <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 2 }}>Tag</label>
                <input value={form.tag} onChange={(e) => setForm({ ...form, tag: e.target.value })} placeholder="e.g. New" style={{ width: '100%', padding: 6, border: '1px solid #ccc', borderRadius: 4, boxSizing: 'border-box' }} />
              </div>
              <div>
                <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 2 }}>Text</label>
                <input value={form.text} onChange={(e) => setForm({ ...form, text: e.target.value })} required style={{ width: '100%', padding: 6, border: '1px solid #ccc', borderRadius: 4, boxSizing: 'border-box' }} />
              </div>
            </div>
            <div style={{ marginBottom: 12 }}>
              <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4 }}>Target Clients</label>
              <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                {clients.map((c) => (
                  <label key={c.id} style={{ display: 'flex', alignItems: 'center', gap: 4, fontSize: 12, background: form.client_ids.includes(c.id) ? '#800020' : '#f0f0f0', color: form.client_ids.includes(c.id) ? '#fff' : '#333', padding: '4px 10px', borderRadius: 16, cursor: 'pointer' }}>
                    <input type="checkbox" checked={form.client_ids.includes(c.id)} onChange={() => toggleClient(c.id)} style={{ display: 'none' }} />
                    {c.name}
                  </label>
                ))}
                {clients.length === 0 && <span style={{ color: '#888', fontSize: 12 }}>No clients registered</span>}
              </div>
            </div>
            <button type="submit" style={{ background: '#800020', color: '#fff', border: 'none', padding: '8px 20px', borderRadius: 6, cursor: 'pointer', fontWeight: 600 }}>
              Create
            </button>
          </form>
        </div>
      )}

      <div style={{ background: '#fff', borderRadius: 8, border: '1px solid #e0e0e0', overflow: 'hidden' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
          <thead>
            <tr style={{ borderBottom: '2px solid #eee' }}>
              <th style={{ textAlign: 'left', padding: '10px 12px', color: '#888', fontWeight: 600, fontSize: 12 }}>Tag</th>
              <th style={{ textAlign: 'left', padding: '10px 12px', color: '#888', fontWeight: 600, fontSize: 12 }}>Text</th>
              <th style={{ textAlign: 'left', padding: '10px 12px', color: '#888', fontWeight: 600, fontSize: 12 }}>Active</th>
              <th style={{ textAlign: 'left', padding: '10px 12px', color: '#888', fontWeight: 600, fontSize: 12 }}>Targeted Clients</th>
              <th style={{ textAlign: 'left', padding: '10px 12px', color: '#888', fontWeight: 600, fontSize: 12 }}>Actions</th>
            </tr>
          </thead>
          <tbody>
            {ads.map((ad) => (
              <tr key={ad.id} style={{ borderBottom: '1px solid #f0f0f0' }}>
                <td style={{ padding: '8px 12px' }}>
                  {ad.tag && <span style={{ display: 'inline-block', padding: '1px 8px', borderRadius: 4, fontSize: 11, fontWeight: 600, color: '#fff', background: '#3498db' }}>{ad.tag}</span>}
                </td>
                <td style={{ padding: '8px 12px' }}>{ad.text}</td>
                <td style={{ padding: '8px 12px' }}>
                  <span style={{ color: ad.is_active ? '#27ae60' : '#e74c3c' }}>{ad.is_active ? 'Yes' : 'No'}</span>
                </td>
                <td style={{ padding: '8px 12px', fontSize: 11 }}>
                  {ad.assignments.map((a) => (
                    <span key={a.client_id} style={{ display: 'inline-block', background: '#f0f0f0', padding: '1px 6px', borderRadius: 3, marginRight: 4, marginBottom: 2 }}>
                      {clientName(a.client_id)}
                      {a.pushed_at && <span style={{ color: '#27ae60', marginLeft: 4 }}>✓</span>}
                    </span>
                  ))}
                  {ad.assignments.length === 0 && <span style={{ color: '#888' }}>none</span>}
                </td>
                <td style={{ padding: '8px 12px' }}>
                  <div style={{ display: 'flex', gap: 4 }}>
                    <button onClick={() => onPush(ad.id)} style={{ background: '#27ae60', color: '#fff', border: 'none', padding: '3px 8px', borderRadius: 3, cursor: 'pointer', fontSize: 11 }}>Push</button>
                    <button onClick={() => onDelete(ad.id)} style={{ background: '#e74c3c', color: '#fff', border: 'none', padding: '3px 8px', borderRadius: 3, cursor: 'pointer', fontSize: 11 }}>Delete</button>
                  </div>
                </td>
              </tr>
            ))}
            {ads.length === 0 && (
              <tr><td colSpan={5} style={{ padding: 20, textAlign: 'center', color: '#888' }}>No advertisements yet.</td></tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  )
}
