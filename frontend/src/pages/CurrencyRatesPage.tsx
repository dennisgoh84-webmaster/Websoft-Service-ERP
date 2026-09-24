// Currency Rate Table -- setup data only. Nothing in the app converts
// an amount using these rates yet (single-currency SGD per CLAUDE.md);
// this is where rates would be maintained ahead of that.
import { useEffect, useState, type FormEvent } from 'react'
import { api, type CurrencyRate } from '../lib/api'
import { formatDate } from '../lib/format'

export default function CurrencyRatesPage() {
  const [rates, setRates] = useState<CurrencyRate[]>([])
  const [error, setError] = useState<string | null>(null)

  const [currencyCode, setCurrencyCode] = useState('')
  const [rateToBase, setRateToBase] = useState('')
  const [effectiveDate, setEffectiveDate] = useState('')
  const [creating, setCreating] = useState(false)

  function refresh() {
    api.listCurrencyRates().then(setRates).catch((e) => setError(e.message))
  }

  useEffect(refresh, [])

  async function onCreate(e: FormEvent) {
    e.preventDefault()
    setError(null)
    setCreating(true)
    try {
      await api.createCurrencyRate({
        currency_code: currencyCode.toUpperCase(),
        rate_to_base: Number(rateToBase),
        effective_date: effectiveDate,
      })
      setCurrencyCode('')
      setRateToBase('')
      setEffectiveDate('')
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to add rate')
    } finally {
      setCreating(false)
    }
  }

  async function onToggleActive(rate: CurrencyRate) {
    setError(null)
    try {
      await api.updateCurrencyRate(rate.id, { is_active: !rate.is_active })
      refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to update rate')
    }
  }

  return (
    <div>
      <h1>Currency Rate Table</h1>
      <p className="muted">
        Rate to SGD (the company's base currency) as at a given date -- setup data only. Nothing in
        the app converts an amount using these rates yet; the ERP is single-currency (SGD) per the
        approved architecture, so this is set up ahead of any multi-currency decision rather than in
        support of one.
      </p>
      {error && <div className="error-banner">{error}</div>}

      <div className="card">
        <h2>Rates ({rates.length})</h2>
        <table>
          <thead>
            <tr>
              <th>Currency</th>
              <th>Rate to SGD</th>
              <th>Effective date</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {rates.map((r) => (
              <tr key={r.id} style={{ opacity: r.is_active ? 1 : 0.6 }}>
                <td>{r.currency_code}</td>
                <td>{r.rate_to_base}</td>
                <td>{formatDate(r.effective_date)}</td>
                <td>
                  <span className={`badge ${r.is_active ? 'active' : 'draft'}`}>
                    {r.is_active ? 'Active' : 'Retired'}
                  </span>
                </td>
                <td>
                  <button className="secondary" onClick={() => onToggleActive(r)}>
                    {r.is_active ? 'Retire' : 'Reinstate'}
                  </button>
                </td>
              </tr>
            ))}
            {rates.length === 0 && (
              <tr>
                <td colSpan={5} className="muted">
                  No rates set up yet.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      <div className="card">
        <h2>Add a rate</h2>
        <form onSubmit={onCreate}>
          <div className="form-row">
            <label>Currency code</label>
            <input
              value={currencyCode}
              onChange={(e) => setCurrencyCode(e.target.value)}
              placeholder="e.g. USD"
              maxLength={3}
              required
            />
          </div>
          <div className="form-row">
            <label>Rate to SGD</label>
            <input
              type="number"
              step="0.000001"
              min={0}
              value={rateToBase}
              onChange={(e) => setRateToBase(e.target.value)}
              placeholder="e.g. 1.35"
              required
            />
          </div>
          <div className="form-row">
            <label>Effective date</label>
            <input type="date" value={effectiveDate} onChange={(e) => setEffectiveDate(e.target.value)} required />
          </div>
          <button type="submit" disabled={creating}>
            {creating ? 'Adding...' : 'Add rate'}
          </button>
        </form>
      </div>
    </div>
  )
}
