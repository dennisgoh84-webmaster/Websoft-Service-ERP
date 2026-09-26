// The key-in flows, in the order they run (docs/self-test.md). Each one
// types into a real form the way staff would -- dates as digits, picks
// from its lists -- saves, then reads back through the API what the
// server stored. Later flows use what earlier ones made (`shared`), so
// the order matters; a flow whose `needs` are missing is skipped, not
// failed, so one broken form reports once rather than cascading.
//
// A NEW OR CHANGED FORM GETS A FLOW HERE (CLAUDE.md: test every field by
// keying it in, on a desktop and a phone).

import { companyIndividual, setupLists } from './masters.mjs'
import { contract, incident, jobOrder, product, prospect, quotation, receipt, salesInvoice, serviceRecord, softwareTask } from './sales.mjs'
import { paymentVoucher, purchaseOrder, purchaseOrderWithinLimit, supplierBill } from './purchasing.mjs'
import { goodsReceive, stockAdjustment, stockItem, warehouse } from './stock.mjs'
import { journalVoucher, staff } from './admin.mjs'
import { mobileApp } from './mobile.mjs'

export const FLOWS = [
  setupLists,
  companyIndividual,
  product,
  contract,
  jobOrder,
  serviceRecord,
  incident,
  quotation,
  salesInvoice,
  receipt,
  prospect,
  softwareTask,
  purchaseOrderWithinLimit,
  purchaseOrder,
  supplierBill,
  paymentVoucher,
  warehouse,
  stockItem,
  goodsReceive,
  stockAdjustment,
  journalVoucher,
  staff,
  mobileApp,
]
