// Key-in flows: sales and service -- the catalog, a contract through to
// its annual invoice, a job order and its service record, an incident,
// a quotation through to acceptance, a sales invoice and the receipt
// that settles it, a credit note on the annual invoice, a prospect with
// an activity, and a software task.

import { apiGet, expectStored, keyIn, rows, selectByText, sgDate, sgDateOf, sleep } from '../lib.mjs'
import { button, card, open, submit, tag } from './helpers.mjs'

const money = (n) => Number(n).toFixed(2)

export const product = {
  name: 'Product Catalog: add a service sold in hours',
  screen: '/product-catalog',
  async run({ page, profileName, shared }) {
    const t = tag(profileName)
    await open(page, '/product-catalog')
    const f = card(page, 'Add catalog item')
    await keyIn(f, 'Type', 'Service')
    await keyIn(f, 'Product name', `Selftest Support ${t}`)
    await keyIn(f, 'Internal reference', `ST-${t}`)
    await keyIn(f, 'Sales price (SGD, net of GST)', '150')
    await keyIn(f, 'Unit of measure', 'Hours')
    await submit(page, button(f, 'Add item'), '/api/catalog')
    const p = rows(await apiGet(page, '/catalog')).find((x) => x.name === `Selftest Support ${t}`)
    if (!p) throw new Error('The product was added but is not in the catalog.')
    expectStored('Type', p.product_type, 'service')
    expectStored('Internal reference', p.internal_reference, `ST-${t}`)
    expectStored('Sales price', money(p.sales_price_sgd), '150.00')
    expectStored('Unit of measure', p.unit_of_measure, 'Hours')
    shared.product = { id: p.id, name: p.name, price: 150 }
  },
}

export const contract = {
  name: 'Contract: add (20 hours, SGD 3,000), activate, annual invoice issued',
  screen: '/contracts',
  needs: ['customer', 'product'],
  async run({ page, shared }) {
    const start = sgDate(0)
    await open(page, '/contracts')
    const f = card(page, 'New contract')
    await keyIn(f, 'Company / Individual', shared.customer.name)
    await keyIn(f, 'Contract Type', 'Service Support')
    await keyIn(f, 'Contracted hours (min 10)', '20')
    await keyIn(f, 'Contract value (SGD, billed annually upfront)', '3000')
    await keyIn(f, 'Start date', start.dmy)
    await keyIn(f, 'Sales staff', 'Dennis')
    await keyIn(f, 'Product coverage (ctrl/cmd-click for more than one)', shared.product.name)
    const created = await submit(page, button(f, 'Create contract (Draft)'), '/api/contracts')

    let c = await apiGet(page, `/contracts/${created.id}`)
    expectStored('Status', c.status, 'draft')
    expectStored('Contract type', c.contract_kind, 'service_support')
    expectStored('Contracted hours', Number(c.contracted_hours), '20')
    expectStored('Contract value', money(c.contract_value_sgd), '3000.00')
    expectStored('Start date', c.start_date, start.iso)
    expectStored('Products covered', (c.products ?? []).map((p) => p.product_name).join(','), shared.product.name)

    await open(page, `/contracts/${created.id}`)
    await submit(page, button(page, 'Activate contract'), `/api/contracts/${created.id}/activate`)
    c = await apiGet(page, `/contracts/${created.id}`)
    expectStored('Status after Activate', c.status, 'active')
    const inv = rows(await apiGet(page, `/invoices?contract_id=${created.id}`))[0]
    if (!inv) throw new Error('Activating the contract issued no annual invoice.')
    expectStored('Annual invoice amount', money(inv.amount_sgd), '3000.00')
    expectStored('Annual invoice GST (9%)', money(inv.gst_amount_sgd), '270.00')
    expectStored('Annual invoice total', money(inv.total_amount_sgd), '3270.00')
    shared.contract = { id: c.id, number: c.contract_number }
  },
}

