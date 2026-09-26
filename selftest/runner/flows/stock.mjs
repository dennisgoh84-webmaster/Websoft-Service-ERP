// Key-in flows: stock -- a warehouse, a stock item, a goods receive note
// confirmed into stock at its cost, and a stock adjustment that moves
// nothing until approved (INV-001) and never takes stock below zero.

import { apiGet, expectStored, keyIn, rows, selectByText, sgDate, sgDateOf } from '../lib.mjs'
import { button, open, submit, tag } from './helpers.mjs'

async function levelOf(page, warehouseId, itemId) {
  const l = rows(await apiGet(page, `/stock/levels?warehouse_id=${warehouseId}`)).find((x) => x.stock_item_id === itemId)
  return l ? { qty: Number(l.quantity), avg: Number(l.avg_cost) } : { qty: 0, avg: 0 }
}

export const warehouse = {
  name: 'Warehouse: add',
  screen: '/warehouses',
  async run({ page, profileName, shared }) {
    const t = tag(profileName)
    await open(page, '/warehouses')
    await page.getByPlaceholder('Code', { exact: true }).pressSequentially(`W${t}`)
    await page.getByPlaceholder('Name', { exact: true }).pressSequentially(`Selftest Store ${t}`)
    await page.getByPlaceholder('Address', { exact: true }).pressSequentially('2 Selftest Road')
    await submit(page, button(page, 'Add Warehouse'), '/api/stock/warehouses')
    const w = rows(await apiGet(page, '/stock/warehouses')).find((x) => x.code === `W${t}`)
    if (!w) throw new Error('The warehouse was added but is not in the list.')
    expectStored('Name', w.name, `Selftest Store ${t}`)
    expectStored('Address', w.address, '2 Selftest Road')
    shared.warehouse = { id: w.id, code: w.code, name: w.name }
  },
}

export const stockItem = {
  name: 'Stock Item: add',
  screen: '/stock-master',
  async run({ page, profileName, shared }) {
    const t = tag(profileName)
    await open(page, '/stock-master')
    await button(page, '+ New Stock Item').click()
    await page.getByPlaceholder('Code *').pressSequentially(`SI${t}`)
    await page.getByPlaceholder('Name *').pressSequentially(`Selftest toner ${t}`)
    await page.getByPlaceholder('Category').pressSequentially('Consumables')
    await page.getByPlaceholder('UoM').fill('PCS')
    await page.getByPlaceholder('Reorder Level').fill('5')
    const created = await submit(page, button(page, 'Create'), '/api/stock/items')
    const item = await apiGet(page, `/stock/items/${created.id}`)
    expectStored('Code', item.code, `SI${t}`)
    expectStored('Name', item.name, `Selftest toner ${t}`)
    expectStored('Category', item.category, 'Consumables')
    expectStored('Unit of measure', item.unit_of_measure, 'PCS')
    expectStored('Reorder level', item.reorder_level, '5')
    shared.stockItem = { id: item.id, code: item.code, name: item.name }
  },
}

export const goodsReceive = {
  name: 'Goods Receive Note: 10 at 12.50, confirm, stock and cost updated',
  screen: '/grn',
  needs: ['warehouse', 'stockItem'],
  async run({ page, shared }) {
    const day = sgDate(0)
    await open(page, '/grn')
    await button(page, '+ New GRN').click()
    const form = page.locator('form').filter({ has: page.locator('h3', { hasText: 'New GRN' }) })
    await keyIn(form, 'Warehouse', shared.warehouse.code)
    await keyIn(form, 'Receive date', day.dmy)
    await keyIn(form, 'Notes', 'Selftest delivery')
    await selectByText(form.locator('select').filter({ has: page.locator('option', { hasText: 'Select Item...' }) }).first(), shared.stockItem.code)
    await form.getByPlaceholder('Qty').first().fill('10')
    await form.getByPlaceholder('Unit Cost').first().fill('12.50')
    const created = await submit(page, button(form, 'Create GRN'), '/api/stock/grn')
    let grn = await apiGet(page, `/stock/grn/${created.id}`)
    expectStored('Receive date (Singapore)', sgDateOf(grn.receive_date), day.iso)
    expectStored('Status', grn.status, 'draft')
    expectStored('Line quantity', grn.lines[0].quantity, '10')
    // INV-001's sibling: nothing moves until it is confirmed.
    expectStored('Stock before Confirm', (await levelOf(page, shared.warehouse.id, shared.stockItem.id)).qty, '0')

    await submit(page, button(page.locator('tr', { hasText: grn.grn_number }), 'Confirm'), `/api/stock/grn/${grn.id}/confirm`)
    grn = await apiGet(page, `/stock/grn/${grn.id}`)
    expectStored('Status after Confirm', grn.status, 'confirmed')
    const level = await levelOf(page, shared.warehouse.id, shared.stockItem.id)
    expectStored('Stock after Confirm', level.qty, '10')
    expectStored('Weighted average cost (INV-002)', level.avg.toFixed(2), '12.50')
  },
}

export const stockAdjustment = {
  name: 'Stock Adjustment: -2, moves nothing until approved (INV-001)',
  screen: '/stock-adjustment',
  needs: ['warehouse', 'stockItem'],
  async run({ page, shared }) {
    const day = sgDate(0)
    const before = (await levelOf(page, shared.warehouse.id, shared.stockItem.id)).qty
    await open(page, '/stock-adjustment')
    await button(page, '+ New Adjustment').click()
    const form = page.locator('form').filter({ has: page.locator('h3', { hasText: 'New Adjustment' }) })
    await keyIn(form, 'Warehouse', shared.warehouse.code)
    await keyIn(form, 'Adjustment date', day.dmy)
    await keyIn(form, 'Reason', 'Selftest: two damaged')
    await selectByText(form.locator('select').filter({ has: page.locator('option', { hasText: 'Select Item...' }) }).first(), shared.stockItem.code)
    await form.getByPlaceholder('Qty Change').first().fill('-2')
    await form.getByPlaceholder('Notes').first().pressSequentially('Water damage')
    const created = await submit(page, button(form, 'Create'), '/api/stock/adjustments')
    const find = async () => rows(await apiGet(page, '/stock/adjustments')).find((a) => a.id === created.id)
    let adj = await find()
    expectStored('Adjustment date (Singapore)', sgDateOf(adj.adjustment_date), day.iso)
    expectStored('Reason', adj.reason, 'Selftest: two damaged')
    expectStored('Line change', adj.lines[0].quantity_change, '-2')

    const row = () => page.locator('tr', { hasText: adj.adj_number })
    await submit(page, button(row(), 'Submit'), `/api/stock/adjustments/${adj.id}/submit`)
    expectStored('Stock while pending approval (INV-001)', (await levelOf(page, shared.warehouse.id, shared.stockItem.id)).qty, String(before))
    await submit(page, button(row(), 'Approve'), `/api/stock/adjustments/${adj.id}/approve`)
    adj = await find()
    expectStored('Status', adj.status, 'approved')
    expectStored('Stock after Approve', (await levelOf(page, shared.warehouse.id, shared.stockItem.id)).qty, String(before - 2))
  },
}
