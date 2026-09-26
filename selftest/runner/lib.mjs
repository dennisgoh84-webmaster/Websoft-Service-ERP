// The self-test program's harness (docs/self-test.md): the two screen
// profiles, the checks every page gets, the key-in helpers, and the
// result list the report is built from.
//
// Plain Node on the `playwright` library the frontend already depends
// on -- no test framework, so no new dependency. Each check is a named
// async function; a thrown error is a failure, with a screenshot.

import { createRequire } from 'node:module'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

export const HERE = path.dirname(fileURLToPath(import.meta.url))
export const REPO = path.resolve(HERE, '..', '..')

/** The frontend's own copy of Playwright; the server's runner image supplies one via SELFTEST_PLAYWRIGHT_DIR. */
export function loadPlaywright() {
  const places = [process.env.SELFTEST_PLAYWRIGHT_DIR, path.join(REPO, 'frontend')].filter(Boolean)
  for (const dir of places) {
    try {
      return createRequire(path.join(dir, 'package.json'))('playwright')
    } catch {
      /* try the next place */
    }
  }
  throw new Error(`Playwright not found. Run "npm ci" in frontend/ (looked in: ${places.join(', ')}).`)
}

/**
 * The two screens every check runs on. The phone is an Android profile
 * (touch, a phone user agent, 412px wide) so the app treats it exactly
 * as it treats a real phone -- including offering the Mobile App.
 */
export function profiles(playwright) {
  return {
    desktop: { viewport: { width: 1366, height: 768 } },
    phone: { ...playwright.devices['Pixel 7'] },
  }
}

export class Results {
  constructor() {
    this.items = []
    this.startedAt = new Date()
  }

  add(item) {
    this.items.push(item)
    const mark = { passed: 'ok  ', failed: 'FAIL', skipped: 'skip' }[item.status]
    const where = item.screen ? ` ${item.screen}` : ''
    console.log(`  ${mark} [${item.profile}] ${item.name}${where}${item.status !== 'passed' && item.message ? `\n         ${item.message.split('\n').join('\n         ')}` : ''}`)
  }

  count(status) {
    return this.items.filter((i) => i.status === status).length
  }
}

/** Unique per run, so a record keyed in today never collides with yesterday's (or the other profile's). */
export const RUN_TAG = new Date().toISOString().replace(/\D/g, '').slice(4, 14)

export const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

export function escapeRegExp(s) {
  return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

/**
 * Watches one page for the problems every screen is checked for: a
 * script error, a failed API call, and console errors (reported as
 * warnings -- a browser logs failed third-party loads, like the promo
 * video, as console errors too).
 */
const inFlight = new WeakMap()

export function watch(page) {
  // Server calls still in flight, for settle(): a tab or button inside
  // the app loads data without the page itself reloading.
  const pending = new Set()
  inFlight.set(page, pending)
  page.on('request', (r) => r.url().includes('/api/') && pending.add(r))
  page.on('requestfinished', (r) => pending.delete(r))
  page.on('requestfailed', (r) => pending.delete(r))
  const seen = { pageErrors: [], apiFailures: [], consoleErrors: [] }
  page.on('pageerror', (e) => seen.pageErrors.push(e.message))
  page.on('console', (m) => {
    if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) seen.consoleErrors.push(m.text())
  })
  page.on('response', (r) => {
    const u = new URL(r.url())
    if (u.pathname.startsWith('/api/') && r.status() >= 400) {
      seen.apiFailures.push({ status: r.status(), method: r.request().method(), path: u.pathname + u.search, expected: false })
    }
  })
  return {
    seen,
    reset() {
      seen.pageErrors.length = 0
      seen.apiFailures.length = 0
      seen.consoleErrors.length = 0
    },
    /** A key-in step that expects the server to refuse (e.g. a validation check) marks that call as expected. */
    expect(statusOrPredicate) {
      for (const f of seen.apiFailures) {
        if (typeof statusOrPredicate === 'function' ? statusOrPredicate(f) : f.status === statusOrPredicate) f.expected = true
      }
    },
    unexpectedApi() {
      return seen.apiFailures.filter((f) => !f.expected)
    },
  }
}

/**
 * Wait for a screen to settle: no server call in flight for half a
 * second, and no "Loading..." line left on it (e.g. "Loading
 * quotations..."). Works after a tab or button inside the app too,
 * where the page itself never reloads.
 */
