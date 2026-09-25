// Shared pieces of the Data Migration screens (Maintenance -> Data
// Migration, docs/data-migration.md): the tab row between the four
// screens, the single Internal Company picker every step needs, the
// source tag and status badges, and the Field Gap / field-mapping editor
// used both on the Migration Modules screen and in step 2 of an import.
import { useEffect, useState } from 'react'
import { NavLink, useSearchParams } from 'react-router-dom'
import ExportControl from './ExportControl'
import { useAuth } from '../lib/AuthContext'
import { formatDateTime } from '../lib/format'
import {
  api,
  downloadBlob,
  type Company,
  type MigrationBatchStatus,
  type MigrationMappingInfo,
  type MigrationModuleStatus,
  type MigrationSource,
} from '../lib/api'

export const SOURCES: { value: MigrationSource; label: string }[] = [
  { value: 'odoo', label: 'ODOO' },
  { value: 'zsoft', label: 'ZSOFT' },
]

/** Every module in run order -- mirrors App\Services\DataMigration\MigrationCatalog::MODULES. */
export const MIGRATION_MODULES: { source: MigrationSource; entity: string; theirs: string; label: string }[] = [
  { source: 'odoo', entity: 'company_individuals', theirs: 'Contacts', label: 'Company / Individual' },
  { source: 'odoo', entity: 'contracts', theirs: 'Subscriptions', label: 'Contracts' },
  { source: 'odoo', entity: 'quotations', theirs: 'Sales Quotations', label: 'Quotations' },
  { source: 'odoo', entity: 'invoices', theirs: 'Sales Invoices', label: 'Sales Invoices (history)' },
  { source: 'odoo', entity: 'receipts', theirs: 'Customer Payments', label: 'Receipts (history)' },
  { source: 'odoo', entity: 'service_records', theirs: 'Timesheets', label: 'Service Records' },
  { source: 'zsoft', entity: 'company_individuals', theirs: 'Customers', label: 'Company / Individual' },
  { source: 'zsoft', entity: 'contracts', theirs: 'Contracts', label: 'Contracts' },
  { source: 'zsoft', entity: 'job_orders', theirs: 'Job Orders', label: 'Job Orders' },
  { source: 'zsoft', entity: 'service_records', theirs: 'Service Records', label: 'Service Records' },
  { source: 'zsoft', entity: 'invoices', theirs: 'Past Invoices', label: 'Sales Invoices (history)' },
]

export function moduleLabel(source: string, entity: string): string {
  const m = MIGRATION_MODULES.find((x) => x.source === source && x.entity === entity)
  return m ? `${m.theirs} → ${m.label}` : entity
}

/** The tab row across the four Data Migration screens. */
export function MigrationTabs() {
  const [params] = useSearchParams()
  const company = params.get('company_id')
  const q = company ? `?company_id=${company}` : ''
  const tabs = [
    { to: '/data-migration', label: 'Dashboard', end: true },
    { to: '/data-migration/modules', label: 'Migration Modules', end: false },
    { to: '/data-migration/import', label: 'Import', end: false },
    { to: '/data-migration/batches', label: 'Batch Log', end: false },
  ]
  return (
    <div className="migration-tabs no-print">
      {tabs.map((t) => (
        <NavLink key={t.to} to={t.to + q} end={t.end} className={({ isActive }) => (isActive ? 'on' : '')}>
          {t.label}
        </NavLink>
      ))}
    </div>
  )
}

/**
 * The Internal Company the screen works in, kept in the URL
 * (?company_id=) so moving between the Data Migration screens keeps it.
 * Defaults to the company you are signed in to, like the report filters.
 */
export function useMigrationCompany(): [string, (id: string) => void] {
  const { user } = useAuth()
  const [params, setParams] = useSearchParams()
  const companyId = params.get('company_id') || user?.company_id || ''
  const set = (id: string) => {
    const next = new URLSearchParams(params)
    next.set('company_id', id)
    setParams(next, { replace: true })
  }
  return [companyId, set]
}

export function InternalCompanySelect({ value, onChange, disabled, label = 'Internal Company' }: { value: string; onChange: (id: string) => void; disabled?: boolean; label?: string }) {
  const { user } = useAuth()
  const [companies, setCompanies] = useState<Company[]>([])
  useEffect(() => {
    api
      .listMyCompanies()
      .then((cs) => setCompanies([...cs].sort((a, b) => Number(b.id === user?.company_id) - Number(a.id === user?.company_id))))
      .catch(() => setCompanies([]))
  }, [user?.company_id])
  return (
    <div className="form-row" style={{ margin: 0 }}>
      <label htmlFor="migration-company">{label}</label>
      <select id="migration-company" value={value} onChange={(e) => onChange(e.target.value)} disabled={disabled}>
        {companies.map((c) => (
          <option key={c.id} value={c.id}>
            {c.code} {c.name}
          </option>
        ))}
      </select>
    </div>
  )
}

