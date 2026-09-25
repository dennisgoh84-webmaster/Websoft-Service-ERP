import type { CSSProperties } from 'react'

export const MAROON = '#800020'
const LIGHT_BG = '#f8f7f5'
const WHITE = '#ffffff'

// Inputs are 16px: below it iPhone Safari zooms the page on focus.
const field: CSSProperties = {
  width: '100%',
  padding: '10px',
  border: '1px solid #ddd',
  borderRadius: 8,
  fontSize: 16,
  boxSizing: 'border-box',
  marginBottom: 12,
}

/** Shared look for the Mobile App's sales screens (Prospects, activities). */
export const mobileStyles = {
  container: {
    maxWidth: 480,
    margin: '0 auto',
    minHeight: '100vh',
    background: LIGHT_BG,
    fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
  } as CSSProperties,
  header: {
    background: MAROON,
    color: WHITE,
    padding: '16px 20px',
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'space-between',
    position: 'sticky',
    top: 0,
    zIndex: 10,
  } as CSSProperties,
  backBtn: { background: 'none', border: 'none', color: WHITE, fontSize: 16, cursor: 'pointer', padding: '10px 8px' } as CSSProperties,
  headerTitle: { fontSize: 18, fontWeight: 700, margin: 0, flex: 1, textAlign: 'center' } as CSSProperties,
  card: {
    background: WHITE,
    borderRadius: 12,
    padding: 16,
    margin: '12px 16px',
    boxShadow: '0 1px 3px rgba(0,0,0,0.08)',
  } as CSSProperties,
  label: { display: 'block', fontSize: 13, fontWeight: 600, color: '#555', marginBottom: 4 } as CSSProperties,
  input: field,
  select: field,
  textarea: { ...field, fontFamily: 'inherit', resize: 'vertical', minHeight: 80 } as CSSProperties,
  btn: {
    display: 'block',
    width: '100%',
    padding: '12px',
    border: 'none',
    borderRadius: 10,
    fontSize: 14,
    fontWeight: 600,
    cursor: 'pointer',
    textAlign: 'center',
  } as CSSProperties,
  btnPrimary: { background: MAROON, color: WHITE } as CSSProperties,
  btnDanger: { background: '#c0392b', color: WHITE } as CSSProperties,
  btnSecondary: { background: '#e0e0e0', color: '#333', marginTop: 8 } as CSSProperties,
  badge: {
    display: 'inline-block',
    padding: '4px 12px',
    borderRadius: 16,
    fontSize: 12,
    fontWeight: 600,
    textTransform: 'uppercase',
  } as CSSProperties,
  errorBox: {
    background: '#fdeaea',
    color: '#c0392b',
    padding: '10px 14px',
    borderRadius: 8,
    margin: '12px 16px',
    fontSize: 14,
  } as CSSProperties,
}

export const ACTIVITY_ICON: Record<string, string> = {
  call: '☎️',
  email: '📧',
  meeting: '👥',
  note: '📝',
  follow_up: '↩️',
  proposal: '💼',
  demo: '🎬',
  negotiation: '🤝',
}

export function statusColors(status: string): { bg: string; text: string } {
  if (status === 'completed' || status === 'won') return { bg: '#eafaf1', text: '#27ae60' }
  if (status === 'lost' || status === 'cancelled') return { bg: '#fdeaea', text: '#c0392b' }
  return { bg: '#fef9e7', text: '#f39c12' }
}
