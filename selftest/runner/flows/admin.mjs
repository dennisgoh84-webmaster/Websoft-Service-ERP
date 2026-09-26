// Key-in flows: accounts and administration -- a manual journal voucher
// posted to the General Ledger, and a new staff account.

import { apiGet, expectStored, keyIn, rows, selectByText, sgDate } from '../lib.mjs'
import { button, card, open, submit, tag } from './helpers.mjs'

export const journalVoucher = {
  name: 'Journal Voucher: two balanced lines, save and post',
  screen: '/general-ledger',
  async run({ page, profileName }) {
    const t = tag(profileName)
    const day = sgDate(0)
    await open(page, '/general-ledger')
    const f = card(page, 'Raise a Journal Voucher')
    await keyIn(f, 'Date', day.dmy)
    await keyIn(f, 'Narration', `Selftest opening float ${t}`)
    const lines = f.locator('tbody tr').filter({ has: page.locator('select') })
    await selectByText(lines.nth(0).locator('select'), '1000')
    await lines.nth(0).locator('td').nth(1).locator('input').fill('250')
    await lines.nth(0).locator('td').nth(3).locator('input').pressSequentially('Cash in')
    // The credit side: any equity account (3xxx).
    const acct = lines.nth(1).locator('select')
    const equity = (await acct.locator('option').allInnerTexts()).find((o) => /^3\d{3}\b/.test(o.trim()))
    if (!equity) throw new Error('No equity (3xxx) account to credit in the account list.')
    await selectByText(acct, equity.trim())
    await lines.nth(1).locator('td').nth(2).locator('input').fill('250')
    const created = await submit(page, button(f, 'Save & post'), '/api/ledger/vouchers')
    const jv = await apiGet(page, `/ledger/vouchers/${created.id}`)
    expectStored('Date', jv.entry_date, day.iso)
    expectStored('Narration', jv.narration, `Selftest opening float ${t}`)
    expectStored('Status', jv.status, 'posted')
    expectStored('Balanced', jv.is_balanced, 'true')
    expectStored('Total debit', Number(jv.total_debit).toFixed(2), '250.00')
    expectStored('Debit account', jv.lines.find((l) => Number(l.debit_sgd) > 0)?.account_code, '1000')
  },
}

export const staff = {
  name: 'Staff: add a support engineer (must change password at first sign-in), then crop and save a photo',
  screen: '/staff',
  async run({ page, profileName }) {
    const t = tag(profileName)
    const username = `st_${t.toLowerCase()}`
    await open(page, '/staff')
    const f = card(page, 'Add staff')
    await keyIn(f, 'Full name', `Selftest Engineer ${t}`)
    // Typed with capitals: the box lower-cases as it goes.
    await keyIn(f, 'Username (3-20 characters, letters/numbers/underscore/hyphen)', username.toUpperCase())
    await keyIn(f, 'Email', `${username}@selftest.example`)
    await keyIn(f, 'Temporary password', 'Welcome2026')
    await keyIn(f, 'Role (named-responsibility rules only)', 'Support Engineer')
    const created = await submit(page, button(f, 'Add staff'), '/api/users')
    const u = await apiGet(page, `/users/${created.id}`)
    expectStored('Full name', u.full_name, `Selftest Engineer ${t}`)
    expectStored('Username (lower case)', u.username, username)
    expectStored('Email', u.email, `${username}@selftest.example`)
    expectStored('Role', u.role, 'support_engineer')
    expectStored('Must change password at first sign-in', u.must_change_password, 'true')
    if (!rows(await apiGet(page, '/users')).some((x) => x.id === u.id)) throw new Error('The new staff member is not in the staff list.')

    // Photo: a wide picture is uploaded, dragged and zoomed in the square
    // crop box, and saved as a 400 x 400 JPEG (Backlog 2, 2026-09-26).
    await open(page, `/staff/${u.id}`)
    const png = await page.evaluate(() => {
      const c = document.createElement('canvas')
      c.width = 900
      c.height = 500
      const x = c.getContext('2d')
      x.fillStyle = '#2a6'
      x.fillRect(0, 0, 900, 500)
      x.fillStyle = '#c33'
      x.fillRect(300, 100, 300, 300)
      return c.toDataURL('image/png').split(',')[1]
    })
    await page.locator('#staff-photo').setInputFiles({ name: 'photo.png', mimeType: 'image/png', buffer: Buffer.from(png, 'base64') })
    const crop = page.getByTestId('photo-cropper')
    await crop.waitFor()
    const b = await crop.getByAltText('Photo to crop').locator('..').boundingBox()
    await page.mouse.move(b.x + b.width / 2, b.y + b.height / 2)
    await page.mouse.down()
    await page.mouse.move(b.x + b.width / 2 + 30, b.y + b.height / 2, { steps: 5 })
    await page.mouse.up()
    await crop.locator('#photo-zoom').fill('1.5')
    await button(crop, 'Use this crop').click()
    await card(page, 'Profile').getByAltText(/photo$/).waitFor()
    await submit(page, button(page, 'Save changes'), `/api/users/${u.id}`, 'PATCH')
    const saved = await apiGet(page, `/users/${u.id}`)
    expectStored('Photo format', saved.photo?.slice(0, 23), 'data:image/jpeg;base64,')
    const dims = await page.evaluate(
      (src) => new Promise((ok) => { const i = new Image(); i.onload = () => ok(`${i.naturalWidth}x${i.naturalHeight}`); i.src = src }),
      saved.photo,
    )
    expectStored('Photo size (cropped square)', dims, '400x400')
  },
}
