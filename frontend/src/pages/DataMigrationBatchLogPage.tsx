import { useEffect, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import ExportControl from '../components/ExportControl'
import { FilterGrid, InternalCompaniesPicker } from '../components/ReportFilters'
import { BatchStatusBadge, DmyDateInput, MIGRATION_MODULES, MigrationTabs, SourceTag, SOURCES, moduleLabel } from '../components/DataMigration'
import { useAuth } from '../lib/AuthContext'
import { ApiError, api, downloadBlob, type MigrationBatch, type MigrationBatchFilters, type MigrationRollbackResult } from '../lib/api'
import { formatDateTime } from '../lib/format'

/**
 * Maintenance -> Data Migration -> Batch Log: every upload, dry run,
 * import and roll back, with who did it and when -- kept permanently,
 * rolled-back batches included. Roll back removes a batch's records only
 * if none has been touched since the import; otherwise it lists what
 * blocks it and removes nothing.
 */
export default function DataMigrationBatchLogPage() {
  const { user } = useAuth()
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const [companyIds, setCompanyIds] = useState<string[]>([params.get('company_id') || user?.company_id || ''].filter(Boolean))
  const [source, setSource] = useState('')
  const [entity, setEntity] = useState('')
  const [status, setStatus] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [batches, setBatches] = useState<MigrationBatch[]>([])
  const [error, setError] = useState<string | null>(null)
  const [rollbackId, setRollbackId] = useState<string | null>(params.get('rollback'))
  const [reason, setReason] = useState('')
  const [rolling, setRolling] = useState(false)
  const [result, setResult] = useState<MigrationRollbackResult | null>(null)

  function filters(): MigrationBatchFilters {
    return {
      company_ids: companyIds.join(','),
      source: source || undefined,
      entity: entity || undefined,
      status: status || undefined,
      date_from: dateFrom || undefined,
      date_to: dateTo || undefined,
    }
  }

  function refresh() {
    api.listMigrationBatches(filters()).then(setBatches).catch((e) => setError(e.message))
  }
  useEffect(refresh, [companyIds.join(','), source, entity, status, dateFrom, dateTo])

  function reset() {
    setSource('')
    setEntity('')
    setStatus('')
    setDateFrom('')
    setDateTo('')
  }

  function openRollback(id: string | null) {
    setRollbackId(id)
    setReason('')
    setResult(null)
    const next = new URLSearchParams(params)
    if (id) next.set('rollback', id)
    else next.delete('rollback')
    setParams(next, { replace: true })
  }

  async function rollBack() {
    if (!rollbackId) return
    setRolling(true)
    setError(null)
    try {
      setResult(await api.rollbackMigrationBatch(rollbackId, reason))
      refresh()
    } catch (e) {
      if (e instanceof ApiError && e.status === 409) {
        setResult(e.body as MigrationRollbackResult)
      } else {
        setError(e instanceof Error ? e.message : 'Roll back failed')
      }
    } finally {
      setRolling(false)
    }
  }

  const target = batches.find((b) => b.id === rollbackId)

  return (
    <div>
      <h1>Data Migration</h1>
      <p className="muted">
        Every upload, dry run, import and roll back, with who did it and when. Kept permanently, even after a roll back.
      </p>
      <MigrationTabs />
      {error && <div className="error-banner">{error}</div>}

      <div className="card">
        <FilterGrid>
          <InternalCompaniesPicker
            value={companyIds}
            onChange={setCompanyIds}
            action={
              <button type="button" className="secondary report-reset" onClick={reset}>
                Reset filters
              </button>
            }
          />
          <div className="form-row">
            <label htmlFor="log-source">Source system</label>
            <select id="log-source" value={source} onChange={(e) => { setSource(e.target.value); setEntity('') }}>
              <option value="">All</option>
              {SOURCES.map((s) => (
                <option key={s.value} value={s.value}>
                  {s.label}
                </option>
              ))}
            </select>
          </div>
          <div className="form-row">
            <label htmlFor="log-module">Module</label>
            <select id="log-module" value={entity} onChange={(e) => setEntity(e.target.value)}>
              <option value="">All</option>
              {[...new Map(MIGRATION_MODULES.filter((m) => !source || m.source === source).map((m) => [m.entity, m.label])).entries()].map(([e, l]) => (
                <option key={e} value={e}>
                  {l}
                </option>
              ))}
            </select>
          </div>
          <div className="form-row">
            <label htmlFor="log-status">Status</label>
            <select id="log-status" value={status} onChange={(e) => setStatus(e.target.value)}>
              <option value="">All</option>
              <option value="uploaded">Uploaded</option>
              <option value="dry_run">Dry run</option>
              <option value="running">Running</option>
              <option value="failed">Failed / refused</option>
              <option value="succeeded">Imported</option>
              <option value="rolled_back">Rolled back</option>
            </select>
          </div>
          <DmyDateInput id="log-from" label="Date from" value={dateFrom} onChange={setDateFrom} />
          <DmyDateInput id="log-to" label="Date to" value={dateTo} onChange={setDateTo} />
          <div className="report-filter-actions">
            <ExportControl
              formats={[
                { value: 'csv', label: 'CSV' },
                { value: 'xlsx', label: 'Excel' },
              ]}
              onExport={async (f) => downloadBlob(await api.exportMigrationBatches(filters(), f as 'csv' | 'xlsx'), `migration-batches.${f}`)}
              onError={setError}
            />
          </div>
        </FilterGrid>

        <h2>Batches ({batches.length})</h2>
        <div style={{ overflowX: 'auto' }}>
          <table>
            <thead>
              <tr>
                <th>Batch</th>
                <th>Date / time</th>
                {companyIds.length > 1 && <th>Internal Company</th>}
                <th>Source · Module</th>
                <th>File</th>
                <th style={{ textAlign: 'right' }}>Rows</th>
                <th style={{ textAlign: 'right' }}>New</th>
                <th style={{ textAlign: 'right' }}>Linked</th>
                <th style={{ textAlign: 'right' }}>Errors</th>
                <th>By</th>
                <th>Status</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {batches.map((b) => (
                <tr key={b.id} className={b.id === rollbackId ? 'row-attention' : ''}>
                  <td style={{ whiteSpace: 'nowrap' }}>{b.batch_number}</td>
                  <td style={{ whiteSpace: 'nowrap' }}>{formatDateTime(b.started_at)}</td>
                  {companyIds.length > 1 && <td>{b.company_name}</td>}
                  <td>
                    <SourceTag source={b.source} />
                    {moduleLabel(b.source, b.entity)}
                  </td>
                  <td>{b.source_filename}</td>
                  <td style={{ textAlign: 'right' }}>{b.rows_read.toLocaleString('en-SG')}</td>
                  <td style={{ textAlign: 'right' }}>{b.rows_created.toLocaleString('en-SG')}</td>
                  <td style={{ textAlign: 'right' }}>{b.rows_linked.toLocaleString('en-SG')}</td>
                  <td style={{ textAlign: 'right', color: b.rows_failed + b.rows_needs_decision > 0 ? 'var(--danger)' : undefined }}>
                    {(b.rows_failed + b.rows_needs_decision).toLocaleString('en-SG')}
                  </td>
                  <td>{b.started_by ?? '—'}</td>
                  <td>
                    <BatchStatusBadge status={b.status} label={b.status_label} hasErrors={b.rows_failed + b.rows_needs_decision > 0} />
                    {b.rolled_back_at && (
                      <div className="muted small">
                        {formatDateTime(b.rolled_back_at)} by {b.rolled_back_by}: {b.rollback_reason}
                      </div>
                    )}
                  </td>
                  <td>
                    <div className="button-row" style={{ marginTop: 0, flexWrap: 'nowrap' }}>
                      <button type="button" className="secondary" onClick={() => navigate(`/data-migration/import?company_id=${b.company_id}&batch=${b.id}`)}>
                        View
                      </button>
                      {b.status === 'succeeded' && (
                        <button type="button" className="secondary" onClick={() => openRollback(b.id)}>
                          Roll back
                        </button>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
              {batches.length === 0 && (
                <tr>
                  <td colSpan={12} className="muted">
                    No batches match these filters.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>

        {rollbackId && target && (
          <div className="migration-confirm">
            {!result && (
              <>
                <h3 style={{ marginTop: 0 }}>
                  Roll back {target.batch_number} · <SourceTag source={target.source} />
                  {moduleLabel(target.source, target.entity)}?
                </h3>
                <p>
                  Removes the {target.rows_created.toLocaleString('en-SG')} records this batch created in {target.company_name}, but only if none of them
                  has been changed or used since the import. If even one has, nothing is removed and you get the list. Records it only linked to
                  ({target.rows_linked.toLocaleString('en-SG')}) are kept. The batch, a copy of every removed record and the Event Log stay.
                </p>
                <div className="form-row">
                  <label htmlFor="rb-reason">Reason (required, recorded in Event Logs)</label>
                  <textarea id="rb-reason" rows={2} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="e.g. Wrong Internal Company selected" />
                </div>
                <div className="button-row">
                  <button type="button" className="secondary" onClick={() => openRollback(null)} disabled={rolling}>
                    Cancel
                  </button>
                  <button type="button" onClick={rollBack} disabled={rolling || reason.trim().length < 3}>
                    {rolling ? 'Rolling back...' : `Roll back ${target.batch_number}`}
                  </button>
                </div>
              </>
            )}
            {result?.rolled_back && (
              <p>
                <span className="badge active">Rolled back</span> {result.removed.toLocaleString('en-SG')} records removed from {target.batch_number}.{' '}
                <button type="button" className="secondary" onClick={() => openRollback(null)}>
                  Close
                </button>
              </p>
            )}
            {result && !result.rolled_back && (
              <>
                <h3 style={{ marginTop: 0 }}>Not rolled back: {result.blockers.length.toLocaleString('en-SG')} record(s) are in use</h3>
                <p>Nothing was removed. Deal with these first, then try again:</p>
                <div style={{ overflowX: 'auto' }}>
                  <table>
                    <thead>
                      <tr>
                        <th>Record</th>
                        <th>Why it is blocked</th>
                      </tr>
                    </thead>
                    <tbody>
                      {result.blockers.slice(0, 200).map((bl, i) => (
                        <tr key={i}>
                          <td>{bl.record}</td>
                          <td>{bl.reason}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
                <button type="button" className="secondary" onClick={() => openRollback(null)}>
                  Close
                </button>
              </>
            )}
          </div>
        )}
      </div>
    </div>
  )
}
