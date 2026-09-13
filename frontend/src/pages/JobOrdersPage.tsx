import { useEffect, useState, type FormEvent } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import ExportControl from '../components/ExportControl'
import { api, downloadBlob, type Contract, type CompanyIndividual, type JobOrder, type JobOrderPriority, type JobOrderType } from '../lib/api'

export default function JobOrdersPage() {
  const [jobOrders, setJobOrders] = useState<JobOrder[]>([])
  const [customers, setCustomers] = useState<CompanyIndividual[]>([])
  const [contracts, setContracts] = useState<Contract[]>([])
  const [searchParams] = useSearchParams()
  const preselectedContract = searchParams.get('contract') ?? ''

  const [customerId, setCustomerId] = useState('')
  const [contractId, setContractId] = useState('')
  const [subject, setSubject] = useState('')
  const [jobOrderType, setJobOrderType] = useState<JobOrderType>('support')
  const [priority, setPriority] = useState<JobOrderPriority>('normal')
  const [dueDate, setDueDate] = useState('')
  const [isUrgent, setIsUrgent] = useState(false)
  const [error, setError] = useState<string | null>(null)

  // Dynamic filters
  const [filterStatus, setFilterStatus] = useState(searchParams.get('status') ?? '')
  const [filterPriority, setFilterPriority] = useState('')
  const [filterCompanyIndividual, setFilterCompanyIndividual] = useState('')
  const [filterContract, setFilterContract] = useState(preselectedContract)
  const [filterType, setFilterType] = useState('')

  function refresh() {
    api
      .listJobOrders({
        status: filterStatus || undefined,
        priority: filterPriority || undefined,
        customer_id: filterCompanyIndividual || undefined,
        contract_id: filterContract || undefined,
        job_order_type: filterType || undefined,
      })
      .then(setJobOrders)
    api.listCompanyIndividuals().then(setCustomers)
    api.listContracts().then(setContracts)
  }

  useEffect(refresh, [filterStatus, filterPriority, filterCompanyIndividual, filterContract, filterType])

  const customerName = (id: string) => customers.find((c) => c.id === id)?.name ?? id.slice(0, 8)
  const contractsForCompanyIndividual = contracts.filter((c) => c.customer_id === customerId)

  async function onCreate(e: FormEvent) {
    e.preventDefault()
    setError(null)
    try {
      await api.createJobOrder({
        customer_id: customerId,
        contract_id: contractId,
        subject,
        job_order_type: jobOrderType,
        priority,
        due_date: dueDate || undefined,
        is_urgent: isUrgent,
      })
      setSubject('')
      setJobOrderType('support')
      setDueDate('')
      setIsUrgent(false)
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to create job order')
    }
  }

  function resetFilters() {
    setFilterStatus('')
    setFilterPriority('')
    setFilterCompanyIndividual('')
    setFilterContract('')
    setFilterType('')
  }

  async function onExport(format: string) {
    setError(null)
    const filters = {
      status: filterStatus || undefined,
      priority: filterPriority || undefined,
      customer_id: filterCompanyIndividual || undefined,
      contract_id: filterContract || undefined,
    }
    if (format === 'csv') {
      downloadBlob(await api.exportJobOrdersCsv(filters), 'job-orders.csv')
    } else {
      downloadBlob(await api.exportJobOrdersExcel(filters), 'job-orders.xlsx')
    }
  }

  return (
    <div>
      <h1>Job Orders</h1>
      <p className="muted">CompanyIndividual &rarr; Job Order &rarr; Assignment &rarr; Service Work</p>

      <div className="card" style={{ marginTop: 20 }}>
        <h2>New job order</h2>
        <form onSubmit={onCreate}>
          <div className="form-row">
            <label>Company / Individual</label>
            <select
              value={customerId}
              onChange={(e) => {
                setCustomerId(e.target.value)
                setContractId('')
              }}
              required
            >
              <option value="">Select...</option>
              {customers.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </select>
          </div>
          <div className="form-row">
            <label>Contract</label>
            <select value={contractId} onChange={(e) => setContractId(e.target.value)} required>
              <option value="">Select...</option>
              {contractsForCompanyIndividual.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.status} -- {c.remaining_hours.toFixed(1)}h remaining
                </option>
              ))}
            </select>
          </div>
          <div className="form-row">
            <label>Subject</label>
            <input value={subject} onChange={(e) => setSubject(e.target.value)} required />
          </div>
          <div className="form-row">
            <label>Type</label>
            <select value={jobOrderType} onChange={(e) => setJobOrderType(e.target.value as JobOrderType)}>
              <option value="support">Support (ad-hoc)</option>
              <option value="project">Project (milestone schedule)</option>
            </select>
          </div>
          <div className="form-row">
            <label>Priority</label>
            <select value={priority} onChange={(e) => setPriority(e.target.value as JobOrderPriority)}>
              <option value="low">Low</option>
              <option value="normal">Normal</option>
              <option value="high">High</option>
              <option value="critical">Critical</option>
            </select>
          </div>
          <div className="form-row">
            <label>Due date (optional, as agreed with Support)</label>
            <input type="date" value={dueDate} onChange={(e) => setDueDate(e.target.value)} />
          </div>
          <div className="form-row">
            <label>
              <input
                type="checkbox"
                checked={isUrgent}
                onChange={(e) => setIsUrgent(e.target.checked)}
                style={{ marginRight: 6 }}
              />
              Urgent (x1.5 suggested deduction rate)
            </label>
          </div>
          {error && <div className="error-banner">{error}</div>}
          <button type="submit" disabled={!customerId || !contractId}>
            Create job order
          </button>
        </form>
      </div>

      <div className="card">
        <div className="filter-bar">
          <div className="form-row" style={{ margin: 0 }}>
            <label>Status</label>
            <select value={filterStatus} onChange={(e) => setFilterStatus(e.target.value)}>
              <option value="">All</option>
              <option value="open">Open</option>
              <option value="assigned">Assigned</option>
              <option value="closed">Closed</option>
              <option value="void">Void</option>
            </select>
          </div>
          <div className="form-row" style={{ margin: 0 }}>
            <label>Priority</label>
            <select value={filterPriority} onChange={(e) => setFilterPriority(e.target.value)}>
              <option value="">All</option>
              <option value="low">Low</option>
              <option value="normal">Normal</option>
              <option value="high">High</option>
              <option value="critical">Critical</option>
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
            <label>Contract</label>
            <select value={filterContract} onChange={(e) => setFilterContract(e.target.value)}>
              <option value="">All</option>
              {contracts.map((c) => (
                <option key={c.id} value={c.id}>
                  {customerName(c.customer_id)} -- {c.status}
                </option>
              ))}
            </select>
          </div>
          <div className="form-row" style={{ margin: 0 }}>
            <label>Type</label>
            <select value={filterType} onChange={(e) => setFilterType(e.target.value)}>
              <option value="">All</option>
              <option value="support">Support</option>
              <option value="project">Project</option>
            </select>
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

        <h2>Job orders ({jobOrders.length})</h2>
        <table>
          <thead>
            <tr>
              <th>Number</th>
              <th>Subject</th>
              <th>Type</th>
              <th>Company / Individual</th>
              <th>Priority</th>
              <th>Status</th>
              <th>Due</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {jobOrders.map((t) => {
              const overdue =
                !!t.due_date && t.due_date < new Date().toISOString().slice(0, 10) && t.status !== 'closed' && t.status !== 'void'
              return (
                <tr key={t.id}>
                  <td className="muted">{t.job_order_number}</td>
                  <td>
                    {t.subject}
                    {t.is_urgent && <span className="badge exceeded" style={{ marginLeft: 6 }}>URGENT</span>}
                  </td>
                  <td>
                    {t.job_order_type === 'project' ? (
                      <span className="badge active">PROJECT</span>
                    ) : (
                      <span className="muted">Support</span>
                    )}
                  </td>
                  <td>{customerName(t.customer_id)}</td>
                  <td>{t.priority}</td>
                  <td>{t.status}</td>
                  <td>
                    {t.due_date ? (
                      overdue ? (
                        <span className="badge exceeded">{t.due_date}</span>
                      ) : (
                        t.due_date
                      )
                    ) : (
                      <span className="muted">-</span>
                    )}
                  </td>
                  <td style={{ display: 'flex', gap: 10 }}>
                    <Link to={`/job-orders/${t.id}`}>Open</Link>
                    <Link to={`/job-orders/${t.id}/print`}>Print</Link>
                  </td>
                </tr>
              )
            })}
            {jobOrders.length === 0 && (
              <tr>
                <td colSpan={8} className="muted">
                  No job orders match these filters.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  )
}
