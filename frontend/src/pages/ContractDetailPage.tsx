import AiChatPanel from '../components/AiChatPanel'
import { useEffect, useState, type FormEvent } from 'react'
import { Link, useParams } from 'react-router-dom'
import {
  api,
  type CompanyIndividual,
  type Contract,
  type ExcessUsageRecord,
  type Invoice,
  type LicenseDeploymentType,
  type Product,
  type Quotation,
  type StaffUser,
} from '../lib/api'
import DocumentAttachmentsPanel from '../components/DocumentAttachmentsPanel'
import SignaturePanel from '../components/SignaturePanel'
import { formatMoney as money, formatDate } from '../lib/format'

const LICENSE_TYPE_LABEL: Record<LicenseDeploymentType, string> = {
  local: 'Local',
  rdp: 'RDP',
  web: 'Web',
}

export default function ContractDetailPage() {
  const { id } = useParams<{ id: string }>()
  const [contract, setContract] = useState<Contract | null>(null)
  const [excessUsage, setExcessUsage] = useState<ExcessUsageRecord[]>([])
  const [invoices, setInvoices] = useState<Invoice[]>([])
  const [staff, setStaff] = useState<StaffUser[]>([])
  const [products, setProducts] = useState<Product[]>([])
  const [customers, setCustomers] = useState<CompanyIndividual[]>([])
  const [error, setError] = useState<string | null>(null)
  const [message, setMessage] = useState<string | null>(null)
  const [renewHours, setRenewHours] = useState('10')
  const [renewValue, setRenewValue] = useState('2400')
  const [renewRate, setRenewRate] = useState('150')
  const [renewQuotationId, setRenewQuotationId] = useState('')

  // Contract <-> Sales Quotation, a real link since 2026-09-15: the
  // customer's quotations for the picker, and the two actions.
  const [customerQuotations, setCustomerQuotations] = useState<Quotation[]>([])
  const [creatingRenewalQuotation, setCreatingRenewalQuotation] = useState(false)
  const [sharedCustomerToAdd, setSharedCustomerToAdd] = useState('')
  const [savingSharedCustomer, setSavingSharedCustomer] = useState(false)

  const [editingCoverage, setEditingCoverage] = useState(false)
  const [salesStaffId, setSalesStaffId] = useState('')
  const [productIds, setProductIds] = useState<string[]>([])
  const [saving, setSaving] = useState(false)

  // License tracking (2026-09-12) drafts, keyed by product_id -- kept
  // separate from the coverage editor above since saving one product's
  // license fields shouldn't require re-submitting the whole coverage list.
  const [licenseDrafts, setLicenseDrafts] = useState<Record<string, { type: string; count: string }>>({})
  const [savingLicenseFor, setSavingLicenseFor] = useState<string | null>(null)

  function refresh() {
    if (!id) return
    api.getContract(id).then((c) => {
      setContract(c)
      setSalesStaffId(c.sales_staff_id ?? '')
      setProductIds(c.products.map((p) => p.product_id))
      setLicenseDrafts(
        Object.fromEntries(
          c.products.map((p) => [
            p.product_id,
            { type: p.license_type ?? '', count: p.number_of_licenses != null ? String(p.number_of_licenses) : '' },
          ]),
        ),
      )
      api.listQuotations({ customer_id: c.customer_id }).then(setCustomerQuotations).catch(() => setCustomerQuotations([]))
    })
    api.listContractExcessUsage(id).then(setExcessUsage)
    api.listInvoices({ contract_id: id }).then(setInvoices)
    api.listStaff().then(setStaff)
    api.listCatalog().then(setProducts)
    api.listCompanyIndividuals().then(setCustomers)
  }

  useEffect(refresh, [id])

  async function onActivate() {
    if (!id) return
    setError(null)
    try {
      await api.activateContract(id)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to activate')
    }
  }

  async function onRenew(e: FormEvent) {
    e.preventDefault()
    if (!id || !contract) return
    setError(null)
    try {
      await api.renewContract(id, {
        contracted_hours: parseFloat(renewHours),
        contract_value_sgd: contract.contract_kind === 'ad_hoc' ? 0 : parseFloat(renewValue),
        hourly_rate_sgd: contract.contract_kind === 'ad_hoc' ? parseFloat(renewRate) : null,
        quotation_id: renewQuotationId || undefined,
      })
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to renew')
    }
  }

  async function onCreateRenewalQuotation() {
    if (!id) return
    setError(null)
    setMessage(null)
    setCreatingRenewalQuotation(true)
    try {
      const r = await api.createContractRenewalQuotation(id)
      setMessage(`New quotation ${r.quotation_number} generated as a draft from this contract -- price it on the Sales Quotation screen, then submit for approval. Accepting it renews this contract.`)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to create the renewal quotation')
    } finally {
      setCreatingRenewalQuotation(false)
    }
  }

  async function onAddSharedCustomer(e: FormEvent) {
    e.preventDefault()
    if (!id || !sharedCustomerToAdd) return
    setError(null)
    setSavingSharedCustomer(true)
    try {
      await api.addContractSharedCustomer(id, sharedCustomerToAdd)
      setSharedCustomerToAdd('')
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to add shared-hours customer')
    } finally {
      setSavingSharedCustomer(false)
    }
  }

  async function onRemoveSharedCustomer(sharedCustomerId: string) {
    if (!id) return
    setError(null)
    try {
      await api.removeContractSharedCustomer(id, sharedCustomerId)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to remove shared-hours customer')
    }
  }

  async function onSaveCoverage(e: FormEvent) {
    e.preventDefault()
    if (!id) return
    setError(null)
    setMessage(null)
    setSaving(true)
    try {
      await api.updateContract(id, { sales_staff_id: salesStaffId || null, product_ids: productIds })
      setMessage('Sales staff / product coverage updated.')
      setEditingCoverage(false)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to update')
    } finally {
      setSaving(false)
    }
  }

  async function onSaveLicense(productId: string) {
    if (!id) return
    const draft = licenseDrafts[productId]
    setError(null)
    setSavingLicenseFor(productId)
    try {
      await api.updateContractProductLicense(id, productId, {
        license_type: (draft?.type as LicenseDeploymentType) || null,
        number_of_licenses: draft?.count ? parseInt(draft.count, 10) : null,
      })
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to update license')
    } finally {
      setSavingLicenseFor(null)
    }
  }

  if (!contract) return <p>Loading...</p>

  const isAnnual = contract.contract_kind === 'annual'
  const isAdHoc = contract.contract_kind === 'ad_hoc'
  const isHourMetered = contract.contract_kind === 'service_support'
  const pctUsed = isHourMetered ? Math.min(100, (contract.consumed_hours / contract.contracted_hours) * 100) : 0
  const staffName = (uid: string | null) => (uid ? staff.find((s) => s.id === uid)?.full_name ?? uid.slice(0, 8) : null)
  const kindLabel = isAdHoc ? 'Ad Hoc Rate (billed as you go)' : isAnnual ? 'Annual (time coverage)' : 'Service Support (deduct hrs)'

  const client = customers.find((c) => c.id === contract.customer_id)

  return (
    <div>
      <h1>Contract {contract.contract_number}</h1>
      <p>
        <span className={`badge ${contract.status}`}>{contract.status}</span>{' '}
        <span className="muted">
          {kindLabel} &middot; {contract.start_date} &rarr; {contract.end_date}
        </span>
      </p>
      {/* The client, always in the header (Dennis, 2026-09-15). */}
      <div className="card" style={{ padding: '12px 16px' }}>
        <div style={{ display: 'flex', gap: 24, flexWrap: 'wrap', alignItems: 'baseline' }}>
          <div>
            <div className="muted" style={{ fontSize: 12, textTransform: 'uppercase' }}>Client</div>
            <Link to={`/company-individuals/${contract.customer_id}`}>
              <strong>{client?.name ?? contract.customer_id}</strong>
            </Link>
          </div>
          {client?.contact_person && (
            <div>
              <div className="muted" style={{ fontSize: 12, textTransform: 'uppercase' }}>Contact person</div>
              {client.contact_person}
            </div>
          )}
          {(client?.phone || client?.mobile) && (
            <div>
              <div className="muted" style={{ fontSize: 12, textTransform: 'uppercase' }}>Phone</div>
              {[client.phone, client.mobile].filter(Boolean).join(' / ')}
            </div>
          )}
          {client?.billing_email && (
            <div>
              <div className="muted" style={{ fontSize: 12, textTransform: 'uppercase' }}>Email</div>
              {client.billing_email}
            </div>
          )}
          {client && (client.address_line1 || client.address_city || client.address_country) && (
            <div>
              <div className="muted" style={{ fontSize: 12, textTransform: 'uppercase' }}>Address</div>
              {[client.address_line1, client.address_line2, client.address_city, client.address_state, client.address_postal_code, client.address_country]
                .filter(Boolean)
                .join(', ')}
            </div>
          )}
        </div>
      </div>
      {error && <div className="error-banner">{error}</div>}
      <AiChatPanel context={{ type: 'contract', id: contract.id, label: `contract ${contract.contract_number}` }} />
      {message && (
        <p className="muted" style={{ marginBottom: 12 }}>
          {message}
        </p>
      )}

      <div className="card">
        {isAdHoc ? (
          <>
            <h2>Ad Hoc Rate (no pre-paid hours or value)</h2>
            <p className="muted">
              Job Orders and Service Records can still be logged against it, for history -- nothing
              is deducted, exceeded, or auto-invoiced. Bill manually off the reference rate below.
            </p>
            <p>
              <strong>Reference rate: {contract.hourly_rate_sgd != null ? money(contract.hourly_rate_sgd) : '-'}/hr</strong>
            </p>
          </>
        ) : isAnnual ? (
          <>
            <h2>Time Coverage (no hours)</h2>
            <p className="muted">
              An Annual contract has a term and a value but no contracted hours -- Job Orders and
              Service Records can still be logged against it, they just aren't deducted from
              anything.
            </p>
            <p>
              <strong>Contract value: {money(contract.contract_value_sgd)}</strong>
            </p>
          </>
        ) : (
          <>
            <h2>Hours (SRV-001 / SRV-002 / SRV-004)</h2>
            <div className="progress-bar">
              <div style={{ width: `${pctUsed}%` }} />
            </div>
            <p>
              {contract.consumed_hours.toFixed(2)} used / {contract.contracted_hours.toFixed(2)} contracted --{' '}
              <strong>{contract.remaining_hours.toFixed(2)} hrs remaining</strong> (never goes negative)
            </p>
            <p className="muted">
              Contract value: {money(contract.contract_value_sgd)} -- blended excess rate:{' '}
              {money(contract.contract_value_sgd / contract.contracted_hours)}/hr (SRV-008)
            </p>
          </>
        )}

        {contract.status === 'draft' && <button onClick={onActivate}>Activate contract</button>}
        <p style={{ marginTop: 10 }}>
          <Link to={`/job-orders?contract=${contract.id}`}>View job orders for this contract</Link>
        </p>
      </div>

      <div className="card">
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
          <h2>Sales staff &amp; Product coverage</h2>
          {!editingCoverage && (
            <button type="button" className="secondary" onClick={() => setEditingCoverage(true)}>
              Edit
            </button>
          )}
        </div>
        {editingCoverage ? (
          <form onSubmit={onSaveCoverage}>
            <div className="form-row">
              <label>Sales staff</label>
              <select value={salesStaffId} onChange={(e) => setSalesStaffId(e.target.value)}>
                <option value="">Unassigned</option>
                {staff.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.full_name}
                  </option>
                ))}
              </select>
            </div>
            <div className="form-row">
              <label>Product coverage (ctrl/cmd-click for more than one)</label>
              <select
                multiple
                size={Math.min(6, Math.max(3, products.length))}
                value={productIds}
                onChange={(e) => setProductIds(Array.from(e.target.selectedOptions, (o) => o.value))}
              >
                {products.map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.name}
                  </option>
                ))}
              </select>
            </div>
            <div style={{ display: 'flex', gap: 8 }}>
              <button type="submit" disabled={saving}>
                {saving ? 'Saving...' : 'Save'}
              </button>
              <button type="button" className="secondary" onClick={() => setEditingCoverage(false)}>
                Cancel
              </button>
            </div>
          </form>
        ) : (
          <>
            <p>
              <strong>Sales staff:</strong> {staffName(contract.sales_staff_id) ?? <span className="muted">Unassigned</span>}
            </p>
            <p style={{ marginBottom: 6 }}>
              <strong>Products covered:</strong>
            </p>
            {contract.products.length > 0 ? (
              <div style={{ overflowX: 'auto' }}>
                <table>
                  <thead>
                    <tr>
                      <th>Product</th>
                      <th>License type</th>
                      <th>No. of licenses</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    {contract.products.map((p) => {
                      const draft = licenseDrafts[p.product_id] ?? { type: '', count: '' }
                      return (
                        <tr key={p.product_id}>
                          <td>{p.product_name}</td>
                          <td>
                            <select
                              value={draft.type}
                              onChange={(e) =>
                                setLicenseDrafts((prev) => ({
                                  ...prev,
                                  [p.product_id]: { ...draft, type: e.target.value },
                                }))
                              }
                            >
                              <option value="">Not a licensed item</option>
                              {(Object.keys(LICENSE_TYPE_LABEL) as LicenseDeploymentType[]).map((t) => (
                                <option key={t} value={t}>
                                  {LICENSE_TYPE_LABEL[t]}
                                </option>
                              ))}
                            </select>
                          </td>
                          <td>
                            <input
                              type="number"
                              min="1"
                              value={draft.count}
                              onChange={(e) =>
                                setLicenseDrafts((prev) => ({
                                  ...prev,
                                  [p.product_id]: { ...draft, count: e.target.value },
                                }))
                              }
                              style={{ width: 70 }}
                            />
                          </td>
                          <td>
                            <button
                              type="button"
                              className="secondary"
                              onClick={() => onSaveLicense(p.product_id)}
                              disabled={savingLicenseFor === p.product_id}
                            >
                              {savingLicenseFor === p.product_id ? 'Saving...' : 'Save'}
                            </button>
                          </td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              </div>
            ) : (
              <p className="muted">None specified</p>
            )}
          </>
        )}
      </div>

      {(contract.status === 'exceeded' || contract.status === 'expired') && (
        <div className="card">
          <h2>Renew (SRV-010 / SRV-016)</h2>
          <p className="muted">
            Creates a new contract record of the same kind -- product coverage and sales staff carry
            forward automatically. Backdated seamlessly if renewed within 2 weeks of expiry.
          </p>
          <form onSubmit={onRenew}>
            {isHourMetered && (
              <div className="form-row">
                <label>New contracted hours</label>
                <input
                  type="number"
                  min={10}
                  step={0.5}
                  value={renewHours}
                  onChange={(e) => setRenewHours(e.target.value)}
                />
              </div>
            )}
            {isAdHoc ? (
              <div className="form-row">
                <label>New reference hourly rate (SGD/hr)</label>
                <input
                  type="number"
                  min={0}
                  step={0.5}
                  value={renewRate}
                  onChange={(e) => setRenewRate(e.target.value)}
                />
              </div>
            ) : (
              <div className="form-row">
                <label>New contract value (SGD)</label>
                <input
                  type="number"
                  min={0}
                  value={renewValue}
                  onChange={(e) => setRenewValue(e.target.value)}
                />
              </div>
            )}
            <div className="form-row">
              <label>Sales Quotation this renewal is from (optional)</label>
              <select value={renewQuotationId} onChange={(e) => setRenewQuotationId(e.target.value)}>
                <option value="">-- none --</option>
                {customerQuotations.map((q) => (
                  <option key={q.id} value={q.id}>
                    {q.quotation_number} · {q.status} · {money(q.total_amount_sgd)}
                  </option>
                ))}
              </select>
              <span className="muted">
                Renewing here is the manual path. If a renewal quotation was raised from this contract
                below, accepting that quotation renews it automatically instead.
              </span>
            </div>
            <button type="submit">Renew</button>
          </form>
        </div>
      )}

      <div className="card">
        <h2>Renewal quotation</h2>
        <p className="muted" style={{ marginTop: -6 }}>
          When this contract is coming due -- its date within 30 days of expiry, or its hours
          finishing -- generate its renewal as a new Sales Quotation carrying the current terms.
          It goes through approval and sending like any quotation; accepting it renews this
          contract (SRV-010 / SRV-016 apply exactly as they do to Renew above).
        </p>

        {contract.renewal_quotation_id ? (
          <p>
            Renewal quotation{' '}
            <Link to={`/quotations/${contract.renewal_quotation_id}/print`}>
              <strong>{contract.renewal_quotation_number}</strong>
            </Link>{' '}
            <span className="badge draft">{contract.renewal_quotation_status}</span>
            <span className="muted"> -- accepting it renews this contract.</span>
          </p>
        ) : contract.renewal_quotation_eligible ? (
          <>
            <p>
              <span className="badge exceeded">Coming due</span>{' '}
              <span className="muted">{contract.renewal_due_reason}</span>
            </p>
            <button type="button" disabled={creatingRenewalQuotation} onClick={onCreateRenewalQuotation}>
              {creatingRenewalQuotation ? 'Generating...' : 'Generate new quotation'}
            </button>
          </>
        ) : (
          <p className="muted">
            {contract.status === 'renewed'
              ? 'Already renewed.'
              : contract.contract_kind === 'ad_hoc'
                ? 'An Ad Hoc Rate contract has no upfront value to quote -- it is renewed directly.'
                : 'Not coming due yet: a new quotation can be generated once this contract is within 30 days of expiry, its hours are finishing, or it has expired.'}
          </p>
        )}

        {contract.quotation_id && (
          <p className="muted" style={{ marginTop: 12 }}>
            This contract came from quotation{' '}
            <Link to={`/quotations/${contract.quotation_id}/print`}>{contract.quotation_number}</Link>.
          </p>
        )}
        {contract.quotation_reference && !contract.quotation_id && (
          <p className="muted" style={{ marginTop: 12 }}>
            Quotation reference recorded earlier: <strong>{contract.quotation_reference}</strong>
            {contract.quotation_reference_set_at && <> -- set {formatDate(contract.quotation_reference_set_at)}</>}
          </p>
        )}
      </div>

      <div className="card">
        <h2>Sharing of Hours</h2>
        <p className="muted" style={{ marginTop: -6 }}>
          Other companies / individuals allowed to draw down this contract's pooled hours when
          opening a Job Order -- independent of any CompanyIndividual Relationship record.
        </p>
        <table>
          <thead>
            <tr>
              <th>Company / Individual</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {contract.shared_customers.map((sc) => (
              <tr key={sc.id}>
                <td>{sc.customer_name}</td>
                <td>
                  <button type="button" className="secondary" onClick={() => onRemoveSharedCustomer(sc.id)}>
                    Remove
                  </button>
                </td>
              </tr>
            ))}
            {contract.shared_customers.length === 0 && (
              <tr>
                <td colSpan={2} className="muted">
                  None -- only this contract's own Company / Individual can open Job Orders against it.
                </td>
              </tr>
            )}
          </tbody>
        </table>
        <form onSubmit={onAddSharedCustomer} className="form-row" style={{ margin: 0, marginTop: 10 }}>
          <select value={sharedCustomerToAdd} onChange={(e) => setSharedCustomerToAdd(e.target.value)}>
            <option value="">Select a company / individual to add...</option>
            {customers
              .filter(
                (c) => c.id !== contract.customer_id && !contract.shared_customers.some((sc) => sc.customer_id === c.id),
              )
              .map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
          </select>
          <button type="submit" className="secondary" disabled={savingSharedCustomer || !sharedCustomerToAdd}>
            {savingSharedCustomer ? 'Adding...' : 'Add to shared-hours list'}
          </button>
        </form>
      </div>

      <div className="card">
        <h2>Excess usage (SRV-003 / SRV-004 / SRV-013)</h2>
        <table>
          <thead>
            <tr>
              <th>Excess hours</th>
              <th>Treatment</th>
              <th>Reason</th>
              <th>Invoiced</th>
            </tr>
          </thead>
          <tbody>
            {excessUsage.map((r) => (
              <tr key={r.id}>
                <td>{r.excess_hours.toFixed(2)}</td>
                <td>{r.treatment ?? <span className="muted">awaiting review</span>}</td>
                <td>{r.reason ?? '-'}</td>
                <td>{r.invoiced ? 'Yes' : 'No'}</td>
              </tr>
            ))}
            {excessUsage.length === 0 && (
              <tr>
                <td colSpan={4} className="muted">
                  None yet.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      <div className="card">
        <h2>Invoices (BILL-001 / BILL-002 / BILL-005)</h2>
        <table>
          <thead>
            <tr>
              <th>Type</th>
              <th>Description</th>
              <th>Amount (SGD)</th>
              <th>Issued</th>
            </tr>
          </thead>
          <tbody>
            {invoices.map((inv) => (
              <tr key={inv.id}>
                <td>{inv.invoice_type}</td>
                <td>{inv.description}</td>
                <td>{money(inv.amount_sgd)}</td>
                <td>{formatDate(inv.issued_at)}</td>
              </tr>
            ))}
            {invoices.length === 0 && (
              <tr>
                <td colSpan={4} className="muted">
                  None yet.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      {id && (
        <div className="card">
          <DocumentAttachmentsPanel entityType="contract" entityId={id} />
          <SignaturePanel entityType="contract" entityId={id} />
        </div>
      )}
    </div>
  )
}
