// Records a video walkthrough of Websoft Service ERP Solution's updated
// UI: the Bright/Dark theme toggle, the confirmed Service Operations
// workflow (SRV-001..018), Module Control, Staff Master (list + detail,
// including password reset, the per-account activity trail, and the
// per-company Group assignment), Group Authority (RBAC), Event Logs
// (the audit trail), and Company Setup + multi-company switching
// between two entities.
//
// Run with: node record_demo.cjs
// (requires the backend + frontend dev servers running, and a freshly
// seeded DB -- `php artisan migrate:fresh --seed` in backend-php/)
const { chromium } = require('playwright')
const fs = require('fs')
const path = require('path')

const OUT_DIR = '/tmp/websoft_demo_video'
const BASE_URL = 'http://127.0.0.1:5173'
const PAUSE = 1200 // ms between narrated steps, so the video is watchable

function rmrf(p) {
  if (fs.existsSync(p)) fs.rmSync(p, { recursive: true, force: true })
}

async function pause(ms = PAUSE) {
  await new Promise((r) => setTimeout(r, ms))
}

async function main() {
  rmrf(OUT_DIR)
  fs.mkdirSync(OUT_DIR, { recursive: true })

  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' })
  const context = await browser.newContext({
    viewport: { width: 1280, height: 800 },
    recordVideo: { dir: OUT_DIR, size: { width: 1280, height: 800 } },
  })
  const page = await context.newPage()

  // 1. Login as Dennis (Owner) -- has full access everywhere, so this
  //    one walkthrough can also show the admin-only screens.
  await page.goto(`${BASE_URL}/login`)
  await pause(600)
  await page.getByRole('button', { name: /sign in/i }).click()
  await page.waitForURL('**/')
  await pause(1200)

  // 2. Dashboard in the default Bright theme...
  await pause(1200)

  // 3. ...then flip the theme toggle to Dark. This is a single control in
  //    Layout's top bar, so it's present on every module screen below.
  await page.getByRole('button', { name: /switch to dark theme/i }).click()
  await pause(1500)

  // 4. Customers
  await page.getByRole('link', { name: 'Customers', exact: true }).click()
  await pause(1200)

  // 5. Contracts -> open Acme's contract, show hours + confirmed rule labels
  await page.getByRole('link', { name: 'Contracts', exact: true }).click()
  await pause(800)
  await page.getByRole('link', { name: 'Open', exact: true }).first().click()
  await pause(1800)

  // 6. Job Orders -> open the VPN job order
  await page.getByRole('link', { name: 'Job Orders', exact: true }).click()
  await pause(800)
  await page.getByRole('link', { name: 'Open', exact: true }).first().click()
  await pause(1200)

  // 7. Approve the pending Service Record -- this is the moment SRV-003/
  //    SRV-004/SRV-007 fire: 80 raw min rounds to 90, only 60 remain, so
  //    the contract exhausts AND an Excess Usage record is created.
  const approveButton = page.getByRole('button', { name: 'Approve' }).first()
  if (await approveButton.count()) {
    await approveButton.scrollIntoViewIfNeeded()
    await pause(500)
    await approveButton.click()
    await pause(1500)
  }

  // 8. Excess Review -- decide: billable, with a reason (SRV-004/008/013)
  await page.getByRole('link', { name: 'Excess Review', exact: true }).click()
  await pause(1000)
  const reasonBox = page.locator('textarea').first()
  if (await reasonBox.count()) {
    await reasonBox.fill(
      'Customer requested urgent after-hours VPN fix beyond contracted hours; approved as billable at standard contract rate.',
    )
    await pause(600)
    await page.getByRole('button', { name: 'Record decision' }).first().click()
    await pause(1500)
  }

  // 9. Invoices -- the new excess-usage invoice at the blended rate (SRV-008)
  await page.getByRole('link', { name: 'Invoices', exact: true }).click()
  await pause(1500)

  // 10. Module Control
  await page.getByRole('link', { name: 'Module Control', exact: true }).click()
  await pause(1800)

  // 11. Staff Master -- open a staff record's detail page and show the
  //     full profile edit, password reset, and activity trail.
  await page.getByRole('link', { name: 'Staff Master', exact: true }).click()
  await pause(1000)
  await page.getByRole('link', { name: 'Wei Ling (Support Engineer)' }).click()
  await pause(1200)

  const fullNameInput = page.locator('.card', { hasText: 'Profile' }).locator('input').first()
  await fullNameInput.fill('Wei Ling (Support Engineer II)')
  await pause(500)
  await page.getByRole('button', { name: 'Save changes' }).click()
  await pause(1200)

  await page.locator('input[type="password"]').fill('newdemo1234')
  await pause(500)
  await page.getByRole('button', { name: 'Reset password' }).click()
  await pause(1500)

  // 11b. Company access & Groups -- a staff member holds a Group PER
  //      COMPANY. Wei Ling works in one company only; Dennis works in
  //      both and holds a different Group in each.
  const accessCard = page.locator('.card', { hasText: 'Company access' })
  await accessCard.scrollIntoViewIfNeeded()
  await pause(2500)

  await page.getByRole('link', { name: 'Staff Master', exact: true }).click()
  await pause(900)
  await page.getByRole('link', { name: 'Dennis (Owner)' }).click()
  await pause(1000)
  await page.locator('.card', { hasText: 'Company access' }).scrollIntoViewIfNeeded()
  await pause(3000) // Owner / Admin in one entity, Finance Team in the other

  // 12. Group Authority -- open a Group and change one module's access level
  await page.getByRole('link', { name: 'Group Authority', exact: true }).click()
  await pause(1000)
  await page.getByText('Sales Team', { exact: true }).click()
  await pause(1000)
  await page.locator('tr', { hasText: 'Billing' }).first().locator('select').selectOption('full')
  await pause(1800)

  // 13. Event Logs -- the audit trail those changes just wrote
  await page.getByRole('link', { name: 'Event Logs', exact: true }).click()
  await pause(2500) // let the viewer read the old -> new value column

  // 14. Company Setup -- the company logo (top-left) and the second entity
  await page.getByRole('link', { name: 'Company Setup', exact: true }).click()
  await pause(2500)

  // 15. Multi-company: switch to the second entity and show the whole app
  //     re-scope -- different logo, different customers, empty dashboard.
  await page.getByLabel('Switch company').selectOption({ label: 'Websoft Digital Pte Ltd' })
  await pause(1500)
  await page.getByRole('link', { name: 'Dashboard', exact: true }).click()
  await pause(2000)
  await page.getByRole('link', { name: 'Customers', exact: true }).click()
  await pause(2000)
  await page.getByLabel('Switch company').selectOption({ label: 'Webmaster Consultancy Pte Ltd' })
  await pause(1800)

  // 16. Back to Bright theme to close out
  await page.getByRole('button', { name: /switch to bright theme/i }).click()
  await pause(800)
  await page.getByRole('link', { name: 'Dashboard', exact: true }).click()
  await pause(2000)

  await context.close()
  await browser.close()

  const files = fs.readdirSync(OUT_DIR).filter((f) => f.endsWith('.webm'))
  console.log('Video saved:', files.map((f) => path.join(OUT_DIR, f)))
}

main().catch((err) => {
  console.error(err)
  process.exit(1)
})
