// Report launcher shared by Accounting, Operations and Stock Reports
// (Dennis, 2026-09-24, picked "Option A": "can explain more for each
// report and easier to guide the users where to find what they
// want"). Step 1 is a grid of report cards grouped by section; step 2
// is the chosen report with only its own filters, a fuller
// explanation, and a way back. The chosen report lives in the URL
// (?report=...) so browser Back returns to the cards and a report can
// be linked to directly.
import { useSearchParams } from 'react-router-dom'

export interface ReportDef<K extends string> {
  key: K
  title: string
  /** One line on the card: what question the report answers. */
  summary: string
  /** Shown above the filters once the report is open. */
  details: string
  /** Filters this report takes, shown as chips on the card. */
  filters: string[]
}

export interface ReportSection<K extends string> {
  label: string
  reports: ReportDef<K>[]
}

export function useSelectedReport<K extends string>(sections: ReportSection<K>[]): [K | null, (key: K | null) => void] {
  const [params, setParams] = useSearchParams()
  const raw = params.get('report')
  const valid = sections.some((s) => s.reports.some((r) => r.key === raw))
  const selected = valid ? (raw as K) : null
  const open = (key: K | null) => {
    const next = new URLSearchParams(params)
    if (key) next.set('report', key)
    else next.delete('report')
    setParams(next)
  }
  return [selected, open]
}

export function ReportLauncher<K extends string>({ sections, onOpen }: { sections: ReportSection<K>[]; onOpen: (key: K) => void }) {
  return (
    <div className="report-sections">
      {sections.map((s) => (
        <section key={s.label} className="report-section">
          <h3>
            {s.label} <span className="report-count">{s.reports.length}</span>
          </h3>
          {s.reports.map((r) => (
            <button key={r.key} type="button" className="report-tile" onClick={() => onOpen(r.key)}>
              <strong>{r.title}</strong>
              <span>{r.summary}</span>
              <span className="report-filters">
                {r.filters.length === 0 ? <em>No filters</em> : r.filters.map((f) => <em key={f}>{f}</em>)}
              </span>
            </button>
          ))}
        </section>
      ))}
    </div>
  )
}

export function ReportHeader<K extends string>({ sections, current, onBack }: { sections: ReportSection<K>[]; current: K; onBack: () => void }) {
  const section = sections.find((s) => s.reports.some((r) => r.key === current))
  const report = section?.reports.find((r) => r.key === current)
  if (!section || !report) return null
  return (
    <div className="report-header">
      <p className="report-crumb">
        <button type="button" className="report-back" onClick={onBack}>← All reports</button>
        <span> / {section.label} / </span>
        <strong>{report.title}</strong>
      </p>
      <h2>{report.title}</h2>
      <p className="muted">{report.details}</p>
    </div>
  )
}
