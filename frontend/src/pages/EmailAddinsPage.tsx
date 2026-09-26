import { useEffect, useState, type ReactNode } from 'react'
import { downloadBlob } from '../lib/api'

/**
 * Maintenance -> Email Add-ins (docs/outlook-addin.md): the Outlook
 * Add-in and its Gmail twin, both putting "Log as Incident" and
 * "Convert to Job Order" on an open email. Hands an administrator each
 * one's files already filled in with this server's address, and the
 * steps to install them. The files themselves are served by this app
 * at /outlook-addin/ and /gmail-addon/.
 */

/** Fetches one of the add-in files; null when this server does not have it. */
async function addinFile(path: string, mustContain: string): Promise<string | null> {
  try {
    const res = await fetch(path, { cache: 'no-store' })
    // A missing file comes back as the app's own index.html, not a 404.
    const text = res.ok ? await res.text() : ''
    return text.includes(mustContain) ? text : null
  } catch {
    return null
  }
}

/**
 * Google's servers run the Gmail add-on and call this server, so an
 * address only reachable inside the office (192.168.x.x, a .local name,
 * localhost) can never work for it -- and Apps Script will not even
 * save an appsscript.json whose address is not a public https:// one.
 */
function isPrivateHost(hostname: string): boolean {
  const h = hostname.toLowerCase()
  if (h === 'localhost' || h.endsWith('.local') || h.endsWith('.lan') || h.endsWith('.internal')) return true
  if (!h.includes('.') || h.includes(':')) return true // single-label names, IPv6 literals
  const m = /^(\d+)\.(\d+)\.\d+\.\d+$/.exec(h)
  if (!m) return false
  const [a, b] = [Number(m[1]), Number(m[2])]
  return a === 10 || a === 127 || (a === 192 && b === 168) || (a === 172 && b >= 16 && b <= 31) || (a === 169 && b === 254) || (a === 100 && b >= 64 && b <= 127)
}

function Badge({ ok, yes, no }: { ok: boolean | null; yes: string; no: string }) {
  if (ok === null) return <span className="muted">Checking…</span>
  return ok ? <span className="badge active">{yes}</span> : <span className="badge status-blocked">{no}</span>
}

function StatusRow({ label, value, ok, yes, no }: { label: string; value: ReactNode; ok: boolean | null; yes: string; no: string }) {
  return (
    <tr>
      <td>{label}</td>
      <td>{value}</td>
      <td>
        <Badge ok={ok} yes={yes} no={no} />
      </td>
    </tr>
  )
}

