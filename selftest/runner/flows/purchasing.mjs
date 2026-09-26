// Key-in flows: buying and paying -- a purchase order through approval
// into Accounts Payable, a supplier bill with its tax code and expense
// account, and a payment voucher allocated to the bill and banked.

import { apiGet, expectStored, keyIn, rows, selectByText, sgDate } from '../lib.mjs'
import { button, card, open, submit, tag } from './helpers.mjs'

const money = (n) => Number(n).toFixed(2)

export const purchaseOrderWithinLimit = {
  name: "Purchase Order within the supplier's approval limit: approve without the owner (PUR-001)",
  screen: '/purchase-orders',
  needs: ['customer'],
  async run({ page, profileName, shared }) {
    const t = tag(profileName)
    await open(page, '/purchase-orders')
    const f = card(page, 'Raise a purchase order')
    await keyIn(f, 'Supplier', shared.customer.name)
    await keyIn(f, 'Order date', sgDate(0).dmy)
    await keyIn(f, 'Description', `Selftest cables ${t}`)
    await keyIn(f, 'Amount, net of GST (SGD)', '200')
    const created = await submit(page, button(f, 'Raise PO'), '/api/accounts-payable/purchase-orders')
    let po = await apiGet(page, `/accounts-payable/purchase-orders/${created.id}`)
    // 218.00 with GST is within the 500 limit set on the supplier.
    expectStored('Status', po.status, 'draft')
    await submit(page, button(page.locator('tr', { hasText: po.po_number }), 'Approve'), `/purchase-orders/${po.id}/approve`)
    po = await apiGet(page, `/accounts-payable/purchase-orders/${po.id}`)
    expectStored('Status after Approve', po.status, 'approved')
  },
}

export const purchaseOrder = {
  name: 'Purchase Order above the limit: owner approves, import to Accounts Payable',
  screen: '/purchase-orders',
  needs: ['customer'],
  async run({ page, profileName, shared }) {
    const t = tag(profileName)
    const day = sgDate(0)
    await open(page, '/purchase-orders')
    const f = card(page, 'Raise a purchase order')
    await keyIn(f, 'Supplier', shared.customer.name)
    await keyIn(f, 'Order date', day.dmy)
    await keyIn(f, 'Description', `Selftest toner cartridges ${t}`)
    await keyIn(f, 'Amount, net of GST (SGD)', '1000')
    const created = await submit(page, button(f, 'Raise PO'), '/api/accounts-payable/purchase-orders')
    let po = await apiGet(page, `/accounts-payable/purchase-orders/${created.id}`)
    expectStored('Order date', po.order_date, day.iso)
    expectStored('Amount', money(po.amount_sgd), '1000.00')
    expectStored('GST 9%', money(po.gst_amount_sgd), '90.00')
    // 1,090.00 with GST is above the supplier's 500 limit: only the owner approves (PUR-001).
    expectStored('Status', po.status, 'pending_approval')

    const row = () => page.locator('tr', { hasText: po.po_number })
    await submit(page, button(row(), 'Approve (owner)'), `/purchase-orders/${po.id}/approve`)
    po = await apiGet(page, `/accounts-payable/purchase-orders/${po.id}`)
    expectStored('Status after Approve', po.status, 'approved')
    await submit(page, button(row(), 'Import to AP'), `/purchase-orders/${po.id}/import-to-ap`)
    po = await apiGet(page, `/accounts-payable/purchase-orders/${po.id}`)
    if (!po.imported_bill_id) throw new Error('Import to AP made no bill.')
    const bill = await apiGet(page, `/accounts-payable/bills/${po.imported_bill_id}`)
    expectStored('Imported bill total', money(bill.total_amount_sgd), '1090.00')
    shared.poBill = { id: bill.id, number: bill.bill_number, total: 1090 }
  },
}

