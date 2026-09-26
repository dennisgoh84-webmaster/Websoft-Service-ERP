// Every screen, opened and checked (docs/self-test.md). The list is read
// from frontend/src/App.tsx at run time, so a screen added there is in
// the sweep automatically -- nobody has to remember to list it here.
//
// A screen with an id in its address (/contracts/:id) opens the first
// real record of its kind; the key-in flows run before the sweep, so on
// a fresh self-test database there is one to open.

import fs from 'node:fs'
import path from 'node:path'
import { REPO, apiGet, rows, screenProblems, settle } from './lib.mjs'

/** Addresses that are not screens of the signed-in ERP, each covered elsewhere. */
const NOT_SWEPT = {
  '/login': 'the sign-in check',
  '/mobile': 'the Mobile App check',
  '/portal/*': 'the Helpdesk Portal check',
}

/** Where to find a real record for a screen whose address carries an id. */
const RECORD_FOR = {
  '/bank-accounts/:id': '/bank-accounts',
  '/company-individuals/:id': '/company-individuals',
  '/contracts/:id': '/contracts',
  '/invoices/:id/print': '/invoices',
  '/job-orders/:id': '/job-orders',
  '/job-orders/:id/print': '/job-orders',
  '/payment-voucher/:id/print': '/accounts-payable/payments',
  '/prospect-activities/:activityId': '/prospect-activities',
  '/prospects/:id': '/prospects',
  '/purchase-orders/:id/print': '/accounts-payable/purchase-orders',
  '/quotations/:id/print': '/quotations',
  '/receipts/:id/print': '/accounts-receivable/payments',
  '/service-records/:id/print': '/service-records',
  '/staff/:id': '/users',
  '/stock-master/:id': '/stock/items',
}

/** Screens with a list type in the address: every list is its own screen. */
const SETUP_LIST_TYPES = ['country', 'state', 'city', 'nationality', 'area_code', 'currency', 'industry', 'product_category', 'unit_of_measure']

export function discoverRoutes() {
  const src = fs.readFileSync(path.join(REPO, 'frontend/src/App.tsx'), 'utf8')
  const routes = [...src.matchAll(/<Route\s+path="([^"]+)"/g)].map((m) => m[1])
  return [...new Set(routes)]
}

/** Expand the route list into concrete addresses to open; unresolvable ones come back as skips. */
export async function screensToVisit(page) {
  const out = []
  for (const route of discoverRoutes()) {
    if (NOT_SWEPT[route]) continue
    if (route === '/setup-lists/:listType') {
      for (const t of SETUP_LIST_TYPES) out.push({ route, url: `/setup-lists/${t}` })
      continue
    }
    if (!route.includes(':')) {
      out.push({ route, url: route })
      continue
    }
    const listPath = RECORD_FOR[route]
    if (!listPath) {
      out.push({ route, skip: `No rule for which record to open at ${route} -- add one to RECORD_FOR in selftest/runner/screens.mjs.` })
      continue
    }
    let first = null
    try {
      first = rows(await apiGet(page, listPath))[0]
    } catch (e) {
      out.push({ route, skip: `Could not list ${listPath} to find a record: ${e.message}` })
      continue
    }
    if (!first?.id) {
      out.push({ route, skip: `No record to open (${listPath} is empty).` })
      continue
    }
    out.push({ route, url: route.replace(/:[A-Za-z]+/, first.id) })
  }
  return out
}

export async function sweep({ page, watcher, profileName, check, skip }) {
  const screens = await screensToVisit(page)
  for (const s of screens) {
    if (s.skip) {
      skip(`Screen ${s.route}`, s.skip, s.route)
      continue
    }
    await check(`Screen ${s.url}`, s.url, async () => {
      watcher.reset()
      await page.goto(s.url)
      await settle(page)
      if (new URL(page.url()).pathname === '/login') throw new Error('Sent back to the sign-in page.')
      const problems = await screenProblems(page, watcher, profileName)
      if (problems.length) throw new Error(problems.join('\n'))
    })
  }
}
