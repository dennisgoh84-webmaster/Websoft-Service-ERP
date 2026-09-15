import { useEffect, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import ExportControl from '../components/ExportControl'
import { api, downloadBlob, type Product, type ProductType, type ReferenceCode, type SetupListItem } from '../lib/api'
import { formatMoney as money } from '../lib/format'
import ProductImplementationTemplateEditor from '../components/ProductImplementationTemplateEditor'

export default function ProductCatalogPage() {
  const [items, setItems] = useState<Product[]>([])
  const [referenceCodes, setReferenceCodes] = useState<ReferenceCode[]>([])
  const [showInactive, setShowInactive] = useState(false)
  const [error, setError] = useState<string | null>(null)
  // NEW FEATURE (not a Python->PHP conversion) -- see
  // docs/backlog.md / docs/planned-work.md.
  const [templateEditorFor, setTemplateEditorFor] = useState<string | null>(null)

  // Category and unit of measure are picked from Setup Lists
  // (Maintenance -> Product Category / Unit of Measure), 2026-09-15.
  const [categories, setCategories] = useState<SetupListItem[]>([])
  const [units, setUnits] = useState<SetupListItem[]>([])
  // Inline view/edit of one product at a time.
  const [editingId, setEditingId] = useState<string | null>(null)
  const [edit, setEdit] = useState({ product_type: 'service' as ProductType, name: '', internal_reference: '', product_category: '', sales_price_sgd: '', cost_sgd: '', unit_of_measure: '', tax_code: '', tags: '' })
  const [savingEdit, setSavingEdit] = useState(false)

  const [productType, setProductType] = useState<ProductType>('service')
  const [name, setName] = useState('')
  const [internalReference, setInternalReference] = useState('')
  const [productCategory, setProductCategory] = useState('')
  const [salesPrice, setSalesPrice] = useState('')
  const [unitOfMeasure, setUnitOfMeasure] = useState('')
  const [defaultReferenceCodeId, setDefaultReferenceCodeId] = useState('')
  const [isStock, setIsStock] = useState(false)

  function refresh() {
    api.listCatalog(showInactive).then(setItems).catch((e) => setError(e.message))
  }

  useEffect(refresh, [showInactive])
  useEffect(() => {
    api.listReferenceCodes().then(setReferenceCodes).catch(() => setReferenceCodes([]))
    api.listSetupItems({ list_type: 'product_category' }).then(setCategories).catch(() => setCategories([]))
    api.listSetupItems({ list_type: 'unit_of_measure' }).then(setUnits).catch(() => setUnits([]))
  }, [])

  function startEdit(p: Product) {
    setEditingId(p.id)
    setEdit({
      product_type: p.product_type,
      name: p.name,
      internal_reference: p.internal_reference ?? '',
      product_category: p.product_category ?? '',
      sales_price_sgd: String(p.sales_price_sgd),
      cost_sgd: p.cost_sgd != null ? String(p.cost_sgd) : '',
      unit_of_measure: p.unit_of_measure ?? '',
      tax_code: p.tax_code,
      tags: p.tags ?? '',
    })
  }

  async function onSaveEdit(e: FormEvent) {
    e.preventDefault()
    if (!editingId) return
    setError(null)
    setSavingEdit(true)
    try {
      await api.updateCatalogItem(editingId, {
        product_type: edit.product_type,
        name: edit.name,
        internal_reference: edit.internal_reference || null,
        product_category: edit.product_category || null,
        sales_price_sgd: parseFloat(edit.sales_price_sgd) || 0,
        cost_sgd: edit.cost_sgd === '' ? null : parseFloat(edit.cost_sgd),
        unit_of_measure: edit.unit_of_measure || null,
        tax_code: edit.tax_code || undefined,
        tags: edit.tags || null,
      })
      setEditingId(null)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to save product')
    } finally {
      setSavingEdit(false)
    }
  }

  /** A select over a Setup List that still shows a value typed before the list existed. */
  function listSelect(value: string, onChange: (v: string) => void, list: SetupListItem[], listLabel: string, listPath: string) {
    return (
      <>
        <select value={value} onChange={(e) => onChange(e.target.value)}>
          <option value="">-- none --</option>
          {value && !list.some((x) => x.name === value) && <option value={value}>{value}</option>}
          {list.map((x) => (
            <option key={x.code} value={x.name}>
              {x.name}
            </option>
          ))}
        </select>
        {list.length === 0 && (
          <span className="muted">
            Nothing set up yet -- <Link to={listPath}>Maintenance → {listLabel}</Link>.
          </span>
        )}
      </>
    )
  }

  async function onCreate(e: FormEvent) {
    e.preventDefault()
    setError(null)
    try {
      await api.createCatalogItem({
        product_type: productType,
        name,
        internal_reference: internalReference || undefined,
        product_category: productCategory || undefined,
        sales_price_sgd: salesPrice === '' ? 0 : parseFloat(salesPrice),
        unit_of_measure: unitOfMeasure || undefined,
        default_reference_code_id: defaultReferenceCodeId || undefined,
        is_stock: isStock,
      })
      setName('')
      setInternalReference('')
      setProductCategory('')
      setSalesPrice('')
      setUnitOfMeasure('')
      setDefaultReferenceCodeId('')
      setIsStock(false)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to add catalog item')
    }
  }

  async function onExport(format: string) {
    setError(null)
    if (format === 'csv') {
      downloadBlob(await api.exportCatalogCsv(showInactive), 'product-catalog.csv')
    } else {
      downloadBlob(await api.exportCatalogExcel(showInactive), 'product-catalog.xlsx')
    }
  }

  async function onToggleActive(item: Product) {
    setError(null)
    try {
      await api.updateCatalogItem(item.id, { is_active: !item.is_active })
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to update item')
    }
  }

  async function onToggleStock(item: Product) {
    setError(null)
    try {
      await api.updateCatalogItem(item.id, { is_stock: !item.is_stock })
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to update item')
    }
  }

  async function onSetReferenceCode(item: Product, referenceCodeId: string) {
    setError(null)
    try {
      await api.updateCatalogItem(item.id, { default_reference_code_id: referenceCodeId || null })
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to update default reference code')
    }
  }

  return (
    <div>
      <h1>Product / Service Catalog</h1>
      <p className="muted">
        Sellable items a Sales Quotation line can be drawn from. Sales Price is net of GST; each
        item sells under the tax code shown (default SR, the company's standard 9% rate). Default
        reference code presets which <Link to="/reference-codes">Reference Monitor</Link> GL
        sub-code a Sales Quotation line defaults to when this item is picked -- still overridable
        per line.
      </p>
      {error && <div className="error-banner">{error}</div>}

      <div className="card">
        <h2>Add catalog item</h2>
        <form onSubmit={onCreate}>
          <div className="form-row">
            <label>Type</label>
            <select value={productType} onChange={(e) => setProductType(e.target.value as ProductType)}>
              <option value="service">Service</option>
              <option value="product">Product</option>
            </select>
          </div>
          <div className="form-row">
            <label>Product name</label>
            <input value={name} onChange={(e) => setName(e.target.value)} required />
          </div>
          <div className="form-row">
            <label>Internal reference</label>
            <input value={internalReference} onChange={(e) => setInternalReference(e.target.value)} />
          </div>
          <div className="form-row">
            <label>Product category</label>
            {listSelect(productCategory, setProductCategory, categories, 'Product Category', '/setup-lists/product_category')}
          </div>
          <div className="form-row">
            <label>Sales price (SGD, net of GST)</label>
            <input
              type="number"
              min="0"
              step="0.01"
              value={salesPrice}
              onChange={(e) => setSalesPrice(e.target.value)}
            />
          </div>
          <div className="form-row">
            <label>Unit of measure</label>
            {listSelect(unitOfMeasure, setUnitOfMeasure, units, 'Unit of Measure', '/setup-lists/unit_of_measure')}
          </div>
          <div className="form-row">
            <label>Default reference code</label>
            <select value={defaultReferenceCodeId} onChange={(e) => setDefaultReferenceCodeId(e.target.value)}>
              <option value="">None</option>
              {referenceCodes.map((rc) => (
                <option key={rc.id} value={rc.id}>
                  {rc.code} -- {rc.name}
                </option>
              ))}
            </select>
          </div>
          <div className="form-row">
            <label style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
              <input type="checkbox" checked={isStock} onChange={(e) => setIsStock(e.target.checked)} />
              Is Stock item
            </label>
            <span className="muted" style={{ fontSize: 12 }}>
              Flag this product as a stock / inventory item. The full Stock Master module will be
              linked when the Websoft Stock Distribution ERP is ready.
            </span>
          </div>
          <button type="submit" disabled={!name}>
            Add item
          </button>
        </form>
      </div>

      <div className="card">
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <h2>Catalog ({items.length})</h2>
          <label style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 13 }}>
            <input type="checkbox" checked={showInactive} onChange={(e) => setShowInactive(e.target.checked)} />
            Show inactive
          </label>
          <ExportControl
            formats={[
              { value: 'csv', label: 'CSV' },
              { value: 'excel', label: 'Excel' },
            ]}
            onExport={onExport}
            onError={setError}
          />
        </div>
        <table>
          <thead>
            <tr>
              <th>Name</th>
              <th>Type</th>
              <th>Reference</th>
              <th>Category</th>
              <th>Sales price</th>
              <th>Unit</th>
              <th>Tax</th>
              <th>Stock</th>
              <th>Default reference code</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {items.map((i) => (
              <tr key={i.id}>
                <td>{i.name}</td>
                <td className="muted">{i.product_type}</td>
                <td className="muted">{i.internal_reference ?? '-'}</td>
                <td className="muted">{i.product_category ?? '-'}</td>
                <td>{money(i.sales_price_sgd)}</td>
                <td className="muted">{i.unit_of_measure ?? '-'}</td>
                <td className="muted">{i.tax_code}</td>
                <td style={{ textAlign: 'center' }}>
                  <input
                    type="checkbox"
                    checked={i.is_stock}
                    onChange={() => onToggleStock(i)}
                    title={i.is_stock ? 'Stock item — click to unmark' : 'Not a stock item — click to mark'}
                  />
                </td>
                <td>
                  <select
                    value={i.default_reference_code_id ?? ''}
                    onChange={(e) => onSetReferenceCode(i, e.target.value)}
                    style={{ minWidth: 160 }}
                  >
                    <option value="">None</option>
                    {referenceCodes.map((rc) => (
                      <option key={rc.id} value={rc.id}>
                        {rc.code} -- {rc.name}
                      </option>
                    ))}
                  </select>
                </td>
                <td>
                  <span className={`badge ${i.is_active ? 'active' : 'draft'}`}>
                    {i.is_active ? 'Active' : 'Inactive'}
                  </span>
                </td>
                <td style={{ display: 'flex', gap: 6 }}>
                  <button className="secondary" onClick={() => (editingId === i.id ? setEditingId(null) : startEdit(i))}>
                    {editingId === i.id ? 'Close' : 'View / Edit'}
                  </button>
                  <button className="secondary" onClick={() => onToggleActive(i)}>
                    {i.is_active ? 'Deactivate' : 'Reactivate'}
                  </button>
                  <button
                    className="secondary"
                    onClick={() => setTemplateEditorFor(templateEditorFor === i.id ? null : i.id)}
                  >
                    {templateEditorFor === i.id ? 'Close template' : 'Job Implementation Template'}
                  </button>
                </td>
              </tr>
            ))}
            {items.length === 0 && (
              <tr>
                <td colSpan={11} className="muted">
                  No catalog items yet.
                </td>
              </tr>
            )}
          </tbody>
        </table>
        {editingId && (
          <div className="card" style={{ marginTop: 12 }}>
            <h2>Edit -- {items.find((x) => x.id === editingId)?.name}</h2>
            <form onSubmit={onSaveEdit}>
              <div style={{ display: 'flex', gap: 20, flexWrap: 'wrap' }}>
                <div style={{ flex: 1, minWidth: 260 }}>
                  <div className="form-row">
                    <label>Type</label>
                    <select value={edit.product_type} onChange={(e) => setEdit((p) => ({ ...p, product_type: e.target.value as ProductType }))}>
                      <option value="service">Service</option>
                      <option value="product">Product</option>
                    </select>
                  </div>
                  <div className="form-row">
                    <label>Product name</label>
                    <input value={edit.name} onChange={(e) => setEdit((p) => ({ ...p, name: e.target.value }))} required />
                  </div>
                  <div className="form-row">
                    <label>Internal reference</label>
                    <input value={edit.internal_reference} onChange={(e) => setEdit((p) => ({ ...p, internal_reference: e.target.value }))} />
                  </div>
                  <div className="form-row">
                    <label>Product category</label>
                    {listSelect(edit.product_category, (v) => setEdit((p) => ({ ...p, product_category: v })), categories, 'Product Category', '/setup-lists/product_category')}
                  </div>
                  <div className="form-row">
                    <label>Tags</label>
                    <input value={edit.tags} onChange={(e) => setEdit((p) => ({ ...p, tags: e.target.value }))} />
                  </div>
                </div>
                <div style={{ flex: 1, minWidth: 260 }}>
                  <div className="form-row">
                    <label>Sales price (SGD, net of GST)</label>
                    <input type="number" min="0" step="0.01" value={edit.sales_price_sgd} onChange={(e) => setEdit((p) => ({ ...p, sales_price_sgd: e.target.value }))} />
                  </div>
                  <div className="form-row">
                    <label>Cost (SGD)</label>
                    <input type="number" min="0" step="0.01" value={edit.cost_sgd} onChange={(e) => setEdit((p) => ({ ...p, cost_sgd: e.target.value }))} placeholder="Blank = no cost recorded" />
                  </div>
                  <div className="form-row">
                    <label>Unit of measure</label>
                    {listSelect(edit.unit_of_measure, (v) => setEdit((p) => ({ ...p, unit_of_measure: v })), units, 'Unit of Measure', '/setup-lists/unit_of_measure')}
                  </div>
                  <div className="form-row">
                    <label>Tax code</label>
                    <input value={edit.tax_code} onChange={(e) => setEdit((p) => ({ ...p, tax_code: e.target.value }))} />
                  </div>
                  <button type="submit" disabled={savingEdit || !edit.name}>
                    {savingEdit ? 'Saving...' : 'Save product'}
                  </button>{' '}
                  <button type="button" className="secondary" onClick={() => setEditingId(null)}>
                    Cancel
                  </button>
                </div>
              </div>
            </form>
          </div>
        )}
        {templateEditorFor && (
          <div className="card" style={{ marginTop: 12 }}>
            <h2>Job Implementation Template -- {items.find((x) => x.id === templateEditorFor)?.name}</h2>
            <ProductImplementationTemplateEditor productId={templateEditorFor} />
          </div>
        )}
      </div>
    </div>
  )
}