export async function settle(page, { timeout = 15000 } = {}) {
  await page.waitForLoadState('load', { timeout }).catch(() => {})
  const pending = inFlight.get(page)
  const until = Date.now() + timeout
  let quietSince = Date.now()
  while (Date.now() < until) {
    if (pending && pending.size > 0) quietSince = Date.now()
    else if (Date.now() - quietSince >= 500) break
    await sleep(100)
  }
  await page
    .waitForFunction(() => !/^\s*Loading\b[^\n]{0,40}$/im.test(document.body.innerText), null, { timeout: 5000 })
    .catch(() => {})
}

/** The same questions for every screen; returns a list of problems (empty = passed). */
export async function screenProblems(page, watcher, profileName) {
  const problems = []
  for (const e of watcher.seen.pageErrors) problems.push(`Script error on the page: ${e}`)
  for (const f of watcher.unexpectedApi()) problems.push(`Server call failed: ${f.method} ${f.path} -> ${f.status}`)
  const banners = await page.locator('.error-banner:visible').allInnerTexts().catch(() => [])
  // The credit-limit warning borrows .error-banner's look but is only a warning.
  for (const b of banners) if (!/credit limit/i.test(b)) problems.push(`Error shown on screen: ${b.trim()}`)
  // A value the screen could not fill in shows up as these words.
  const garbled = await page.evaluate(() => {
    const out = []
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT)
    for (let n = walker.nextNode(); n && out.length < 5; n = walker.nextNode()) {
      const t = n.textContent
      if (/(^|[^A-Za-z])(undefined|NaN|\[object Object\])([^A-Za-z]|$)/.test(t) && n.parentElement?.offsetParent !== null && !n.parentElement?.closest('script, style, textarea, input')) {
        out.push(t.trim().slice(0, 80))
      }
    }
    return out
  })
  for (const g of garbled) problems.push(`Screen shows a value it could not fill in: "${g}"`)
  if (profileName === 'phone') {
    const wide = await page.evaluate(() => {
      const w = window.innerWidth
      const doc = document.documentElement.scrollWidth
      if (doc <= w + 1) return null
      // Name the widest thing that sticks out, so the report says where to look.
      let worst = null
      for (const el of document.querySelectorAll('body *')) {
        const r = el.getBoundingClientRect()
        if (r.right > w + 1 && r.width > 0 && getComputedStyle(el).position !== 'fixed') {
          if (!worst || r.right > worst.right) worst = { right: r.right, tag: el.tagName.toLowerCase(), cls: String(el.className || '').slice(0, 60), text: (el.innerText || '').trim().slice(0, 40) }
        }
      }
      return { doc, w, worst }
    })
    if (wide) {
      const w = wide.worst
      problems.push(`Page is wider than the phone screen (${wide.doc}px on a ${wide.w}px screen), so it scrolls sideways.${w ? ` Widest part: <${w.tag}${w.cls ? ` class="${w.cls}"` : ''}>${w.text ? ` "${w.text}"` : ''}` : ''}`)
    }
  }
  return problems
}

// ---- Key-in helpers ---------------------------------------------------
//
// Screens label their fields as <div class="form-row"><label>Name</label>
// <input/></div>, mostly without for/id, so a field is found by the
// label text in its .form-row; a properly linked label works too.

const CONTROL = 'input:not([type=hidden]):not(.date-input-native), select, textarea'

/**
 * The field for a label, in any of the three layouts the screens use:
 * <div class="form-row"><label>X</label><input/></div> (most forms),
 * <label>X <select/></label> (the label wraps its control), and
 * <div><label>X</label><select/></div> (the stock screens).
 */
export function field(scope, label) {
  const exact = new RegExp(`^\\s*${escapeRegExp(label)}\\s*\\*?\\s*$`, 'i')
  const starts = new RegExp(`^\\s*${escapeRegExp(label)}`, 'i')
  // `has` is matched inside each element, so it must be a page-level locator.
  const page = typeof scope.page === 'function' ? scope.page() : scope
  const inRow = scope.locator('.form-row').filter({ has: page.locator('label', { hasText: exact }) }).locator(CONTROL)
  const wrapped = scope.locator('label').filter({ hasText: starts }).filter({ has: page.locator(CONTROL) }).locator(CONTROL)
  const beside = scope.locator('div').filter({ has: page.locator(':scope > label', { hasText: exact }) }).locator(`:scope > ${CONTROL.split(', ').join(', :scope > ')}, :scope > .date-input > input:not(.date-input-native)`)
  return inRow.or(wrapped).or(beside).first()
}

