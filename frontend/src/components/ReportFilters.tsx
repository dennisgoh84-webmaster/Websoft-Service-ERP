// Filter controls shared by the report screens (Dennis, 2026-09-24:
// "company selection, and multiple company selection, ACC period and
// date selection from and to" -- "company" there meaning the Company /
// Individual file, i.e. customers and suppliers).
import { useEffect, useRef, useState } from 'react'
import { api, type AccountingPeriod } from '../lib/api'
import { formatDate } from '../lib/format'

/** Checkbox dropdown: nothing ticked means "all". */
export function MultiPick({
  label,
  allLabel,
  options,
  value,
  onChange,
}: {
  label: string
  allLabel: string
  options: { id: string; name: string }[]
  value: string[]
  onChange: (ids: string[]) => void
}) {
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const ref = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!open) return
    const close = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false)
    }
    document.addEventListener('mousedown', close)
    return () => document.removeEventListener('mousedown', close)
  }, [open])

  const shown = options.filter((o) => o.name.toLowerCase().includes(search.toLowerCase()))
  const summary =
    value.length === 0 ? allLabel : value.length === 1 ? options.find((o) => o.id === value[0])?.name ?? '1 selected' : `${value.length} selected`

  return (
    <div className="form-row multi-pick" ref={ref}>
      <label>{label}</label>
      <button type="button" className="multi-pick-toggle" onClick={() => setOpen(!open)}>
        <span>{summary}</span>
        <span aria-hidden>▾</span>
      </button>
      {open && (
        <div className="multi-pick-panel">
          <input autoFocus placeholder="Search…" value={search} onChange={(e) => setSearch(e.target.value)} />
          <div className="multi-pick-list">
            {shown.map((o) => (
              <label key={o.id} className="multi-pick-item">
                <input
                  type="checkbox"
                  checked={value.includes(o.id)}
                  onChange={() => onChange(value.includes(o.id) ? value.filter((x) => x !== o.id) : [...value, o.id])}
                />
                {o.name}
              </label>
            ))}
            {shown.length === 0 && <p className="muted" style={{ margin: 8 }}>Nothing matches.</p>}
          </div>
          <div className="multi-pick-foot">
            <button type="button" className="secondary" onClick={() => onChange([])}>Show all</button>
            <button type="button" onClick={() => setOpen(false)}>Done</button>
          </div>
        </div>
      )}
    </div>
  )
}

function usePeriods(): AccountingPeriod[] {
  const [periods, setPeriods] = useState<AccountingPeriod[]>([])
  useEffect(() => {
    api
      .listAccountingPeriods()
      .then((ps) => setPeriods([...ps].sort((a, b) => b.period_start.localeCompare(a.period_start))))
      .catch(() => setPeriods([]))
  }, [])
  return periods
}

function periodLabel(p: AccountingPeriod): string {
  return `${p.name} (FY${p.fiscal_year}) · ${formatDate(p.period_start)} – ${formatDate(p.period_end)}`
}

/** Accounting period shortcut + exact From / To dates. Picking a period fills both dates. */
export function PeriodRange({ from, to, onChange }: { from: string; to: string; onChange: (from: string, to: string) => void }) {
  const periods = usePeriods()
  const match = periods.find((p) => p.period_start === from && p.period_end === to)

  return (
    <>
      <div className="form-row">
        <label>Accounting period</label>
        <select
          value={match?.id ?? ''}
          onChange={(e) => {
            const p = periods.find((x) => x.id === e.target.value)
            if (p) onChange(p.period_start, p.period_end)
          }}
          disabled={periods.length === 0}
          title={periods.length === 0 ? 'No accounting periods set up yet -- use the dates, or set periods up under Accounting Periods.' : undefined}
        >
          <option value="">{periods.length === 0 ? 'None set up' : 'Custom dates'}</option>
          {periods.map((p) => (
            <option key={p.id} value={p.id}>{periodLabel(p)}</option>
          ))}
        </select>
      </div>
      <div className="form-row">
        <label>Date from</label>
        <input type="date" value={from} max={to || undefined} onChange={(e) => onChange(e.target.value, to)} />
      </div>
      <div className="form-row">
        <label>Date to</label>
        <input type="date" value={to} min={from || undefined} onChange={(e) => onChange(from, e.target.value)} />
      </div>
    </>
  )
}

/** "As at" date with an accounting-period shortcut (sets it to that period's last day). Empty = today. */
export function AsAtPicker({ value, onChange }: { value: string; onChange: (asAt: string) => void }) {
  const periods = usePeriods()
  const match = periods.find((p) => p.period_end === value)

  return (
    <>
      <div className="form-row">
        <label>Accounting period end</label>
        <select
          value={match?.id ?? ''}
          onChange={(e) => {
            const p = periods.find((x) => x.id === e.target.value)
            onChange(p ? p.period_end : '')
          }}
          disabled={periods.length === 0}
        >
          <option value="">{periods.length === 0 ? 'None set up' : 'Custom date'}</option>
          {periods.map((p) => (
            <option key={p.id} value={p.id}>{periodLabel(p)}</option>
          ))}
        </select>
      </div>
      <div className="form-row">
        <label>As at</label>
        <input type="date" value={value} onChange={(e) => onChange(e.target.value)} />
      </div>
      <button type="button" className="secondary" onClick={() => onChange('')}>Today</button>
    </>
  )
}
