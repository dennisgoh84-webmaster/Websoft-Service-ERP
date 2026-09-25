import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api, type Invoice } from '../lib/api'
import { formatDate } from '../lib/format'

/**
 * A Company / Individual's invoices, newest first, with the ones brought
 * in from ODOO / ZSOFT by Data Migration marked "Migrated" (history only,
 * no General Ledger posting). Shown on the Company / Individual record
 * and on the Prospect (CRM) screens for the same party (Dennis,
 * 2026-09-25: past invoices "attached to company / individual and
 * Prospect Module"). Hidden for anyone without Billing access.
 */
export default function InvoiceHistoryPanel({ customerId, title = 'Invoice history' }: { customerId: string; title?: string }) {
  const [invoices, setInvoices] = useState<Invoice[] | null>(null)

  useEffect(() => {
    if (!customerId) return
    api
      .listInvoices({ customer_id: customerId })
      .then((rows) => setInvoices([...rows].sort((a, b) => (b.issued_at ?? '').localeCompare(a.issued_at ?? ''))))
      .catch(() => setInvoices(null))
  }, [customerId])

  if (invoices === null) return null
  const migrated = invoices.filter((i) => i.migrated).length

  return (
    <div className="card">
      <h2>
        {title} ({invoices.length})
      </h2>
      {migrated > 0 && (
        <p className="muted">
          {migrated} migrated from the old system as history: they are shown for reference and post nothing to the General Ledger.
        </p>
      )}
      {invoices.length === 0 ? (
        <p className="muted">No invoices yet.</p>
      ) : (
        <div style={{ overflowX: 'auto' }}>
          <table>
            <thead>
              <tr>
                <th>Invoice</th>
                <th>Date</th>
                <th>Description</th>
                <th style={{ textAlign: 'right' }}>Total (SGD)</th>
                <th style={{ textAlign: 'right' }}>Outstanding (SGD)</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              {invoices.slice(0, 50).map((i) => (
                <tr key={i.id}>
                  <td>
                    <Link to={`/invoices/${i.id}/print`}>{i.invoice_number}</Link>
                    {i.migrated && (
                      <>
                        {' '}
                        <span className="badge draft">Migrated</span>
                      </>
                    )}
                  </td>
                  <td>{formatDate(i.issued_at)}</td>
                  <td>{i.description}</td>
                  <td style={{ textAlign: 'right' }}>{i.total_amount_sgd.toLocaleString('en-SG', { minimumFractionDigits: 2 })}</td>
                  <td style={{ textAlign: 'right' }}>{i.outstanding_sgd.toLocaleString('en-SG', { minimumFractionDigits: 2 })}</td>
                  <td>{i.status.replace('_', ' ')}</td>
                </tr>
              ))}
            </tbody>
          </table>
          {invoices.length > 50 && <p className="muted small">Showing the latest 50 of {invoices.length}. The Invoices screen lists them all.</p>}
        </div>
      )}
    </div>
  )
}