export function SourceTag({ source }: { source: string }) {
  return <span className={`source-tag ${source}`}>{source.toUpperCase()}</span>
}

const MODULE_STATUS: Record<MigrationModuleStatus, [string, string]> = {
  not_started: ['Not started', 'draft'],
  uploaded: ['Uploaded', 'draft'],
  checking: ['Dry run in progress', 'status-in-progress'],
  has_errors: ['Has errors', 'status-blocked'],
  ready_to_import: ['Ready to import', 'status-watch'],
  importing: ['Importing', 'status-in-progress'],
  partly_imported: ['Partly imported', 'exceeded'],
  complete: ['Complete', 'active'],
}

export function ModuleStatusBadge({ status }: { status: MigrationModuleStatus }) {
  const [label, cls] = MODULE_STATUS[status] ?? [status, 'draft']
  return <span className={`badge ${cls}`}>{label}</span>
}

const BATCH_STATUS_CLASS: Record<MigrationBatchStatus, string> = {
  uploaded: 'draft',
  running: 'status-in-progress',
  dry_run: 'status-watch',
  failed: 'status-blocked',
  succeeded: 'active',
  rolled_back: 'exceeded',
}

export function BatchStatusBadge({ status, label, hasErrors }: { status: MigrationBatchStatus; label: string; hasErrors?: boolean }) {
  const cls = status === 'dry_run' && hasErrors ? 'status-blocked' : BATCH_STATUS_CLASS[status]
  return <span className={`badge ${cls}`}>{label}</span>
}

const SKIP = '__skip__'
const NEW_FIELD = '__new_field__'

/**
 * Map each old-system column to a field here, leave it out, or mark it a
 * Field Gap (needs a field added here first). Import stays locked until
 * every column is decided and a FULL user has signed the list off.
 */
