// Small helpers shared by the key-in flows.

import { RUN_TAG, escapeRegExp, settle } from '../lib.mjs'

/** A card on the screen, found by its heading. */
export function card(page, heading) {
  const h = typeof heading === 'string' ? new RegExp(`^\\s*${escapeRegExp(heading)}`, 'i') : heading
  return page.locator('.card').filter({ has: page.locator('h2, h3', { hasText: h }) }).first()
}

/** A per-run, per-screen-size tag, so desktop and phone never key in the same number or code. */
export function tag(profileName) {
  return `${RUN_TAG}${profileName === 'phone' ? 'P' : 'D'}`
}

/**
 * Click a button and wait for the server call it makes; returns that
 * call's JSON. A refusal from the server fails the step with the
 * server's own message, which is what a person would have seen.
 */
export async function submit(page, button, urlPart, method = 'POST') {
  const matcher = (r) => r.request().method() === method && new URL(r.url()).pathname.includes(urlPart)
  const [res] = await Promise.all([page.waitForResponse(matcher, { timeout: 20000 }), button.click()])
  let body = null
  try {
    body = await res.json()
  } catch {
    /* no body */
  }
  if (!res.ok()) throw new Error(`Saving was refused (${method} ${new URL(res.url()).pathname} -> ${res.status()}): ${body?.detail ?? body?.message ?? JSON.stringify(body)?.slice(0, 300)}`)
  return body
}

export async function open(page, url) {
  await page.goto(url)
  await settle(page)
}

/** Click a button by its visible text (exact), within a scope. */
export function button(scope, name) {
  return scope.getByRole('button', { name, exact: true })
}
