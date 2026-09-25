import { Fragment, useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import ExportControl from '../components/ExportControl'
import { FieldMappingEditor, InternalCompanySelect, MigrationTabs, ModuleStatusBadge, SourceTag, SOURCES, useMigrationCompany } from '../components/DataMigration'
import { api, downloadBlob, type MigrationModule, type MigrationOverview } from '../lib/api'
import { formatDate } from '../lib/format'

/**
 * Maintenance -> Data Migration -> Migration Modules: every module
 * involved, in the order they must run. Beside each: its Field Gap list,
 * Import (upload that module's consolidated Excel file) and Roll back
 * (undo its last import).
 */
export default function DataMigrationModulesPage() {
  const navigate = useNavigate()
  const [companyId, setCompanyId] = useMigrationCompany()
  const [source, setSource] = useState('')
  const [data, setData] = useState<MigrationOverview | null>(null)
  const [openGap, setOpenGap] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  function refresh() {
    if (!companyId) return
    api.getMigrationOverview(companyId).then(setData).catch((e) => setError(e.message))
  }
  useEffect(refresh, [companyId])

  const modules = (data?.modules ?? []).filter((m) => !source || m.source === source)
  const key = (m: MigrationModule) => `${m.source}-${m.entity}`

  function fieldGapLabel(m: MigrationModule) {
    if (m.field_gap.columns === 0) return <span className="muted">No file yet</span>
    if (m.field_gap.signed_off) return <span className="badge active">Signed off</span>
    return (
      <span className="badge status-not-started">
        {m.field_gap.undecided} undecided · {m.field_gap.gaps} gap{m.field_gap.gaps === 1 ? '' : 's'}
      </span>
    )
  }

  return (
    <div>
      <h1>Data Migration</h1>
      <p className="muted">
        Every module in the migration, in the order they must run: each one looks up the ones above it (Contracts need
        their Company / Individual first), and ODOO runs before ZSOFT so a ZSOFT Company / Individual links to one ODOO
        already brought in. <b>Field Gap</b> lists every column of the old system's file with its decision here; import
        stays locked until that list is signed off.
      </p>
      <MigrationTabs />
      {error && <div className="error-banner">{error}</div>}

      <div className="card">
        <div className="filter-bar">
          <InternalCompanySelect value={companyId} onChange={setCompanyId} />
          <div className="form-row" style={{ margin: 0 }}>
            <label htmlFor="mod-source">Source system</label>
            <select id="mod-source" value={source} onChange={(e) => setSource(e.target.value)}>
              <option value="">ODOO + ZSOFT</option>
              {SOURCES.map((s) => (
                <option key={s.value} value={s.value}>
                  {s.label}
                </option>
              ))}
            </select>
          </div>
          <button type="button" className="secondary" onClick={() => setSource('')}>
            Reset filters
          </button>
          <ExportControl
            formats={[
              { value: 'csv', label: 'CSV' },
              { value: 'xlsx', label: 'Excel' },
            ]}
            onExport={async (f) => downloadBlob(await api.exportMigrationModules(companyId, source, f as 'csv' | 'xlsx'), `migration-modules.${f}`)}
            onError={setError}
          />
        </div>

        <h2>Migration Modules ({modules.length})</h2>
        <div style={{ overflowX: 'auto' }}>
          <table>
            <thead>
              <tr>
                <th>#</th>
                <th>Source</th>
                <th>Module (theirs → here)</th>
                <th>Posts to GL</th>
                <th style={{ textAlign: 'right' }}>Imported</th>
                <th>Last activity</th>
                <th>Field Gap</th>
                <th>Status</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {modules.map((m) => (
                <Fragment key={key(m)}>
                  <tr>
                    <td>{m.order}</td>
                    <td>
                      <SourceTag source={m.source} />
                    </td>
                    <td>
                      {m.their_name}
                      <br />
                      <span className="muted">→ {m.label}</span>
                    </td>
                    <td>No</td>
                    <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>
                      {m.imported.toLocaleString('en-SG')} / {m.total.toLocaleString('en-SG')}
                    </td>
                    <td>{formatDate(m.last_activity_at)}</td>
                    <td>{fieldGapLabel(m)}</td>
                    <td>
                      <ModuleStatusBadge status={m.status} />
                    </td>
                    <td>
                      <div className="button-row" style={{ marginTop: 0, flexWrap: 'nowrap' }}>
                        <button type="button" className="secondary" onClick={() => setOpenGap(openGap === key(m) ? null : key(m))}>
                          Field Gap
                        </button>
                        <button
                          type="button"
                          disabled={m.status === 'importing' || m.status === 'checking'}
                          onClick={() => navigate(`/data-migration/import?company_id=${companyId}&source=${m.source}&entity=${m.entity}`)}
                        >
                          Import
                        </button>
                        <button
                          type="button"
                          className="secondary"
                          disabled={!m.last_import_batch_id}
                          title={m.last_import_batch_number ? `Roll back ${m.last_import_batch_number}` : 'Nothing imported yet'}
                          onClick={() => navigate(`/data-migration/batches?company_id=${companyId}&rollback=${m.last_import_batch_id}`)}
                        >
                          Roll back
                        </button>
                      </div>
                    </td>
                  </tr>
                  {openGap === key(m) && (
                    <tr>
                      <td colSpan={9} style={{ background: 'var(--bg)' }}>
                        <h3 style={{ marginTop: 4 }}>
                          Field Gap: <SourceTag source={m.source} />
                          {m.their_name} → {m.label}
                        </h3>
                        <FieldMappingEditor
                          companyId={companyId}
                          source={m.source}
                          entity={m.entity}
                          onChanged={refresh}
                          onError={setError}
                        />
                      </td>
                    </tr>
                  )}
                </Fragment>
              ))}
            </tbody>
          </table>
        </div>
        <p className="muted small">
          No ledger data comes from either system: opening balances are keyed in as one{' '}
          <Link to="/general-ledger">Journal Voucher</Link> for the new financial year.
        </p>
      </div>
    </div>
  )
}
