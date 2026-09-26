// Key-in flow: multi-currency (Backlog 2, 2026-09-26) -- a rate in the
// Currency Rate Table, a Company / Individual's default currency, then a
// Sales Invoice and a Receipt that start in that currency at that rate,
// the receipt allocated. Runs last, since it changes the customer's
// default currency.

import { apiGet, expectStored, keyIn, sgDate } from '../lib.mjs'
import { button, card, open, submit } from './helpers.mjs'

const money = (n) => Number(n).toFixed(2)

export const multiCurrency = {
  name: 'Multi-currency: rate, customer currency, invoice and receipt in it, allocated',
  screen: '/currency-rates',
  needs: ['customer'],
  async run({ page, profileName, shared }) {
    // A currency of its own per screen size, so the two runs never share a rate.
    const code = profileName === 'phone' ? 'EUR' : 'USD'
    const day = sgDate(0)

    await open(page, '/currency-rates')
    const r = card(page, 'Add a rate')
    await keyIn(r, 'Currency code', code)
    await keyIn(r, 'Rate to SGD', '1.25')
    await keyIn(r, 'Effective date', day.dmy)
    await submit(page, button(r, 'Add rate'), '/api/currency-rates')

    await open(page, `/company-individuals/${shared.customer.id}`)
    const p = card(page, 'Profile')
    await keyIn(p, 'Default currency', code)
    await submit(page, button(p, 'Save changes'), `/api/company-individuals/${shared.customer.id}`, 'PATCH')
    expectStored('Default currency', (await apiGet(page, `/company-individuals/${shared.customer.id}`)).default_currency, code)

    await open(page, '/invoices')
    await button(page, 'New Sales Invoice').click()
    const f = card(page, 'Raise Sales Invoice')
    await keyIn(f, 'Company / Individual', shared.customer.name)
    // The currency follows the customer, and the rate comes from the table.
    await f.locator('#invoice-rate').waitFor()
    for (let i = 0; i < 40 && (await f.locator('#invoice-rate').inputValue()) === ''; i++) await page.waitForTimeout(100)
    expectStored('Currency shown', await f.locator('#invoice-currency').inputValue(), code)
    expectStored('Rate shown', Number(await f.locator('#invoice-rate').inputValue()).toFixed(2), '1.25')
    const line = f.locator('table tbody tr').first().locator('td')
    await line.nth(1).locator('input').fill(`Consulting in ${code}`)
    await line.nth(5).locator('input').fill('2')
    await line.nth(6).locator('input').fill('100')
    const created = await submit(page, button(f, 'Issue invoice'), '/api/invoices')
    const inv = await apiGet(page, `/invoices/${created.id}`)
    expectStored('Invoice currency', inv.currency_code, code)
    expectStored('Invoice rate', money(inv.exchange_rate), '1.25')
    expectStored(`Net in ${code}`, money(inv.amount_fx), '200.00')
    expectStored(`Total in ${code} (GST 9%)`, money(inv.total_amount_fx), '218.00')
    expectStored('Total in SGD', money(inv.total_amount_sgd), '272.50')

    await open(page, '/receipts')
    const rc = card(page, 'Record a receipt')
    await keyIn(rc, 'Company / Individual', shared.customer.name)
    await keyIn(rc, 'Payment date', day.dmy)
    await page.locator('#receipt-rate').waitFor()
    await keyIn(rc, `Amount received (${code})`, '218')
    const rvCreated = await submit(page, button(rc, 'Record receipt'), '/api/accounts-receivable/payments')
    let rv = await apiGet(page, `/accounts-receivable/payments/${rvCreated.id}`)
    expectStored('Receipt currency', rv.currency_code, code)
    expectStored(`Receipt in ${code}`, money(rv.amount_fx), '218.00')
    expectStored('Receipt in SGD', money(rv.amount_sgd), '272.50')
    const row = page.locator('tr', { hasText: rv.voucher_number })
    await row.locator('select').first().selectOption(inv.id)
    await row.locator('input[placeholder="Amount"]').fill('218')
    await submit(page, button(row, 'Allocate'), `/api/accounts-receivable/payments/${rv.id}/allocate`)
    const after = await apiGet(page, `/invoices/${inv.id}`)
    expectStored('Invoice status', after.status, 'paid')
    expectStored('Invoice outstanding (SGD)', money(after.outstanding_sgd), '0.00')
  },
}
