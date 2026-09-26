// Currency and exchange rate on a document form (multi-currency,
// Dennis 2026-09-26): the currency starts as the Company / Individual's
// own default currency and can be changed; the rate starts as the
// Currency Rate Table's latest rate on or before the document date
// ("1 unit = X SGD") and can be changed. SGD needs no rate.
import { useEffect, useRef, useState } from 'react'
import { api } from '../lib/api'

export interface CurrencyValue {
  currency: string
  /** Blank until a rate is known; ignored for SGD. */
  rate: string
}

let currenciesPromise: Promise<string[]> | null = null
function loadCurrencies(): Promise<string[]> {
  currenciesPromise ??= api.listDocumentCurrencies().catch(() => ['SGD'])
  return currenciesPromise
}

export function currencyPayload(v: CurrencyValue): { currency_code: string; exchange_rate?: number } {
  return v.currency === 'SGD' || !v.rate.trim() ? { currency_code: v.currency } : { currency_code: v.currency, exchange_rate: Number(v.rate) }
}

export default function CurrencyFields({
  value,
  onChange,
  date,
  partyCurrency,
  idPrefix,
  disabled,
}: {
  value: CurrencyValue
  onChange: (v: CurrencyValue) => void
  /** The document date (YYYY-MM-DD) the rate is looked up for. */
  date: string
  /** The chosen Company / Individual's default currency, if any. */
  partyCurrency?: string | null
  idPrefix: string
  disabled?: boolean
}) {
  const [codes, setCodes] = useState<string[]>(['SGD'])
  const [note, setNote] = useState<string | null>(null)
  const rateTouched = useRef(false)
  const current = useRef(value)
  current.current = value

  useEffect(() => {
    loadCurrencies().then((c) => setCodes(c.length ? c : ['SGD']))
  }, [])

  // A newly picked Company / Individual brings its own currency.
  useEffect(() => {
    const next = (partyCurrency || 'SGD').toUpperCase()
    if (next !== current.current.currency) {
      rateTouched.current = false
      onChange({ currency: next, rate: '' })
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [partyCurrency])

  // The table's rate for the currency and date, unless one was keyed.
  useEffect(() => {
    const code = value.currency
    if (code === 'SGD') {
      setNote(null)
      return
    }
    if (rateTouched.current || !date) return
    let cancelled = false
    api
      .currencyRateAsAt(code, date)
      .then((r) => {
        if (cancelled || rateTouched.current) return
        if (r.rate === null) {
          setNote(`No ${code} rate on or before this date in the Currency Rate Table -- key one in.`)
          onChange({ ...current.current, rate: '' })
        } else {
          setNote('From the Currency Rate Table; change it if needed.')
          onChange({ ...current.current, rate: String(r.rate) })
        }
      })
      .catch(() => setNote(null))
    return () => {
      cancelled = true
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value.currency, date])

  const options = codes.includes(value.currency) ? codes : [...codes, value.currency]

  return (
    <>
      <div className="form-row">
        <label htmlFor={`${idPrefix}-currency`}>Currency</label>
        <select
          id={`${idPrefix}-currency`}
          value={value.currency}
          disabled={disabled}
          onChange={(e) => {
            rateTouched.current = false
            onChange({ currency: e.target.value, rate: '' })
          }}
        >
          {options.map((c) => (
            <option key={c} value={c}>
              {c}
            </option>
          ))}
        </select>
      </div>
      {value.currency !== 'SGD' && (
        <div className="form-row">
          <label htmlFor={`${idPrefix}-rate`}>Exchange rate (1 {value.currency} = ? SGD)</label>
          <input
            id={`${idPrefix}-rate`}
            type="number"
            inputMode="decimal"
            step="0.000001"
            min="0"
            required
            disabled={disabled}
            value={value.rate}
            onChange={(e) => {
              rateTouched.current = true
              setNote(null)
              onChange({ ...value, rate: e.target.value })
            }}
            style={{ maxWidth: 160 }}
          />
          {note && <span className="muted">{note}</span>}
        </div>
      )}
    </>
  )
}
