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
// `.toLocaleDateString()` produces in most environments. Built from
// parts rather than a locale format string, so the format is guaranteed
// regardless of the visitor's browser/OS locale.
//
// Timestamps are shown in the company's time zone, Asia/Singapore
// (CLAUDE.md), not the viewer's: the backend stores a date typed as
// "07/10/2026" as midnight Singapore time, which is still 06/10 in any
// time zone west of Singapore -- so a browser set to, say, UTC showed
// every such date a day early (found 2026-09-25).
export const COMPANY_TIME_ZONE = 'Asia/Singapore'

const partsFormatter = new Intl.DateTimeFormat('en-GB', {
  timeZone: COMPANY_TIME_ZONE,
  year: 'numeric',
  month: '2-digit',
  day: '2-digit',
  hour: '2-digit',
  minute: '2-digit',
  hourCycle: 'h23',
})

function sgParts(d: Date): { day: string; month: string; year: string; hour: string; minute: string } {
  const out: Record<string, string> = {}
  for (const p of partsFormatter.formatToParts(d)) out[p.type] = p.value
  return { day: out.day, month: out.month, year: out.year, hour: out.hour === '24' ? '00' : out.hour, minute: out.minute }
}

export function formatDate(value: string | Date | null | undefined): string {
  if (value == null || value === '') return '—'
  // A bare YYYY-MM-DD (every date-only column the API returns) is
  // formatted as-is: it is already a calendar date, with no time zone.
  if (typeof value === 'string') {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value)
    if (m) return `${m[3]}/${m[2]}/${m[1]}`
  }
  const d = typeof value === 'string' ? new Date(value) : value
  if (isNaN(d.getTime())) return '—'
  const p = sgParts(d)
  return `${p.day}/${p.month}/${p.year}`
}

// Same, with a trailing 24-hour HH:mm -- for timestamps where the time
// matters too (audit log entries, signed/closed/approved-at, etc.),
// not just the date. Singapore time, like formatDate.
export function formatDateTime(value: string | Date | null | undefined): string {
  if (value == null || value === '') return '—'
  const d = typeof value === 'string' ? new Date(value) : value
  if (isNaN(d.getTime())) return '—'
  const p = sgParts(d)
  return `${p.day}/${p.month}/${p.year} ${p.hour}:${p.minute}`
}

/** Today's date in Singapore as YYYY-MM-DD -- the default for a date box ("today" by the company's clock, not UTC's). */
export function todayIso(): string {
  const p = sgParts(new Date())
  return `${p.year}-${p.month}-${p.day}`
}

/**
 * A timestamp as the Singapore calendar date, YYYY-MM-DD -- to fill a
 * date box from an API time. Never slice the ISO string: the API sends
 * UTC, so anything before 08:00 in Singapore is still "yesterday" there.
 */
export function sgDateIso(value: string | Date | null | undefined): string {
  if (value == null || value === '') return ''
  if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)) return value
  const d = typeof value === 'string' ? new Date(value) : value
  if (isNaN(d.getTime())) return ''
  const p = sgParts(d)
  return `${p.year}-${p.month}-${p.day}`
}

/** A timestamp's time of day in Singapore, HH:MM (24-hour). */
export function formatTime(value: string | Date | null | undefined): string {
  if (value == null || value === '') return '—'
  const d = typeof value === 'string' ? new Date(value) : value
  if (isNaN(d.getTime())) return '—'
  const p = sgParts(d)
  return `${p.hour}:${p.minute}`
}

/** A calendar date built from year / month (0-based) / day, as YYYY-MM-DD, without going through UTC. */
export function ymdIso(year: number, monthIndex: number, day: number): string {
  return `${year}-${String(monthIndex + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`
}

// Typed-date helpers for components/DateInput.tsx.
export function isoToDmy(iso: string | null | undefined): string {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso ?? '')
  return m ? `${m[3]}/${m[2]}/${m[1]}` : ''
}

/**
 * What a date box shows as it is typed (Dennis, 2026-09-26: the slashes
 * are "fixed inside the box" -- nobody types them). Digits fill DD, MM
 * and YYYY in turn and a slash is added as each part fills, so 12092026
 * shows 12/09/2026; a phone's number pad has no "/" key at all. A slash
 * (or - or .) typed after a one-digit day or month pads it (1/9 -> 01/09/).
 * Deleting never re-adds the slash being deleted. A pasted ISO date
 * (2026-09-12) becomes 12/09/2026.
 */
export function formatDateTyping(raw: string, previous = ''): string {
  if (/^\d{4}-\d{2}-\d{2}$/.test(raw.trim())) return isoToDmy(raw.trim())
  const deleting = raw.length < previous.length
  const parts = ['', '', '']
  let idx = 0
  for (const ch of raw) {
    if (ch >= '0' && ch <= '9') {
      const cap = idx < 2 ? 2 : 4
      if (parts[idx].length >= cap) {
        if (idx === 2) continue
        idx++
      }
      parts[idx] += ch
    } else if ((ch === '/' || ch === '-' || ch === '.') && idx < 2 && parts[idx].length > 0) {
      if (parts[idx].length === 1) parts[idx] = `0${parts[idx]}`
      idx++
    }
  }
  let out = parts[0]
  if (idx >= 1) out += `/${parts[1]}`
  if (idx >= 2) out += `/${parts[2]}`
  if (!deleting && idx < 2 && parts[idx].length === 2) out += '/'
  return out
}

/** DD/MM/YYYY (1- or 2-digit day and month; - or . also accepted) -> ISO, or null if not a real date. */
export function dmyToIso(text: string): string | null {
  const m = /^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$/.exec(text.trim())
  if (!m) return null
  const [d, mo, y] = [Number(m[1]), Number(m[2]), Number(m[3])]
  const date = new Date(Date.UTC(y, mo - 1, d))
  if (date.getUTCFullYear() !== y || date.getUTCMonth() !== mo - 1 || date.getUTCDate() !== d) return null
  return `${y}-${String(mo).padStart(2, '0')}-${String(d).padStart(2, '0')}`
}
