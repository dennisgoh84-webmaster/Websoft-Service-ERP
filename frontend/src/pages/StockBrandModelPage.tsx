import { useEffect, useState, type FormEvent } from 'react'
import { api, type StockBrandRow, type StockSetupRow } from '../lib/api'

export default function StockBrandModelPage() {
  const [brands, setBrands] = useState<StockBrandRow[]>([])
  const [error, setError] = useState<string | null>(null)

  /* add-brand form */
  const [newBrandName, setNewBrandName] = useState('')

  /* selected brand for model management */
  const [selectedBrandId, setSelectedBrandId] = useState<string | null>(null)
  const [models, setModels] = useState<StockSetupRow[]>([])

  /* add-model form */
  const [newModelName, setNewModelName] = useState('')

  /* inline-edit brand */
  const [editBrandId, setEditBrandId] = useState<string | null>(null)
  const [editBrandName, setEditBrandName] = useState('')

  /* inline-edit model */
  const [editModelId, setEditModelId] = useState<string | null>(null)
  const [editModelName, setEditModelName] = useState('')

  function refreshBrands() {
    api.listStockBrands().then(setBrands).catch((e) => setError(e.message))
  }
  useEffect(refreshBrands, [])

  function refreshModels(brandId: string) {
    api.listStockModels(brandId).then(setModels).catch((e) => setError(e.message))
  }
  useEffect(() => {
    if (selectedBrandId) refreshModels(selectedBrandId)
    else setModels([])
  }, [selectedBrandId])

  /* ── Brand CRUD ────────────────────────────────────────────── */
  async function onCreateBrand(e: FormEvent) {
    e.preventDefault(); setError(null)
    try {
      await api.createStockBrand({ name: newBrandName })
      setNewBrandName('')
      refreshBrands()
    } catch (err) { setError(err instanceof Error ? err.message : 'Failed') }
  }

  async function toggleBrand(b: StockBrandRow) {
    setError(null)
    try { await api.toggleStockBrand(b.id); refreshBrands() }
    catch (err) { setError(err instanceof Error ? err.message : 'Failed') }
  }

  function startEditBrand(b: StockBrandRow) {
    setEditBrandId(b.id); setEditBrandName(b.name)
  }

  async function saveEditBrand() {
    if (!editBrandId) return; setError(null)
    try {
      await api.updateStockBrand(editBrandId, { name: editBrandName })
      setEditBrandId(null)
      refreshBrands()
    } catch (err) { setError(err instanceof Error ? err.message : 'Failed') }
  }

  /* ── Model CRUD ────────────────────────────────────────────── */
  async function onCreateModel(e: FormEvent) {
    e.preventDefault(); setError(null)
    if (!selectedBrandId) return
    try {
      await api.createStockModel({ brand_id: selectedBrandId, name: newModelName })
      setNewModelName('')
      refreshModels(selectedBrandId)
    } catch (err) { setError(err instanceof Error ? err.message : 'Failed') }
  }

  async function toggleModel(m: StockSetupRow) {
    setError(null)
    try {
      await api.toggleStockModel(m.id)
      if (selectedBrandId) refreshModels(selectedBrandId)
    } catch (err) { setError(err instanceof Error ? err.message : 'Failed') }
  }

  function startEditModel(m: StockSetupRow) {
    setEditModelId(m.id); setEditModelName(m.name)
  }

  async function saveEditModel() {
    if (!editModelId || !selectedBrandId) return; setError(null)
    try {
      // brand_id is required by StockModelCreate on the backend -- a
      // rename that omits it is rejected with a 422, so send the brand
      // the model already belongs to.
      await api.updateStockModel(editModelId, { brand_id: selectedBrandId, name: editModelName })
      setEditModelId(null)
      if (selectedBrandId) refreshModels(selectedBrandId)
    } catch (err) { setError(err instanceof Error ? err.message : 'Failed') }
  }

  const selectedBrand = brands.find((b) => b.id === selectedBrandId)

  return (
    <div style={{ padding: 24 }}>
      <h2>Brands &amp; Models</h2>
      {error && <p className="error">{error}</p>}

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 24, alignItems: 'start' }}>
        {/* ── Brands ──────────────────────────────────────────── */}
        <div>
          <h3 style={{ marginTop: 0 }}>Brands</h3>
          <form onSubmit={onCreateBrand} style={{ display: 'flex', gap: 8, marginBottom: 12, flexWrap: 'wrap' }}>
            <input placeholder="Brand name" value={newBrandName} onChange={(e) => setNewBrandName(e.target.value)} required style={{ width: 200 }} />
            <button type="submit">Add Brand</button>
          </form>
          <div style={{ overflowX: 'auto' }}>
            <table>
              <thead>
                <tr><th>Name</th><th>Active</th><th></th></tr>
              </thead>
              <tbody>
                {brands.map((b) => (
                  <tr
                    key={b.id}
                    style={{
                      cursor: 'pointer',
                      background: selectedBrandId === b.id ? 'var(--accent-bg, rgba(0,0,0,.05))' : undefined,
                    }}
                    onClick={() => { setSelectedBrandId(b.id); setEditModelId(null) }}
                  >
                    {editBrandId === b.id ? (
                      <>
                        <td><input value={editBrandName} onChange={(e) => setEditBrandName(e.target.value)} style={{ width: 160 }} onClick={(e) => e.stopPropagation()} /></td>
                        <td>{b.is_active ? '✅' : '❌'}</td>
                        <td style={{ display: 'flex', gap: 4 }}>
                          <button onClick={(e) => { e.stopPropagation(); saveEditBrand() }}>Save</button>
                          <button className="secondary" onClick={(e) => { e.stopPropagation(); setEditBrandId(null) }}>Cancel</button>
                        </td>
                      </>
                    ) : (
                      <>
                        <td><strong>{b.name}</strong></td>
                        <td>{b.is_active ? '✅' : '❌'}</td>
                        <td style={{ display: 'flex', gap: 4 }}>
                          <button className="secondary" onClick={(e) => { e.stopPropagation(); startEditBrand(b) }}>Edit</button>
                          <button className="secondary" onClick={(e) => { e.stopPropagation(); toggleBrand(b) }}>{b.is_active ? 'Deactivate' : 'Activate'}</button>
                        </td>
                      </>
                    )}
                  </tr>
                ))}
                {!brands.length && <tr><td colSpan={3} style={{ textAlign: 'center' }}>No brands yet</td></tr>}
              </tbody>
            </table>
          </div>
        </div>

        {/* ── Models (child of selected brand) ────────────────── */}
        <div>
          <h3 style={{ marginTop: 0 }}>
            Models {selectedBrand ? `— ${selectedBrand.name}` : ''}
          </h3>
          {selectedBrandId ? (
            <>
              <form onSubmit={onCreateModel} style={{ display: 'flex', gap: 8, marginBottom: 12, flexWrap: 'wrap' }}>
                <input placeholder="Model name" value={newModelName} onChange={(e) => setNewModelName(e.target.value)} required style={{ width: 200 }} />
                <button type="submit">Add Model</button>
              </form>
              <div style={{ overflowX: 'auto' }}>
                <table>
                  <thead>
                    <tr><th>Name</th><th>Active</th><th></th></tr>
                  </thead>
                  <tbody>
                    {models.map((m) => (
                      <tr key={m.id}>
                        {editModelId === m.id ? (
                          <>
                            <td><input value={editModelName} onChange={(e) => setEditModelName(e.target.value)} style={{ width: 160 }} /></td>
                            <td>{m.is_active ? '✅' : '❌'}</td>
                            <td style={{ display: 'flex', gap: 4 }}>
                              <button onClick={saveEditModel}>Save</button>
                              <button className="secondary" onClick={() => setEditModelId(null)}>Cancel</button>
                            </td>
                          </>
                        ) : (
                          <>
                            <td>{m.name}</td>
                            <td>{m.is_active ? '✅' : '❌'}</td>
                            <td style={{ display: 'flex', gap: 4 }}>
                              <button className="secondary" onClick={() => startEditModel(m)}>Edit</button>
                              <button className="secondary" onClick={() => toggleModel(m)}>{m.is_active ? 'Deactivate' : 'Activate'}</button>
                            </td>
                          </>
                        )}
                      </tr>
                    ))}
                    {!models.length && <tr><td colSpan={3} style={{ textAlign: 'center' }}>No models for this brand</td></tr>}
                  </tbody>
                </table>
              </div>
            </>
          ) : (
            <p style={{ color: 'var(--muted)' }}>Select a brand on the left to manage its models.</p>
          )}
        </div>
      </div>
    </div>
  )
}
