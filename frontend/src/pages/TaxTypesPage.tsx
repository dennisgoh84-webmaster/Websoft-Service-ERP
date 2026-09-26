// Tax Types -- maintenance over the GST tax codes used on invoices
// (Invoice.tax_code/gst_rate). A rate change is a data edit here, not a
// code change; a historical invoice keeps the rate it was actually
// raised at regardless of later edits.
import { useEffect, useState, type FormEvent } from 'react'
import ExportControl from '../components/ExportControl'
import { api, downloadBlob, type TaxCode } from '../lib/api'

// Where each code counts in the IRAS Form 5 (Dennis, 2026-09-26, decision 47.4).
const FORM5_BOXES: Record<'supply' | 'purchase', { value: string; label: string }[]> = {
  supply: [
    { value: '1', label: 'Box 1 -- standard-rated supplies' },
    { value: '2', label: 'Box 2 -- zero-rated supplies' },
    { value: '3', label: 'Box 3 -- exempt supplies' },
    { value: 'out_of_scope', label: 'Out of scope (revenue only)' },
  ],
  purchase: [
    { value: '5', label: 'Box 5 -- taxable purchases' },
    { value: 'not_taxable', label: 'Not a taxable purchase' },
  ],
}
const boxLabel = (kind: 'supply' | 'purchase', box: string | null) =>
  FORM5_BOXES[kind].find((b) => b.value === box)?.label ?? 'Not set (standard-rated if it charges GST)'

