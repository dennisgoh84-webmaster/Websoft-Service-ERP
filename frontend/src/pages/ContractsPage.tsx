import { useEffect, useState, type FormEvent } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import ExportControl from '../components/ExportControl'
import {
  api,
  downloadBlob,
  type Contract,
  type ContractKind,
  type CompanyIndividual,
  type Product,
  type StaffUser,
} from '../lib/api'
import { isoToMonth, monthEndISO, monthStartISO } from '../lib/period'
import { formatMoney as money } from '../lib/format'

const KIND_LABELS: Record<ContractKind, string> = {
  service_support: 'Service Support (deduct hrs)',
  annual: 'Annual (time coverage)',
  ad_hoc: 'Ad Hoc Rate',
}

export default function ContractsPage() {
  const [contracts, setContracts] = useState<Contract[]>([])
  const [customers, setCustomers] = useState<CompanyIndividual[]>([])
  const [staff, setStaff] = useState<StaffUser[]>([])
  const [products, setProducts] = useState<Product[]>([])
  const [searchParams] = useSearchParams()
  const preselectedCompanyIndividual = searchParams.get('customer') ?? ''

  const [customerId, setCustomerId] = useState(preselectedCompanyIndividual)
  const [kind, setKind] = useState<ContractKind>('service_support')
  const [hours, setHours] = useState('10')
  const [value, setValue] = useState('2400')
  const [hourlyRate, setHourlyRate] = useState('150')
  const [startDate, setStartDate] = useState(new Date().toISOString().slice(0, 10))
  const [salesStaffId, setSalesStaffId] = useState('')
  const [productIds, setProductIds] = useState<string[]>([])
  const [error, setError] = useState<string | null>(null)

  const [filterStatus, setFilterStatus] = useState(searchParams.get('status') ?? '')
  const [filterCompanyIndividual, setFilterCompanyIndividual] = useState(preselectedCompanyIndividual)
  const [filterKind, setFilterKind] = useState<ContractKind | ''>('')
  const [filterSalesStaff, setFilterSalesStaff] = useState('')
  const [filterProduct, setFilterProduct] = useState('')
  const [filterCoverageStart, setFilterCoverageStart] = useState('')
  const [filterCoverageEnd, setFilterCoverageEnd] = useState('')
  // NEW FEATURE (not a Python->PHP conversion) -- see
  // docs/backlog.md / docs/planned-work.md: "Service Contract -
  // Require to be able to filter out less than (Flexible) Number of
  // Hours, require filter by Expiry Date Range".
  const [filterRemainingHoursLt, setFilterRemainingHoursLt] = useState('')
  const [filterExpiryFrom, setFilterExpiryFrom] = useState('')
  const [filterExpiryTo, setFilterExpiryTo] = useState('')

  function refresh() {
    api
      .listContracts({
        status: filterStatus || undefined,
        customer_id: filterCompanyIndividual || undefined,
        contract_kind: filterKind || undefined,
        sales_staff_id: filterSalesStaff || undefined,
        product_id: filterProduct || undefined,
        coverage_start: filterCoverageStart || undefined,
        coverage_end: filterCoverageEnd || undefined,
        remaining_hours_lt: filterRemainingHoursLt ? Number(filterRemainingHoursLt) : undefined,
        expiry_from: filterExpiryFrom || undefined,
        expiry_to: filterExpiryTo || undefined,
      })
      .then(setContracts)
    api.listCompanyIndividuals().then(setCustomers)
    api.listStaff().then(setStaff)
    api.listCatalog().then(setProducts)
  }

  useEffect(refresh, [
    filterStatus, filterCompanyIndividual, filterKind, filterSalesStaff, filterProduct,
    filterCoverageStart, filterCoverageEnd, filterRemainingHoursLt, filterExpiryFrom, filterExpiryTo,
  ])

  const customerName = (id: string) => customers.find((c) => c.id === id)?.name ?? id.slice(0, 8)
  const staffName = (id: string | null) => (id ? staff.find((s) => s.id === id)?.full_name ?? id.slice(0, 8) : '-')

  function resetFilters() {
    setFilterStatus('')
    setFilterCompanyIndividual('')
    setFilterKind('')
    setFilterSalesStaff('')
    setFilterProduct('')
    setFilterCoverageStart('')
    setFilterCoverageEnd('')
    setFilterRemainingHoursLt('')
    setFilterExpiryFrom('')
    setFilterExpiryTo('')
  }

  async function onCreate(e: FormEvent) {
    e.preventDefault()
    setError(null)
    try {
      await api.createContract({
        customer_id: customerId,
        contract_kind: kind,
        contracted_hours: kind === 'service_support' ? parseFloat(hours) : 0,
        contract_value_sgd: kind === 'ad_hoc' ? 0 : parseFloat(value),
        start_date: startDate,
        hourly_rate_sgd: kind === 'ad_hoc' ? parseFloat(hourlyRate) : null,
        sales_staff_id: salesStaffId || null,
        product_ids: productIds,
      })
      setProductIds([])
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to create contract')
    }
  }

  async function onExport(format: string) {
    setError(null)
    const filters = {
      status: filterStatus || undefined,
      customer_id: filterCompanyIndividual || undefined,
      contract_kind: filterKind || undefined,
      sales_staff_id: filterSalesStaff || undefined,
      product_id: filterProduct || undefined,
      coverage_start: filterCoverageStart || undefined,
      coverage_end: filterCoverageEnd || undefined,
      remaining_hours_lt: filterRemainingHoursLt || undefined,
      expiry_from: filterExpiryFrom || undefined,
      expiry_to: filterExpiryTo || undefined,
    }
    const blob = format === 'csv' ? await api.exportContractsCsv(filters) : await api.exportContractsExcel(filters)
    downloadBlob(blob, `contracts.${format === 'csv' ? 'csv' : 'xlsx'}`)
  }

  return (
    <div>
      <h1>Service Contracts</h1>
      <p className="muted">
        SRV-001: 12-month standard term. SRV-002/SRV-012: 10-hour hard minimum, no override.
        Contract Type decides the offset method: Service Support deducts hours, Annual is time
        coverage only (no hours), Ad Hoc Rate has neither -- work is billed as it happens off the
        contract's reference rate.
      </p>

      <div className="card" style={{ marginTop: 20 }}>
        <h2>New contract</h2>
        <form onSubmit={onCreate}>
          <div className="form-row">
            <label>Company / Individual</label>
            <select value={customerId} onChange={(e) => setCustomerId(e.target.value)} required>
              <option value="">Select a Company / Individual...</option>
              {customers.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </select>
          </div>
          <div className="form-row">
            <label>Contract Type</label>
            <select value={kind} onChange={(e) => setKind(e.target.value as ContractKind)}>
              <option value="service_support">Service Support -- deduct hrs</option>
              <option value="annual">Annual -- time coverage</option>
              <option value="ad_hoc">Ad Hoc Rate -- billed as you go</option>
            </select>
          </div>
          {kind === 'service_support' && (
            <div className="form-row">
              <label>Contracted hours (min 10)</label>
              <input type="number" min={0} step={0.5} value={hours} onChange={(e) => setHours(e.target.value)} />
            </div>
          )}
          {kind === 'ad_hoc' ? (
            <div className="form-row">
              <label>Reference hourly rate (SGD/hr)</label>
              <input
                type="number"
                min={0}
                step={0.5}
                value={hourlyRate}
                onChange={(e) => setHourlyRate(e.target.value)}
                required
              />
              <span className="muted">
                Nothing auto-bills off this -- it's the rate whoever invoices manually should use.
              </span>
            </div>
          ) : (
            <div className="form-row">
              <label>Contract value (SGD, billed annually upfront)</label>
              <input type="number" min={0} step={1} value={value} onChange={(e) => setValue(e.target.value)} />
            </div>
          )}
          <div className="form-row">
            <label>Start date</label>
            <input type="date" value={startDate} onChange={(e) => setStartDate(e.target.value)} />
          </div>
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
          {error && <div className="error-banner">{error}</div>}
          <button type="submit" disabled={!customerId}>
            Create contract (Draft)
          </button>
        </form>
      </div>

      <div className="card">
        <div className="filter-bar">
          <div className="form-row" style={{ margin: 0 }}>
            <label>Status</label>
            <select value={filterStatus} onChange={(e) => setFilterStatus(e.target.value)}>
              <option value="">All</option>
              <option value="draft">Draft</option>
              <option value="active">Active</option>
              <option value="exceeded">Exceeded</option>
              <option value="expired">Expired</option>
              <option value="renewed">Renewed</option>
            </select>
          </div>
          <div className="form-row" style={{ margin: 0 }}>
            <label>Company / Individual</label>
            <select value={filterCompanyIndividual} onChange={(e) => setFilterCompanyIndividual(e.target.value)}>
              <option value="">All</option>
              {customers.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </select>
          </div>
          <div className="form-row" style={{ margin: 0 }}>
            <label>Contract Type</label>
            <select value={filterKind} onChange={(e) => setFilterKind(e.target.value as ContractKind | '')}>
              <option value="">All</option>
              <option value="service_support">Service Support</option>
              <option value="annual">Annual</option>
              <option value="ad_hoc">Ad Hoc Rate</option>
            </select>
          </div>
          <div className="form-row" style={{ margin: 0 }}>
            <label>Sales staff</label>
            <select value={filterSalesStaff} onChange={(e) => setFilterSalesStaff(e.target.value)}>
              <option value="">All</option>
              {staff.map((s) => (
                <option key={s.id} value={s.id}>
                  {s.full_name}
                </option>
              ))}
            </select>
          </div>
          <div className="form-row" style={{ margin: 0 }}>
            <label>Product</label>
            <select value={filterProduct} onChange={(e) => setFilterProduct(e.target.value)}>
              <option value="">All</option>
              {products.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </select>
          </div>
          <div className="form-row" style={{ margin: 0 }}>
            <label>Period from</label>
            <input
              type="month"
              value={isoToMonth(filterCoverageStart)}
              onChange={(e) => setFilterCoverageStart(monthStartISO(e.target.value))}
            />
          </div>
          <div className="form-row" style={{ margin: 0 }}>
            <label>Period to</label>
            <input
              type="month"
              value={isoToMonth(filterCoverageEnd)}
              onChange={(e) => setFilterCoverageEnd(monthEndISO(e.target.value))}
            />
          </div>
          <div className="form-row" style={{ margin: 0 }}>
            <label>Remaining hours less than</label>
            <input
              type="number"
              min={0}
              step={0.5}
              style={{ width: 90 }}
              value={filterRemainingHoursLt}
              onChange={(e) => setFilterRemainingHoursLt(e.target.value)}
              placeholder="Any"
            />
          </div>
          <div className="form-row" style={{ margin: 0 }}>
            <label>Expiry from</label>
            <input type="date" value={filterExpiryFrom} onChange={(e) => setFilterExpiryFrom(e.target.value)} />
          </div>
          <div className="form-row" style={{ margin: 0 }}>
            <label>Expiry to</label>
            <input type="date" value={filterExpiryTo} onChange={(e) => setFilterExpiryTo(e.target.value)} />
          </div>
          <button type="button" className="secondary" onClick={resetFilters}>
            Reset filters
          </button>
          <ExportControl
            formats={[
              { value: 'csv', label: 'CSV' },
              { value: 'excel', label: 'Excel' },
            ]}
            onExport={onExport}
            onError={setError}
          />
        </div>

        <h2>Contracts ({contracts.length})</h2>
        <div className="report-table-wrap" style={{ overflowX: 'auto' }}>
          <table>
            <thead>
              <tr>
                <th>Number</th>
                <th>Company / Individual</th>
                <th>Type</th>
                <th>Status</th>
                <th>Hours (used / total)</th>
                <th>Sales staff</th>
                <th>Products</th>
                <th>Coverage</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {contracts.map((c) => (
                <tr key={c.id}>
                  <td className="muted">{c.contract_number}</td>
                  <td>{customerName(c.customer_id)}</td>
                  <td className="muted">{KIND_LABELS[c.contract_kind]}</td>
                  <td>
                    <span className={`badge ${c.status}`}>{c.status}</span>
                  </td>
                  <td>
                    {c.contract_kind === 'ad_hoc' ? (
                      <span className="muted">{c.hourly_rate_sgd != null ? money(c.hourly_rate_sgd) : '-'}/hr</span>
                    ) : c.contract_kind === 'annual' ? (
                      <span className="muted">not hour-tracked</span>
                    ) : (
                      `${c.consumed_hours.toFixed(2)} / ${c.contracted_hours.toFixed(2)} hrs`
                    )}
                  </td>
                  <td>{staffName(c.sales_staff_id)}</td>
                  <td className="muted">
                    {c.products.length > 0 ? c.products.map((p) => p.product_name).join(', ') : '-'}
                  </td>
                  <td>
                    {c.start_date} &rarr; {c.end_date}
                  </td>
                  <td>
                    <Link to={`/contracts/${c.id}`}>Open</Link>
                  </td>
                </tr>
              ))}
              {contracts.length === 0 && (
                <tr>
                  <td colSpan={9} className="muted">
                    No contracts match these filters.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  )
}
