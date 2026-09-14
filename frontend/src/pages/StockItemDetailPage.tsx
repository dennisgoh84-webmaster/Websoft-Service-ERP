import { useEffect, useState, useRef, type FormEvent, type ChangeEvent } from 'react'
import { Link, useParams } from 'react-router-dom'
import {
  api,
  type StockItemRow,
  type StockItemAttachmentRow,
  type StockLevelRow,
  type StockMovementRow,
  type StockSetupRow,
  type StockBrandRow,
  type Warehouse,
} from '../lib/api'
import { formatMoney as money, formatDate } from '../lib/format'

export default function StockItemDetailPage() {
  const { id } = useParams<{ id: string }>()

  const [item, setItem] = useState<StockItemRow | null>(null)
  const [levels, setLevels] = useState<StockLevelRow[]>([])
  const [movements, setMovements] = useState<StockMovementRow[]>([])
  const [warehouses, setWarehouses] = useState<Warehouse[]>([])
  const [error, setError] = useState<string | null>(null)
  const [editing, setEditing] = useState(false)
  const [saving, setSaving] = useState(false)

  /* setup-master dropdown options */
  const [categories, setCategories] = useState<StockSetupRow[]>([])
  const [groups, setGroups] = useState<StockSetupRow[]>([])
  const [brands, setBrands] = useState<StockBrandRow[]>([])
  const [brandModels, setBrandModels] = useState<StockSetupRow[]>([])
  const [usages, setUsages] = useState<StockSetupRow[]>([])

  /* attachment upload */
  const fileInputRef = useRef<HTMLInputElement>(null)
  const [uploading, setUploading] = useState(false)

  /* picture upload (images only, max 10) */
  const picInputRef = useRef<HTMLInputElement>(null)
  const [uploadingPic, setUploadingPic] = useState(false)
  const [previewUrl, setPreviewUrl] = useState<string | null>(null)

  /* edit form state — original fields */
  const [editCode, setEditCode] = useState('')
  const [editName, setEditName] = useState('')
  const [editDesc, setEditDesc] = useState('')
  const [editCategory, setEditCategory] = useState('')
  const [editUom, setEditUom] = useState('')
  const [editReorder, setEditReorder] = useState('')

  /* edit form state — new fields */
  const [editCategoryId, setEditCategoryId] = useState('')
  const [editGroupId, setEditGroupId] = useState('')
  const [editBrandId, setEditBrandId] = useState('')
  const [editModelId, setEditModelId] = useState('')
  const [editUsageId, setEditUsageId] = useState('')
  const [editBarcode, setEditBarcode] = useState('')
  const [editPartNumber, setEditPartNumber] = useState('')
  const [editInvoiceDesc, setEditInvoiceDesc] = useState('')
  const [editMemo, setEditMemo] = useState('')
  const [editNotes, setEditNotes] = useState('')
  const [editDimensions, setEditDimensions] = useState('')

  function refresh() {
    if (!id) return
    api.getStockItem(id).then((d) => { setItem(d); populateForm(d) }).catch((e) => setError(e.message))
    api.listStockLevels().then((all) => setLevels(all.filter((l) => l.stock_item_id === id))).catch(() => {})
    api.listStockMovements({ stock_item_id: id, limit: 20 }).then(setMovements).catch(() => {})
    api.listWarehouses().then(setWarehouses).catch(() => {})
  }

  function loadSetupData() {
    api.listStockCategories().then(setCategories).catch(() => {})
    api.listStockGroups().then(setGroups).catch(() => {})
    api.listStockBrands().then(setBrands).catch(() => {})
    api.listStockUsages().then(setUsages).catch(() => {})
  }

  useEffect(refresh, [id])
  useEffect(loadSetupData, [])

  /* When brand changes, load that brand's models */
  useEffect(() => {
    if (editBrandId) {
      api.listStockModels(editBrandId).then(setBrandModels).catch(() => {})
    } else {
      setBrandModels([])
      setEditModelId('')
    }
  }, [editBrandId])

  function populateForm(d: StockItemRow) {
    setEditCode(d.code)
    setEditName(d.name)
    setEditDesc(d.description || '')
    setEditCategory(d.category || '')
    setEditUom(d.unit_of_measure)
    setEditReorder(String(d.reorder_level))
    setEditCategoryId(d.category_id || '')
    setEditGroupId(d.group_id || '')
    setEditBrandId(d.brand_id || '')
    setEditModelId(d.model_id || '')
    setEditUsageId(d.usage_id || '')
    setEditBarcode(d.barcode || '')
    setEditPartNumber(d.part_number || '')
    setEditInvoiceDesc(d.invoice_description || '')
    setEditMemo(d.memo || '')
    setEditNotes(d.notes || '')
    setEditDimensions(d.dimensions || '')
  }

  async function onSave(e: FormEvent) {
    e.preventDefault(); setError(null); setSaving(true)
    try {
      const updated = await api.updateStockItem(id!, {
        code: editCode,
        name: editName,
        description: editDesc || null,
        category: editCategory || null,
        unit_of_measure: editUom || 'PCS',
        reorder_level: parseInt(editReorder) || 0,
        category_id: editCategoryId || null,
        group_id: editGroupId || null,
        brand_id: editBrandId || null,
        model_id: editModelId || null,
        usage_id: editUsageId || null,
        barcode: editBarcode || null,
        part_number: editPartNumber || null,
        invoice_description: editInvoiceDesc || null,
        memo: editMemo || null,
        notes: editNotes || null,
        dimensions: editDimensions || null,
      })
      setItem(updated)
      populateForm(updated)
      setEditing(false)
    } catch (err) { setError(err instanceof Error ? err.message : 'Failed') }
    finally { setSaving(false) }
  }

  async function toggleActive() {
    if (!item) return
    setError(null)
    try {
      const updated = await api.updateStockItem(item.id, { is_active: !item.is_active })
      setItem(updated)
    } catch (err) { setError(err instanceof Error ? err.message : 'Failed') }
  }

  function isImage(att: StockItemAttachmentRow) {
    return att.content_type?.startsWith('image/')
  }

  async function handleFileUpload(e: ChangeEvent<HTMLInputElement>) {
    if (!e.target.files?.length || !id) return
    setUploading(true); setError(null)
    try {
      for (const file of Array.from(e.target.files)) {
        await api.uploadStockItemAttachment(id, file)
      }
      refresh()
    } catch (err) { setError(err instanceof Error ? err.message : 'Upload failed') }
    finally {
      setUploading(false)
      if (fileInputRef.current) fileInputRef.current.value = ''
    }
  }

  async function handlePictureUpload(e: ChangeEvent<HTMLInputElement>) {
    if (!e.target.files?.length || !id) return
    const pictures = item?.attachments?.filter(isImage) || []
    const remaining = 10 - pictures.length
    const files = Array.from(e.target.files).slice(0, remaining)
    if (!files.length) { setError('Maximum 10 pictures reached'); return }

    setUploadingPic(true); setError(null)
    try {
      for (const file of files) {
        await api.uploadStockItemAttachment(id, file)
      }
      refresh()
    } catch (err) { setError(err instanceof Error ? err.message : 'Upload failed') }
    finally {
      setUploadingPic(false)
      if (picInputRef.current) picInputRef.current.value = ''
    }
  }

  async function deleteAttachment(att: StockItemAttachmentRow) {
    if (!id) return
    setError(null)
    try {
      await api.deleteStockItemAttachment(id, att.id)
      refresh()
    } catch (err) { setError(err instanceof Error ? err.message : 'Delete failed') }
  }

  const whMap = Object.fromEntries(warehouses.map((w) => [w.id, w]))

  if (!item) return <div style={{ padding: 24 }}><p>Loading...</p></div>

  const totalQty = levels.reduce((s, l) => s + l.quantity, 0)
  const totalValue = levels.reduce((s, l) => s + l.quantity * l.avg_cost, 0)

  const fieldStyle: React.CSSProperties = { fontSize: '0.8em', color: 'var(--muted)' }

  return (
    <div style={{ padding: 24 }}>
      <div style={{ marginBottom: 16 }}>
        <Link to="/stock-master" style={{ fontSize: '0.9em' }}>← Back to Stock Master</Link>
      </div>

      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: 12, marginBottom: 16 }}>
        <div>
          <h2 style={{ margin: 0 }}>{item.code} — {item.name}</h2>
          <span className={`badge badge-${item.is_active ? 'success' : 'neutral'}`} style={{ marginTop: 4, display: 'inline-block' }}>
            {item.is_active ? 'Active' : 'Inactive'}
          </span>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          {!editing && <button onClick={() => setEditing(true)}>Edit</button>}
          <button className="secondary" onClick={toggleActive}>
            {item.is_active ? 'Deactivate' : 'Activate'}
          </button>
        </div>
      </div>

      {error && <p className="error">{error}</p>}

      {/* ── Details / Edit Form ──────────────────────────────────── */}
      {editing ? (
        <form onSubmit={onSave} style={{ border: '1px solid var(--border)', padding: 16, borderRadius: 8, marginBottom: 24 }}>
          <h3 style={{ marginTop: 0 }}>Edit Stock Item</h3>

          {/* Row 1: Basic fields */}
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))', gap: 12, marginBottom: 12 }}>
            <div><label>Code *</label><input value={editCode} onChange={(e) => setEditCode(e.target.value)} required /></div>
            <div><label>Name *</label><input value={editName} onChange={(e) => setEditName(e.target.value)} required /></div>
            <div><label>Unit of Measure</label><input value={editUom} onChange={(e) => setEditUom(e.target.value)} /></div>
            <div><label>Reorder Level</label><input type="number" value={editReorder} onChange={(e) => setEditReorder(e.target.value)} /></div>
          </div>

          {/* Row 2: Setup master dropdowns */}
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))', gap: 12, marginBottom: 12 }}>
            <div>
              <label>Category</label>
              <select value={editCategoryId} onChange={(e) => setEditCategoryId(e.target.value)}>
                <option value="">— None —</option>
                {categories.filter((c) => c.is_active || c.id === item.category_id).map((c) => (
                  <option key={c.id} value={c.id}>{c.code} — {c.name}</option>
                ))}
              </select>
            </div>
            <div>
              <label>Group</label>
              <select value={editGroupId} onChange={(e) => setEditGroupId(e.target.value)}>
                <option value="">— None —</option>
                {groups.filter((g) => g.is_active || g.id === item.group_id).map((g) => (
                  <option key={g.id} value={g.id}>{g.code} — {g.name}</option>
                ))}
              </select>
            </div>
            <div>
              <label>Brand</label>
              <select value={editBrandId} onChange={(e) => { setEditBrandId(e.target.value); setEditModelId('') }}>
                <option value="">— None —</option>
                {brands.filter((b) => b.is_active || b.id === item.brand_id).map((b) => (
                  <option key={b.id} value={b.id}>{b.name}</option>
                ))}
              </select>
            </div>
            <div>
              <label>Model</label>
              <select value={editModelId} onChange={(e) => setEditModelId(e.target.value)} disabled={!editBrandId}>
                <option value="">— None —</option>
                {brandModels.filter((m) => m.is_active || m.id === item.model_id).map((m) => (
                  <option key={m.id} value={m.id}>{m.name}</option>
                ))}
              </select>
            </div>
            <div>
              <label>Usage</label>
              <select value={editUsageId} onChange={(e) => setEditUsageId(e.target.value)}>
                <option value="">— None —</option>
                {usages.filter((u) => u.is_active || u.id === item.usage_id).map((u) => (
                  <option key={u.id} value={u.id}>{u.code} — {u.name}</option>
                ))}
              </select>
            </div>
          </div>

          {/* Row 3: Text fields */}
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))', gap: 12, marginBottom: 12 }}>
            <div><label>Barcode</label><input value={editBarcode} onChange={(e) => setEditBarcode(e.target.value)} /></div>
            <div><label>Part Number</label><input value={editPartNumber} onChange={(e) => setEditPartNumber(e.target.value)} /></div>
            <div><label>Dimensions</label><input value={editDimensions} onChange={(e) => setEditDimensions(e.target.value)} placeholder="e.g. 100x50x25 mm" /></div>
          </div>

          {/* Row 4: Textarea fields */}
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, marginBottom: 12 }}>
            <div>
              <label>Description</label>
              <textarea value={editDesc} onChange={(e) => setEditDesc(e.target.value)} rows={3} style={{ width: '100%' }} />
            </div>
            <div>
              <label>Invoice Description</label>
              <textarea value={editInvoiceDesc} onChange={(e) => setEditInvoiceDesc(e.target.value)} rows={3} style={{ width: '100%' }} />
            </div>
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, marginBottom: 12 }}>
            <div>
              <label>Memo</label>
              <textarea value={editMemo} onChange={(e) => setEditMemo(e.target.value)} rows={3} style={{ width: '100%' }} />
            </div>
            <div>
              <label>Notes</label>
              <textarea value={editNotes} onChange={(e) => setEditNotes(e.target.value)} rows={3} style={{ width: '100%' }} />
            </div>
          </div>

          <div style={{ display: 'flex', gap: 8, marginTop: 12 }}>
            <button type="submit" disabled={saving}>{saving ? 'Saving...' : 'Save'}</button>
            <button type="button" className="secondary" onClick={() => { setEditing(false); populateForm(item) }}>Cancel</button>
          </div>
        </form>
      ) : (
        <div style={{ border: '1px solid var(--border)', padding: 16, borderRadius: 8, marginBottom: 24 }}>
          {/* View mode: all fields displayed */}
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))', gap: 12, marginBottom: 16 }}>
            <div><label style={fieldStyle}>Code</label><div><strong>{item.code}</strong></div></div>
            <div><label style={fieldStyle}>Name</label><div>{item.name}</div></div>
            <div><label style={fieldStyle}>Unit of Measure</label><div>{item.unit_of_measure}</div></div>
            <div><label style={fieldStyle}>Reorder Level</label><div>{item.reorder_level}</div></div>
            <div><label style={fieldStyle}>Category</label><div>{item.category_name || '—'}</div></div>
            <div><label style={fieldStyle}>Group</label><div>{item.group_name || '—'}</div></div>
            <div><label style={fieldStyle}>Brand</label><div>{item.brand_name || '—'}</div></div>
            <div><label style={fieldStyle}>Model</label><div>{item.model_name || '—'}</div></div>
            <div><label style={fieldStyle}>Usage</label><div>{item.usage_name || '—'}</div></div>
            <div><label style={fieldStyle}>Barcode</label><div>{item.barcode || '—'}</div></div>
            <div><label style={fieldStyle}>Part Number</label><div>{item.part_number || '—'}</div></div>
            <div><label style={fieldStyle}>Dimensions</label><div>{item.dimensions || '—'}</div></div>
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
            <div><label style={fieldStyle}>Description</label><div style={{ whiteSpace: 'pre-wrap' }}>{item.description || '—'}</div></div>
            <div><label style={fieldStyle}>Invoice Description</label><div style={{ whiteSpace: 'pre-wrap' }}>{item.invoice_description || '—'}</div></div>
            <div><label style={fieldStyle}>Memo</label><div style={{ whiteSpace: 'pre-wrap' }}>{item.memo || '—'}</div></div>
            <div><label style={fieldStyle}>Notes</label><div style={{ whiteSpace: 'pre-wrap' }}>{item.notes || '—'}</div></div>
          </div>
        </div>
      )}

      {/* ── Pictures (images only, max 10) ─────────────────────── */}
      {(() => {
        const pictures = item.attachments?.filter(isImage) || []
        const picCount = pictures.length
        return (
          <div style={{ border: '1px solid var(--border)', padding: 16, borderRadius: 8, marginBottom: 24 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
              <h3 style={{ margin: 0 }}>Pictures ({picCount}/10)</h3>
              <div>
                <input
                  ref={picInputRef}
                  type="file"
                  multiple
                  accept="image/*"
                  onChange={handlePictureUpload}
                  style={{ display: 'none' }}
                />
                <button
                  onClick={() => picInputRef.current?.click()}
                  disabled={uploadingPic || picCount >= 10}
                >
                  {uploadingPic ? 'Uploading...' : '🖼️ Add Picture'}
                </button>
              </div>
            </div>
            {picCount > 0 ? (
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(140px, 1fr))', gap: 12 }}>
                {pictures.map((att) => (
                  <div
                    key={att.id}
                    style={{
                      position: 'relative',
                      border: '1px solid var(--border)',
                      borderRadius: 6,
                      overflow: 'hidden',
                      aspectRatio: '1',
                      cursor: 'pointer',
                      background: 'var(--surface)',
                    }}
                    onClick={() => setPreviewUrl(`/api/stock/items/${item.id}/attachments/${att.id}/download`)}
                  >
                    <img
                      src={`/api/stock/items/${item.id}/attachments/${att.id}/download`}
                      alt={att.filename}
                      style={{ width: '100%', height: '100%', objectFit: 'cover', display: 'block' }}
                    />
                    <button
                      className="secondary"
                      style={{
                        position: 'absolute',
                        top: 4,
                        right: 4,
                        padding: '2px 6px',
                        fontSize: '0.75em',
                        background: 'rgba(0,0,0,0.6)',
                        color: '#fff',
                        border: 'none',
                        borderRadius: 4,
                        cursor: 'pointer',
                      }}
                      onClick={(e) => { e.stopPropagation(); deleteAttachment(att) }}
                      title="Delete picture"
                    >
                      ✕
                    </button>
                    <div
                      style={{
                        position: 'absolute',
                        bottom: 0,
                        left: 0,
                        right: 0,
                        padding: '4px 6px',
                        background: 'rgba(0,0,0,0.55)',
                        color: '#fff',
                        fontSize: '0.7em',
                        whiteSpace: 'nowrap',
                        overflow: 'hidden',
                        textOverflow: 'ellipsis',
                      }}
                    >
                      {att.filename}
                    </div>
                  </div>
                ))}
              </div>
            ) : (
              <p style={{ color: 'var(--muted)', margin: 0 }}>No pictures yet. Upload up to 10 images.</p>
            )}
          </div>
        )
      })()}

      {/* ── Preview Modal ───────────────────────────────────────── */}
      {previewUrl && (
        <div
          onClick={() => setPreviewUrl(null)}
          style={{
            position: 'fixed',
            inset: 0,
            zIndex: 9999,
            background: 'rgba(0,0,0,0.8)',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            cursor: 'pointer',
          }}
        >
          <img
            src={previewUrl}
            alt="Preview"
            style={{ maxWidth: '90vw', maxHeight: '90vh', borderRadius: 8, objectFit: 'contain' }}
            onClick={(e) => e.stopPropagation()}
          />
          <button
            style={{
              position: 'absolute',
              top: 20,
              right: 20,
              background: 'rgba(255,255,255,0.2)',
              color: '#fff',
              border: 'none',
              borderRadius: '50%',
              width: 36,
              height: 36,
              fontSize: '1.2em',
              cursor: 'pointer',
            }}
            onClick={() => setPreviewUrl(null)}
          >
            ✕
          </button>
        </div>
      )}

      {/* ── Attachments (non-image files) ───────────────────────── */}
      {(() => {
        const nonImageAtts = item.attachments?.filter((a) => !isImage(a)) || []
        return (
          <div style={{ border: '1px solid var(--border)', padding: 16, borderRadius: 8, marginBottom: 24 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
              <h3 style={{ margin: 0 }}>Attachments</h3>
              <div>
                <input
                  ref={fileInputRef}
                  type="file"
                  multiple
                  onChange={handleFileUpload}
                  style={{ display: 'none' }}
                />
                <button onClick={() => fileInputRef.current?.click()} disabled={uploading}>
                  {uploading ? 'Uploading...' : '📎 Upload File'}
                </button>
              </div>
            </div>
            {nonImageAtts.length > 0 ? (
              <table>
                <thead>
                  <tr>
                    <th>Filename</th>
                    <th>Type</th>
                    <th style={{ textAlign: 'right' }}>Size</th>
                    <th>Uploaded</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  {nonImageAtts.map((att) => (
                    <tr key={att.id}>
                      <td>
                        <a
                          href={`/api/stock/items/${item.id}/attachments/${att.id}/download`}
                          target="_blank"
                          rel="noopener noreferrer"
                        >
                          {att.filename}
                        </a>
                      </td>
                      <td>{att.content_type || '—'}</td>
                      <td style={{ textAlign: 'right' }}>
                        {att.file_size ? `${(att.file_size / 1024).toFixed(1)} KB` : '—'}
                      </td>
                      <td>{formatDate(att.created_at)}</td>
                      <td>
                        <button
                          className="secondary"
                          style={{ color: 'red', fontSize: '0.85em' }}
                          onClick={() => deleteAttachment(att)}
                        >
                          Delete
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            ) : (
              <p style={{ color: 'var(--muted)', margin: 0 }}>No attachments yet.</p>
            )}
          </div>
        )
      })()}

      {/* ── Summary Tiles ────────────────────────────────────────── */}
      <div style={{ display: 'flex', gap: 16, marginBottom: 24, flexWrap: 'wrap' }}>
        <div style={{ border: '1px solid var(--border)', borderRadius: 8, padding: '12px 20px', minWidth: 140, textAlign: 'center' }}>
          <div style={{ fontSize: '0.8em', color: 'var(--muted)' }}>Total Qty</div>
          <div style={{ fontSize: '1.6em', fontWeight: 'bold' }}>{totalQty}</div>
        </div>
        <div style={{ border: '1px solid var(--border)', borderRadius: 8, padding: '12px 20px', minWidth: 140, textAlign: 'center' }}>
          <div style={{ fontSize: '0.8em', color: 'var(--muted)' }}>Total Value</div>
          <div style={{ fontSize: '1.6em', fontWeight: 'bold' }}>{money(totalValue)}</div>
        </div>
        <div style={{ border: '1px solid var(--border)', borderRadius: 8, padding: '12px 20px', minWidth: 140, textAlign: 'center' }}>
          <div style={{ fontSize: '0.8em', color: 'var(--muted)' }}>Warehouses</div>
          <div style={{ fontSize: '1.6em', fontWeight: 'bold' }}>{levels.length}</div>
        </div>
      </div>

      {/* ── Stock Levels by Warehouse ────────────────────────────── */}
      <h3>Stock Levels by Warehouse</h3>
      <div style={{ overflowX: 'auto', marginBottom: 24 }}>
        <table>
          <thead>
            <tr>
              <th>Warehouse</th>
              <th style={{ textAlign: 'right' }}>Quantity</th>
              <th style={{ textAlign: 'right' }}>Avg Cost</th>
              <th style={{ textAlign: 'right' }}>Total Value</th>
            </tr>
          </thead>
          <tbody>
            {levels.map((l) => (
              <tr key={l.id}>
                <td>{l.warehouse_code} — {l.warehouse_name}</td>
                <td style={{ textAlign: 'right' }}>{l.quantity}</td>
                <td style={{ textAlign: 'right' }}>{money(l.avg_cost)}</td>
                <td style={{ textAlign: 'right' }}>{money(l.quantity * l.avg_cost)}</td>
              </tr>
            ))}
            {!levels.length && <tr><td colSpan={4} style={{ textAlign: 'center' }}>No stock in any warehouse</td></tr>}
          </tbody>
        </table>
      </div>

      {/* ── Recent Stock Movements ───────────────────────────────── */}
      <h3>Recent Movements</h3>
      <div style={{ overflowX: 'auto' }}>
        <table>
          <thead>
            <tr>
              <th>Date</th>
              <th>Warehouse</th>
              <th>Type</th>
              <th style={{ textAlign: 'right' }}>Qty</th>
              <th style={{ textAlign: 'right' }}>Unit Cost</th>
              <th style={{ textAlign: 'right' }}>Total</th>
              <th>Ref</th>
            </tr>
          </thead>
          <tbody>
            {movements.map((m) => (
              <tr key={m.id}>
                <td>{formatDate(m.created_at)}</td>
                <td>{whMap[m.warehouse_id]?.code || '?'}</td>
                <td>{m.movement_type.replace('_', ' ')}</td>
                <td style={{ textAlign: 'right', color: m.quantity >= 0 ? 'green' : 'red' }}>
                  {m.quantity > 0 ? '+' : ''}{m.quantity}
                </td>
                <td style={{ textAlign: 'right' }}>{money(m.unit_cost)}</td>
                <td style={{ textAlign: 'right' }}>{money(m.total_cost)}</td>
                <td style={{ fontSize: '0.85em' }}>{m.reference_type?.toUpperCase()}</td>
              </tr>
            ))}
            {!movements.length && <tr><td colSpan={7} style={{ textAlign: 'center' }}>No movements yet</td></tr>}
          </tbody>
        </table>
      </div>
    </div>
  )
}
