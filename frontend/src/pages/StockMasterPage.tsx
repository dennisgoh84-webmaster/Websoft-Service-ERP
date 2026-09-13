import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { api, type StockItemRow } from '../lib/api'

const PAGE_SIZE = 10

export default function StockMasterPage() {
  const [rows, setRows] = useState<StockItemRow[]>([])
  const [error, setError] = useState<string | null>(null)

  /* ── filters ──────────────────────────────────────────────────── */
  const [search, setSearch] = useState('')
  const [catFilter, setCatFilter] = useState('')
  const [statusFilter, setStatusFilter] = useState<'' | 'active' | 'inactive'>('')
  const [page, setPage] = useState(1)

  /* ── new-item form ────────────────────────────────────────────── */
  const [creating, setCreating] = useState(false)
  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const [category, setCategory] = useState('')
  const [uom, setUom] = useState('PCS')
  const [reorder, setReorder] = useState('')

  function refresh() {
    api.listStockItems().then(setRows).catch((e) => setError(e.message))
  }
  useEffect(refresh, [])

  /* derive unique categories for the dropdown */
  const categories = useMemo(() => {
    const set = new Set<string>()
    rows.forEach((r) => { if (r.category) set.add(r.category) })
    return [...set].sort()
  }, [rows])

  /* apply filters client-side */
  const filtered = useMemo(() => {
    let list = rows
    if (search) {
      const q = search.toLowerCase()
      list = list.filter(
        (r) =>
          r.code.toLowerCase().includes(q) ||
          r.name.toLowerCase().includes(q) ||
          (r.description || '').toLowerCase().includes(q),
      )
    }
    if (catFilter) list = list.filter((r) => r.category === catFilter)
    if (statusFilter === 'active') list = list.filter((r) => r.is_active)
    if (statusFilter === 'inactive') list = list.filter((r) => !r.is_active)
    return list
  }, [rows, search, catFilter, statusFilter])

  /* pagination */
  const totalPages = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE))
  const pageRows = filtered.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE)

  /* reset page when filters change */
  useEffect(() => { setPage(1) }, [search, catFilter, statusFilter])

  async function onCreate(e: FormEvent) {
    e.preventDefault(); setError(null)
    try {
      await api.createStockItem({
        code, name,
        category: category || undefined,
        unit_of_measure: uom || 'PCS',
        reorder_level: reorder ? parseInt(reorder) : 0,
      })
      setCode(''); setName(''); setCategory(''); setUom('PCS'); setReorder('')
      setCreating(false)
      refresh()
    } catch (err) { setError(err instanceof Error ? err.message : 'Failed') }
  }

  return (
    <div style={{ padding: 24 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h2>Stock Master</h2>
        {!creating && <button onClick={() => setCreating(true)}>+ New Stock Item</button>}
      </div>
      {error && <p className="error">{error}</p>}

      {creating && (
        <form onSubmit={onCreate} style={{ border: '1px solid var(--border)', padding: 16, borderRadius: 8, marginBottom: 16 }}>
          <h3>New Stock Item</h3>
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 8 }}>
            <input placeholder="Code *" value={code} onChange={(e) => setCode(e.target.value)} required style={{ width: 100 }} />
            <input placeholder="Name *" value={name} onChange={(e) => setName(e.target.value)} required style={{ width: 220 }} />
            <input placeholder="Category" value={category} onChange={(e) => setCategory(e.target.value)} style={{ width: 130 }} />
            <input placeholder="UoM" value={uom} onChange={(e) => setUom(e.target.value)} style={{ width: 80 }} />
            <input placeholder="Reorder Level" type="number" value={reorder} onChange={(e) => setReorder(e.target.value)} style={{ width: 110 }} />
          </div>
          <div style={{ display: 'flex', gap: 8 }}>
            <button type="submit">Create</button>
            <button type="button" className="secondary" onClick={() => setCreating(false)}>Cancel</button>
          </div>
        </form>
      )}

      {/* ── Dynamic Filters ──────────────────────────────────────── */}
      <div style={{ display: 'flex', gap: 12, marginBottom: 12, flexWrap: 'wrap', alignItems: 'center' }}>
        <input
          placeholder="Search code / name..."
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          style={{ width: 220 }}
        />
        <select value={catFilter} onChange={(e) => setCatFilter(e.target.value)}>
          <option value="">All Categories</option>
          {categories.map((c) => <option key={c} value={c}>{c}</option>)}
        </select>
        <select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value as '' | 'active' | 'inactive')}>
          <option value="">All Status</option>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
        <span style={{ fontSize: '0.85em', color: 'var(--muted)' }}>
          {filtered.length} item{filtered.length !== 1 ? 's' : ''}
        </span>
      </div>

      {/* ── Table ────────────────────────────────────────────────── */}
      <div style={{ overflowX: 'auto' }}>
        <table>
          <thead>
            <tr>
              <th>Code</th>
              <th>Name</th>
              <th>Category</th>
              <th>UoM</th>
              <th style={{ textAlign: 'right' }}>Reorder Level</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            {pageRows.map((item) => (
              <tr key={item.id} style={{ cursor: 'pointer' }}>
                <td>
                  <Link to={`/stock-master/${item.id}`} style={{ fontWeight: 'bold' }}>
                    {item.code}
                  </Link>
                </td>
                <td>
                  <Link to={`/stock-master/${item.id}`}>{item.name}</Link>
                </td>
                <td>{item.category || '—'}</td>
                <td>{item.unit_of_measure}</td>
                <td style={{ textAlign: 'right' }}>{item.reorder_level}</td>
                <td>
                  <span className={`badge badge-${item.is_active ? 'success' : 'neutral'}`}>
                    {item.is_active ? 'Active' : 'Inactive'}
                  </span>
                </td>
              </tr>
            ))}
            {!pageRows.length && (
              <tr><td colSpan={6} style={{ textAlign: 'center' }}>No stock items found</td></tr>
            )}
          </tbody>
        </table>
      </div>

      {/* ── Pagination ───────────────────────────────────────────── */}
      {totalPages > 1 && (
        <div style={{ display: 'flex', justifyContent: 'center', gap: 8, marginTop: 12 }}>
          <button className="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>
            ← Prev
          </button>
          <span style={{ padding: '6px 12px', fontSize: '0.9em' }}>
            Page {page} of {totalPages}
          </span>
          <button className="secondary" disabled={page >= totalPages} onClick={() => setPage(page + 1)}>
            Next →
          </button>
        </div>
      )}
    </div>
  )
}