export function FieldMappingEditor({
  companyId,
  source,
  entity,
  sample,
  onChanged,
  onError,
}: {
  companyId: string
  source: MigrationSource
  entity: string
  /** First data row of the uploaded file, shown beside each column. */
  sample?: Record<string, string>
  onChanged?: (info: MigrationMappingInfo) => void
  onError: (message: string) => void
}) {
  const [info, setInfo] = useState<MigrationMappingInfo | null>(null)
  const [draft, setDraft] = useState<Record<string, string | null>>({})
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    api
      .getMigrationMapping(source, entity, companyId)
      .then((i) => {
        setInfo(i)
        setDraft({})
      })
      .catch((e) => onError(e.message))
  }, [companyId, source, entity])

  if (!info) return <p className="muted">Loading the field mapping...</p>

  const dirty = Object.keys(draft).length > 0
  const valueOf = (header: string, field: string | null) => (header in draft ? draft[header] : field)
  const usedFields = new Set(info.columns.map((c) => valueOf(c.header, c.field)).filter((f): f is string => !!f && f !== SKIP && f !== NEW_FIELD))

  async function save() {
    setSaving(true)
    try {
      const next = await api.updateMigrationMapping(source, entity, companyId, draft)
      setInfo(next)
      setDraft({})
      onChanged?.(next)
    } catch (e) {
      onError(e instanceof Error ? e.message : 'Could not save the mapping')
    } finally {
      setSaving(false)
    }
  }

  async function signOff() {
    setSaving(true)
    try {
      const next = await api.signOffMigrationMapping(source, entity, companyId)
      setInfo(next)
      onChanged?.(next)
    } catch (e) {
      onError(e instanceof Error ? e.message : 'Could not sign off')
    } finally {
      setSaving(false)
    }
  }

  if (info.columns.length === 0) {
    return <p className="muted">No file uploaded for this module yet. Upload one under Import; its columns then appear here.</p>
  }

  return (
    <div className="field-mapping">
      <div className="field-mapping-summary">
        <span className={`badge ${info.signed_off ? 'active' : info.can_sign_off ? 'status-watch' : 'status-not-started'}`}>
          {info.signed_off
            ? `Signed off by ${info.signed_off_by ?? '—'} on ${formatDateTime(info.signed_off_at)}`
            : `${info.undecided} undecided · ${info.gaps} Field Gap${info.gaps === 1 ? '' : 's'}`}
        </span>
        {info.missing_required.length > 0 && (
          <span className="badge status-blocked">Still needs a column for: {info.missing_required.join(', ')}</span>
        )}
        <ExportControl
          formats={[
            { value: 'xlsx', label: 'Excel' },
            { value: 'csv', label: 'CSV' },
          ]}
          onExport={async (f) => downloadBlob(await api.exportMigrationFieldGap(source, entity, companyId, f as 'csv' | 'xlsx'), `field-gap-${source}-${entity}.${f}`)}
          onError={onError}
        />
      </div>
      <div style={{ overflowX: 'auto' }}>
        <table>
          <thead>
            <tr>
              <th>{source.toUpperCase()} column</th>
              {sample && <th>Sample value</th>}
              <th>Websoft field</th>
              <th>Decision</th>
            </tr>
          </thead>
          <tbody>
            {info.columns.map((c) => {
              const v = valueOf(c.header, c.field)
              const state = v === null ? 'undecided' : v === SKIP ? 'left_out' : v === NEW_FIELD ? 'field_gap' : 'mapped'
              return (
                <tr key={c.header} className={state === 'undecided' || state === 'field_gap' ? 'row-attention' : ''}>
                  <td>
                    <code>{c.header}</code>
                  </td>
                  {sample && <td className="muted">{sample[c.header] || '—'}</td>}
                  <td>
                    <select
                      aria-label={`Map ${c.header}`}
                      value={v ?? ''}
                      onChange={(e) => setDraft({ ...draft, [c.header]: e.target.value === '' ? null : e.target.value })}
                    >
                      <option value="">— decide —</option>
                      <optgroup label="Map to">
                        {info.fields.map((f) => (
                          <option key={f.key} value={f.key} disabled={usedFields.has(f.key) && v !== f.key}>
                            {f.label}
                            {f.required ? ' *' : ''}
                          </option>
                        ))}
                      </optgroup>
                      <option value={SKIP}>Leave out (not imported)</option>
                      <option value={NEW_FIELD}>Field Gap: needs a new field here</option>
                    </select>
                  </td>
                  <td>
                    {state === 'mapped' && <span className="badge active">Mapped</span>}
                    {state === 'left_out' && <span className="badge draft">Left out</span>}
                    {state === 'field_gap' && <span className="badge exceeded">Field Gap</span>}
                    {state === 'undecided' && <span className="badge status-not-started">Undecided</span>}
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
      <p className="muted small">* required. Only one column can map to each field.</p>
      <div className="button-row">
        <button type="button" onClick={save} disabled={!dirty || saving}>
          {saving ? 'Saving...' : 'Save mapping'}
        </button>
        <button type="button" className="secondary" onClick={() => setDraft({})} disabled={!dirty || saving}>
          Discard changes
        </button>
        <button type="button" className="secondary" onClick={signOff} disabled={dirty || saving || !info.can_sign_off || info.signed_off}>
          {info.signed_off ? 'Signed off' : 'Sign off Field Gap list'}
        </button>
      </div>
    </div>
  )
}

/**
 * A date typed as DD/MM/YYYY (Dennis's standing rule), whatever the
 * browser's own locale would make a native date picker show. Reports
 * the ISO date (YYYY-MM-DD) once what is typed is a real date, and ''
 * when cleared.
 */
export function DmyDateInput({ id, label, value, onChange }: { id: string; label: string; value: string; onChange: (iso: string) => void }) {
  const toDmy = (iso: string) => (iso ? iso.split('-').reverse().join('/') : '')
  const [text, setText] = useState(toDmy(value))
  useEffect(() => setText(toDmy(value)), [value])
  const [bad, setBad] = useState(false)
  function commit(t: string) {
    setText(t)
    if (t.trim() === '') {
      setBad(false)
      onChange('')
      return
    }
    const m = /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/.exec(t.trim())
    if (!m) {
      setBad(true)
      return
    }
    const [d, mo, y] = [Number(m[1]), Number(m[2]), Number(m[3])]
    const date = new Date(Date.UTC(y, mo - 1, d))
    if (date.getUTCDate() !== d || date.getUTCMonth() !== mo - 1) {
      setBad(true)
      return
    }
    setBad(false)
    onChange(`${y}-${String(mo).padStart(2, '0')}-${String(d).padStart(2, '0')}`)
  }
  return (
    <div className="form-row">
      <label htmlFor={id}>{label}</label>
      <input
        id={id}
        value={text}
        placeholder="DD/MM/YYYY"
        inputMode="numeric"
        aria-invalid={bad}
        style={bad ? { borderColor: 'var(--danger)' } : undefined}
        onChange={(e) => commit(e.target.value)}
      />
    </div>
  )
}