export const supplierBill = {
  name: 'Supplier bill: record with tax code TX and an expense account',
  screen: '/accounts-payable',
  needs: ['customer'],
  async run({ page, profileName, shared }) {
    const t = tag(profileName)
    const day = sgDate(-3)
    await open(page, '/accounts-payable')
    const f = card(page, 'Record a supplier bill')
    await keyIn(f, 'Supplier', shared.customer.name)
    await keyIn(f, "Supplier's invoice number", `SUP-${t}`)
    await keyIn(f, "Supplier's invoice date", day.dmy)
    // Any expense account other than the 5000 default, so a dropped choice shows.
    const expense = f.locator('.form-row').filter({ has: page.locator('label', { hasText: /^\s*Expense account/ }) }).locator('select')
    const options = await expense.locator('option').evaluateAll((os) => os.map((o) => ({ value: o.value, label: o.textContent.trim() })))
    const other = options.find((o) => o.value && !/^5000\b/.test(o.label))
    if (!other) throw new Error('The Expense account list offers nothing but the default.')
    await expense.selectOption(other.value)
    await keyIn(f, 'Description', `Selftest courier charges ${t}`)
    await keyIn(f, 'Amount, net of GST (SGD)', '200')
    await keyIn(f, 'Tax code', 'TX')
    const created = await submit(page, button(f, 'Record bill'), '/api/accounts-payable/bills')
    const bill = await apiGet(page, `/accounts-payable/bills/${created.id}`)
    expectStored("Supplier's invoice number", bill.supplier_invoice_no, `SUP-${t}`)
    expectStored('Invoice date', bill.invoice_date, day.iso)
    expectStored('Tax code', bill.tax_code, 'TX')
    expectStored('GST 9%', money(bill.gst_amount_sgd), '18.00')
    expectStored('Total', money(bill.total_amount_sgd), '218.00')
    expectStored('Expense account', bill.expense_account_id, other.value)
  },
}

export const paymentVoucher = {
  name: 'Payment Voucher: record, allocate to the bill, bank it',
  screen: '/payment-voucher',
  needs: ['poBill'],
  async run({ page, profileName, shared }) {
    const t = tag(profileName)
    const day = sgDate(0)
    await open(page, '/payment-voucher')
    const f = card(page, 'Record a payment voucher')
    await keyIn(f, 'Supplier', shared.customer.name)
    await keyIn(f, 'Payment date', day.dmy)
    await keyIn(f, 'Amount (SGD)', '1090')
    await keyIn(f, 'Reference', `GIRO-${t}`)
    const created = await submit(page, button(f, 'Record payment'), '/api/accounts-payable/payments')
    let pv = await apiGet(page, `/accounts-payable/payments/${created.id}`)
    expectStored('Payment date', pv.payment_date, day.iso)
    expectStored('Amount', money(pv.amount_sgd), '1090.00')
    expectStored('Reference', pv.reference, `GIRO-${t}`)
    expectStored('Posted to the General Ledger', pv.gl_status, 'posted')

    const row = () => page.locator('tr', { hasText: pv.voucher_number })
    await selectByText(row().locator('select').first(), shared.poBill.number)
    await row().locator('input[placeholder="Amount"]').fill('1090')
    await submit(page, button(row(), 'Allocate'), `/api/accounts-payable/payments/${pv.id}/allocate`)
    const bill = await apiGet(page, `/accounts-payable/bills/${shared.poBill.id}`)
    expectStored('Bill outstanding after allocating', money(bill.outstanding_sgd), '0.00')
    expectStored('Bill status', bill.status, 'paid')

    await submit(page, button(row(), 'Bank'), `/api/accounts-payable/payments/${pv.id}/bank`)
    pv = await apiGet(page, `/accounts-payable/payments/${pv.id}`)
    expectStored('Bank status', pv.bank_status, 'banked')
    if (!pv.bank_transaction_number) throw new Error('Banking the voucher gave no Bank Book transaction number.')
  },
}