export default function EmailAddinsPage() {
  const host = window.location.host
  const origin = window.location.origin
  const https = window.location.protocol === 'https:'
  const privateHost = isPrivateHost(window.location.hostname)
  const gmailReady = https && !privateHost
  const [outlookOk, setOutlookOk] = useState<boolean | null>(null)
  const [gmailOk, setGmailOk] = useState<boolean | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    addinFile('/outlook-addin/manifest.xml', '{{HOST}}').then((t) => setOutlookOk(t !== null))
    addinFile('/gmail-addon/Code.gs', '{{BASE_URL}}').then((t) => setGmailOk(t !== null))
  }, [])

  async function download(path: string, placeholder: string, value: string, filename: string, type: string) {
    setError(null)
    const text = await addinFile(path, placeholder)
    if (text === null) {
      setError('The add-in files are not on this server.')
      return
    }
    downloadBlob(new Blob([text.split(placeholder).join(value)], { type }), filename)
  }

  return (
    <div>
      <h1>Email Add-ins</h1>
      <p className="muted">
        Puts <b>Log as Incident</b> and <b>Convert to Job Order</b> on any email, in Outlook or in Gmail, the same as the
        Incidents screen, and acknowledges the sender from the Helpdesk mailbox (Maintenance → System Email). Staff need
        Edit access to Helpdesk / Service Operations to use them. Install whichever your helpdesk inbox is read in, or
        both.
      </p>
      {error && <div className="error-banner">{error}</div>}

      <div className="card">
        <h2>This server</h2>
        <table>
          <tbody>
            <tr>
              <td>Address</td>
              <td>
                <code>{origin}</code>
              </td>
              <td></td>
            </tr>
            <StatusRow label="Secure connection (HTTPS)" value={https ? 'Yes' : 'No'} ok={https} yes="Ready" no="Needed" />
            <StatusRow label="Outlook Add-in files" value={<code>/outlook-addin/</code>} ok={outlookOk} yes="Found" no="Missing" />
            <StatusRow label="Gmail add-on files" value={<code>/gmail-addon/</code>} ok={gmailOk} yes="Found" no="Missing" />
            <StatusRow
              label="Reachable by Google (Gmail)"
              value={privateHost ? 'No: an office-only address' : https ? 'Public https:// address' : 'No: not https://'}
              ok={gmailReady}
              yes="Ready"
              no="Needed"
            />
          </tbody>
        </table>
        {https && privateHost && (
          <p className="muted">
            <b>{window.location.hostname}</b> is only reachable inside your own network. That is fine for Outlook on
            office PCs that trust this server’s certificate, but <b>not for Gmail</b>: Google’s servers run the Gmail
            add-on and cannot reach it, and Google will not save the add-on’s appsscript.json with it. Give the server a
            public https:// address (DEPLOY.md section 4: a domain, or a Cloudflare tunnel), open this page from that
            address, and download the Gmail files from there.
          </p>
        )}
        {!https && (
          <p className="muted">
            Both need this server on an <b>https://</b> address: Outlook will not load an add-in from anything else, and
            Google only lets the Gmail add-on reach an https:// address. Open this page from the server’s HTTPS address
            (DEPLOY.md section 4: a domain with a certificate, or a tunnel) and download the files from there, so they
            carry that address.
          </p>
        )}
      </div>

      <div className="card">
        <h2>Outlook</h2>
        <p className="muted">
          A <b>Log Incident</b> button on the Outlook ribbon. Staff sign in to it with their usual Websoft email and
          password (and sign-in code, if one is set up).
        </p>
        <ol>
          <li>
            <p>
              Download the manifest. It is filled in with <code>{host}</code>, the address you opened this page from.
            </p>
            <button
              type="button"
              onClick={() => download('/outlook-addin/manifest.xml', '{{HOST}}', host, 'websoft-outlook-addin.xml', 'application/xml')}
              disabled={outlookOk === false}
            >
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

      <div className="card">
        <h2>Gmail</h2>
        <p className="muted">
          A <b>Websoft Incidents</b> panel on the right of Gmail. It never asks for a password: each person connects it
          once with a one-time code from Websoft, from the panel’s <b>Get a code</b> link (it opens{' '}
          <code>{origin}/connect-addin</code>). Connecting again is needed when the Websoft session ends (after 8 hours, by default).
        </p>
        <ol>
          <li>
            <p>
              Download the two files. Both are filled in with <code>{origin}</code>
              {gmailReady ? '.' : ', which Gmail cannot use: open this page from the server’s public https:// address first (see This server above).'}
            </p>
            <div className="button-row">
              <button
                type="button"
                onClick={() => download('/gmail-addon/Code.gs', '{{BASE_URL}}', origin, 'Code.gs', 'text/plain')}
                disabled={gmailOk === false || !gmailReady}
              >
                Download Code.gs
              </button>
              <button
                type="button"
                className="secondary"
                onClick={() => download('/gmail-addon/appsscript.json', '{{BASE_URL}}', origin, 'appsscript.json', 'application/json')}
                disabled={gmailOk === false || !gmailReady}
              >
                Download appsscript.json
              </button>
            </div>
          </li>
          <li>
            <p>
              Signed in to Google as the helpdesk account, open <b>script.google.com</b> → New project, and name it
              “Websoft Incidents”. Replace everything in <b>Code.gs</b> with the downloaded Code.gs.
            </p>
          </li>
          <li>
            <p>
              Project Settings (the gear) → tick <b>Show “appsscript.json” manifest file in editor</b>. Back in the
              editor, replace everything in <b>appsscript.json</b> with the downloaded one, and Save.
            </p>
          </li>
          <li>
            <p>
              <b>Just for this Google account:</b> Deploy → Test deployments → Install. Reload Gmail, open an email and
              click the Websoft icon on the right; allow the access Google asks for, then <b>Get a code</b> and{' '}
              <b>Connect</b>.
            </p>
            <p>
              <b>For everyone in a Google Workspace company:</b> a Workspace administrator publishes the same project
              privately to the company through the Google Workspace Marketplace SDK (it needs a Google Cloud project
              linked to the script). A personal @gmail.com account can only use the step above.
            </p>
          </li>
        </ol>
      </div>
    </div>
  )
}