/** Today (and days from today) in Singapore, as keyed (DD/MM/YYYY) and as stored (YYYY-MM-DD). */
export function sgDate(offsetDays = 0) {
  const d = new Date(Date.now() + offsetDays * 86400000)
  const p = Object.fromEntries(new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Singapore', day: '2-digit', month: '2-digit', year: 'numeric' }).formatToParts(d).map((x) => [x.type, x.value]))
  return { dmy: `${p.day}/${p.month}/${p.year}`, iso: `${p.year}-${p.month}-${p.day}` }
}

/** The Singapore calendar date of a stored timestamp (the API sends some as UTC). */
export function sgDateOf(stamp) {
  if (!stamp) return ''
  if (/^\d{4}-\d{2}-\d{2}$/.test(stamp)) return stamp
  const p = Object.fromEntries(new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Singapore', day: '2-digit', month: '2-digit', year: 'numeric' }).formatToParts(new Date(stamp)).map((x) => [x.type, x.value]))
  return `${p.year}-${p.month}-${p.day}`
}

/**
 * Key a value in the way a person would: type into text boxes (a date
 * as bare digits, so the box's own slashes are exercised), pick from a
 * list by what it shows, tick a box.
 */
export async function keyIn(scope, label, value) {
  const el = field(scope, label)
  await el.waitFor({ state: 'visible', timeout: 10000 }).catch(() => {
    throw new Error(`No field labelled "${label}" on this screen.`)
  })
  const tag = await el.evaluate((n) => n.tagName.toLowerCase())
  const type = await el.getAttribute('type')
  const isDate = await el.evaluate((n) => !!n.closest('.date-input'))
  if (tag === 'select') {
    await selectByText(el, value)
  } else if (type === 'checkbox') {
    if (value) await el.check()
    else await el.uncheck()
  } else if (isDate) {
    // value is DD/MM/YYYY; typed as digits only, as on a phone's number pad.
    await el.click()
    await el.fill('')
    await el.pressSequentially(String(value).replace(/\D/g, ''))
    await el.blur()
  } else {
    await el.click()
    await el.fill('')
    await el.pressSequentially(String(value), { delay: 5 })
  }
  return el
}

/** Choose an option by its visible text (exact first, then "starts with", then "contains"). */
export async function selectByText(select, text) {
  const t = String(text).toLowerCase()
  // A list that fills from the server (a State list after picking the
  // Country) gets a moment to arrive, as a person would give it.
  let options = []
  let hit = null
  for (let i = 0; i < 40 && !hit; i++) {
    if (i) await sleep(200)
    options = await select.locator('option').evaluateAll((os) => os.map((o) => ({ value: o.value, label: o.textContent.trim() })))
    hit =
      options.find((o) => o.label.toLowerCase() === t) ||
      options.find((o) => o.label.toLowerCase().startsWith(t)) ||
      options.find((o) => o.label.toLowerCase().includes(t))
  }
  if (!hit) throw new Error(`"${text}" is not one of the choices: ${options.map((o) => o.label).filter(Boolean).slice(0, 15).join(', ')}`)
  await select.selectOption(hit.value)
}

/** Read back what the server stored, as the signed-in user. */
export async function apiGet(page, apiPath) {
  const token = await page.evaluate(() => localStorage.getItem('websoft_token') || localStorage.getItem('token'))
  const res = await page.request.get(apiPath.startsWith('/api') ? apiPath : `/api${apiPath}`, { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } })
  if (!res.ok()) throw new Error(`GET ${apiPath} -> ${res.status()}`)
  return res.json()
}

/** A list endpoint's rows, whichever shape it answers in. */
export function rows(body) {
  if (Array.isArray(body)) return body
  for (const k of ['items', 'data', 'rows', 'results']) if (Array.isArray(body?.[k])) return body[k]
  return []
}

/** Assert a stored value equals what was keyed in, with a message a person can act on. */
export function expectStored(what, actual, expected) {
  const norm = (v) => (v === null || v === undefined ? '' : typeof v === 'number' ? String(v) : String(v).trim())
  if (norm(actual) !== norm(expected)) throw new Error(`${what}: keyed in "${expected}", but the server stored "${actual}".`)
}

export function ensureDir(p) {
  fs.mkdirSync(p, { recursive: true })
  return p
}
