import { useState } from 'react'

interface ExportFormat {
  value: string
  label: string
}

interface ExportControlProps {
  /** Format choices for the <select>, e.g. [{ value: 'csv', label: 'CSV' }, { value: 'excel', label: 'Excel' }]. */
  formats: ExportFormat[]
  /** Fetch + downloadBlob for the chosen format. Throw/reject on failure. */
  onExport: (format: string) => Promise<void>
  /** Surfaces a failed export the same way the page shows its other errors. */
  onError?: (message: string) => void
}

/** The standard "Export" control (button + format select to its right --
 * Dennis, 2026-09-24) used on every
 * list/report screen and every print form -- see docs/ui-guidelines.md
 * section 2-3. Keeping this in one place is what keeps every screen's
 * export button looking and behaving the same. */
export default function ExportControl({ formats, onExport, onError }: ExportControlProps) {
  const [format, setFormat] = useState(formats[0]?.value ?? '')
  const [exporting, setExporting] = useState(false)

  async function handleClick() {
    setExporting(true)
    try {
      await onExport(format)
    } catch (err) {
      onError?.(err instanceof Error ? err.message : 'Failed to export')
    } finally {
      setExporting(false)
    }
  }

  return (
    <div className="export-control no-print">
      <button type="button" className="secondary" onClick={handleClick} disabled={exporting}>
        {exporting ? 'Exporting...' : 'Export'}
      </button>
      <select value={format} onChange={(e) => setFormat(e.target.value)} disabled={exporting} aria-label="Export format">
        {formats.map((f) => (
          <option key={f.value} value={f.value}>
            {f.label}
          </option>
        ))}
      </select>
    </div>
  )
}
