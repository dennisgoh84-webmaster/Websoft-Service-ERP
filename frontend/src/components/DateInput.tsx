import { useEffect, useRef, useState, type CSSProperties } from 'react'
import { dmyToIso, formatDateTyping, isoToDmy } from '../lib/format'

/**
 * The one date box used everywhere (Dennis, 2026-09-25: "standardize
 * all" -- every date reads and is typed DD/MM/YYYY). A native
 * <input type="date"> shows whatever format the viewer's browser
 * locale picks, often MM/DD/YYYY; this is a text box that always takes
 * DD/MM/YYYY, plus a calendar button that still opens the browser's own
 * calendar for picking by mouse or finger.
 *
 * Dennis, 2026-09-26, after keying dates in on a phone: the slashes are
 * "fixed inside the box" -- typing 12092026 shows 12/09/2026
 * (formatDateTyping), since a phone's number pad has no "/" key -- and
 * the calendar must open on a phone. The browser's own date input sits,
 * invisible, exactly over the calendar button, so a tap lands on it and
 * the phone opens its picker natively; on a desktop the same click also
 * asks for the picker (showPicker). A two-digit year (12/09/26) is read
 * as 20yy when the box is left.
 *
 * Drop-in for <input type="date">: `value` is the ISO date
 * (YYYY-MM-DD, '' for none) and `onChange` receives `{ target: { value } }`
 * with the ISO date -- fired only once what is typed is a complete, real
 * date (or the box is cleared), never with a half-typed one. `min`,
 * `max` and `required` are enforced through the browser's own form
 * validation, so a form with a bad date will not submit.
 */
interface DateInputProps {
  value: string | null | undefined
  onChange: (e: { target: { value: string } }) => void
  min?: string
  max?: string
  required?: boolean
  disabled?: boolean
  id?: string
  className?: string
  style?: CSSProperties
  'aria-label'?: string
}

export default function DateInput({ value, onChange, min, max, required, disabled, id, className, style, ...rest }: DateInputProps) {
  const iso = value ?? ''
  const [text, setText] = useState(isoToDmy(iso))
  const textRef = useRef<HTMLInputElement>(null)
  const pickerRef = useRef<HTMLInputElement>(null)

  // Follow the value when it changes from outside (a reset, a loaded
  // record), unless the box already shows that same date -- adjusted
  // during render, React's pattern for state derived from a prop.
  const [shownIso, setShownIso] = useState(iso)
  if (iso !== shownIso) {
    setShownIso(iso)
    if (dmyToIso(text) !== (iso || null)) setText(isoToDmy(iso))
  }

  function problem(t: string): string {
    if (t.trim() === '') return required ? 'Enter a date (DD/MM/YYYY).' : ''
    const parsed = dmyToIso(t)
    if (!parsed) return 'Enter a real date as DD/MM/YYYY.'
    if (min && parsed < min) return `The date cannot be before ${isoToDmy(min)}.`
    if (max && parsed > max) return `The date cannot be after ${isoToDmy(max)}.`
    return ''
  }
  const message = problem(text)

  // The browser's own form validation blocks a submit while this is set.
  useEffect(() => {
    textRef.current?.setCustomValidity(message)
  }, [message])

  function typed(raw: string) {
    const t = formatDateTyping(raw, text)
    setText(t)
    if (t.trim() === '') {
      if (iso !== '') onChange({ target: { value: '' } })
      return
    }
    const parsed = dmyToIso(t)
    if (parsed && parsed !== iso) onChange({ target: { value: parsed } })
  }

  function picked(v: string) {
    setText(isoToDmy(v))
    if (v !== iso) onChange({ target: { value: v } })
  }

  // The native input is already under the pointer; ask for its picker
  // too, for desktop browsers that only open it from their own icon.
  function openCalendar() {
    try {
      pickerRef.current?.showPicker()
    } catch {
      // A phone has already opened its own picker from the tap.
    }
  }

  const invalid = text.trim() !== '' && message !== ''

  return (
    <span className={`date-input${className ? ` ${className}` : ''}`}>
      <input
        ref={textRef}
        id={id}
        value={text}
        placeholder="DD/MM/YYYY"
        inputMode="numeric"
        autoComplete="off"
        maxLength={10}
        required={required}
        disabled={disabled}
        aria-invalid={invalid}
        title={invalid ? message : undefined}
        style={style}
        onChange={(e) => typed(e.target.value)}
        onBlur={() => {
          // Tidy a typed 1/9/2026 into 01/09/2026 once it is a real date,
          // and read a two-digit year as this century's.
          const short = /^(\d{2})\/(\d{2})\/(\d{2})$/.exec(text)
          const full = short ? `${short[1]}/${short[2]}/20${short[3]}` : text
          const parsed = dmyToIso(full)
          if (!parsed) return
          setText(isoToDmy(parsed))
          if (short && parsed !== iso) onChange({ target: { value: parsed } })
        }}
        aria-label={rest['aria-label']}
      />
      <span className="date-input-cal">
        <button type="button" className="secondary date-input-calendar" disabled={disabled} aria-hidden="true" tabIndex={-1}>
          <svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true">
            <rect x="1.5" y="3" width="13" height="11.5" rx="1.5" fill="none" stroke="currentColor" strokeWidth="1.3" />
            <path d="M1.5 6.5h13M5 1.5v3M11 1.5v3" stroke="currentColor" strokeWidth="1.3" fill="none" />
          </svg>
        </button>
        <input
          ref={pickerRef}
          type="date"
          className="date-input-native"
          tabIndex={-1}
          aria-label="Open calendar"
          title="Open calendar"
          disabled={disabled}
          value={dmyToIso(text) ?? ''}
          min={min}
          max={max}
          onClick={openCalendar}
          onChange={(e) => picked(e.target.value)}
        />
      </span>
    </span>
  )
}