// Bank interest and bank charges: keyed as an Other receipt / payment,
// never straight into the Bank Book (#49 / 31.1), so each bank line has
// its General Ledger entry.

async function bankBookLine(page, text) {
  const banks = rows(await apiGet(page, '/bank-accounts'))
  for (const b of banks) {
    const hit = (await apiGet(page, `/bank-accounts/${b.id}/transactions`)).rows.find((r) => (r.description ?? '').includes(text))
    if (hit) return { bank: b, line: hit }
  }
  return null
}

export const otherReceipt = {
  name: 'Other receipt: bank interest to 4900, banked into the Bank Book',
  screen: '/receipts',
  async run({ page, profileName }) {
    const t = tag(profileName)
    const what = `DBS interest ${t}`
    await open(page, '/receipts')
    const f = card(page, 'Record a receipt')
    await keyIn(f, 'Received from', 'Other')
    await keyIn(f, 'Account', '4900')
    await keyIn(f, 'What it is', what)
    await keyIn(f, 'Payment date', sgDate(0).dmy)
    await keyIn(f, 'Amount received (SGD)', '12.34')
    const created = await submit(page, button(f, 'Record receipt'), '/api/accounts-receivable/payments')
    const rv = await apiGet(page, `/accounts-receivable/payments/${created.id}`)
    expectStored('Kind', rv.kind, 'other')
    expectStored('Company / Individual (none)', rv.customer_id, '')
    expectStored('Account', rv.gl_account, '4900 Other income')
    expectStored('What it is', rv.notes, what)
    expectStored('Posted to the General Ledger', rv.gl_status, 'posted')
    expectStored('Nothing to allocate', Number(rv.unallocated_sgd).toFixed(2), '0.00')

    await submit(page, button(page.locator('tr', { hasText: rv.voucher_number }), 'Bank'), `/api/accounts-receivable/payments/${rv.id}/bank`)
    const found = await bankBookLine(page, what)
    if (!found) throw new Error('After Bank, the receipt is not in any Bank Book.')
    expectStored('Bank Book: money in', Number(found.line.debit_sgd).toFixed(2), '12.34')

    // The Bank Book itself takes no direct keying any more.
    await open(page, `/bank-accounts/${found.bank.id}`)
    if (await page.getByRole('button', { name: 'Add transaction' }).count()) throw new Error('The Bank Book still offers "Add transaction".')
    await page.getByRole('heading', { name: 'Adding to the Bank Book' }).waitFor()
  },
}

export const otherPayment = {
  name: 'Other payment: bank charges to 6500, banked into the Bank Book',
  screen: '/payment-voucher',
  async run({ page, profileName }) {
    const t = tag(profileName)
    const what = `DBS service charge ${t}`
    await open(page, '/payment-voucher')
    const f = card(page, 'Payment vouchers')
    await keyIn(f, 'Paid to', 'Other')
    await keyIn(f, 'Account', '6500')
    await keyIn(f, 'What it is', what)
    await keyIn(f, 'Payment date', sgDate(0).dmy)
    await keyIn(f, 'Amount (SGD)', '25')
    const created = await submit(page, button(f, 'Record payment'), '/api/accounts-payable/payments')
    const pv = await apiGet(page, `/accounts-payable/payments/${created.id}`)
    expectStored('Kind', pv.kind, 'other')
    expectStored('Supplier (none)', pv.supplier_id, '')
    expectStored('Account', pv.gl_account, '6500 Bank charges')
    expectStored('What it is', pv.notes, what)
    expectStored('Posted to the General Ledger', pv.gl_status, 'posted')

    await submit(page, button(page.locator('tr', { hasText: pv.voucher_number }), 'Bank'), `/api/accounts-payable/payments/${pv.id}/bank`)
    const found = await bankBookLine(page, what)
    if (!found) throw new Error('After Bank, the payment is not in any Bank Book.')
    expectStored('Bank Book: money out', Number(found.line.credit_sgd).toFixed(2), '25.00')
  },
}
