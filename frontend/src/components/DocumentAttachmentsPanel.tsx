/**
 * Reusable file-attachment panel for any document type.
 *
 * Drop this onto any detail page (Quotation, Invoice, PO, PV, SR, etc.)
 * and pass the entity type + id. Handles upload, list, download, delete.
 */
import { useEffect, useRef, useState } from 'react'
import { api, type DocumentAttachment, type DocumentEntityType, getToken } from '../lib/api'
import { getDeviceId } from '../lib/deviceId'

interface Props {
  entityType: DocumentEntityType
  entityId: string
}

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

export default function DocumentAttachmentsPanel({ entityType, entityId }: Props) {
  const [attachments, setAttachments] = useState<DocumentAttachment[]>([])
  const [uploading, setUploading] = useState(false)
  const [error, setError] = useState('')
  const fileRef = useRef<HTMLInputElement>(null)

  const load = () => {
    api.listDocumentAttachments(entityType, entityId).then(setAttachments).catch(() => {})
  }

  useEffect(load, [entityType, entityId])

  const handleUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    if (!file) return
    setUploading(true)
    setError('')
    try {
      await api.uploadDocumentAttachment(entityType, entityId, file)
      load()
    } catch (err: any) {
      setError(err.message || 'Upload failed')
    } finally {
      setUploading(false)
      if (fileRef.current) fileRef.current.value = ''
    }
  }

  const handleDelete = async (att: DocumentAttachment) => {
    if (!confirm(`Delete "${att.original_filename}"?`)) return
    try {
      await api.deleteDocumentAttachment(entityType, entityId, att.id)
      load()
    } catch (err: any) {
      setError(err.message || 'Delete failed')
    }
  }

  const handleDownload = async (att: DocumentAttachment) => {
    const token = getToken()
    const res = await fetch(api.downloadDocumentAttachmentUrl(entityType, entityId, att.id), {
      headers: {
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
        'X-Device-Id': getDeviceId(),
      },
    })
    if (!res.ok) return
    const blob = await res.blob()
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = att.original_filename
    document.body.appendChild(a)
    a.click()
    a.remove()
    URL.revokeObjectURL(url)
  }

  return (
    <div style={{ marginTop: 24 }}>
      <h3>📎 Attachments</h3>
      {error && <p className="error">{error}</p>}

      <div style={{ marginBottom: 12 }}>
        <input
          type="file"
          ref={fileRef}
          onChange={handleUpload}
          disabled={uploading}
          style={{ display: 'none' }}
        />
        <button
          className="primary"
          onClick={() => fileRef.current?.click()}
          disabled={uploading}
        >
          {uploading ? 'Uploading…' : '⬆ Upload File'}
        </button>
        <small style={{ marginLeft: 8, opacity: 0.7 }}>Max 20 MB per file</small>
      </div>

      {attachments.length === 0 ? (
        <p style={{ opacity: 0.6 }}>No attachments yet.</p>
      ) : (
        <table className="data-table" style={{ fontSize: '0.9em' }}>
          <thead>
            <tr>
              <th>Filename</th>
              <th>Type</th>
              <th>Size</th>
              <th>Uploaded</th>
              <th style={{ width: 120 }}>Actions</th>
            </tr>
          </thead>
          <tbody>
            {attachments.map((att) => (
              <tr key={att.id}>
                <td>{att.original_filename}</td>
                <td>{att.content_type}</td>
                <td>{formatBytes(att.file_size_bytes)}</td>
                <td>{new Date(att.uploaded_at).toLocaleDateString()}</td>
                <td>
                  <button
                    className="secondary"
                    style={{ marginRight: 4, fontSize: '0.85em', padding: '2px 8px' }}
                    onClick={() => handleDownload(att)}
                    title="Download"
                  >
                    ⬇
                  </button>
                  <button
                    className="secondary"
                    style={{ fontSize: '0.85em', padding: '2px 8px', color: 'var(--danger, #c0392b)' }}
                    onClick={() => handleDelete(att)}
                    title="Delete"
                  >
                    🗑
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  )
}
