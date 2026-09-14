// Shared money formatting -- confirmed 2026-09-12: every financial figure
// displays with a "$" prefix and thousands separators, e.g. "$ 8,750.00",
// instead of a bare `n.toFixed(2)` ("8750.00"). Single-currency SGD per
// CLAUDE.md, so no currency-code suffix is needed alongside the "$".
// A non-breaking space ( ) sits between "$" and the number so a
// narrow stat tile can't line-wrap between them.
export function formatMoney(amount: number): string {
  const formatted = Math.abs(amount).toLocaleString('en-SG', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })
  return amount < 0 ? `-$ ${formatted}` : `$ ${formatted}`
}

// Shared date formatting -- DD/MM/YYYY throughout the app (Singapore
// convention), not the browser-locale-dependent M/D/YYYY that a bare
// `.toLocaleDateString()` produces in most environments. Built by hand
// (not `toLocaleDateString('en-GB', ...)`) so the format is guaranteed
// regardless of the visitor's browser/OS locale.
export function formatDate(value: string | Date): string {
  const d = typeof value === 'string' ? new Date(value) : value
  const day = String(d.getDate()).padStart(2, '0')
  const month = String(d.getMonth() + 1).padStart(2, '0')
  return `${day}/${month}/${d.getFullYear()}`
}

// Same, with a trailing 24-hour HH:mm -- for timestamps where the time
// matters too (audit log entries, signed/closed/approved-at, etc.),
// not just the date.
export function formatDateTime(value: string | Date): string {
  const d = typeof value === 'string' ? new Date(value) : value
  const hours = String(d.getHours()).padStart(2, '0')
  const minutes = String(d.getMinutes()).padStart(2, '0')
  return `${formatDate(d)} ${hours}:${minutes}`
}

