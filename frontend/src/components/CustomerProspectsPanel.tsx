import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api, PROSPECT_BADGE, PROSPECT_STATUSES, type Prospect } from '../lib/api'
import { formatMoney } from '../lib/format'

/** A Company / Individual's prospects, on its own page. Renders nothing for a user without Prospect / Leads. */
export default function CustomerProspectsPanel({ customerId }: { customerId: string }) {
  const [prospects, setProspects] = useState<Prospect[] | null>(null)

  useEffect(() => {
    api.listProspects({ customer_id: customerId }).then(setProspects).catch(() => setProspects(null))
  }, [customerId])

  if (prospects === null) return null

  return (
    <div className="card">
      <h2>Prospects ({prospects.length})</h2>
      <p>
        <Link to={`/prospects?customer_id=${customerId}`}>New prospect for this Company / Individual</Link>
      </p>
      {prospects.length > 0 && (
        <div style={{ overflowX: 'auto' }}>
          <table>
            <thead>
              <tr>
                <th>Prospect</th>
                <th>Status</th>
                <th>Salesperson</th>
                <th>Estimated</th>
                <th>Quoted</th>
                <th>Billed</th>
                <th>Paid</th>
                <th>Outstanding</th>
              </tr>
            </thead>
            <tbody>
              {prospects.map((p) => (
                <tr key={p.id}>
                  <td>
                    <Link to={`/prospects/${p.id}`}>
                      {p.prospect_number} — {p.title}
                    </Link>
                  </td>
                  <td>
                    <span className={`badge ${PROSPECT_BADGE[p.status]}`}>
                      {PROSPECT_STATUSES.find((s) => s.value === p.status)?.label}
                    </span>
                  </td>
                  <td>{p.salesperson_name}</td>
                  <td>{p.estimated_value_sgd === null ? '—' : formatMoney(p.estimated_value_sgd)}</td>
                  <td>{formatMoney(p.quoted_amount_sgd)}</td>
                  <td>{formatMoney(p.billed_amount_sgd)}</td>
                  <td>{formatMoney(p.paid_amount_sgd)}</td>
                  <td>{formatMoney(p.outstanding_amount_sgd)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