export const jobOrder = {
  name: 'Job Order: add against the contract',
  screen: '/job-orders',
  needs: ['contract'],
  async run({ page, profileName, shared }) {
    const t = tag(profileName)
    const due = sgDate(7)
    await open(page, '/job-orders')
    const f = card(page, 'New job order')
    await keyIn(f, 'Company / Individual', shared.customer.name)
    // The contract list shows status and hours, not a number: pick the one just made.
    const contractSelect = f.locator('.form-row').filter({ has: page.locator('label', { hasText: /^\s*Contract\s*$/ }) }).locator('select')
    await contractSelect.locator(`option[value="${shared.contract.id}"]`).waitFor({ state: 'attached' })
    await contractSelect.selectOption(shared.contract.id)
    await keyIn(f, 'Subject', `Printer not printing ${t}`)
    await keyIn(f, 'Products (ctrl/cmd-click for more than one)', shared.product.name)
    await keyIn(f, 'Type', 'Support')
    await keyIn(f, 'Billing', 'Contract hours')
    await keyIn(f, 'Priority', 'High')
    await keyIn(f, 'Due date (optional, as agreed with Support)', due.dmy)
    const created = await submit(page, button(f, 'Create job order'), '/api/job-orders')

    const jo = await apiGet(page, `/job-orders/${created.id}`)
    expectStored('Customer', jo.customer_id, shared.customer.id)
    expectStored('Contract', jo.contract_id, shared.contract.id)
    expectStored('Subject', jo.subject, `Printer not printing ${t}`)
    expectStored('Type', jo.job_order_type, 'support')
    expectStored('Billing', jo.billing_classification, 'contract')
    expectStored('Priority', jo.priority, 'high')
    expectStored('Due date', jo.due_date, due.iso)
    expectStored('Status', jo.status, 'open')
    shared.jobOrder = { id: jo.id, number: jo.job_order_number }
  },
}

export const serviceRecord = {
  name: 'Service Record: log 50 minutes on the job order (rounds up to 60)',
  screen: '/job-orders/:id',
  needs: ['jobOrder'],
  async run({ page, profileName, shared }) {
    const t = tag(profileName)
    const day = sgDate(0)
    await open(page, `/job-orders/${shared.jobOrder.id}`)
    const f = card(page, 'Log a Service Record')
    await keyIn(f, 'Employee', 'Dennis')
    await keyIn(f, 'Work date', day.dmy)
    await keyIn(f, 'Minutes worked', '50')
    await keyIn(f, 'Completion', 'Uncompleted')
    await keyIn(f, 'Work description (optional)', `Replaced the fuser unit ${t}`)
    const created = await submit(page, button(f, 'Submit Service Record'), '/api/service-records')

    const sr = await apiGet(page, `/service-records/${created.id}`)
    expectStored('Job order', sr.job_order_id, shared.jobOrder.id)
    expectStored('Work date', sr.work_date, day.iso)
    expectStored('Minutes worked (as keyed)', sr.raw_minutes, '50')
    expectStored('Minutes after rounding up to 15 (SRV-007)', sr.rounded_minutes, '60')
    expectStored('Completion', sr.completion_status, 'U')
    expectStored('Work description', sr.work_description, `Replaced the fuser unit ${t}`)
    expectStored('Status', sr.status, 'submitted')
    shared.serviceRecord = { id: sr.id, number: sr.service_record_number }
  },
}

export const incident = {
  name: "Incident: log an email, matched to the Company / Individual by its contact's email",
  screen: '/incidents',
  needs: ['customer'],
  async run({ page, profileName, shared }) {
    const t = tag(profileName)
    await open(page, '/incidents')
    const f = card(page, 'Log an incident')
    await keyIn(f, 'Source', 'Email')
    await keyIn(f, 'Subject', `Cannot print invoices ${t}`)
    await keyIn(f, 'Description', 'Since this morning nothing prints.')
    await keyIn(f, 'Caller/sender name', 'Kim Selftest')
    await keyIn(f, 'Caller/sender email', shared.customer.contactEmail.toUpperCase())
    await keyIn(f, 'Caller/sender phone', '+65 9000 0001')
    const created = await submit(page, button(f, 'Log incident'), '/api/incidents')

    const inc = await apiGet(page, `/incidents/${created.id}`)
    expectStored('Source', inc.source, 'email')
    expectStored('Subject', inc.subject, `Cannot print invoices ${t}`)
    expectStored('Description', inc.description, 'Since this morning nothing prints.')
    expectStored('Sender name', inc.sender_name, 'Kim Selftest')
    expectStored('Company / Individual matched by the contact email (any case)', inc.customer_id, shared.customer.id)
    expectStored('Status', inc.status, 'open')
  },
}

