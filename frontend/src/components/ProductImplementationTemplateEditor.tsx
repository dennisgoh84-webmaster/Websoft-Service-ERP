import { useEffect, useState } from 'react'
import { api } from '../lib/api'

/**
 * NEW FEATURE (not a Python->PHP conversion -- see
 * docs/backlog.md / docs/planned-work.md): "Product - To add in Job
 * Implementation Template". A reusable, ordered task checklist edited
 * as a whole (add/remove/reorder rows, then Save replaces the whole
 * list) -- same "edit the whole list, save wholesale" pattern as the
 * Contract detail page's product coverage editor.
 */
export default function ProductImplementationTemplateEditor({ productId }: { productId: string }) {
  const [tasks, setTasks] = useState<{ task_name: string; description: string }[]>([])
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [message, setMessage] = useState<string | null>(null)

  useEffect(() => {
    setLoading(true)
    api
      .getProductImplementationTemplate(productId)
      .then((t) => setTasks(t.tasks.map((x) => ({ task_name: x.task_name, description: x.description ?? '' }))))
      .catch((e) => setError(e instanceof Error ? e.message : 'Failed to load template'))
      .finally(() => setLoading(false))
  }, [productId])

  function addRow() {
    setTasks([...tasks, { task_name: '', description: '' }])
  }

  function updateRow(index: number, field: 'task_name' | 'description', value: string) {
    setTasks(tasks.map((t, i) => (i === index ? { ...t, [field]: value } : t)))
  }

  function removeRow(index: number) {
    setTasks(tasks.filter((_, i) => i !== index))
  }

  function moveRow(index: number, delta: number) {
    const target = index + delta
    if (target < 0 || target >= tasks.length) return
    const next = [...tasks]
    ;[next[index], next[target]] = [next[target], next[index]]
    setTasks(next)
  }

  async function onSave() {
    setError(null)
    setMessage(null)
    const cleaned = tasks.filter((t) => t.task_name.trim() !== '')
    setSaving(true)
    try {
      const result = await api.setProductImplementationTemplate(
        productId,
        cleaned.map((t) => ({ task_name: t.task_name, description: t.description || undefined })),
      )
      setTasks(result.tasks.map((x) => ({ task_name: x.task_name, description: x.description ?? '' })))
      setMessage('Job Implementation Template saved.')
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to save template')
    } finally {
      setSaving(false)
    }
  }

  if (loading) return <p className="muted">Loading template...</p>

  return (
    <div>
      <p className="muted" style={{ marginTop: 0 }}>
        Selecting this product on a Job Order copies this checklist onto it as tasks.
      </p>
      {error && <div className="error-banner">{error}</div>}
      {message && <p className="muted">{message}</p>}
      <table>
        <thead>
          <tr>
            <th style={{ width: 32 }}></th>
            <th>Task name</th>
            <th>Description</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          {tasks.map((t, i) => (
            <tr key={i}>
              <td className="muted">{i + 1}</td>
              <td>
                <input value={t.task_name} onChange={(e) => updateRow(i, 'task_name', e.target.value)} style={{ width: '100%' }} />
              </td>
              <td>
                <input
                  value={t.description}
                  onChange={(e) => updateRow(i, 'description', e.target.value)}
                  style={{ width: '100%' }}
                />
              </td>
              <td style={{ display: 'flex', gap: 4 }}>
                <button type="button" className="secondary" onClick={() => moveRow(i, -1)} disabled={i === 0}>
                  ↑
                </button>
                <button type="button" className="secondary" onClick={() => moveRow(i, 1)} disabled={i === tasks.length - 1}>
                  ↓
                </button>
                <button type="button" className="secondary" onClick={() => removeRow(i)}>
                  Remove
                </button>
              </td>
            </tr>
          ))}
          {tasks.length === 0 && (
            <tr>
              <td colSpan={4} className="muted">
                No tasks yet.
              </td>
            </tr>
          )}
        </tbody>
      </table>
      <div style={{ display: 'flex', gap: 8, marginTop: 10 }}>
        <button type="button" className="secondary" onClick={addRow}>
          Add task
        </button>
        <button type="button" onClick={onSave} disabled={saving}>
          {saving ? 'Saving...' : 'Save template'}
        </button>
      </div>
    </div>
  )
}
