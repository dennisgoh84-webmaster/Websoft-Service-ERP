// Key-in flow: the Mobile App, on the phone -- reached the way a person
// reaches it (the "Mobile app" switch in the ERP's top bar), every tab
// checked like a screen, an activity logged on a prospect from the
// phone, and back to the full site.

import { apiGet, expectStored, screenProblems, settle, sgDate, sgDateOf } from '../lib.mjs'
import { submit, tag } from './helpers.mjs'

async function checkTab(page, watcher, profileName, tabName) {
  await settle(page)
  const problems = await screenProblems(page, watcher, profileName)
  if (problems.length) throw new Error(`Mobile App, ${tabName} tab:\n${problems.join('\n')}`)
}

export const mobileApp = {
  name: 'Mobile App: every tab, log a prospect activity from the phone',
  screen: '/mobile',
  profiles: ['phone'],
  needs: ['prospect'],
  async run({ page, watcher, profileName, shared }) {
    const t = tag(profileName)
    await page.goto('/')
    await settle(page)
    await page.getByRole('button', { name: /Mobile app/ }).click()
    await page.waitForURL('**/mobile')

    for (const tab of ['Jobs', 'Quotations', 'Prospects']) {
      watcher.reset()
      await page.getByRole('button', { name: new RegExp(tab) }).last().click()
      await checkTab(page, watcher, profileName, tab)
    }

    // On the Prospects tab: open the prospect and log a call from the phone.
    await page.getByText(shared.prospect.title).first().click()
    const form = page.locator('form').filter({ has: page.locator('h3', { hasText: 'Log activity' }) })
    await form.locator('select').nth(0).selectOption('call')
    await form.getByPlaceholder('e.g. Met the finance director').pressSequentially(`Called from site ${t}`)
    await form.locator('textarea').pressSequentially('Customer asked for a revised price.')
    await form.locator('select').nth(1).selectOption('completed')
    await submit(page, form.getByRole('button', { name: /Log activity/ }), '/api/prospect-activities')
    const p = await apiGet(page, `/prospects/${shared.prospect.id}`)
    const act = (p.activities ?? []).find((a) => a.subject === `Called from site ${t}`)
    if (!act) throw new Error('The activity logged on the phone is not on the prospect.')
    expectStored('Activity type', act.activity_type, 'call')
    expectStored('Activity notes', act.description, 'Customer asked for a revised price.')
    expectStored('Activity date (Singapore, stamped by the server)', sgDateOf(act.activity_date), sgDate(0).iso)

    // Back out of the prospect to the tab bar, then to the full site.
    await page.getByRole('button', { name: /Back/ }).first().click()
    await page.getByRole('button', { name: /Full site/ }).click()
    await page.waitForURL((u) => !u.pathname.startsWith('/mobile'))
  },
}
