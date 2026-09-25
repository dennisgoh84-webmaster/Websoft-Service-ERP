import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { InternalCompanySelect, MigrationTabs, ModuleStatusBadge, SourceTag, useMigrationCompany, SOURCES } from '../components/DataMigration'
import { api, type MigrationModuleStatus, type MigrationOverview } from '../lib/api'
import { formatDate, formatDateTime } from '../lib/format'

/**
 * Maintenance -> Data Migration -> Dashboard: live progress of the whole
 * ODOO + ZSOFT migration into one Internal Company, one bar per module.
 * Refreshes every 5 seconds while an import runs, every 30 otherwise.
 */
export default function DataMigrationDashboardPage() {
  const [companyId, setCompanyId] = useMigrationCompany()
  const [source, setSource] = useState('')
  const [status, setStatus] = useState('')
  const [data, setData] = useState<MigrationOverview | null>(null)
  const [error, setError] = useState<string | null>(null)

  function refresh() {
    if (!companyId) return
    api
      .getMigrationOverview(companyId)
      .then((d) => {
        setData(d)
        setError(null)
      })
      .catch((e) => setError(e.message))
  }

  useEffect(refresh, [companyId])
  const busy = data?.modules.some((m) => m.status === 'importing' || m.status === 'checking') ?? false
  useEffect(() => {
    const t = window.setInterval(refresh, busy ? 5000 : 30000)
    return () => window.clearInterval(t)
  }, [companyId, busy])

  const modules = (data?.modules ?? []).filter((m) => (!source || m.source === source) && (!status || m.status === status))
  const pct = (n: number, total: number) => (total > 0 ? Math.min(100, (n / total) * 100) : 0)

  return (
    <div>
      <h1>Data Migration</h1>
      <p className="muted">
        Bringing the old ODOO and ZSOFT data in, module by module. Each bar is one module: the records imported out of
        the rows in its latest uploaded file. Invoices and receipts come across as history only; nothing posts to the
        General Ledger, which starts from an opening-balance Journal Voucher.
      </p>
      <MigrationTabs />
      {error && <div className="error-banner">{error}</div>}

      <div className="card">
        <div className="filter-bar" style={{ marginBottom: 0, paddingBottom: 0, borderBottom: 'none' }}>
          <InternalCompanySelect value={companyId} onChange={setCompanyId} />
          <div className="form-row" style={{ margin: 0 }}>
            <label htmlFor="dash-source">Source system</label>
            <select id="dash-source" value={source} onChange={(e) => setSource(e.target.value)}>
              <option value="">ODOO + ZSOFT</option>
              {SOURCES.map((s) => (
                <option key={s.value} value={s.value}>
                  {s.label}
                </option>
              ))}
            </select>
          </div>
          <div className="form-row" style={{ margin: 0 }}>
            <label htmlFor="dash-status">Status</label>
            <select id="dash-status" value={status} onChange={(e) => setStatus(e.target.value)}>
              <option value="">All</option>
              <option value="not_started">Not started</option>
              <option value="importing">Importing</option>
              <option value="has_errors">Has errors</option>
              <option value="ready_to_import">Ready to import</option>
              <option value="complete">Complete</option>
            </select>
          </div>
          <button type="button" className="secondary" onClick={() => { setSource(''); setStatus('') }}>
            Reset filters
          </button>
          <button type="button" className="secondary" onClick={refresh}>
            Refresh
          </button>
        </div>
      </div>

      {data && (
        <>
          <div className="stat-grid">
            <div className="card stat-tile">
              <div className="stat-value">{data.totals.modules}</div>
              <div className="stat-label">Modules in scope</div>
            </div>
            <div className="card stat-tile">
              <div className="stat-value" style={{ color: 'var(--ok-text)' }}>{data.totals.complete}</div>
              <div className="stat-label">Complete</div>
            </div>
            <div className="card stat-tile">
              <div className="stat-value" style={{ color: 'var(--info-text)' }}>{data.totals.importing}</div>
              <div className="stat-label">Importing now</div>
            </div>
            <div className="card stat-tile">
              <div className="stat-value" style={{ color: data.totals.rows_with_errors ? 'var(--danger)' : undefined }}>
                {data.totals.rows_with_errors.toLocaleString('en-SG')}
              </div>
              <div className="stat-label">Rows with errors to fix</div>
            </div>
            <div className="card stat-tile">
              <div className="stat-value stat-value-total">{data.totals.percent}%</div>
              <div className="stat-label">
                Of the records uploaded so far ({data.totals.records_done.toLocaleString('en-SG')} of{' '}
                {data.totals.records_total.toLocaleString('en-SG')}) · {data.totals.complete} of {data.totals.modules} modules complete
              </div>
            </div>
          </div>

          <div className="card">
            <div style={{ display: 'flex', justifyContent: 'space-between', flexWrap: 'wrap', gap: 10, marginBottom: 12 }}>
              <h2 style={{ margin: 0 }}>Progress by module ({modules.length})</h2>
              <div className="migration-legend">
                <span><i style={{ background: 'var(--ok-text)' }} />Imported</span>
                <span><i style={{ background: 'var(--danger)' }} />Rows with errors</span>
                <span><i style={{ background: 'var(--info-text)' }} />Importing</span>
                <span><i style={{ background: 'var(--border)' }} />Not yet</span>
              </div>
            </div>
            <div className="migration-bars">
              {modules.map((m) => {
                const done = m.imported + m.in_progress
                const percent = m.total > 0 ? Math.round(pct(done, m.total)) : 0
                return (
                  <div className="migration-bar-row" key={`${m.source}-${m.entity}`}>
                    <div>
                      <SourceTag source={m.source} />
                      {m.their_name} → {m.label}
                      <small>
                        <ModuleStatusBadge status={m.status as MigrationModuleStatus} />
                        {m.last_activity_at ? ` · ${formatDate(m.last_activity_at)}` : ''}
                      </small>
                    </div>
                    <div className="migration-track" role="img" aria-label={`${m.label}: ${percent}%`}>
                      <div className="done" style={{ width: `${pct(m.imported, m.total)}%` }} />
                      <div className="running" style={{ width: `${pct(m.in_progress, m.total)}%` }} />
                      <div className="failed" style={{ width: `${pct(m.failed, m.total)}%` }} />
                    </div>
                    <div className="migration-bar-num">
                      <b>{percent}%</b> · {done.toLocaleString('en-SG')} / {m.total.toLocaleString('en-SG')}
                      {m.failed > 0 && <span style={{ color: 'var(--danger)' }}> · {m.failed} with errors</span>}
                    </div>
                  </div>
                )
              })}
              {modules.length === 0 && <p className="muted">No module matches these filters.</p>}
            </div>
          </div>
          <p className="muted small">
            {data.company.code} {data.company.name} · last refreshed {formatDateTime(data.as_at)} ·{' '}
            <Link to={`/data-migration/modules?company_id=${companyId}`}>Go to Migration Modules</Link>
          </p>
        </>
      )}
    </div>
  )
}
