import { useEffect, useState } from 'react'
import { api } from '../../lib/api'
import { formatDate } from '../../lib/format'

const MAROON = '#800020'
const LIGHT_BG = '#f8f7f5'
const WHITE = '#ffffff'

const styles = {
  container: {
    maxWidth: 480,
    margin: '0 auto',
    minHeight: '100vh',
    background: LIGHT_BG,
    fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
  } as React.CSSProperties,
  header: {
    background: MAROON,
    color: WHITE,
    padding: '16px 20px',
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'space-between',
    position: 'sticky' as const,
    top: 0,
    zIndex: 10,
  } as React.CSSProperties,
  headerTitle: { fontSize: 18, fontWeight: 700, margin: 0 } as React.CSSProperties,
  card: {
    background: WHITE,
    borderRadius: 12,
    padding: 16,
    margin: '12px 16px',
    boxShadow: '0 1px 3px rgba(0,0,0,0.08)',
  } as React.CSSProperties,
  badge: {
    display: 'inline-block',
    padding: '2px 8px',
    borderRadius: 10,
    fontSize: 11,
    fontWeight: 700,
    textTransform: 'uppercase' as const,
  } as React.CSSProperties,
  errorBox: {
    background: '#fdeaea',
    color: '#c0392b',
    padding: '10px 14px',
    borderRadius: 8,
    margin: '12px 16px',
    fontSize: 14,
  } as React.CSSProperties,
}

interface MobileQuotation {
  id: string
  quotation_number: string
  customer_name: string
  total_amount: number
  status: string
  valid_until: string | null
  created_at: string
}

export default function MobileQuotationsPage() {
  const [quotations, setQuotations] = useState<MobileQuotation[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [filter, setFilter] = useState<'all' | 'pending' | 'approved'>('all')

  useEffect(() => {
    async function loadQuotations() {
      try {
        const res = await api.request<MobileQuotation[]>('GET', '/quotations')
        setQuotations(res || [])
      } catch (e: unknown) {
        setError(e instanceof Error ? e.message : 'Failed to load quotations')
      } finally {
        setLoading(false)
      }
    }
    loadQuotations()
  }, [])

  const filtered = quotations.filter(q => {
    if (filter === 'all') return true
    return q.status === filter
  })

  const getStatusColor = (status: string): { bg: string; text: string } => {
    switch (status) {
      case 'approved':
        return { bg: '#eafaf1', text: '#27ae60' }
      case 'pending_approval':
        return { bg: '#fef9e7', text: '#f39c12' }
      case 'pending_client_confirmation':
        return { bg: '#eaf0fa', text: '#2c3e80' }
      default:
        return { bg: '#f0f0f0', text: '#555' }
    }
  }

  const formatAmount = (amount: number): string => {
    return new Intl.NumberFormat('en-SG', {
      style: 'currency',
      currency: 'SGD',
    }).format(amount)
  }

  if (loading)
    return (
      <div style={{ ...styles.container, padding: 40, textAlign: 'center' }}>
        Loading quotations...
      </div>
    )

  return (
    <div style={styles.container}>
      <div style={styles.header}>
        <h1 style={styles.headerTitle}>My Quotations</h1>
      </div>

      {error && <div style={styles.errorBox}>{error}</div>}

      {/* Filter tabs */}
      <div style={{ display: 'flex', gap: 4, padding: '12px 16px', borderBottom: `1px solid #eee` }}>
        {(['all', 'pending', 'approved'] as const).map(f => (
          <button
            key={f}
            onClick={() => setFilter(f)}
            style={{
              flex: 1,
              padding: '8px 4px',
              border: 'none',
              borderRadius: 6,
              fontSize: 12,
              fontWeight: 600,
              cursor: 'pointer',
              background: filter === f ? MAROON : '#f0f0f0',
              color: filter === f ? WHITE : '#555',
            }}
          >
            {f === 'all' ? 'All' : f === 'pending' ? 'Pending' : 'Approved'}
          </button>
        ))}
      </div>

      {filtered.length === 0 ? (
        <div style={{ padding: 40, textAlign: 'center', color: '#999' }}>
          No quotations found.
        </div>
      ) : (
        filtered.map(quote => {
          const statusColor = getStatusColor(quote.status)
          return (
            <div key={quote.id} style={styles.card}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
                <div style={{ flex: 1 }}>
                  <div style={{ fontWeight: 700, fontSize: 15, color: '#222' }}>
                    {quote.quotation_number}
                  </div>
                  <div style={{ fontSize: 13, color: '#666', marginTop: 2 }}>{quote.customer_name}</div>
                </div>
                <span style={{ ...styles.badge, background: statusColor.bg, color: statusColor.text }}>
                  {quote.status.replace(/_/g, ' ')}
                </span>
              </div>

              <div style={{ marginTop: 8, fontSize: 16, fontWeight: 600, color: MAROON }}>
                {formatAmount(quote.total_amount)}
              </div>

              {quote.valid_until && (
                <div style={{ fontSize: 12, color: '#999', marginTop: 4 }}>
                  Valid until: {formatDate(quote.valid_until)}
                </div>
              )}

              <div style={{ fontSize: 12, color: '#bbb', marginTop: 2 }}>
                Created {formatDate(quote.created_at)}
              </div>

              <button
                style={{
                  width: '100%',
                  marginTop: 12,
                  padding: '10px',
                  border: 'none',
                  borderRadius: 8,
                  background: MAROON,
                  color: WHITE,
                  fontSize: 14,
                  fontWeight: 600,
                  cursor: 'pointer',
                }}
              >
                View Details
              </button>
            </div>
          )
        })
      )}
    </div>
  )
}
