#!/usr/bin/env node
// The self-test program (docs/self-test.md). Usually started by
// selftest/run.sh (a laptop / dev box) or selftest/run-server.sh (the
// nightly run on the test server), which first build the throwaway
// self-test database and app; this file only drives the browser.
//
//   node selftest/runner/run.mjs --base http://127.0.0.1:4180 [--out DIR]
//        [--only desktop|phone] [--grep TEXT] [--no-sweep] [--no-flows]
//
// Exit code 0 when every check passed, 1 when any failed, 2 when the run
// itself could not start.

import fs from 'node:fs'
import path from 'node:path'
import { execSync } from 'node:child_process'
import { REPO, Results, ensureDir, loadPlaywright, profiles, settle, watch, screenProblems } from './lib.mjs'
import { sweep } from './screens.mjs'
import { FLOWS } from './flows/index.mjs'
import { writeReport } from './report.mjs'

const args = process.argv.slice(2)
const arg = (name, dflt = null) => {
  const i = args.indexOf(`--${name}`)
  return i >= 0 ? args[i + 1] : dflt
}
const flag = (name) => args.includes(`--${name}`)

const BASE = arg('base')
const OUT = ensureDir(path.resolve(arg('out', path.join(REPO, 'selftest', 'results', 'latest'))))
const ONLY = arg('only')
const GREP = arg('grep') ? new RegExp(arg('grep'), 'i') : null
const EMAIL = process.env.SELFTEST_LOGIN_EMAIL || 'dennis@websoft.example'
const PASSWORD = process.env.SELFTEST_LOGIN_PASSWORD || 'demo1234'
const CHECK_TIMEOUT_MS = 120000

if (!BASE) {
  console.error('Usage: node selftest/runner/run.mjs --base <app address> [--out DIR] [--only desktop|phone] [--grep TEXT]')
  process.exit(2)
}

function commit() {
  if (process.env.SELFTEST_COMMIT) return process.env.SELFTEST_COMMIT
  try {
    return execSync('git log -1 --format="%h %s"', { cwd: REPO }).toString().trim()
  } catch {
    return ''
  }
}

const results = new Results()
const shotsDir = ensureDir(path.join(OUT, 'screenshots'))
for (const f of fs.readdirSync(shotsDir)) fs.rmSync(path.join(shotsDir, f))

/** The app may still be starting (the nightly run starts it moments before); give it two minutes. */
async function waitForApp() {
  for (let i = 0; i < 120; i++) {
    try {
      if ((await fetch(new URL('/api/health', BASE))).ok) return
    } catch {
      /* not up yet */
    }
    await new Promise((r) => setTimeout(r, 1000))
  }
  throw new Error(`The app at ${BASE} did not answer /api/health within two minutes.`)
}

