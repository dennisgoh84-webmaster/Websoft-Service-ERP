# Backlog

A short, checkable list of what's pending, so we can just work down it.
Full detail for each item lives in [planned-work.md](planned-work.md) or
[open-business-decisions.md](open-business-decisions.md) -- linked per
item below rather than repeated here. Tick an item off when it's built
(or move it, with a short note, if it turns out to need more decisions
first) -- don't delete finished lines, so this stays a record of what
shipped and when.

## In progress

- [ ] **Backend language conversion, Python/FastAPI → PHP/Laravel**
  -- started 2026-09-14. Converted and verified so far: Core /
  Administration (auth, audit logging, Group Authority, Module
  Control, Company Setup, Users/Staff Master), CompanyIndividual
  Management (Customer/Supplier master, Contacts, Branches,
  Relationships, PDPA consent/archive), Product/Service Catalog, and
  Service Contracts + Job Orders (SRV-001/002/005/010/012/014/016/018,
  21 dedicated business-logic tests), Service Records
  (SRV-003/004/007/015, the approval queue + Job Order auto-close, 24
  dedicated tests), Excess Usage (SRV-004/011/013 treatment decisions,
  11 dedicated tests), Billing/Invoicing (BILL-001/002/005, SRV-008,
  GST via TaxCode, 10 dedicated tests), Accounts Receivable (AR-002
  write-offs with the owner/threshold rule, AR-003 dispute flagging,
  the 5-bucket aging report, 16 dedicated tests), and Accounts
  Payable/Purchasing (PUR-001/002/003 -- PO approval, 2-way matching,
  auto-approval on match, "confirm and import to AP", AP aging, 25
  dedicated tests), all in a new `backend-php/` app running against
  its own Postgres database, `backend/` (Python) untouched. Each
  converted module verified against the real React frontend (proxied
  at backend-php/ for the check), not just its own tests -- including
  the Invoices page's Aging widget, previously 404ing, now showing all
  5 buckets, and the Purchase Orders/Accounts Payable pages. Billing
  closes both known gaps flagged by earlier modules -- contract
  activation now issues its BILL-001 annual invoice (except AD_HOC),
  and a Billable excess-usage decision now issues its SRV-008 invoice,
  both with GST correctly applied. Also fixed in passing: a real gap
  in the earlier CompanyIndividual Management conversion where
  `is_customer`/`is_supplier` were missing from create/update
  validation entirely, silently blocking anyone from ever being
  marked a supplier. **Known gaps:** invoices aren't posted to the
  General Ledger yet, a matched bill's own GL posting is one step
  short, and AR-001 (customer receipts) / Payment Vouchers can't
  happen at all yet -- all genuinely blocked on the still-unconverted
  GL posting + Bank module (`bank_accounts`/`accounts` don't exist
  yet), not merely deferred for time. Still pending: GL posting +
  Bank step, and everything else -- converted module by module, same
  pattern as the Odoo replacement strategy.
  → [php-conversion-plan.md](php-conversion-plan.md)

## Waiting on Dennis to pick up (deferred 2026-09-12)

- [x] **GL Transactions / multi-currency** -- GL debit/credit ledger
  view built 2026-09-12: account-level transaction ledger with running
  balance, date filters, CSV/Excel export. Trial balance rows are now
  clickable drill-downs. Default ledger codes per document header/line
  and multi-currency (original + base SGD) are still waiting on Dennis
  (open items 4b.2 auto-posting accounts and 4b.5 multi-currency).
