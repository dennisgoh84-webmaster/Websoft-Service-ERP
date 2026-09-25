import { useEffect, useState } from 'react'
import { downloadBlob } from '../lib/api'

/**
 * Maintenance -> Outlook Add-in (docs/outlook-addin.md): hands an
 * administrator the add-in's manifest already filled in with this
 * server's address, and the steps to load it into Outlook. The add-in
 * itself is served by this app at /outlook-addin/.
 */
export default function OutlookAddinPage() {
  const host = window.location.host
  const https = window.location.protocol === 'https:'
  const [filesOk, setFilesOk] = useState<boolean | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    fetch('/outlook-addin/manifest.xml', { cache: 'no-store' })
      .then((r) => setFilesOk(r.ok && (r.headers.get('content-type') ?? '').includes('xml')))
      .catch(() => setFilesOk(false))
  }, [])

  async function downloadManifest() {
    setError(null)
    try {
      const res = await fetch('/outlook-addin/manifest.xml', { cache: 'no-store' })
      if (!res.ok) throw new Error('The add-in files are not on this server.')
      const xml = (await res.text()).split('{{HOST}}').join(host)
      downloadBlob(new Blob([xml], { type: 'application/xml' }), 'websoft-outlook-addin.xml')
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Could not build the manifest.')
    }
  }

  return (
    <div>
      <h1>Outlook Add-in</h1>
      <p className="muted">
        Adds a <b>Log Incident</b> button to Outlook. On any email it opens a panel with <b>Log as Incident</b> and{' '}
        <b>Convert to Job Order</b>, the same as the Incidents screen, and acknowledges the sender from the Helpdesk
        mailbox (Maintenance → System Email). Staff sign in to it with their usual Websoft email and password (and
        sign-in code, if one is set up), and need Edit access to Helpdesk / Service Operations to use it.
      </p>
      {error && <div className="error-banner">{error}</div>}

      <div className="card">
        <h2>This server</h2>
        <table>
          <tbody>
            <tr>
              <td>Address</td>
              <td>
                <code>{host}</code>
              </td>
              <td></td>
            </tr>
            <tr>
              <td>Secure connection (HTTPS)</td>
              <td>{https ? 'Yes' : 'No'}</td>
              <td>
                {https ? (
                  <span className="badge active">Ready</span>
                ) : (
                  <span className="badge status-blocked">Needed</span>
                )}
              </td>
            </tr>
            <tr>
              <td>Add-in files</td>
              <td>
                <code>/outlook-addin/</code>
              </td>
              <td>
                {filesOk === null ? (
                  <span className="muted">Checking…</span>
                ) : filesOk ? (
                  <span className="badge active">Found</span>
                ) : (
                  <span className="badge status-blocked">Missing</span>
                )}
              </td>
            </tr>
          </tbody>
        </table>
        {!https && (
          <p className="muted">
            Outlook only loads an add-in from an <b>https://</b> address. Open this page from the server’s HTTPS address
            (see DEPLOY.md section 4: a domain with a certificate, or a tunnel), then download the manifest from there,
            so it carries that address.
          </p>
        )}
      </div>

      <div className="card">
        <h2>Install</h2>
        <ol>
          <li>
            <p>
              Download the manifest. It is filled in with <code>{host}</code>, the address you opened this page from.
            </p>
            <button type="button" onClick={downloadManifest} disabled={filesOk === false}>
              Download manifest
            </button>
          </li>
          <li>
            <p>
              <b>For the whole company</b> (Microsoft 365 administrator): Microsoft 365 admin center → Settings →
              Integrated apps → Upload custom apps → Office Add-in → upload the manifest, then choose who gets it.
              It can take up to a day to appear in everyone’s Outlook.
            </p>
            <p>
              <b>Or just for yourself, to try it first:</b> Outlook on the web → open any email → … (More actions) → Get
              Add-ins → My add-ins → Add a custom add-in → Add from file → the manifest.
            </p>
          </li>
          <li>
            <p>
              Open an email and click <b>Log Incident</b> on the ribbon (in Outlook on the web it is under the … menu of
              the email). Sign in once, then use <b>Log as Incident</b> or <b>Convert to Job Order</b>.
            </p>
          </li>
        </ol>
      </div>
    </div>
  )
}