export const quotation = {
  name: 'Quotation: two lines, GST, submit / approve / send / accept into contracts',
  screen: '/quotations',
  needs: ['customer', 'product'],
  async run({ page, profileName, shared }) {
    const t = tag(profileName)
    const day = sgDate(0)
    const valid = sgDate(30)
    await open(page, '/quotations')
    const f = card(page, 'New quotation')
    await keyIn(f, 'Company / Individual', shared.customer.name)
    await keyIn(f, 'Date', day.dmy)
    await keyIn(f, 'Valid until', valid.dmy)
    await keyIn(f, 'Notes', `Selftest quotation ${t}`)
    const lines = f.locator('tbody tr').filter({ has: page.locator('input') })
    // Line 1 from the catalog (fills description, unit and price), 12 hours.
    await selectByText(lines.nth(0).locator('td').nth(0).locator('select'), shared.product.name)
    await lines.nth(0).locator('td').nth(3).locator('input').fill('12')
    // Line 2 typed in free: 2 units at 500.
    await button(f, 'Add line').click()
    const l2 = lines.nth(1).locator('td')
    await l2.nth(1).locator('input').pressSequentially('Selftest network switch')
    await l2.nth(2).locator('input').pressSequentially('Unit')
    await l2.nth(3).locator('input').fill('2')
    await l2.nth(4).locator('input').fill('500')
    const created = await submit(page, button(f, 'Create quotation (Draft)'), '/api/quotations')

    let q = await apiGet(page, `/quotations/${created.id}`)
    expectStored('Quotation date', q.quotation_date, day.iso)
    expectStored('Valid until', q.valid_until, valid.iso)
    expectStored('Number of lines', q.lines.length, '2')
    expectStored('Line 1 hours', Number(q.lines[0].quantity), '12')
    expectStored('Line 1 price (from the catalog)', money(q.lines[0].unit_price_sgd), '150.00')
    expectStored('Net (12 x 150 + 2 x 500)', money(q.amount_sgd), '2800.00')
    expectStored('GST 9%', money(q.gst_amount_sgd), '252.00')
    expectStored('Total', money(q.total_amount_sgd), '3052.00')

    // Through its workflow from the list row.
    const row = () => page.locator('tr', { hasText: q.quotation_number })
    for (const [label, status] of [['Submit for approval', 'pending_approval'], ['Approve', 'approved'], ['Send to customer', 'sent'], ['Accept', 'accepted']]) {
      await submit(page, button(row(), label), `/api/quotations/${q.id}/`)
      q = await apiGet(page, `/quotations/${q.id}`)
      expectStored(`Status after "${label}"`, q.status, status)
    }
    if (!q.converted_contract_id) throw new Error('Accepting did not create the Service Support contract from the hours line.')
    if (!q.converted_annual_contract_id) throw new Error('Accepting did not create the Annual contract from the other line.')
    const hours = await apiGet(page, `/contracts/${q.converted_contract_id}`)
    expectStored('Hours in the contract made from the quotation', Number(hours.contracted_hours), '12')
    shared.quotation = { id: q.id, number: q.quotation_number }
  },
}

export const salesInvoice = {
  name: 'Sales Invoice: raise with a catalog line, GST, due date from payment terms',
  screen: '/invoices',
  needs: ['customer', 'product'],
  async run({ page, shared }) {
    await open(page, '/invoices')
    await button(page, 'New Sales Invoice').click()
    const f = card(page, 'Raise Sales Invoice')
    await keyIn(f, 'Company / Individual', shared.customer.name)
    const line = f.locator('table tbody tr').first().locator('td')
    await selectByText(line.nth(0).locator('select'), shared.product.name)
    await line.nth(5).locator('input').fill('3')
    const created = await submit(page, button(f, 'Issue invoice'), '/api/invoices')

    const inv = await apiGet(page, `/invoices/${created.id}`)
    expectStored('Lines', inv.lines.length, '1')
    expectStored('Quantity', Number(inv.lines[0].quantity), '3')
    expectStored('Net (3 x 150)', money(inv.amount_sgd), '450.00')
    expectStored('GST 9%', money(inv.gst_amount_sgd), '40.50')
    expectStored('Total', money(inv.total_amount_sgd), '490.50')
    expectStored('Due date (issue date + 30 days terms)', inv.due_date, sgDate(30).iso)
    expectStored('Posted to the General Ledger', inv.gl_status, 'posted')
    shared.invoice = { id: inv.id, number: inv.invoice_number, total: 490.5 }
  },
}

