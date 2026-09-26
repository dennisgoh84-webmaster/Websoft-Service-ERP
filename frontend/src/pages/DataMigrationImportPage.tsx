import { useEffect, useRef, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import DateInput from '../components/DateInput'
import ExportControl from '../components/ExportControl'
import {
  BatchStatusBadge,
  FieldMappingEditor,
  InternalCompanySelect,
  MIGRATION_MODULES,
  MigrationTabs,
  SourceTag,
  SOURCES,
  moduleLabel,
} from '../components/DataMigration'
import { useAuth } from '../lib/AuthContext'
import { api, downloadBlob, type MigrationBatch, type MigrationMappingInfo, type MigrationReportRow, type MigrationSource } from '../lib/api'
import { formatDate, formatDateTime } from '../lib/format'

type Step = 1 | 2 | 3 | 4

const STEPS: { n: Step; label: string }[] = [
  { n: 1, label: 'Upload' },
  { n: 2, label: 'Map fields' },
  { n: 3, label: 'Dry-run preview' },
  { n: 4, label: 'Import' },
]

const OUTCOME_LABEL: Record<string, string> = {
  failed: 'Error',
  needs_decision: 'Possible duplicate',
  skipped: 'Skipped',
  linked: 'Linked to existing',
  created: 'New (with a note)',
  already_imported: 'Already imported',
}

function stepFor(batch: MigrationBatch | null): Step {
  if (!batch) return 1
  if (batch.status === 'uploaded') return 2
  if (batch.status === 'succeeded' || batch.status === 'rolled_back') return 4
  if (batch.status === 'running') return batch.mode === 'commit' ? 4 : 3
  if (batch.status === 'failed' && batch.mode === 'commit') return 4
  if (batch.status === 'failed' && batch.rows_read === 0 && !batch.headers?.length) return 1
  return 3
}

/**
 * Maintenance -> Data Migration -> Import: upload -> map fields ->
 * dry-run preview (errors, possible duplicates) -> import. A batch can be
 * reopened at any point from the Batch Log (?batch=<id>).
 */
export default function DataMigrationImportPage() {
  const { user } = useAuth()
  const [params, setParams] = useSearchParams()
  const [companyId, setCompanyId] = useState(params.get('company_id') || user?.company_id || '')
  const [source, setSource] = useState<MigrationSource>((params.get('source') as MigrationSource) || 'odoo')
  const [entity, setEntity] = useState(params.get('entity') || 'company_individuals')
  const [file, setFile] = useState<File | null>(null)
  const [dragOver, setDragOver] = useState(false)
  const [batch, setBatch] = useState<MigrationBatch | null>(null)
  const [step, setStep] = useState<Step>(1)
  const [mapping, setMapping] = useState<MigrationMappingInfo | null>(null)
  const [decisions, setDecisions] = useState<Record<string, string>>({})
  // Cut-off date (Backlog 2, 2026-09-26): transactions dated before it
  // are left out unless still open. Picked for the dry run; the import
  // uses the same one.
  const [cutoff, setCutoff] = useState('')
  const [cutoffFor, setCutoffFor] = useState<string | null>(null)
  if (batch && batch.id !== cutoffFor) {
    setCutoffFor(batch.id)
    setCutoff(batch.cutoff_date ?? '')
  }
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const poller = useRef<number | null>(null)

  const batchId = params.get('batch')

  function openBatch(b: MigrationBatch, forceStep?: Step) {
    setBatch(b)
    setCompanyId(b.company_id)
    setSource(b.source)
    setEntity(b.entity)
    setDecisions(b.decisions ?? {})
    setStep(forceStep ?? stepFor(b))
    if (b.status === 'running') watch(b.id)
    const next = new URLSearchParams()
    next.set('company_id', b.company_id)
    next.set('batch', b.id)
    setParams(next, { replace: true })
  }

  useEffect(() => {
    if (batchId && batch?.id !== batchId) {
      api.getMigrationBatch(batchId).then((b) => openBatch(b)).catch((e) => setError(e.message))
    }
    return () => {
      if (poller.current) window.clearInterval(poller.current)
    }
  }, [batchId])

  useEffect(() => {
    if (batch && step >= 3) {
      api.getMigrationMapping(batch.source, batch.entity, batch.company_id).then(setMapping).catch(() => setMapping(null))
    }
  }, [batch?.id, batch?.status, step])

  /** Poll a running dry run / import until it finishes, then load the result. */
  function watch(id: string) {
    if (poller.current) window.clearInterval(poller.current)
    poller.current = window.setInterval(async () => {
      try {
        const p = await api.getMigrationBatchProgress(id)
        setBatch((b) => (b ? { ...b, ...p } : b))
        if (p.status !== 'running') {
          if (poller.current) window.clearInterval(poller.current)
          poller.current = null
          const full = await api.getMigrationBatch(id)
          setBatch(full)
          setStep(stepFor(full))
          setBusy(false)
        }
      } catch (e) {
        setError(e instanceof Error ? e.message : 'Lost track of the run')
      }
    }, 1500)
  }

  async function run(action: () => Promise<MigrationBatch>) {
    setBusy(true)
    setError(null)
    try {
      const b = await action()
      setBatch(b)
      if (b.status === 'running') {
        watch(b.id)
      } else {
        setStep(stepFor(b))
        setBusy(false)
      }
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Something went wrong')
      setBusy(false)
    }
  }

  async function upload() {
    if (!file) return
    setBusy(true)
    setError(null)
    try {
      const b = await api.uploadMigrationFile(companyId, source, entity, file)
      if (b.status === 'failed') {
        setError(b.error_message ?? 'The file could not be read.')
        setBusy(false)
        return
      }
      openBatch(b, 2)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Upload failed')
    } finally {
      setBusy(false)
    }
  }

  function startOver() {
    setBatch(null)
    setFile(null)
    setStep(1)
    setDecisions({})
    const next = new URLSearchParams()
    next.set('company_id', companyId)
    next.set('source', source)
    next.set('entity', entity)
    setParams(next, { replace: true })
  }

  const moduleOptions = MIGRATION_MODULES.filter((m) => m.source === source)
  const problems = batch?.problems ?? []
  const toDecide = problems.filter((p) => p.outcome === 'needs_decision')
  const otherProblems = problems.filter((p) => p.outcome !== 'needs_decision')
  const undecided = toDecide.filter((p) => !decisions[p.source_ref ?? ''])
  const decisionsChanged = toDecide.some((p) => (decisions[p.source_ref ?? ''] ?? '') !== (batch?.decisions?.[p.source_ref ?? ''] ?? ''))
  const clean = batch?.status === 'dry_run' && batch.rows_failed === 0 && batch.rows_needs_decision === 0
  const importBlocker = !clean
    ? 'Fix the errors and decide the possible duplicates, then dry run again.'
    : mapping && !mapping.signed_off
      ? 'Sign off this module’s Field Gap list first (step 2).'
      : null
  const toImport = (batch?.rows_created ?? 0) + (batch?.rows_linked ?? 0)

  return (
    <div>
      <h1>Data Migration</h1>
      <p className="muted">
        Upload one module’s consolidated Excel file, map its columns, check a dry run, then import. The dry run does
        everything the import would and then undoes it, so what it reports is exactly what will happen. The import is all
        or nothing.
      </p>
      <MigrationTabs />
      {error && <div className="error-banner">{error}</div>}

      <div className="migration-steps">
        {STEPS.map((s) => (
          <div key={s.n} className={s.n === step ? 'current' : s.n < step ? 'done' : ''}>
            <b>{s.n < step ? '✓' : s.n}</b>
            {s.label}
          </div>
        ))}
      </div>

      {batch && (
        <p className="muted" style={{ marginTop: -6 }}>
          Batch <b>{batch.batch_number}</b> · <SourceTag source={batch.source} />
          {moduleLabel(batch.source, batch.entity)} · {batch.source_filename} · {batch.rows_read.toLocaleString('en-SG')} rows ·
          into <b>{batch.company_name}</b> ·{' '}
          <BatchStatusBadge status={batch.status} label={batch.status_label} hasErrors={batch.rows_failed + batch.rows_needs_decision > 0} />
        </p>
      )}

      {step === 1 && (
        <div className="card">
          <h2>Upload</h2>
          <div className="filter-bar">
            <InternalCompanySelect value={companyId} onChange={setCompanyId} label="Import into Internal Company *" />
            <div className="form-row" style={{ margin: 0 }}>
              <label htmlFor="up-source">Source system</label>
              <select
                id="up-source"
                value={source}
                onChange={(e) => {
                  const s = e.target.value as MigrationSource
                  setSource(s)
                  setEntity(MIGRATION_MODULES.find((m) => m.source === s)!.entity)
                }}
              >
                {SOURCES.map((s) => (
                  <option key={s.value} value={s.value}>
                    {s.label}
                  </option>
                ))}
              </select>
            </div>
            <div className="form-row" style={{ margin: 0 }}>
              <label htmlFor="up-module">Module</label>
              <select id="up-module" value={entity} onChange={(e) => setEntity(e.target.value)}>
                {moduleOptions.map((m) => (
                  <option key={m.entity} value={m.entity}>
                    {m.theirs} → {m.label}
                  </option>
                ))}
              </select>
            </div>
          </div>
          <label
            className={`migration-drop${dragOver ? ' over' : ''}`}
            onDragOver={(e) => {
              e.preventDefault()
              setDragOver(true)
            }}
            onDragLeave={() => setDragOver(false)}
            onDrop={(e) => {
              e.preventDefault()
              setDragOver(false)
              if (e.dataTransfer.files[0]) setFile(e.dataTransfer.files[0])
            }}
          >
            <b>Drop the Excel file here, or choose a file</b>
            <span>.xlsx or .csv · up to 20 MB · the first row must be the column headings</span>
            <input type="file" accept=".xlsx,.csv" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
            {file && <span className="badge draft">{file.name} · {(file.size / 1024).toFixed(0)} KB</span>}
          </label>
          <div className="button-row" style={{ justifyContent: 'flex-end' }}>
            <button type="button" onClick={upload} disabled={!file || !companyId || busy}>
              {busy ? 'Uploading...' : 'Upload and map fields'}
            </button>
          </div>
        </div>
      )}

      {step === 2 && batch && (
        <div className="card">
          <h2>Map fields ({batch.headers?.length ?? 0} columns)</h2>
          <p className="muted">
            Columns are matched automatically where the name is known. Change any line, then save. The mapping is kept
            for the next file of this module. Mark a column <b>Field Gap</b> if it needs a field added here first, or{' '}
            <b>Leave out</b> if it is not wanted.
          </p>
          <FieldMappingEditor
            companyId={batch.company_id}
            source={batch.source}
            entity={batch.entity}
            sample={batch.sample_row}
            onChanged={setMapping}
            onError={setError}
          />
          {batch.cutoff_applies && (
            <div className="form-row" style={{ maxWidth: 420 }}>
              <label htmlFor="migration-cutoff">Cut-off date (optional)</label>
              <DateInput id="migration-cutoff" value={cutoff} onChange={(e) => setCutoff(e.target.value)} />
              <span className="muted">
                Rows dated before this are left out, unless still open (unpaid, not yet accepted or rejected, not
                closed). Receipts and service records before it are always left out. Blank brings everything.
              </span>
            </div>
          )}
          <div className="button-row" style={{ justifyContent: 'flex-end' }}>
            <button type="button" className="secondary" onClick={startOver} disabled={busy}>
              Upload a different file
            </button>
            <button type="button" onClick={() => run(() => api.dryRunMigrationBatch(batch.id, cutoff || null))} disabled={busy}>
              {busy ? 'Running dry run...' : 'Next: dry run'}
            </button>
          </div>
        </div>
      )}

      {step === 3 && batch && (
        <>
          {batch.status === 'running' ? (
            <div className="card">
              <h2>Dry run in progress</h2>
              <Progress batch={batch} />
            </div>
          ) : (
            <>
              {batch.error_message && <div className="error-banner">{batch.error_message}</div>}
              {batch.cutoff_date && (
                <p className="muted">
                  Cut-off date {formatDate(batch.cutoff_date)}: rows dated before it and already closed are left out (counted
                  as skipped). Change it with Back to field mapping.
                </p>
              )}
              <div className="stat-grid">
                <Tile n={batch.rows_created} label="New records" />
                <Tile n={batch.rows_linked} label="Linked to existing (same UEN / GST no. or your choice)" />
                <Tile n={batch.rows_already_imported} label="Already imported earlier" />
                <Tile n={batch.rows_skipped} label={batch.cutoff_date ? 'Skipped (drafts, cancelled, credit notes, closed before the cut-off)' : 'Skipped (drafts, cancelled, credit notes)'} />
                <Tile n={batch.rows_needs_decision} label="Possible duplicates for you to decide" warn />
                <Tile n={batch.rows_failed} label="Errors (block the import)" danger />
              </div>

              {toDecide.length > 0 && (
                <div className="card">
                  <h2>Possible duplicates ({toDecide.length})</h2>
                  <p className="muted">
                    Same name as a Company / Individual already here, but no matching UEN or GST no. Nothing is merged or
                    overwritten: <b>Link</b> attaches this row to the existing record, <b>Create new</b> adds a separate one.
                  </p>
                  <div style={{ overflowX: 'auto' }}>
                    <table>
                      <thead>
                        <tr>
                          <th>Row</th>
                          <th>{batch.source_label}</th>
                          <th>Already here</th>
                          <th>Decision</th>
                        </tr>
                      </thead>
                      <tbody>
                        {toDecide.map((p) => (
                          <tr key={p.row}>
                            <td>{p.row}</td>
                            <td>
                              {p.source_ref}
                              <br />
                              <span className="muted">{p.message}</span>
                            </td>
                            <td>
                              {p.candidates?.map((c) => (
                                <div key={c.id}>
                                  {c.name}
                                  <br />
                                  <span className="muted small">{c.detail}</span>
                                </div>
                              ))}
                            </td>
                            <td>
                              <select
                                aria-label={`Decision for row ${p.row}`}
                                value={decisions[p.source_ref ?? ''] ?? ''}
                                onChange={(e) => setDecisions({ ...decisions, [p.source_ref ?? '']: e.target.value })}
                              >
                                <option value="">— decide —</option>
                                {p.candidates?.map((c) => (
                                  <option key={c.id} value={`link:${c.id}`}>
                                    Link to {c.name}
                                  </option>
                                ))}
                                <option value="new">Create new</option>
                              </select>
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                  <div className="button-row">
                    <button
                      type="button"
                      disabled={busy || undecided.length > 0 || !decisionsChanged}
                      onClick={() =>
                        run(async () => {
                          await api.saveMigrationDecisions(batch.id, decisions)
                          return api.dryRunMigrationBatch(batch.id, cutoff || null)
                        })
                      }
                    >
                      Save decisions and dry run again
                    </button>
                    {undecided.length > 0 && <span className="muted">{undecided.length} still to decide.</span>}
                  </div>
                </div>
              )}

              <div className="card">
                <div style={{ display: 'flex', justifyContent: 'space-between', flexWrap: 'wrap', gap: 10 }}>
                  <h2 style={{ margin: 0 }}>Errors, skipped rows and notes ({otherProblems.length})</h2>
                  <ExportControl
                    formats={[
                      { value: 'xlsx', label: 'Excel' },
                      { value: 'csv', label: 'CSV' },
                    ]}
                    onExport={async (f) => downloadBlob(await api.exportMigrationProblems(batch.id, f as 'csv' | 'xlsx'), `${batch.batch_number}-problems.${f}`)}
                    onError={setError}
                  />
                </div>
                <p className="muted">Fix errors in the Excel file and upload it again; row numbers are the file’s own.</p>
                <ProblemTable rows={otherProblems} />
              </div>

              <div className="button-row" style={{ justifyContent: 'flex-end' }}>
                <button type="button" className="secondary" onClick={() => setStep(2)} disabled={busy}>
                  Back to field mapping
                </button>
                <button type="button" className="secondary" onClick={startOver} disabled={busy}>
                  Upload a corrected file
                </button>
                <button type="button" className="secondary" onClick={() => run(() => api.dryRunMigrationBatch(batch.id, cutoff || null))} disabled={busy}>
                  Dry run again
                </button>
                <button type="button" onClick={() => setStep(4)} disabled={!!importBlocker || busy} title={importBlocker ?? undefined}>
                  Next: import
                </button>
              </div>
              {importBlocker && <p className="muted small" style={{ textAlign: 'right' }}>{importBlocker}</p>}
            </>
          )}
        </>
      )}

      {step === 4 && batch && (
        <div className="card">
          <h2>Import</h2>
          {batch.status === 'dry_run' && (
            <>
              <p>
                Ready to import <b>{toImport.toLocaleString('en-SG')}</b> {moduleLabel(batch.source, batch.entity).split('→ ')[1]} records from{' '}
                <b>{batch.source_label}</b> into <b>{batch.company_name}</b>: {batch.rows_created.toLocaleString('en-SG')} new,{' '}
                {batch.rows_linked.toLocaleString('en-SG')} linked to existing records
                {batch.rows_already_imported > 0 && `, ${batch.rows_already_imported.toLocaleString('en-SG')} already imported earlier (left as they are)`}.
              </p>
              <ul className="muted">
                <li>All or nothing: if anything fails, nothing is written.</li>
                <li>Runs in the background. You can leave this screen and watch the Dashboard.</li>
                <li>Recorded as batch {batch.batch_number} in the Batch Log and in Event Logs; it can be rolled back while untouched.</li>
              </ul>
              <div className="button-row" style={{ justifyContent: 'flex-end' }}>
                <button type="button" className="secondary" onClick={() => setStep(3)} disabled={busy}>
                  Back
                </button>
                <button type="button" onClick={() => run(() => api.importMigrationBatch(batch.id))} disabled={busy}>
                  {busy ? 'Importing...' : `Import ${toImport.toLocaleString('en-SG')} records`}
                </button>
              </div>
            </>
          )}
          {batch.status === 'running' && <Progress batch={batch} />}
          {batch.status === 'succeeded' && (
            <>
              <p>
                <span className="badge active">Imported</span> {batch.rows_created.toLocaleString('en-SG')} new and{' '}
                {batch.rows_linked.toLocaleString('en-SG')} linked records from {batch.source_filename} on {formatDateTime(batch.imported_at)} by{' '}
                {batch.started_by}.
              </p>
              <div className="button-row">
                <Link to={`/data-migration?company_id=${batch.company_id}`}>Back to the Dashboard</Link>
                <span className="muted">·</span>
                <Link to={`/data-migration/batches?company_id=${batch.company_id}`}>Batch Log</Link>
                <span className="muted">·</span>
                <button type="button" className="secondary" onClick={startOver}>
                  Import another file
                </button>
              </div>
            </>
          )}
          {batch.status === 'failed' && (
            <>
              <div className="error-banner">{batch.error_message ?? 'The import was refused; nothing was written.'}</div>
              <ProblemTable rows={problems.filter((p) => p.outcome === 'failed' || p.outcome === 'needs_decision')} />
              <button type="button" className="secondary" onClick={() => run(() => api.dryRunMigrationBatch(batch.id, cutoff || null))}>
                Dry run again
              </button>
            </>
          )}
          {batch.status === 'rolled_back' && (
            <p>
              <span className="badge exceeded">Rolled back</span> on {formatDateTime(batch.rolled_back_at)} by {batch.rolled_back_by}:{' '}
              {batch.rollback_reason}
            </p>
          )}
        </div>
      )}
    </div>
  )
}

function Tile({ n, label, warn, danger }: { n: number; label: string; warn?: boolean; danger?: boolean }) {
  const color = n > 0 && danger ? 'var(--danger)' : n > 0 && warn ? 'var(--warn-text)' : undefined
  return (
    <div className="card stat-tile" style={{ minHeight: 110 }}>
      <div className="stat-value" style={{ color }}>
        {n.toLocaleString('en-SG')}
      </div>
      <div className="stat-label">{label}</div>
    </div>
  )
}

function Progress({ batch }: { batch: MigrationBatch }) {
  const pct = batch.progress_total > 0 ? Math.min(100, Math.round((batch.progress_done / batch.progress_total) * 100)) : 0
  return (
    <div>
      <div className="migration-track">
        <div className="running" style={{ width: `${Math.max(pct, 3)}%` }} />
      </div>
      <p className="migration-bar-num" style={{ textAlign: 'left' }}>
        {batch.mode === 'commit' ? 'Importing' : 'Checking'}… {batch.progress_done.toLocaleString('en-SG')} of{' '}
        {batch.progress_total.toLocaleString('en-SG')} ({pct}%)
      </p>
    </div>
  )
}

function ProblemTable({ rows }: { rows: MigrationReportRow[] }) {
  if (rows.length === 0) return <p className="muted">None.</p>
  const shown = rows.slice(0, 300)
  return (
    <div style={{ overflowX: 'auto' }}>
      <table>
        <thead>
          <tr>
            <th>Row</th>
            <th>Source ID</th>
            <th>Outcome</th>
            <th>Detail</th>
          </tr>
        </thead>
        <tbody>
          {shown.map((p) => (
            <tr key={`${p.row}-${p.outcome}`}>
              <td>{p.row}</td>
              <td>{p.source_ref ?? '—'}</td>
              <td>
                <span className={`badge ${p.outcome === 'failed' ? 'status-blocked' : p.outcome === 'skipped' ? 'draft' : p.outcome === 'linked' ? 'status-watch' : 'status-in-progress'}`}>
                  {OUTCOME_LABEL[p.outcome] ?? p.outcome}
                </span>
              </td>
              <td>
                {p.message}
                {p.warnings?.map((w) => (
                  <div key={w} className="muted small">
                    ! {w}
                  </div>
                ))}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      {rows.length > shown.length && <p className="muted small">Showing {shown.length} of {rows.length}. Export for the full list.</p>}
    </div>
  )
}