async function main() {
  await waitForApp()
  const playwright = loadPlaywright()
  // SELFTEST_CHROMIUM: a Chromium already on the machine, for when
  // Playwright's own download is not there (e.g. an offline box).
  const browser = await playwright.chromium.launch(process.env.SELFTEST_CHROMIUM ? { executablePath: process.env.SELFTEST_CHROMIUM } : {})
  const all = profiles(playwright)
  console.log(`Self-test against ${BASE} -- ${commit()}`)

  for (const [profileName, profile] of Object.entries(all)) {
    if (ONLY && ONLY !== profileName) continue
    console.log(`\n== ${profileName} (${profile.viewport.width}x${profile.viewport.height}) ==`)
    const context = await browser.newContext({ ...profile, baseURL: BASE, locale: 'en-SG', timezoneId: 'Asia/Singapore' })
    context.setDefaultTimeout(15000)
    const page = await context.newPage()
    const watcher = watch(page)
    // A confirm is answered yes; a prompt for a reason gets one, as a person would give.
    page.on('dialog', (d) => d.accept(d.type() === 'prompt' ? 'Self-test' : undefined).catch(() => {}))
    let shotNo = 0

    const check = async (name, screen, fn) => {
      if (GREP && !GREP.test(name)) return true
      const t0 = Date.now()
      try {
        await Promise.race([fn(), new Promise((_, rej) => setTimeout(() => rej(new Error(`Did not finish within ${CHECK_TIMEOUT_MS / 1000}s.`)), CHECK_TIMEOUT_MS))])
        results.add({ profile: profileName, name, screen, status: 'passed', ms: Date.now() - t0 })
        return true
      } catch (e) {
        const file = `${profileName}-${String(++shotNo).padStart(3, '0')}.png`
        await page.screenshot({ path: path.join(shotsDir, file), fullPage: true, timeout: 10000 }).catch(() => {})
        // Playwright colours its messages for a terminal; the report wants plain text.
        const message = String(e?.message ?? e).replace(new RegExp(`${String.fromCharCode(27)}\\[[0-9;]*m`, 'g'), '').split('\nCall log:')[0].trim()
        results.add({ profile: profileName, name, screen: screen ?? new URL(page.url()).pathname, status: 'failed', message, screenshot: fs.existsSync(path.join(shotsDir, file)) ? `screenshots/${file}` : null, ms: Date.now() - t0 })
        return false
      }
    }
    const skip = (name, message, screen) => {
      if (GREP && !GREP.test(name)) return
      results.add({ profile: profileName, name, screen, status: 'skipped', message })
    }

    // 1. Sign in, through the real sign-in page and the one-time PDPA
    //    declaration a fresh account meets first.
    const signedIn = await check('Sign in', '/login', async () => {
      watcher.reset()
      await page.goto('/login')
      await page.locator('input[type=email]').fill(EMAIL)
      await page.locator('input[type=password]').fill(PASSWORD)
      await page.getByRole('button', { name: 'Sign in', exact: true }).click()
      await page.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 20000 })
      // Either the app's menu or the one-time declaration comes up next.
      const gate = page.getByRole('button', { name: 'Accept and continue' })
      // (The menu starts hidden after sign-in, so wait for the app itself.)
      await page.locator('.app-shell').or(gate).first().waitFor({ timeout: 20000 })
      if (await gate.isVisible()) {
        await page.locator('input[type=checkbox]').first().check()
        await gate.click()
        await gate.waitFor({ state: 'hidden' })
      }
      await settle(page)
      const problems = await screenProblems(page, watcher, profileName)
      if (problems.length) throw new Error(problems.join('\n'))
    })
    if (!signedIn) {
      skip('Everything after sign-in', 'Could not sign in, so nothing else was checked on this screen size.')
      await context.close()
      continue
    }

    // 2. Key-in flows: type into the forms, save, check what was stored.
    //    They run before the sweep so the sweep has records to open.
    if (!flag('no-flows')) {
      const shared = {}
      for (const flow of FLOWS) {
        if (flow.profiles && !flow.profiles.includes(profileName)) continue
        const missing = (flow.needs ?? []).filter((n) => !shared[n])
        if (missing.length) {
          skip(flow.name, `Skipped: needs ${missing.join(', ')}, which an earlier step did not produce.`, flow.screen)
          continue
        }
        await check(flow.name, flow.screen, async () => {
          watcher.reset()
          await flow.run({ page, watcher, profileName, shared })
          // The screen the flow ends on gets the same checks as every screen.
          await settle(page)
          const problems = await screenProblems(page, watcher, profileName)
          if (problems.length) throw new Error(problems.join('\n'))
        })
      }
    }

    // 3. Every screen.
    if (!flag('no-sweep')) {
      await sweep({ page, watcher, profileName, check, skip })
    }

    await context.close()
  }

  // 4. The Customer Helpdesk Portal's sign-in page (its own realm; no staff session).
  for (const [profileName, profile] of Object.entries(all)) {
    if (ONLY && ONLY !== profileName) continue
    if (GREP && !GREP.test('Helpdesk Portal sign-in')) continue
    const context = await browser.newContext({ ...profile, baseURL: BASE, locale: 'en-SG', timezoneId: 'Asia/Singapore' })
    const page = await context.newPage()
    const watcher = watch(page)
    const t0 = Date.now()
    try {
      await page.goto('/portal')
      await settle(page)
      await page.locator('input[type=password]').waitFor({ timeout: 10000 })
      const problems = await screenProblems(page, watcher, profileName)
      if (problems.length) throw new Error(problems.join('\n'))
      results.add({ profile: profileName, name: 'Helpdesk Portal sign-in page', screen: '/portal', status: 'passed', ms: Date.now() - t0 })
    } catch (e) {
      const file = `${profileName}-portal.png`
      await page.screenshot({ path: path.join(shotsDir, file), fullPage: true }).catch(() => {})
      results.add({ profile: profileName, name: 'Helpdesk Portal sign-in page', screen: '/portal', status: 'failed', message: String(e.message).split('\nCall log:')[0], screenshot: `screenshots/${file}` })
    }
    await context.close()
  }

  await browser.close()
}

let fatal = null
try {
  await main()
} catch (e) {
  fatal = e
  console.error(`\nThe self-test could not finish: ${e.stack ?? e}`)
}

const report = writeReport({ results, out: OUT, base: BASE, commit: commit(), error: fatal ? String(fatal.message ?? fatal) : null })
console.log(`\n${report.status.toUpperCase()}: ${report.summary.passed} passed, ${report.summary.failed} failed, ${report.summary.skipped} skipped -- ${path.join(OUT, 'report.html')}`)
process.exit(fatal ? 2 : report.summary.failed > 0 ? 1 : 0)
