// The self-test's report (docs/self-test.md): report.json, which the
// nightly email is built from (backend-php's selftest:report command),
// and report.html, to open in a browser. Both land in the run's --out
// directory next to the failure screenshots.

import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'

const esc = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')

/** DD/MM/YYYY HH:mm in Singapore time, like every date in the app. */
function sgTime(d) {
  const p = Object.fromEntries(new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Singapore', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false }).formatToParts(d).map((x) => [x.type, x.value]))
  return `${p.day}/${p.month}/${p.year} ${p.hour}:${p.minute}`
}

export function writeReport({ results, out, base, commit, error }) {
  const finishedAt = new Date()
  const summary = { passed: results.count('passed'), failed: results.count('failed'), skipped: results.count('skipped') }
  const status = error ? 'error' : summary.failed > 0 ? 'failed' : 'passed'
  const failures = results.items
    .filter((i) => i.status === 'failed')
    .map((i) => ({
      profile: i.profile,
      name: i.name,
      screen: i.screen,
      message: i.message,
      screenshot: i.screenshot && fs.existsSync(path.join(out, i.screenshot)) ? fs.readFileSync(path.join(out, i.screenshot)).toString('base64') : null,
    }))

  const report = {
    status,
    started_at: results.startedAt.toISOString(),
    finished_at: finishedAt.toISOString(),
    duration_s: Math.round((finishedAt - results.startedAt) / 1000),
    commit,
    base_url: base,
    server: process.env.SELFTEST_SERVER_NAME || os.hostname(),
    // Inside the nightly runner container the folder is /out; the email names the server's own path.
    report_path: path.join(process.env.SELFTEST_REPORT_PATH || out, 'report.html'),
    summary,
    failures,
    warnings: results.items.filter((i) => i.status === 'skipped').map((i) => ({ profile: i.profile, screen: i.screen, message: `${i.name}: ${i.message}` })),
    error,
    checks: results.items.map(({ screenshot, ...rest }) => ({ ...rest, screenshot })),
  }
  fs.writeFileSync(path.join(out, 'report.json'), JSON.stringify(report, null, 1))

  const badge = { passed: '#2c6b2f', failed: '#c0362c', error: '#c0362c' }[status]
  const row = (i) => `<tr class="${i.status}"><td>${esc(i.profile)}</td><td>${esc(i.name)}</td><td><code>${esc(i.screen)}</code></td><td>${esc(i.status)}</td><td>${i.ms != null ? (i.ms / 1000).toFixed(1) + 's' : ''}</td><td>${i.message ? `<pre>${esc(i.message)}</pre>` : ''}${i.screenshot ? `<a href="${esc(i.screenshot)}"><img src="${esc(i.screenshot)}" loading="lazy"></a>` : ''}</td></tr>`
  const ordered = [...results.items].sort((a, b) => ({ failed: 0, skipped: 1, passed: 2 })[a.status] - ({ failed: 0, skipped: 1, passed: 2 })[b.status])
  const html = `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Websoft self-test ${esc(status)}</title>
<style>
 body{font:14px/1.45 -apple-system,"Segoe UI",Roboto,Arial,sans-serif;margin:16px;color:#1a1315}
 h1{font-size:20px;margin:0 0 4px} .muted{color:#6b6067}
 .badge{display:inline-block;color:#fff;background:${badge};border-radius:4px;padding:2px 10px;font-weight:600}
 table{border-collapse:collapse;width:100%;margin-top:12px} td,th{border-bottom:1px solid #e3dadb;padding:6px 8px;text-align:left;vertical-align:top}
 tr.failed td{background:#fdf1f0} tr.skipped td{background:#fbf8ef} pre{white-space:pre-wrap;margin:0 0 6px;font-size:12px}
 img{max-width:360px;border:1px solid #e3dadb;display:block} .wrap{overflow-x:auto}
</style></head><body>
<h1>Websoft self-test <span class="badge">${esc(status.toUpperCase())}</span></h1>
<div class="muted">${esc(sgTime(results.startedAt))} · ${esc(report.duration_s)}s · ${esc(base)} · ${esc(commit)}</div>
<p><b>${summary.passed}</b> passed · <b>${summary.failed}</b> failed · <b>${summary.skipped}</b> skipped</p>
${error ? `<p><b>The run stopped before it finished:</b></p><pre>${esc(error)}</pre>` : ''}
<div class="wrap"><table><thead><tr><th>Screen size</th><th>Check</th><th>Where</th><th>Result</th><th>Took</th><th>Details</th></tr></thead>
<tbody>${ordered.map(row).join('\n')}</tbody></table></div></body></html>`
  fs.writeFileSync(path.join(out, 'report.html'), html)
  return report
}
