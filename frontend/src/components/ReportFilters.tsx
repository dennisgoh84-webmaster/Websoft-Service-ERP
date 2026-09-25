// Filter controls shared by the report screens (Dennis, 2026-09-24:
// "company selection, and multiple company selection, ACC period and
// date selection from and to"). Two different "companies": Internal
// Companies are the user's own companies (the top-right switcher's list);
// Company / Individual is the customer-or-supplier file.
import { useEffect, useLayoutEffect, useRef, useState, type ReactNode } from 'react'
import { api, type AccountingPeriod, type Company } from '../lib/api'
import { formatDate } from '../lib/format'
import { isoToMonth, monthEndISO, monthStartISO } from '../lib/period'
import { useAuth } from '../lib/AuthContext'

/**
 * The filter area on a report screen: an even column grid, so every line
 * runs to the same right edge and the fields line up under each other.
 * When the filters wrap, the last line is pushed right so it ends under
 * the first line's right end (Dennis, 2026-09-25); the Export control is
 * always the last cell. Items marked "span-all" take a whole line.
 */
export function FilterGrid({ children }: { children: ReactNode }) {
  const ref = useRef<HTMLDivElement>(null)
  useLayoutEffect(() => {
    const el = ref.current
    if (!el) return
    const align = () => {
      const items = Array.from(el.children).filter((c) => !c.classList.contains('span-all')) as HTMLElement[]
      items.forEach((i) => {
        i.style.gridColumnStart = ''
        i.style.gridColumnEnd = ''
      })
      const style = getComputedStyle(el)
      const cols = style.gridTemplateColumns.split(' ').filter(Boolean).length
      if (cols < 2 || items.length === 0) return
      const colGap = parseFloat(style.columnGap) || 0
      const colW = (el.clientWidth - colGap * (cols - 1)) / cols
      // A cell whose controls sit side by side (Export + format, As at +
      // Today) takes as many columns as it needs rather than overflowing.
      const width = (i: HTMLElement) => {
        const row = i.matches('.report-filter-actions') ? i : i.querySelector<HTMLElement>('.input-with-button')
        if (!row) return 1
        const kids = Array.from(row.children) as HTMLElement[]
        const need = kids.reduce((w, k) => w + k.getBoundingClientRect().width, 0) + (parseFloat(getComputedStyle(row).columnGap) || 0) * (kids.length - 1)
        return Math.min(cols, Math.max(1, Math.ceil((need + colGap) / (colW + colGap) - 0.01)))
      }
      const widths = items.map(width)
      widths.forEach((w, idx) => {
        if (w > 1) items[idx].style.gridColumnEnd = `span ${w}`
      })
      // Lay the cells out the way the grid will, to find the last line.
      let lines = 1
      let used = 0
      let lineStart = 0
      widths.forEach((w, idx) => {
        if (used + w > cols) {
          lines += 1
          used = 0
          lineStart = idx
        }
        used += w
      })
      if (lines > 1 && used < cols) {
        items[lineStart].style.gridColumnStart = String(cols - used + 1)
      } else if (lines === 1) {
        const last = items.length - 1
        if (items[last].classList.contains('report-filter-actions')) items[last].style.gridColumnStart = String(cols - widths[last] + 1)
      }
    }
    align()
    const ro = new ResizeObserver(align)
    ro.observe(el)
    return () => ro.disconnect()
  })
  return (
    <div ref={ref} className="report-filter-grid">
      {children}
    </div>
  )
}

/**
 * One or several of the user's own (internal) companies; at least one
 * always stays selected. The company you are signed in to is listed first
 * and is the default.
 */
export function InternalCompaniesPicker({ value, onChange }: { value: string[]; onChange: (ids: string[]) => void }) {
  const { user } = useAuth()
  const activeId = user?.company_id
  const [companies, setCompanies] = useState<Company[]>([])
  useEffect(() => {
    api
      .listMyCompanies()
      .then((cs) => setCompanies([...cs].sort((a, b) => Number(b.id === activeId) - Number(a.id === activeId))))
      .catch(() => setCompanies([]))
  }, [activeId])

  const toggle = (id: string) => {
    const next = value.includes(id) ? value.filter((x) => x !== id) : [...value, id]
    if (next.length > 0) onChange(next)
  }
  const allSelected = companies.length > 0 && companies.every((c) => value.includes(c.id))

  return (
    <div className="form-row report-companies span-all">
      <label>Internal Companies{companies.length > 1 ? ' (tick one or more)' : ''}</label>
      <div className="company-chips">
        {companies.map((c) => (
          <button
            key={c.id}
            type="button"
            className={`company-chip${value.includes(c.id) ? ' on' : ''}`}
            onClick={() => toggle(c.id)}
            disabled={companies.length === 1}
            title={c.name}
          >
            {c.code} {c.name}
          </button>
        ))}
        {companies.length > 2 && (
          <button type="button" className="company-chip all" onClick={() => onChange(allSelected ? [companies[0].id] : companies.map((c) => c.id))}>
            {allSelected ? 'Clear' : 'All internal companies'}
          </button>
        )}
      </div>
    </div>
  )
}

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

/**
 * Month from / Month to (a whole-month range), an accounting period
 * shortcut, and the exact From / To dates they fill in -- any one can be
 * used; the dates are what the report runs on.
 */
export function PeriodRange({ from, to, onChange }: { from: string; to: string; onChange: (from: string, to: string) => void }) {
  const periods = usePeriods()
  const match = periods.find((p) => p.period_start === from && p.period_end === to)

  return (
    <>
      <div className="form-row">
        <label>Month from</label>
        <input type="month" value={isoToMonth(from)} onChange={(e) => e.target.value && onChange(monthStartISO(e.target.value), to)} />
      </div>
      <div className="form-row">
        <label>Month to</label>
        <input type="month" value={isoToMonth(to)} onChange={(e) => e.target.value && onChange(from, monthEndISO(e.target.value))} />
      </div>
      <div className="form-row">
        <label>Accounting period</label>
        <select
          value={match?.id ?? ''}
          onChange={(e) => {
            const p = periods.find((x) => x.id === e.target.value)
            if (p) onChange(p.period_start, p.period_end)
          }}
          disabled={periods.length === 0}
          title={periods.length === 0 ? 'No accounting periods set up yet -- use the months or dates, or set periods up under Accounting Periods.' : undefined}
        >
          <option value="">{periods.length === 0 ? 'None set up' : 'Custom'}</option>
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

/** "As at" date with month-end and accounting-period shortcuts (each sets it to that month's / period's last day). Empty = today. */
export function AsAtPicker({ value, onChange }: { value: string; onChange: (asAt: string) => void }) {
  const periods = usePeriods()
  const match = periods.find((p) => p.period_end === value)

  return (
    <>
      <div className="form-row">
        <label>Month end</label>
        <input type="month" value={isoToMonth(value)} onChange={(e) => e.target.value && onChange(monthEndISO(e.target.value))} />
      </div>
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
          <option value="">{periods.length === 0 ? 'None set up' : 'Custom'}</option>
          {periods.map((p) => (
            <option key={p.id} value={p.id}>{periodLabel(p)}</option>
          ))}
        </select>
      </div>
      <div className="form-row">
        <label>As at</label>
        <div className="input-with-button">
          <input type="date" value={value} onChange={(e) => onChange(e.target.value)} />
          <button type="button" className="secondary" onClick={() => onChange('')}>Today</button>
        </div>
      </div>
    </>
  )
}
