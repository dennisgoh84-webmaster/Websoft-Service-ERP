// Split out of AccountsPayablePage.tsx (2026-09-12) into its own nav
// item, directly above Accounts Payable: "confirm and import to AP" plus
// Print/Email/WhatsApp needed enough room of its own. A supplier is a
// Company/Individual record flagged is_supplier=true (not a separate
// master) -- this page only reads that list to populate the "Raise a
// purchase order" form; add/edit suppliers on the Company/Individual page.
import { Fragment, useEffect, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { EmailIcon, PrintIcon, WhatsAppIcon } from '../components/DocActionIcons'
import DocumentAttachmentsPanel from '../components/DocumentAttachmentsPanel'
import ExportControl from '../components/ExportControl'
import SignaturePanel from '../components/SignaturePanel'
import { api, downloadBlob, type CompanyIndividual, type PurchaseOrder } from '../lib/api'
import { formatMoney as money } from '../lib/format'

const PO_BADGE: Record<string, string> = {
  draft: 'draft',
  pending_approval: 'exceeded',
  approved: 'active',
  cancelled: 'expired',
}

// wa.me needs digits only (country code + number, no "+", spaces or dashes).
function waNumber(phone: string): string {
  return phone.replace(/[^0-9]/g, '')
}

export default function PurchaseOrdersPage() {
  const [suppliers, setSuppliers] = useState<CompanyIndividual[]>([])
  const [pos, setPos] = useState<PurchaseOrder[]>([])
  const [error, setError] = useState<string | null>(null)
  const [message, setMessage] = useState<string | null>(null)
  const [busyId, setBusyId] = useState<string | null>(null)
  const [docPanelId, setDocPanelId] = useState<string | null>(null)

  // New PO
  const [poSupplier, setPoSupplier] = useState('')
  const [poDesc, setPoDesc] = useState('')
  const [poAmount, setPoAmount] = useState('')

  function refresh() {
    api.listCompanyIndividuals({ is_supplier: true }).then(setSuppliers).catch((e) => setError(e.message))
    api.listPurchaseOrders().then(setPos).catch((e) => setError(e.message))
  }

  useEffect(refresh, [])

  const supplierName = (id: string) => suppliers.find((s) => s.id === id)?.name ?? id.slice(0, 8)
  const supplierOf = (id: string) => suppliers.find((s) => s.id === id)

  async function onCreatePO(e: FormEvent) {
    e.preventDefault()
    setError(null)
    setMessage(null)
    try {
      const po = await api.createPurchaseOrder({
        supplier_id: poSupplier,
        order_date: new Date().toISOString().slice(0, 10),
        description: poDesc,
        amount_sgd: parseFloat(poAmount),
      })
      setPoDesc('')
      setPoAmount('')
      setMessage(
        po.status === 'pending_approval'
          ? `${po.po_number} needs the owner's approval (PUR-001).`
          : `${po.po_number} created.`,
      )
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to create purchase order')
    }
  }

  async function onApprovePO(po: PurchaseOrder) {
    setError(null)
    setMessage(null)
    try {
      await api.approvePurchaseOrder(po.id)
      setMessage(`${po.po_number} approved.`)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to approve purchase order')
    }
  }

  async function onImportToAP(po: PurchaseOrder) {
    setError(null)
    setMessage(null)
    setBusyId(po.id)
    try {
      const bill = await api.importPurchaseOrderToAP(po.id)
      setMessage(`${po.po_number} imported to Accounts Payable as ${bill.bill_number}.`)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to import purchase order to AP')
    } finally {
      setBusyId(null)
    }
  }

  async function onEmailPO(po: PurchaseOrder) {
    setError(null)
    setMessage(null)
    setBusyId(po.id)
    try {
      const result = await api.emailPurchaseOrder(po.id)
      setMessage(`${po.po_number} emailed to ${result.to}.`)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to email purchase order')
    } finally {
      setBusyId(null)
    }
  }

  function onWhatsAppPO(po: PurchaseOrder) {
    setError(null)
    const supplier = supplierOf(po.supplier_id)
    if (!supplier?.phone) {
      setError(`${supplierName(po.supplier_id)} has no phone number on file -- add one on the Company/Individual page first.`)
      return
    }
    const text = `Purchase Order ${po.po_number}, ${money(po.total_amount_sgd)} -- ${po.description}. PDF to follow.`
    window.open(`https://wa.me/${waNumber(supplier.phone)}?text=${encodeURIComponent(text)}`, '_blank')
  }

  async function onExportPurchaseOrders(format: string) {
    setError(null)
    if (format === 'csv') {
      downloadBlob(await api.exportPurchaseOrdersCsv(), 'purchase-orders.csv')
    } else {
      downloadBlob(await api.exportPurchaseOrdersExcel(), 'purchase-orders.xlsx')
    }
  }

  return (
    <div>
      <h1>Purchase Order</h1>
      <p className="muted">
        PUR-001: purchase orders above the threshold set in Company Setup need the owner's
        approval -- with none set, every PO does. Once approved, "Import to AP" turns a PO
        straight into its matching bill (2-way matched, PUR-002/003) instead of re-typing it on
        the <Link to="/accounts-payable">Accounts Payable</Link> page. A supplier is a{' '}
        <Link to="/company-individuals">Company / Individual</Link> record ticked "Is Supplier" there --
        add or edit suppliers (including email and phone for Email/WhatsApp) on that page.
      </p>
      {error && <div className="error-banner">{error}</div>}
      {message && (
        <p className="muted" style={{ marginBottom: 12 }}>
          {message}
        </p>
      )}

      <div className="card">
        <div className="filter-bar">
          <h2 style={{ margin: 0 }}>Purchase orders ({pos.length})</h2>
          <ExportControl
            formats={[
              { value: 'csv', label: 'CSV' },
              { value: 'excel', label: 'Excel' },
            ]}
            onExport={onExportPurchaseOrders}
            onError={setError}
          />
        </div>
        <div style={{ overflowX: 'auto' }}>
          <table>
            <thead>
              <tr>
                <th>PO</th>
                <th>Supplier</th>
                <th>Description</th>
                <th>Total</th>
                <th>Status</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {pos.map((po) => {
                const supplier = supplierOf(po.supplier_id)
                return (
                  <Fragment key={po.id}>
                  <tr>
                    <td>{po.po_number}</td>
                    <td>{supplierName(po.supplier_id)}</td>
                    <td>{po.description}</td>
                    <td>{money(po.total_amount_sgd)}</td>
                    <td>
                      <span className={`badge ${PO_BADGE[po.status] ?? 'draft'}`}>
                        {po.status.replace('_', ' ')}
                      </span>
                    </td>
                    <td>
                      <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', justifyContent: 'flex-end' }}>
                        {po.status === 'pending_approval' && (
                          <button className="secondary" onClick={() => onApprovePO(po)}>
                            Approve (owner)
                          </button>
                        )}
                        {po.status === 'approved' && !po.imported_bill_id && (
                          <button
                            className="secondary"
                            disabled={busyId === po.id}
                            onClick={() => onImportToAP(po)}
                          >
                            Import to AP
                          </button>
                        )}
                        {po.imported_bill_id && (
                          <span className="muted" style={{ alignSelf: 'center', fontSize: 13 }}>
                            Imported &rarr; {po.imported_bill_number}
                          </span>
                        )}
                        <button
                          className="secondary icon-button"
                          title="Attachments & Signatures"
                          aria-label="Attachments & Signatures"
                          onClick={() => setDocPanelId(docPanelId === po.id ? null : po.id)}
                        >📎</button>
                        <Link to={`/purchase-orders/${po.id}/print`} className="secondary icon-button" title="Print" aria-label="Print">
                          <PrintIcon />
                        </Link>
                        <button
                          className="secondary icon-button"
                          disabled={busyId === po.id || !supplier?.billing_email}
                          title={supplier?.billing_email ? 'Email' : 'Add an email on the Company/Individual page first'}
                          aria-label="Email"
                          onClick={() => onEmailPO(po)}
                        >
                          <EmailIcon />
                        </button>
                        <button
                          className="secondary icon-button"
                          disabled={busyId === po.id || !supplier?.phone}
                          title={supplier?.phone ? 'WhatsApp' : 'Add a phone number on the Company/Individual page first'}
                          aria-label="WhatsApp"
                          onClick={() => onWhatsAppPO(po)}
                        >
                          <WhatsAppIcon />
                        </button>
                      </div>
                    </td>
                  </tr>
                  {docPanelId === po.id && (
                    <tr>
                      <td colSpan={6} style={{ padding: 16, background: 'var(--bg-muted, #f9f9f9)' }}>
                        <DocumentAttachmentsPanel entityType="purchase_order" entityId={po.id} />
                        <SignaturePanel entityType="purchase_order" entityId={po.id} />
                      </td>
                    </tr>
                  )}
                  </Fragment>
                )
              })}
              {pos.length === 0 && (
                <tr>
                  <td colSpan={6} className="muted">
                    No purchase orders yet.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
        <h2 style={{ marginTop: 18 }}>Raise a purchase order</h2>
        <form onSubmit={onCreatePO}>
          <div className="form-row">
            <label>Supplier</label>
            <select value={poSupplier} onChange={(e) => setPoSupplier(e.target.value)} required>
              <option value="">Select...</option>
              {suppliers.map((s) => (
                <option key={s.id} value={s.id}>
                  {s.name}
                </option>
              ))}
            </select>
          </div>
          <div className="form-row">
            <label>Description</label>
            <input value={poDesc} onChange={(e) => setPoDesc(e.target.value)} required />
          </div>
          <div className="form-row">
            <label>Amount, net of GST (SGD)</label>
            <input
              type="number"
              min="0.01"
              step="0.01"
              value={poAmount}
              onChange={(e) => setPoAmount(e.target.value)}
              required
            />
          </div>
          <button type="submit" disabled={!poSupplier}>
            Raise PO
          </button>
        </form>
      </div>
    </div>
  )
}