export const receipt = {
  name: 'Receipt: record, allocate to the invoice, invoice becomes paid',
  screen: '/receipts',
  needs: ['invoice'],
  async run({ page, profileName, shared }) {
    const t = tag(profileName)
    const day = sgDate(0)
    await open(page, '/receipts')
    const f = card(page, 'Record a receipt')
    await keyIn(f, 'Company / Individual', shared.customer.name)
    await keyIn(f, 'Payment date', day.dmy)
    await keyIn(f, 'Amount received (SGD)', '490.50')
    await keyIn(f, 'Method', 'PayNow')
    await keyIn(f, 'Bank / remittance reference', `PAYNOW-${t}`)
    const created = await submit(page, button(f, 'Record receipt'), '/api/accounts-receivable/payments')
    let rv = await apiGet(page, `/accounts-receivable/payments/${created.id}`)
    expectStored('Payment date', rv.payment_date, day.iso)
    expectStored('Amount', money(rv.amount_sgd), '490.50')
    expectStored('Method', rv.method, 'paynow')
    expectStored('Reference', rv.reference, `PAYNOW-${t}`)

    const row = page.locator('tr', { hasText: rv.voucher_number })
    await selectByText(row.locator('select').first(), shared.invoice.number)
    await row.locator('input[placeholder="Amount"]').fill('490.50')
    await submit(page, button(row, 'Allocate'), `/api/accounts-receivable/payments/${rv.id}/allocate`)
    rv = await apiGet(page, `/accounts-receivable/payments/${rv.id}`)
    expectStored('Unallocated after allocating', money(rv.unallocated_sgd), '0.00')
    const inv = await apiGet(page, `/invoices/${shared.invoice.id}`)
    expectStored('Invoice outstanding', money(inv.outstanding_sgd), '0.00')
    expectStored('Invoice status', inv.status, 'paid')
  },
}

export const creditNote = {
  name: 'Credit Note: raise on the annual invoice, owner approves, GL reversed, invoice owes less',
  screen: '/credit-notes',
  needs: ['contract'],
  async run({ page, profileName, shared }) {
    const t = tag(profileName)
    const inv = rows(await apiGet(page, `/invoices?contract_id=${shared.contract.id}`)).find((i) => i.invoice_type === 'contract_annual')
    if (!inv) throw new Error('The contract has no annual invoice to credit.')
    await open(page, '/invoices')
    await button(page.locator('tr', { hasText: inv.invoice_number }), 'Credit note').click()
    const f = page.locator('form.credit-note-form')
    await keyIn(f, 'Amount to credit, net of GST (SGD)', '100')
    await keyIn(f, 'Reason', `Goodwill for a late visit ${t}`)
    const created = await submit(page, button(f, 'Raise credit note'), '/api/credit-notes')

    let cn = await apiGet(page, `/credit-notes/${created.id}`)
    expectStored('Status', cn.status, 'pending_approval')
    expectStored('Against invoice', cn.invoice_number, inv.invoice_number)
    expectStored('Net', money(cn.amount_sgd), '100.00')
    expectStored('GST 9%', money(cn.gst_amount_sgd), '9.00')
    expectStored('Total', money(cn.total_amount_sgd), '109.00')
    expectStored('Reason', cn.reason, `Goodwill for a late visit ${t}`)
    expectStored('Owner approves (no credit note limit set)', cn.needs_owner, 'true')
    expectStored('Number before approval', cn.credit_note_number, '')
    let after = await apiGet(page, `/invoices/${inv.id}`)
    expectStored('Invoice outstanding while pending', money(after.outstanding_sgd), money(inv.outstanding_sgd))

    await open(page, '/credit-notes')
    const row = page.locator('tr', { hasText: `Goodwill for a late visit ${t}` })
    await submit(page, button(row, 'Approve'), `/api/credit-notes/${cn.id}/approve`)
    cn = await apiGet(page, `/credit-notes/${cn.id}`)
    expectStored('Status after Approve', cn.status, 'issued')
    if (!/^CN/.test(cn.credit_note_number ?? '')) throw new Error(`Issued credit note number "${cn.credit_note_number}" does not start with CN.`)
    expectStored('Issued date', sgDateOf(cn.issued_at), sgDate(0).iso)
    expectStored('Posted to the General Ledger', cn.gl_status, 'posted')
    after = await apiGet(page, `/invoices/${inv.id}`)
    expectStored('Invoice credited', money(after.credited_sgd), '109.00')
    expectStored('Invoice outstanding', money(after.outstanding_sgd), money(inv.outstanding_sgd - 109))
    expectStored('Invoice status', after.status, 'partially_paid')
  },
}

