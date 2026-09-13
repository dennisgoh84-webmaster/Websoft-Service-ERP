# Backlog

A short, checkable list of what's pending, so we can just work down it.
Full detail for each item lives in [planned-work.md](planned-work.md) or
[open-business-decisions.md](open-business-decisions.md) -- linked per
item below rather than repeated here. Tick an item off when it's built
(or move it, with a short note, if it turns out to need more decisions
first) -- don't delete finished lines, so this stays a record of what
shipped and when.

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
- [x] **Server Company Central Command** -- built 2026-09-12. Separate
  app scaffolded in `central-command/` directory with its own FastAPI
  backend (port 8001) + React frontend (port 5174) + Docker Compose.
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
Last updated: 2026-09-12 (Central Command built — client registry, ad push, license management, config updates)