export default function TaxTypesPage() {
  const [taxCodes, setTaxCodes] = useState<TaxCode[]>([])
  const [showInactive, setShowInactive] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const [ratePercent, setRatePercent] = useState('')
  const [kind, setKind] = useState<'supply' | 'purchase'>('supply')
  const [form5Box, setForm5Box] = useState('1')
  const [creating, setCreating] = useState(false)
  const [editing, setEditing] = useState<Record<string, string>>({})

  function refresh() {
    api.listTaxCodes(showInactive).then(setTaxCodes).catch((e) => setError(e.message))
  }

  useEffect(refresh, [showInactive])

  async function onCreate(e: FormEvent) {
    e.preventDefault()
    setError(null)
    setCreating(true)
    try {
      await api.createTaxCode({ code, name, rate_percent: Number(ratePercent), kind, form5_box: form5Box })
      setCode('')
      setName('')
      setRatePercent('')
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to create tax type')
    } finally {
      setCreating(false)
    }
  }

  async function onSetBox(taxCode: TaxCode, box: string) {
    setError(null)
    try {
      await api.updateTaxCode(taxCode.id, { form5_box: box || null })
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to set the Form 5 box')
    }
  }

  async function onRename(taxCode: TaxCode) {
    const newName = editing[taxCode.id]
    if (!newName || newName === taxCode.name) return
    setError(null)
    try {
      await api.updateTaxCode(taxCode.id, { name: newName })
      setEditing((prev) => ({ ...prev, [taxCode.id]: '' }))
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to rename tax type')
    }
  }

  async function onToggleActive(taxCode: TaxCode) {
    setError(null)
    try {
      await api.updateTaxCode(taxCode.id, { is_active: !taxCode.is_active })
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to update tax type')
    }
  }

  async function onExport(format: string) {
    setError(null)
    const blob = format === 'csv' ? await api.exportTaxCodesCsv(showInactive) : await api.exportTaxCodesExcel(showInactive)
    downloadBlob(blob, `tax-types.${format === 'csv' ? 'csv' : 'xlsx'}`)
  }

  return (
    <div>
      <h1>Tax Types</h1>
      <p className="muted">
        GST treatments (Standard-Rated, Zero-Rated, Exempt, Out-of-Scope) and their current rate.
        Webmaster's services are Standard-Rated (SR), confirmed 2026-09-10.
      </p>
      {error && <div className="error-banner">{error}</div>}

      <div className="card">
        <div className="filter-bar">
          <label style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 13 }}>
            <input type="checkbox" checked={showInactive} onChange={(e) => setShowInactive(e.target.checked)} />
            Show retired
          </label>
          <ExportControl formats={[{ value: 'csv', label: 'CSV' }, { value: 'excel', label: 'Excel' }]} onExport={onExport} onError={setError} />
        </div>

        <h2>Tax Types ({taxCodes.length})</h2>
        <table>
          <thead>
            <tr>
              <th>Code</th>
              <th>Name</th>
              <th>Used on</th>
              <th>Rate %</th>
              <th>Form 5 box</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {taxCodes.map((t) => (
              <tr key={t.id} style={{ opacity: t.is_active ? 1 : 0.6 }}>
                <td>{t.code}</td>
                <td>
                  <input
                    value={editing[t.id] ?? t.name}
                    onChange={(e) => setEditing((prev) => ({ ...prev, [t.id]: e.target.value }))}
                    onBlur={() => onRename(t)}
                    style={{ width: '100%', minWidth: 220 }}
                  />
                </td>
                <td>{t.kind === 'purchase' ? 'Supplier bills' : 'Sales'}</td>
                <td>{t.rate_percent}%</td>
                <td>
                  <select
                    aria-label={`Form 5 box for ${t.code}`}
                    value={t.form5_box ?? ''}
                    onChange={(e) => onSetBox(t, e.target.value)}
                  >
                    {!t.form5_box && <option value="">{boxLabel(t.kind, null)}</option>}
                    {FORM5_BOXES[t.kind].map((b) => (
                      <option key={b.value} value={b.value}>
                        {b.label}
                      </option>
                    ))}
                  </select>
                </td>
                <td>
                  <span className={`badge ${t.is_active ? 'active' : 'draft'}`}>
                    {t.is_active ? 'Active' : 'Retired'}
                  </span>
                </td>
                <td>
                  <button className="secondary" onClick={() => onToggleActive(t)}>
                    {t.is_active ? 'Retire' : 'Reinstate'}
                  </button>
                </td>
              </tr>
            ))}
            {taxCodes.length === 0 && (
              <tr>
                <td colSpan={7} className="muted">
                  No tax types yet.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      <div className="card">
        <h2>Add a Tax Type</h2>
        <form onSubmit={onCreate}>
          <div className="form-row">
            <label>Code</label>
            <input value={code} onChange={(e) => setCode(e.target.value)} placeholder="e.g. ZR" required />
          </div>
          <div className="form-row">
            <label>Name</label>
            <input value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Zero-Rated" required />
          </div>
          <div className="form-row">
            <label>Used on</label>
            <select
              value={kind}
              onChange={(e) => {
                const k = e.target.value as 'supply' | 'purchase'
                setKind(k)
                setForm5Box(FORM5_BOXES[k][0].value)
              }}
            >
              <option value="supply">Sales (supply codes: SR, ZR, ES, OS)</option>
              <option value="purchase">Supplier bills (purchase codes: TX, ZP, EP, OP, NR)</option>
            </select>
          </div>
          <div className="form-row">
            <label htmlFor="tax-form5-box">Form 5 box</label>
            <select id="tax-form5-box" value={form5Box} onChange={(e) => setForm5Box(e.target.value)}>
              {FORM5_BOXES[kind].map((b) => (
                <option key={b.value} value={b.value}>
                  {b.label}
                </option>
              ))}
            </select>
          </div>
          <div className="form-row">
            <label>Rate %</label>
            <input
              type="number"
              min={0}
              max={100}
              step="0.01"
              value={ratePercent}
              onChange={(e) => setRatePercent(e.target.value)}
              required
            />
          </div>
          <button type="submit" disabled={creating}>
            {creating ? 'Adding...' : 'Add Tax Type'}
          </button>
        </form>
      </div>
    </div>
  )
}