// Backlog 2 (2026-09-26): a paid invoice can be credited -- by line,
// here one of its three hours -- and the credit then sits on the
// customer's account: part is set against the contract's annual
// invoice, the rest refunded with a Payment Voucher.
export const creditOnAccount = {
  name: 'Credit Note: one line of a paid invoice, credit on account, set against another invoice, rest refunded',
  screen: '/credit-notes',
  needs: ['invoice', 'contract'],
  async run({ page, profileName, shared }) {
    const t = tag(profileName)
    const paid = await apiGet(page, `/invoices/${shared.invoice.id}`)
    if (paid.status !== 'paid') throw new Error(`The sales invoice is "${paid.status}", not paid -- the receipt flow did not settle it.`)
    const annual = rows(await apiGet(page, `/invoices?contract_id=${shared.contract.id}`)).find((i) => i.invoice_type === 'contract_annual')
    if (!annual) throw new Error('The contract has no annual invoice to set the credit against.')

    await open(page, '/invoices')
    await button(page.locator('tr', { hasText: paid.invoice_number }), 'Credit note').click()
    const f = page.locator('form.credit-note-form')
    // The quantity boxes sit in a table, named by aria-label per line.
    const qty = f.getByLabel(`Quantity to credit: ${paid.lines[0].description}`)
    await qty.click()
    await qty.pressSequentially('1')
    await keyIn(f, 'Reason', `One hour not used ${t}`)
    const created = await submit(page, button(f, 'Raise credit note'), '/api/credit-notes')

    let cn = await apiGet(page, `/credit-notes/${created.id}`)
    expectStored('Lines credited', cn.lines.length, '1')
    expectStored('Quantity credited', Number(cn.lines[0].quantity), '1')
    expectStored('Net (1 x 150)', money(cn.amount_sgd), '150.00')
    expectStored('Total with GST', money(cn.total_amount_sgd), '163.50')
    expectStored('Owner approves (no credit note limit set)', cn.status, 'pending_approval')
    await open(page, '/credit-notes')
    await submit(page, button(page.locator('tr', { hasText: `One hour not used ${t}` }), 'Approve'), `/api/credit-notes/${cn.id}/approve`)
    cn = await apiGet(page, `/credit-notes/${cn.id}`)
    expectStored('Status after Approve', cn.status, 'issued')
    expectStored('On the customer\'s account', money(cn.unapplied_sgd), '163.50')
    const stillPaid = await apiGet(page, `/invoices/${paid.id}`)
    expectStored('Paid invoice stays paid', stillPaid.status, 'paid')

    // Set 63.50 against the annual invoice.
    const row = page.locator('tr', { hasText: cn.credit_note_number })
    await button(row, 'Use credit').click()
    const use = page.getByTestId('credit-on-account')
    await keyIn(use, 'Set against invoice', annual.invoice_number)
    await keyIn(use, 'Amount (SGD)', '63.50')
    await submit(page, button(use, 'Apply credit'), `/api/credit-notes/${cn.id}/apply`)
    cn = await apiGet(page, `/credit-notes/${cn.id}`)
    expectStored('Left on account after applying', money(cn.unapplied_sgd), '100.00')
    const annualAfter = await apiGet(page, `/invoices/${annual.id}`)
    expectStored('Annual invoice owes less', money(annualAfter.outstanding_sgd), money(annual.outstanding_sgd - 63.5))

    // Refund the 100.00 left, dated today, keyed in.
    const day = sgDate(0)
    await use.getByText(`SGD 100.00 of ${cn.credit_note_number}`).waitFor({ timeout: 10000 })
    await keyIn(use, 'Payment date', day.dmy)
    const refunded = await submit(page, button(use, 'Refund with a Payment Voucher'), `/api/credit-notes/${cn.id}/refund`)
    if (!refunded.refund_voucher_number) throw new Error('The refund raised no Payment Voucher.')
    cn = await apiGet(page, `/credit-notes/${cn.id}`)
    expectStored('Left on account after the refund', money(cn.unapplied_sgd), '0.00')
    const pv = cn.applications.find((a) => a.kind === 'refund')
    if (!pv) throw new Error('The refund is not recorded against the credit note.')
    expectStored('Refunded', money(pv.amount_fx), '100.00')
  },
}