- [ ] **Bank Portal / ZSOFT HP Agency** -- still needs Dennis to say
  what this actually is (an in-app record + Send button, vs. literal
  automation of a real bank's website) before it can be started safely.

## Confirmed scope, not yet built

- [x] **Sub-ledger → GL posting + Bank step** -- settled and built 2026-09-14.
  Invoices, bills, receipts and payments post to the GL; receipts and
  payments get an explicit Bank / Unbank step into the bank book.
  Closes the gap that the Trial Balance was only ever trivially balanced.
  Built: posting service + account map, auto-post on invoice issue / bill
  approval / RV+PV save, Bank/Unbank and UNGL endpoints with period
  guards, `scripts/post_backlog.py` back-fill, GL/Bank chips on the four
  screens, bank-account and expense-account selects.
  → [gl-posting-design.md](gl-posting-design.md), rules ACC-001..004
- [x] **Customer Helpdesk Portal** -- settled and built 2026-09-14,
  extended same day with Invoices/Payments and contract-scoped service
  records. Per-Contact logins (email + password + OTP, own
  `portal_users` table, purpose="portal" token -- never accepted by any
  staff endpoint and vice versa, tested explicitly). Staff
  enable/disable/reset access from the Contacts tab, gated by PDPA
  consent. Customers sign in at `/portal` to view their contracts/hours
  (+ drill into a contract's own service records, PORTAL-006), job
  orders + service records, their own Invoices and Payments
  (PORTAL-005 -- reverses the original "no money" call), and incidents
  (with the routed Job Order shown once converted), and to raise a new
  Incident (source=portal, visible to staff same as any other Incident).
  → [customer-portal-design.md](customer-portal-design.md), rules PORTAL-001..004
- [x] **Mobile web app for Support Staff** -- built 2026-09-12. Time
  in/out, work description, camera photo/video attachments, finger-drawn
  signature + watermarked chop photo sign-off. All 8 open questions
  settled. Route: `/mobile`.
  → [planned-work.md #1](planned-work.md#1-mobile-web-app-for-support-staff----on-site-job-order--service-record-capture-raised-2026-09-11-built-2026-09-12)
- [x] **Incident Module** -- built 2026-09-12. In-app screen (log,
  route to Quotation/Job Order/Software Task, or a callback status) is
  live; the Outlook Add-in half is scaffolded only -- not deployable/
  testable without a real Microsoft 365 tenant + HTTPS host (see
  outlook-addin/README.md).
  → [planned-work.md #2](planned-work.md#2-incident-module----support-staff-callissue-log-with-routing-to-salesjob-ordersoftware-tasks-raised-2026-09-11-deferred-until-after-companyindividual)
- [x] **eSignature + eDocument attachments** -- built 2026-09-12.
  Backend: DocumentAttachment + DocumentSignature models, file-upload
  service, REST routers, Alembic migration. Frontend: reusable
  DocumentAttachmentsPanel + SignaturePanel components wired into all 12
  document pages (Quotation, Invoice, Receipt, Payment Voucher, Purchase
  Order, Supplier Invoice/AP Bill, Journal Entry, Job Order, Service
  Record, Contract, Incident, Commission Payout).
  → [planned-work.md #3](planned-work.md#3-esignature--edocument-attachments----all-operations-and-accounting-documents-raised-2026-09-12-put-on-the-waiting-list-at-the-end-then-we-build-it-in)
- [x] **eApproval Master** -- built 2026-09-12. Backend: generic
  authority-based, multi-staff, value-gated approval framework with
  ApprovalRule + ApprovalRequest + ApprovalStep models, configurable per
  document type / value threshold, REST admin pages. Frontend admin UI
  for managing approval rules already in place. Absorbs Service Record
  approval logic.
  → [planned-work.md #4](planned-work.md#4-eapproval-master----authority-based-value-gated-multi-staff-approvals-across-documents-raised-2026-09-12)
- [x] **Product "Is Stock" flag** -- built 2026-09-12. `is_stock`
  boolean added to Product model + migration + frontend toggle on
  Product Catalog page. Full Stock Master link-up deferred until
  the separate Websoft Stock Distribution ERP project is ready.
  → [planned-work.md #5](planned-work.md#5-product-is-stock-flag--stock-master-item-selection----pending-websoft-stock-distribution-erp-raised-2026-09-12)
- [ ] **Odoo migration program** -- Contacts/Subscriptions/Timesheets/
  Quotations/Invoices/Receipts/Chart of Accounts. 6 open questions on
  access method, field mapping, cutover sequencing.
  → [planned-work.md #6](planned-work.md#6-odoo-migration-program----contacts-subscriptions-timesheets-sales-quotationsinvoicesreceipts-chart-of-accounts-raised-2026-09-12)
- [ ] **WhatsApp OTP** as a second login factor -- blocked on
  provisioning a WhatsApp Business API account (Twilio/Meta); email OTP
  already works today.
  → [planned-work.md #7](planned-work.md#7-whatsapp-otp-as-a-second-login-factor-raised-2026-09-12-deferred)
- [x] **Server Company Central Command** -- built 2026-09-12, moved to
  its own repository 2026-09-13:
  [websoft-central-command](https://github.com/dennisgoh84-webmaster/websoft-central-command).
  Separate app with its own FastAPI backend (port 8001) + React
  frontend (port 5174) + Docker Compose.
  All 6 open questions settled. Features: client registry with DB
  connection testing + Alembic version check, advertisement creation +
  per-client targeting + push, video banner push, module license
  management (enable/disable via direct DB push), config updates (SQL
  push for tax rate changes, new defaults), full push activity log.
  Admin login: `admin` / `Admin123`.
  → [planned-work.md #8](planned-work.md#8-server-company-central-command----remote-adbanner-push--license-enforcement-raised-2026-09-12)

## Partially open

- [x] **Commission Management** -- ~~the GP-based report is built;
  approval workflow, clawback rules, and payout mechanism (6.3-6.5) are
  still fully open.~~ All 6 items (6.1-6.5) resolved and built:
  approval workflow (DRAFT→PENDING→APPROVED→PAID), automatic clawback
  on write-off, finance-administered payout with Mark Paid action.
  → [open-business-decisions.md #6](open-business-decisions.md#6-commission-management)
- [x] **Smaller longstanding open questions (sections 7 & 8)** --
  settled and built 2026-09-12. Budget overrun detection + Sales Manager
  approval on PROJECT Job Orders (7.1); labour costing deferred (7.2);
  milestone completion approval gated to Sales Manager (7.3); ownership
  questions (8.1-8.3) confirmed as open-to-team via Group Authority.
  → [open-business-decisions.md #7-8](open-business-decisions.md#7-projects)

---
Last updated: 2026-09-14 (GL posting + Bank step, and Customer Helpdesk Portal, both built and verified)