export const prospect = {
  name: 'Prospect: add, then log a meeting on it',
  screen: '/prospects',
  needs: ['customer'],
  async run({ page, profileName, shared }) {
    const t = tag(profileName)
    const close = sgDate(60)
    await open(page, '/prospects')
    const f = card(page, 'New prospect')
    await keyIn(f, 'Company / Individual', shared.customer.name)
    await keyIn(f, 'Title', `Server room upgrade ${t}`)
    await keyIn(f, 'Source (optional)', 'Referral')
    await keyIn(f, 'Estimated value (SGD, optional)', '8000')
    await keyIn(f, 'Expected close date (optional)', close.dmy)
    await keyIn(f, 'Notes (optional)', 'Met at the trade show.')
    const created = await submit(page, button(f, 'Create prospect'), '/api/prospects')
    let p = await apiGet(page, `/prospects/${created.id}`)
    expectStored('Title', p.title, `Server room upgrade ${t}`)
    expectStored('Source', p.source, 'Referral')
    expectStored('Estimated value', money(p.estimated_value_sgd), '8000.00')
    expectStored('Expected close date', p.expected_close_date, close.iso)
    expectStored('Customer', p.customer_id, shared.customer.id)

    await open(page, `/prospects/${created.id}`)
    const a = card(page, /^\s*Activities \(/)
    await keyIn(a, 'Type', 'Meeting')
    await keyIn(a, 'Subject', `Site visit ${t}`)
    await keyIn(a, 'Date', sgDate(0).dmy)
    await keyIn(a, 'Status', 'Completed')
    await keyIn(a, 'Description (optional)', 'Walked the server room.')
    await submit(page, button(a, 'Log activity'), '/api/prospect-activities')
    p = await apiGet(page, `/prospects/${created.id}`)
    const act = (p.activities ?? []).find((x) => x.subject === `Site visit ${t}`)
    if (!act) throw new Error('The activity was logged but is not on the prospect.')
    expectStored('Activity type', act.activity_type, 'meeting')
    expectStored('Activity status', act.status, 'completed')
    expectStored('Activity date (Singapore)', sgDateOf(act.activity_date), sgDate(0).iso)
    shared.prospect = { id: p.id, number: p.prospect_number, title: p.title }
  },
}

/** PATCH as the signed-in user -- setup a flow needs that is not what it tests. */
async function apiPatch(page, apiPath, body) {
  const token = await page.evaluate(() => localStorage.getItem('websoft_token') || localStorage.getItem('token'))
  const res = await page.request.patch(`/api${apiPath}`, { headers: { Authorization: `Bearer ${token}` }, data: body })
  if (!res.ok()) throw new Error(`PATCH ${apiPath} -> ${res.status()}`)
  return res.json()
}

// Decision 11.2 (2026-09-26): accepting issues a Sales Invoice for the
// product lines straight away, taking stock from the warehouse picked.
export const quotationProductInvoice = {
  name: 'Quotation with a stock product: Accept picks the warehouse and issues the Sales Invoice',
  screen: '/quotations',
  needs: ['customer', 'warehouse', 'stockItem'],
  async run({ page, profileName, shared }) {
    const t = tag(profileName)
    // A Product-type catalog item, kept in stock as the self-test's stock item.
    await open(page, '/product-catalog')
    const pf = card(page, 'Add catalog item')
    await keyIn(pf, 'Type', 'Product')
    await keyIn(pf, 'Product name', `Selftest router ${t}`)
    await keyIn(pf, 'Internal reference', `RT-${t}`)
    await keyIn(pf, 'Sales price (SGD, net of GST)', '80')
    await keyIn(pf, 'Unit of measure', 'Unit')
    await submit(page, button(pf, 'Add item'), '/api/catalog')
    const product = rows(await apiGet(page, '/catalog')).find((x) => x.name === `Selftest router ${t}`)
    expectStored('Catalog type', product?.product_type, 'product')
    await apiPatch(page, `/stock/items/${shared.stockItem.id}`, { product_id: product.id })
    const levelBefore = rows(await apiGet(page, `/stock/levels?warehouse_id=${shared.warehouse.id}`)).find((x) => x.stock_item_id === shared.stockItem.id)
    const before = Number(levelBefore?.quantity ?? 0)

    await open(page, '/quotations')
    const f = card(page, 'New quotation')
    await keyIn(f, 'Company / Individual', shared.customer.name)
    await keyIn(f, 'Notes', `Selftest product quotation ${t}`)
    const line = f.locator('tbody tr').filter({ has: page.locator('input') }).nth(0).locator('td')
    await selectByText(line.nth(0).locator('select'), product.name)
    await line.nth(3).locator('input').fill('2')
    const created = await submit(page, button(f, 'Create quotation (Draft)'), '/api/quotations')
    let q = await apiGet(page, `/quotations/${created.id}`)
    const row = () => page.locator('tr', { hasText: q.quotation_number })
    for (const label of ['Submit for approval', 'Approve', 'Send to customer']) {
      await submit(page, button(row(), label), `/api/quotations/${q.id}/`)
    }
    q = await apiGet(page, `/quotations/${q.id}`)
    expectStored('Line is a product line', q.lines[0].is_product_line, 'true')
    expectStored('Line takes stock', q.lines[0].is_stock_line, 'true')

    // Accept asks which warehouse the stock leaves from, then issues the invoice.
    await button(row(), 'Accept').click()
    const panel = card(page, `Accept ${q.quotation_number}`)
    await keyIn(panel, 'Stock leaves from', shared.warehouse.code)
    await submit(page, button(panel, 'Accept and issue invoice'), `/api/quotations/${q.id}/accept`)
    q = await apiGet(page, `/quotations/${q.id}`)
    expectStored('Status', q.status, 'accepted')
    if (!q.converted_invoice_id) throw new Error('Accepting did not issue the Sales Invoice for the product line.')
    expectStored('No Annual contract for a product-only quotation', q.converted_annual_contract_id, '')
    const inv = await apiGet(page, `/invoices/${q.converted_invoice_id}`)
    expectStored('Invoice net (2 x 80)', Number(inv.amount_sgd).toFixed(2), '160.00')
    const after = rows(await apiGet(page, `/stock/levels?warehouse_id=${shared.warehouse.id}`)).find((x) => x.stock_item_id === shared.stockItem.id)
    expectStored('Stock after acceptance', Number(after?.quantity ?? 0), String(before - 2))
  },
}

export const softwareTask = {
  name: 'Software Task: add with programmer, tester, date and hours; move it to Released',
  screen: '/software-tasks',
  async run({ page, profileName }) {
    const t = tag(profileName)
    const finish = sgDate(14)
    await open(page, '/software-tasks')
    const f = card(page, 'New task')
    await keyIn(f, 'Title', `Selftest export fix ${t}`)
    await keyIn(f, 'Description', 'Excel export drops the last row.')
    await keyIn(f, 'Module(s) / report(s) affected', 'Invoices')
    await keyIn(f, 'Assigned programmer', 'Dennis')
    await keyIn(f, 'Target finish date (programming)', finish.dmy)
    await keyIn(f, 'Programming hours (manual)', '3.5')
    await keyIn(f, 'Assigned tester', 'Dennis')
    await submit(page, button(f, 'Add task'), '/api/software-tasks')
    await sleep(300)
    const task = rows(await apiGet(page, '/software-tasks')).find((x) => x.title === `Selftest export fix ${t}`)
    if (!task) throw new Error('The task was added but is not in the list.')
    expectStored('Modules affected', task.modules_affected, 'Invoices')
    expectStored('Target finish date', sgDateOf(task.programming_finish_date), finish.iso)
    expectStored('Programming hours', Number(task.programming_hours), '3.5')
    if (!task.assigned_programmer_id || !task.tester_user_id) throw new Error('Programmer or tester was not stored.')
    expectStored('Status of a new task', task.status, 'open')

    // Decision 12.1: Open -> Programming -> For Testing -> Tested -> Released, from the row's buttons.
    const row = () => page.locator('tr', { hasText: `Selftest export fix ${t}` })
    for (const [label, status] of [['Start programming', 'programming'], ['Send for testing', 'for_testing'], ['Mark tested', 'tested'], ['Release', 'released']]) {
      await submit(page, button(row(), label), `/api/software-tasks/${task.id}/status`)
      const now = rows(await apiGet(page, '/software-tasks')).find((x) => x.id === task.id)
      expectStored(`Status after "${label}"`, now.status, status)
    }
  },
}
