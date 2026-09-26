# Backlog

A short, checkable list of what's pending, so we can just work down it.
Full detail for each item lives in [planned-work.md](planned-work.md) or
[open-business-decisions.md](open-business-decisions.md) -- linked per
item below rather than repeated here. Tick an item off when it's built
(or move it, with a short note, if it turns out to need more decisions
first) -- don't delete finished lines, so this stays a record of what
shipped and when.

## In progress

- [x] **AI Assistant PDPA self-declaration at login** -- built
  2026-09-15, settling the open half of decision 12.1. Every staff
  user must tick a one-time notice (masked queries may reach
  Anthropic's US-hosted API; non-sensitive usage data may be analysed
  internally) before using the system at all, on desktop and mobile.
  Recorded once as `users.ai_data_consent_at`, shown read-only on
  Staff Master, and structurally protected from ever being edited or
  cleared (not in `$fillable`, not in the profile-update endpoint's
  validated fields, and the acknowledge endpoint itself refuses to
  move an existing timestamp).
  → [open-business-decisions.md #43](open-business-decisions.md#43-ai-assistant-pdpa-self-declaration-at-login-raised-and-built-2026-09-15),
  [business-requirements.md PDPA-002](business-requirements.md)

- [x] **AI Assistant monthly token spending cap** -- built 2026-09-16,
  settling decision 12.2/42.2 ahead of the original "wait a month"
  plan, at Dennis's explicit instruction. An installation-wide
  `monthly_token_cap` under Maintenance → AI Assistant; once this
  calendar month's recorded usage (Asia/Singapore, the same boundary
  the Usage tile uses) reaches it, incident triage, staff chat, portal
  chat and the settings screen's connection test all refuse with a
  clear 422 before any provider call or `ai_interactions` row --
  checked once per request, not per tool-use round, so a capped
  conversation refuses cleanly rather than dying mid-reply. In tokens,
  not SGD (no live pricing feed; per-model rates differ and change).
  Leaving it blank keeps the previous unlimited behaviour.
  → [open-business-decisions.md #44](open-business-decisions.md#44-ai-assistant-monthly-token-spending-cap-raised-and-built-2026-09-16)


- [x] **Backend language conversion, Python/FastAPI → PHP/Laravel**
  -- **COMPLETE 2026-09-15** (the cutover itself is still Dennis's to
  call). Started 2026-09-14. Converted and verified: Core /
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
  the 5-bucket aging report, 16 dedicated tests), Accounts
  Payable/Purchasing (PUR-001/002/003 -- PO approval, 2-way matching,
  auto-approval on match, "confirm and import to AP", AP aging, 25
  dedicated tests), GL posting + Bank (ACC-001..004 -- every
  accounting event posts a balanced double-entry voucher, the
  explicit Bank step, UNGL reversal, 28 dedicated tests), and AR-001
  (recording a customer receipt, allocating it against invoices, the
  Bank step and UNGL -- symmetric to the Accounts Payable Payment
  Voucher, 18 dedicated tests), all in a new `backend-php/` app
  running against its own Postgres database, `backend/` (Python)
  untouched. Each converted module verified against the real React
  frontend (proxied at backend-php/ for the check), not just its own
  tests -- including the Invoices page's Aging widget, previously
  404ing, now showing all 5 buckets and correctly dropping to $0.00
  once a receipt fully pays an invoice; the Purchase Orders/Accounts
  Payable pages; and the full GL posting + Bank loop (Chart of
  Accounts, a Payment Voucher banked, the Bank Master File balance
  updating correctly). Billing closes both known gaps flagged by
  earlier modules -- contract activation now issues its BILL-001
  annual invoice (except AD_HOC), and a Billable excess-usage decision
  now issues its SRV-008 invoice, both with GST correctly applied; GL
  posting then closes the remaining gaps every billing-adjacent module
  had flagged -- invoices, matched bills, and Payment/Receipt Vouchers
  (create/allocate/bank/unbank/UNGL, both directions) all post
  correctly. Also fixed in passing: a real gap in the earlier
  CompanyIndividual Management conversion where `is_customer`/
  `is_supplier` were missing from create/update validation entirely,
  silently blocking anyone from ever being marked a supplier; a
  field-name mismatch (`balance_sgd` vs. the frontend's
  `current_balance_sgd`) caught by the Playwright verification pass
  before it shipped; and a caching bug in both AP's and AR's payment
  allocation (a payment's unallocated balance is computed from a
  cached relation that didn't refresh between two allocations in the
  same request, which could have let a payment be over-allocated).
  Also converted: Accounting Period management (create/close/reopen a
  period, the per-doc-type per-operation lock matrix, Year-End
  Closing, 26 dedicated tests) and GL Trial Balance / the per-account
  transaction ledger (including the `/reports/accounting/trial-balance`
  duplicate route AccountingReportsPage depends on, gated by its own
  `accounting_reports` module key, 16 dedicated tests) -- this makes
  `App\Services\Periods::requireAllows()`, wired into every posting/
  bank/reversal path since the GL posting + Bank module, a real check
  for the first time rather than a permanent no-op. Also converted:
  Quotations (create/list/get/send/accept/reject, single-rate GST
  totals, and the confirmed 2026-09-10 accept -> auto-Contract
  conversion -- hourly lines become one Service Support contract,
  every other line one Annual contract, a mix becoming two separate
  contracts never one blend, 19 dedicated tests). Also converted:
  Inventory / Stock -- the Stock Master half so far (setup masters
  Categories/Groups/Brands+Models/Usages, Warehouses, the stock item
  with its attachments, and read-only per-warehouse stock levels, 17
  dedicated tests), which also fixed a real bug: the Warehouses and
  Stock Item screens' Activate/Deactivate buttons could only ever 422
  against the Python backend, because its PATCH body required
  `code`/`name` and had no `is_active` field. Stock movements
  followed: Goods Receive/Transfer/Return Notes and Stock Adjustments,
  carrying both confirmed Inventory rules -- INV-002 weighted average
  cost (pinned to worked examples at the 4dp precision the stock
  columns actually use) and INV-001, where only an approval ever moves
  stock -- 32 dedicated tests -- and the stock operation reports
  (valuation, reorder alert, movements journal, 12 dedicated tests),
  which turned up a second real bug: the movements ledger's timestamp
  column only stored whole seconds, so same-second movements came back
  in an arbitrary order. Also converted:
  Incidents (the Helpdesk front door -- logging a call/email with its
  one automatic customer-by-email match, the "needs a callback" status
  + assignee, Close, and converting to a draft Sales Quotation or a
  Job Order against a valid contract, each auto-creating the real
  record with a back-reference; both Outlook Add-in endpoints
  including the confirmed fallback-to-plain-Incident rule; 35
  dedicated tests). KNOWN GAP: no convert-to-software-task route
  (Software Tasks isn't converted). Also converted: the
  **Company Dashboard** summary (`/dashboard/summary`, 9 dedicated
  tests) -- the app's landing page, which until now showed "Company
  Dashboard summary unavailable: Not Found" on every login against
  `backend-php/`; it aggregates only over modules already converted,
  reusing each owning service (AR/AP aging, the GL trial balance)
  rather than re-querying, so no tile reports a placeholder figure.
  Also converted: **Document Control** (the document-numbering admin
  screen -- counters plus each document kind's number format, both
  FULL-only and both requiring a reason recorded to Event Logs, 10
  dedicated tests) and **Document Attachments + eSignature** (the
  generic panel mounted on ~12 document detail pages, all of which
  404'd until now -- file upload/list/download/soft-delete on disk
  under the same layout the Python backend uses, 20MB cap, any file
  type, plus drawn signatures; 11 dedicated tests). **Correction to an
  earlier note:** the `.docx` export / "Email X" gaps recorded against
  Service Records, Invoices, Purchase Orders and Quotations were NOT
  closed by this -- that wiring is a separate stack
  (`mailer.py`, `pdf_convert.py`, `docx_forms.py`,
  `document_email.py`), **which has since been converted in its own
  right (2026-09-15, below)**.
  Also converted: **Announcements + Ad Banner** (the platform
  announcements and promo video URL behind the app-wide ad banner,
  plus its admin screen, 10 dedicated tests) --
  `GET /announcements/public` is unauthenticated and called on every
  page load by the app layout, which made it the single most
  frequently 404'd request in every prior smoke test against
  `backend-php/`. Deliberately global rather than company-scoped, and
  its table shape is kept identical to the Python model because
  [planned-work.md #8a](planned-work.md) has the future Server Company
  Central Command app writing advertisements straight into it.
  Also converted: the **Customer
  Helpdesk Portal** (PORTAL-001..006 -- the customer-side login as its
  own auth realm in its own `portal_users` table, whose
  `purpose="portal"` token every staff endpoint refuses and which
  refuses every staff token in return; the 5-failure/15-minute
  lockout; the staff-side enable/disable/reset actions with their PDPA
  consent gate and the PORTAL-004 "archiving a customer disables every
  portal login under it" cascade; and the customer-facing reads --
  contracts and hour balance, job orders, service records, invoices
  and payments -- plus raising an Incident that lands in the staff
  Helpdesk queue as `source=portal`; 48 dedicated tests, every data
  test run with a second customer's data present to prove isolation).
  That closes the Incidents module's other known gap and the
  CompanyIndividual Management module's portal-access gap, and adds
  the two foreign keys (`login_otps.portal_user_id`,
  `incidents.raised_by_portal_user_id`) earlier migrations had
  deferred until `portal_users` existed.
  Also converted: the **document generation stack and every `.docx` /
  "Email X" endpoint** (`docx_forms.py`, `pdf_convert.py`,
  `document_email.py` -- 42 dedicated tests). All seven Word forms
  (Sales Invoice, Sales Quotation, Receipt Voucher, Purchase Order,
  Payment Voucher, Service Record, Statement of Accounts), DOCX → PDF
  via LibreOffice headless (the same .docx bytes the Word button
  serves, so the two formats can never drift), and the shared "Email
  this document" helper -- which closes the `.docx`/"Email X" KNOWN
  GAPs recorded against Service Records, Invoices, Purchase Orders,
  Payment Vouchers, Receipt Vouchers, Quotations **and** the AR
  Customer Statement endpoints, all at once. The AR Customer
  Statement itself (its own separate gap) is converted with them.
  Adds `phpoffice/phpword` as a dependency (PHP has no built-in DOCX
  writer; the alternative is hand-rolling OOXML) and needs
  **`libreoffice-writer`** installed, not just `libreoffice-core`
  (DEV_SETUP.md already says this; `pdf_convert.py`'s own docstring
  in `backend/` is the stale one). Two bugs
  fixed on the way: `Mailer`'s `SMTP_USE_TLS` flag was inert (both
  branches of a ternary were identical, so TLS could never be turned
  off), and PHPWord's default of writing `<w:t>` text unescaped meant
  a literal "&" -- which every Service Record carries -- produced a
  file Word repairs silently but LibreOffice refuses, breaking the
  PDF behind every Email button while the Word download still looked
  fine.
  **The conversion is COMPLETE as of 2026-09-15.** The last pieces
  were Management Reporting (the four Operations Reports and the
  Accounting Reports -- AR/AP aging, trial balance, GST return, Sales
  GP, Commission with its rate setting), Commission Payouts, and
  CSV/Excel export on the remaining eighteen list screens. Every
  router in `backend/app/routers/` and every `export.csv`/
  `export.xlsx` route now has a PHP equivalent, and `backend-php/` has
  a Dockerfile and compose services (behind a `php` profile).
  `backend/` (Python) is untouched and still the system of record --
  **switching the frontend across is Dennis's decision**, one line in
  `frontend/nginx.conf`, see DEPLOY.md §4b.
  Two bugs found in `backend/` while converting, both worth raising:
  its commission service allocates payout numbers with positional
  arguments against a keyword-only signature, so Commission Payouts
  cannot run there at all and an AR write-off would fail once a
  non-zero commission rate is set; and the Job Orders report's
  "overdue" column tests for a `"resolved"` status this system has
  never had, so a VOID job order reads as overdue there while the
  filter beside it excludes VOID correctly.
  → [php-conversion-plan.md](php-conversion-plan.md)

## Waiting on Dennis to pick up (deferred 2026-09-12)

- [x] **GL Transactions / multi-currency** -- GL debit/credit ledger
  view built 2026-09-12: account-level transaction ledger with running
  balance, date filters, CSV/Excel export. Trial balance rows are now
  clickable drill-downs. Default ledger codes per document header/line
  and multi-currency (original + base SGD) are still waiting on Dennis
  (open items 4b.2 auto-posting accounts and 4b.5 multi-currency).
- [ ] **Bank Portal / ZSOFT HP Agency** -- **described by Dennis
  2026-09-16**, not yet built. **2026-09-16, separately: the Module
  Control gate itself and a placeholder page were built ahead of this
  scope description** -- `bank_portal_testing`, seeded OFF like every
  module (Module Control read API is out of scope in this repo;
  enablement is set directly in `company_modules`, or pushed from
  Central Command), nav entry + route gated the same way as any other
  module, see `BankPortalController.php` / `BankPortalTestingPage.tsx`.
  That page is still only a placeholder -- none of the actual HP/
  Insurance functionality below exists yet, and the module key/page
  should be revisited once real fields are being built, but the gate
  itself is real and testable now: nothing until Dennis switches it
  on. A Maintenance menu item ("Bank Portal Testing - HP Agency") that
  appears once a "bank module testing" Module Control key is switched
  on. A page stores each Hire Purchase
  application's basic data, to be submitted to multiple banks' HP
  application portals. Dennis: "Condition is the keep the bank portal
  window open in the background and after the security OTP login then
  can start transfer submit the data from our HP Agency Page to theirs
  according to the fields." Submission mechanism, asked and answered
  2026-09-16: a **browser extension** -- staff logs into the bank's own
  portal manually (their own credentials, their own OTP) in one tab,
  then a companion extension reads our HP Agency page's data and fills
  the bank portal's form fields in the other tab; no server-side
  automation and no bank credentials/OTP ever touch our backend. Still
  open before this can be scoped into a build: which bank portal(s)
  first, their exact field layout/selectors (likely a different
  extension content-script per bank), and how the extension itself is
  built, reviewed and distributed to staff machines.

  **Second tab described 2026-09-16, same page: Insurance Application
  to Insurance Portal for Quote.** Dennis: prefilled with customer,
  vehicle and basic driving details, submitted to an insurance
  quotation portal -- **no OTP** (unlike the bank HP tab) -- and once
  the quote is out, "now we need to copy back the information to our
  relevant fields (quote price with additional conditions)." So the
  same browser extension needs to work in **both directions**: fill
  forward into the insurer's portal, then read the resulting quote
  back out of it into our page. Dennis also flagged directly: "this may
  be done for different insurance company and bank portal because all
  their format may be different... We may need to also store their
  format or field name" -- i.e. a per-provider field-mapping table
  (their field name/selector ↔ our field), not a single hardcoded
  layout, covering both the HP tab's banks and this tab's insurers.
  **New open question this raises:** neither "vehicle" nor "driving
  details" exists anywhere in this system yet (no Vehicle entity, no
  hire-purchase or insurance record) -- confirmed by search, this is a
  new business area, not an extension of an existing one. Before any
  of this (either tab) can be scoped into a build, still needed:
  their exact field layouts, the extension's own build/review/
  distribution story, and where vehicle + driving-detail data is
  meant to live (a new entity, and whose record it hangs off --
  presumably the customer/CompanyIndividual, but not yet confirmed).

  **Settled 2026-09-16: first banks are DBS and UOB.** Field-mapping
  storage settled too, deliberately smaller than the rest of this
  system's pattern: Dennis -- "Don't need to have setup master files
  for them, just flat file and manually key in the data to store
  there." So per-bank/insurer field mappings are a flat file (no
  Setup List master, no CRUD admin screen, no database table with an
  owner-editable UI) -- engineering keys the mapping in when a
  provider is added, from the field names/selectors captured off that
  provider's actual form (see the testing/hand-off process agreed the
  same day: save the real form's page source, blank/dummy data only,
  and hand it over rather than a screenshot, since that carries the
  real field names the mapping needs -- verified field-by-field
  against the live portal since nothing here can be reached or tested
  directly). Still blocked on: the actual DBS/UOB page sources.

## Confirmed scope, not yet built

- [x] **Prospect / Leads + Sales Supervisor / Sales Staff roles** --
  built 2026-09-26 (SALES-009 / SALES-010 in business-requirements.md).
  Replaces the "CRM" activities. Dennis confirmed the defaults
  2026-09-26 -- pipeline stages, the Supervisor sees everything,
  "quoted" = sent to the customer, activities voided never deleted --
  except whether an accepted quotation marks the prospect Won:
  [open-business-decisions.md #46](open-business-decisions.md#46-prospect--leads-defaults-taken-raised-and-built-2026-09-26).
- [x] **Times stored eight hours ahead -- fixed 2026-09-26, including
  the stored data.** With `APP_TIMEZONE=Asia/Singapore` and a UTC
  database session, every time the app wrote itself (Eloquent's
  `created_at`/`updated_at`, `now()` in services) landed eight hours
  ahead; only database defaults and the few `Carbon::now('UTC')`
  writers were right. Fixed at the root: the database session now runs
  in `APP_TIMEZONE` (`config/database.php`), every
  `Carbon::now('UTC')` became `Carbon::now()`, migration
  `2026_09_30_002500` moved the rows the app had written back by eight
  hours, and CLAUDE.md's Development Rules now carry the rule, guarded
  by `TimeZoneRuleTest`. A few screens that turned a time into a date
  through UTC (`toISOString().slice(0, 10)`) were fixed with it.
- [x] **Module Control tidy-up -- done 2026-09-26.** Dennis: remove
  Purchasing (redundant -- purchase orders are Accounts Payable's),
  Projects and Hardware Management; Goods Issue is needed (it now has
  its own screen under Stock); Commission Management now switches and
  grants the commission report, its rate and Commission Payouts (each
  company's and group's access copied from Accounting Reports); the AI
  Assistant, the owner included, shows nothing anywhere unless it is
  switched on. The last two empty placeholders went the same day
  (Dennis: "Proceed to remove"): `inventory` (stock runs under its own
  Stock Master / goods-note / adjustment / report keys) and
  `integrations` (superseded by `data_migration`).

- [x] **Sales module enhancements (Job Implementation Template,
  multi-Product Job Orders, Contract hour-sharing, Contract filters,
  Contract Operation Report, Contract–Quotation reference, Sales
  Dashboard)** -- built 2026-09-22, directly in `backend-php/` +
  `frontend/`. **Not a Python→PHP conversion** -- new business scope
  Dennis asked for, with no `backend/` (Python) equivalent; distinct
  from the conversion work tracked in
  [php-conversion-plan.md](php-conversion-plan.md). Product now carries
  a reusable Job Implementation Template (ordered task checklist); a
  Job Order can select multiple Products, each importing its template's
  tasks (deduped by name across products, first selection wins), with
  completion gated to Sales Manager/Owner. A Contract keeps its own
  independent hour-sharing customer list (separate from
  CompanyIndividual Relationships), enforced when a Job Order is
  opened. Contracts list gained a remaining-hours-less-than filter and
  an expiry-date-range filter. New Contract Operation Report (Expiry
  Listing, Renewal Due Listing -- reusing SRV-014's window exactly)
  with CSV/Excel export. Contract gained a free-text `quotation_reference`
  field, settable once Renewed/Expired (KNOWN GAP -- see below). New
  New Sales Dashboard: Contracts Due for Renewal, Total/2‑/3‑month AR
  Outstanding (reusing the AR Aging report's own bucket logic), each
  drilling into its underlying rows, plus Top 10 Sales Billing Customer
  and Bottom 10 Non-Active Customer listings for "this Financial Year"
  with CSV/Excel export. **Update 2026-09-14, per Dennis's request:**
  moved to its own standalone Main Menu page/route (`/sales-dashboard`,
  gated on the `reporting` module), between Company Dashboard and My
  Ops Dashboard -- not merged into the Company Dashboard as originally
  built.
  **Two pragmatic defaults, flagged for Dennis to confirm, not silently
  assumed:** ~~"this Financial Year" = the calendar year (no
  fiscal-year-start field exists yet)~~ -- **settled 2026-09-15**: a
  real Company Setup value (`financial_year_start_month`, Webmaster
  runs 1 Jul - 30 Jun), editable on the Company Setup screen since
  the same day; and the Contract–Quotation link
  is free text only, not a real linked record -- both recorded in
  [open-business-decisions.md #40](open-business-decisions.md#40-sales-module-enhancements-financial-year-definition-and-contractquotation-link-raised-2026-09-22).
  ~~(Quotations has since been converted to `backend-php/`, which
  unblocks a real link; the free-text field itself hasn't been swapped
  out for one yet.)~~ **Built 2026-09-15 (SALES-006):** a real
  `contracts.quotation_id`, set on accept or by hand, and "Create
  renewal quotation" on an expiring contract whose acceptance renews
  it. ~~Two Quotations-dependent Sales Dashboard KPIs
  ("Pending Approval" / "Pending Confirmation by Client") still always
  report not-available.~~ **Settled 2026-09-15 (SALES-008):** the
  Quotation status model gained `pending_approval` and `approved`
  (BILL-006's Sales Manager approval step), so both tiles now count
  real rows.
  → [planned-work.md #11](planned-work.md#11-sales-module-enhancements-job-implementation-template-multi-product-job-orders-contract-hour-sharing-contract-filters-contract-operation-report-contractquotation-reference-sales-dashboard-raised-earlier-built-2026-09-22),
  rules SALES-001..007
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
  live; the Outlook Add-in half is built and tested (2026-09-25) and
  waits only on an HTTPS address + a Microsoft 365 upload (see
  docs/outlook-addin.md).
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
- [x] **Odoo migration program** -- Contacts/Subscriptions/Timesheets/
  Quotations/Invoices/Receipts/Chart of Accounts, plus an opening-balance
  journal. **Built 2026-09-25 as Maintenance → Data Migration** for ODOO
  and ZSOFT (dashboard, per-module Field Gap / Import / Roll back, 4-step
  import, batch log) -- [data-migration.md](data-migration.md). Next: the
  Excel exports from both systems, to close each module's Field Gap list. Still open: which history is
  "important" and the phasing order (open-business-decisions #10), and
  the actual cut-over run against Webmaster's Odoo exports.
  → [planned-work.md #6](planned-work.md#6-odoo-migration-program----contacts-subscriptions-timesheets-sales-quotationsinvoicesreceipts-chart-of-accounts-raised-2026-09-12)
- [x] **WhatsApp OTP** as a second login factor -- was blocked on
  provisioning a WhatsApp Business API account (Twilio/Meta); email OTP
  already works today. **Dennis confirmed 2026-09-16: provisioned, and
  built in the separate
  [websoft-central-command](https://github.com/dennisgoh84-webmaster/websoft-central-command)
  repository.** **This repo's own gap since closed, same day:** a
  separate pass built WhatsApp OTP directly in `backend-php`/`frontend`
  too -- a `phone` field on `users`, a `channel` column on
  `login_otps`, and `WhatsAppSender` (Twilio's WhatsApp API, mirroring
  `Mailer`'s `isConfigured()`/`send()` contract) -- so a user with both
  channels available now picks Email or WhatsApp at login
  (`otp_channel_required` / `POST /api/auth/send-otp`); one channel
  available sends on it as before. Still blocked on real Twilio
  credentials in THIS install's own `.env` -- Central Command having
  its own working account does not put credentials into this app's
  `backend-php/.env`, so every install here still runs email-only until
  `TWILIO_ACCOUNT_SID`/`TWILIO_AUTH_TOKEN`/`TWILIO_WHATSAPP_FROM` are
  set for it specifically.
  → [planned-work.md #7](planned-work.md#7-whatsapp-otp-as-a-second-login-factor-raised-2026-09-12-built-2026-09-16-blocked-on-credentials)
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
- [x] **Server/system config pushed from Central Command, not set per
  client** -- raised 2026-09-15. This side (the `system_mail_settings`
  table + Maintenance → System Email screen, `.env` kept only as the
  `otp` bootstrap fallback) was done 2026-09-15. **Dennis confirmed
  2026-09-16: the push itself is now built in the separate
  [websoft-central-command](https://github.com/dennisgoh84-webmaster/websoft-central-command)
  repository** -- outside this session's access, so not independently
  verified here, but taken as done on his word. Distinct from the
  per-company mailbox in Company Setup, which stays client-side and is
  unaffected.
  → [planned-work.md #8c](planned-work.md#8c-serversystem-configuration-push-raised-2026-09-15)

- [x] **No Dockerfile for `backend-php/`** -- found and written
  2026-09-15. `backend-php/Dockerfile` (php:8.4-fpm with nginx in
  front, so it speaks HTTP on :8000 like the Python image and is a
  drop-in swap), plus `backend-php` and `migrate-php` services in
  `docker-compose.yml` behind a `php` profile, so a plain
  `docker compose up` still brings up the Python stack unchanged.
  Cutting the frontend over is one line in `frontend/nginx.conf` and
  is **Dennis's decision, not a deploy side effect** -- see DEPLOY.md
  §4b. It installs `libreoffice-writer`, as the Python image does.
  ~~**Not yet built or run:** there is no Docker daemon in the
  development environment it was written in, so `docker compose
  --profile php up --build` has never been executed. First run may
  need small fixes.~~ **Built and run on Dennis's test server
  2026-09-15** via `deploy/install.sh` -- and the first run did need
  fixes, three of them, see "Test server" below.
- [x] **Two export writers now coexist** -- resolved 2026-09-15 by
  retiring `App\Services\ExportService`. There is now ONE export
  writer, `App\Services\Exports` (the faithful port of `exports.py`:
  real CSV, real `.xlsx` via PhpSpreadsheet). The two report screens
  that used the old one -- Contract Operation Report and the Sales
  Dashboard drill-downs -- moved onto it and now download a genuine
  `.xlsx`, which is what docs/ui-guidelines.md section 2 specified all
  along ("never write a CSV/XLSX writer by hand"). Their column
  headers stay human labels ("Contract Number"), which is theirs to
  keep -- these screens have no Python counterpart.
  **User-visible:** those five downloads change from `.xls` to
  `.xlsx`, and the `/export.xls` routes are gone (they now 404).
  Excel had been warning that the old file's format and extension
  disagreed, because they did.
  → [php-conversion-plan.md](php-conversion-plan.md)

- [x] **Product-based Sales Invoicing** -- built 2026-09-15, closing
  the blocker recorded the same day. `invoices` was header-only, so
  "Sales Invoice picks stock" had nowhere to put a product. There is
  now an `invoice_lines` table, `POST /invoices` raises a Sales
  Invoice by hand, and the Invoices page has a "Raise Sales Invoice"
  form that shows on-hand quantity per line as it is filled in.
  **Lines are optional** (confirmed): every existing header-only
  invoice keeps working untouched, no backfill, and the auto-issued
  ones (contract activation, excess-usage decision) still issue a
  single amount.
  A stock line deducts through the same
  `InventoryService::deductStock()` a Goods Issue Note uses, so
  INV-002 holds by construction -- insufficient stock refuses the
  WHOLE invoice, quantity never goes negative, and units leave at
  weighted average without re-weighting it. The line records the
  average AS AT ISSUE, so a later receipt cannot move a past
  invoice's gross profit; that also gives these invoices a real
  `cost_sgd`, so Sales GP shows a measured margin on them.
  Verified end to end against the running app, not only by tests.
  **Deliberately NOT done:** no COGS/inventory journal is posted --
  whether stock movements post to the GL is still an open question
  below, and this was not the place to answer it quietly.
  → [php-conversion-plan.md](php-conversion-plan.md)

- [x] **Maintenance / Company-Individual batch** -- built 2026-09-15
  from Dennis's list. Company/Individual File: Country, State /
  Province and City are pull-downs fed by the setup lists (State and
  City narrow to the chosen Country; a value recorded before the
  lists existed is kept as an option rather than blanked); the data
  expiry date moved into the PDPA & Data Retention section and
  defaults to five years from the e-signed date (PDPA-001); the
  Relationship picker reads the new Relationship setup list. Service
  Contract detail shows the client (name, contact, phone, email,
  address) in its header. Maintenance: Company Setup says "Add a Sub
  Company" / "Create Sub Company"; the Product Catalog has a View /
  Edit form per product, with Product Category and Unit of Measure
  picked from setup lists; the ten setup lists (Country, State /
  Province, City, Nationality, Area Code, Currency, Industry, Product
  Category, Unit of Measure, Relationship) are each their own
  Maintenance menu entry at `/setup-lists/<type>`. Six units of
  measure are seeded (Hours, Unit, Piece, Lot, Month, Year).
  Company code: the running number is zero-padded to eight characters
  in all (WEBCOPT1, ACMMA001, ACM00001) per Dennis's clarification,
  and the recoding migration's NOT NULL slip -- it failed on any
  database that already had a company -- is fixed with a regression
  test that runs both migrations over live rows.
  → [open-business-decisions.md #41](open-business-decisions.md#41-system-generated-company-code-raised-and-settled-2026-09-15),
  [business-requirements.md PDPA-001](business-requirements.md)

- [x] **Service Record rules 9.1 / 9.2 settled, SLA removed** --
  2026-09-15. SRV-019: only Nico (Service Lead) or Cherish (Sales
  Manager) approve a Service Record -- the owner no longer can -- and
  a record still unapproved a week after submission is flagged
  "approval overdue" on the approval queue and counted on the Company
  Dashboard (a flag, not an enforcement). SRV-020: every Job Order
  carries a billing classification (Contract hours / Billable /
  Non-billable) that decides what its approved time becomes; Billable
  and Non-billable never touch the contract hour pool, and staff never
  choose per record. SRV-009 (SLA targets) is removed from the
  requirements and every doc at Dennis's instruction -- not deferred,
  gone.
  → [business-requirements.md SRV-019 / SRV-020](business-requirements.md),
  [open-business-decisions.md #9](open-business-decisions.md#9-service-records--approval)

- [x] **AI Assistant — slice 1 built 2026-09-15** (scoped the same
  day): incident triage + resolution suggestions on the Incidents page
  ("AI triage": customer, contract, priority, route, similar past
  incidents and what fixed them, a draft reply — proposes only, staff
  press the same buttons), Maintenance → AI Assistant (API key,
  model, personal-data mask, connection test, usage/tokens), the
  `ai_assistant` paid add-on module key gating everyone including the
  owner, and an `ai_interactions` audit per call. Personal data is
  masked before sending by default. Anthropic PHP SDK, structured JSON
  answers, provider faked in tests.
  **Slice 2 built the same day:** a chat panel on the Incidents,
  Company / Individual, Contract, Job Order and Service Records
  screens — ask in any language (English, 中文, Bahasa Melayu, தமிழ்),
  answered in the same language, through read-only tools that run
  with the asking person's own permissions (customers, contracts and
  hours, job orders, service records, incidents, receivables, AR
  aging) — plus the assistant's name and avatar under Maintenance →
  AI Assistant (image generated outside from the /imagine prompt kept
  there and in planned-work #12).
  **Slice 3, same day ("Yes on helpdesk portal is good"):** the same
  assistant as a floating chat widget on every Customer Helpdesk
  Portal tab, its own auth realm and its own hard-scoped read-only
  tools (never a customer id as input — always the signed-in portal
  user's own customer), the same "propose, never commit" rule (she
  cannot raise an Incident, only help word one), and its own audit
  trail (`ai_interactions.portal_user_id`).
  **Still open for Dennis:** 12.1 (is masked text to a US-hosted API
  acceptable, or is a regional / self-hosted model needed?) and a cost
  cap once usage is visible (12.2). Tier 1 items 3–4 and Tier 3 not
  built.
  → [planned-work.md #12](planned-work.md#12-ai-assistant--where-ai-fits-this-system-and-what-it-must-not-do-raised-2026-09-15),
  [open-business-decisions.md #42](open-business-decisions.md#42-ai-assistant-slice-1--defaults-taken-for-the-four-open-decisions-built-2026-09-15)

## Test server (2026-09-15) -- first real deployment of `backend-php/`

- [x] **Three bugs that stopped a clean checkout deploying** -- found
  by Dennis installing `deploy/` on a real server, fixed and verified
  the same day (`3e27871`). Compose read `.env` from `deploy/`, not the
  repo root (`--env-file` now passed everywhere); the Dockerfile ran
  `mkdir` after composer needed the directory and used a brace
  expansion `/bin/sh` (dash) does not have; and the Mobile screen was
  blank for every engineer because `response()->json(null)` emits `{}`
  (Symfony's JsonResponse), which the frontend treats as an open
  time-in. Also: `install.sh` now generates a random owner password
  (`user:set-password` artisan command) instead of leaving the
  published `demo1234` live on a box reachable through a tunnel.
- [x] **Portal login had no admin screen** (`fc5fd78`) -- not a
  conversion gap: the four staff-side endpoints were converted and
  tested, but no screen ever called them, so no portal user could be
  created and the portal refused every sign-in. Contact Person table
  now carries Enable / Reset / Disable + status + the one-time
  temporary password.
- [x] **Seeder parity** (`fc5fd78`) -- `DatabaseSeeder` now seeds the
  real letterhead (address, UEN, GST no.) and logo, the ad banner and
  the three announcements, ported from `seed_demo.py`; a reseed fills
  blanks only, never overwriting entered data.
- [x] **Portal is desktop-first** (`111318c`) -- reverses the design
  doc's phone-first guess at Dennis's request; phone width still works.
- [x] **Company mailbox + financial year had no screen** (`b93d01a`)
  -- same class of gap as the portal admin: backend built and tested,
  `Mailer`'s own error told people to fill it in under Company Setup,
  and no such fields existed. Now an "Outbound email" card with Send
  test email, and the FY start month select.
- [x] **Cutover -- Python backend retired 2026-09-15.** Dennis's
  instruction: "settle all python coding in the system and ensure all
  retired, make sure only backend-php". `backend/` removed from the
  tree (in git history); `deploy/docker-compose.php.yml` became the
  root `docker-compose.yml` with the same project name and volume
  names, so the test server's data is adopted on its next upgrade;
  install/upgrade scripts, `.env.example`, DEPLOY.md, DEV_SETUP.md,
  README, CLAUDE.md and the architecture/contract docs rewritten
  PHP-only. Not ported on purpose: `seed_demo.py`'s larger demo
  dataset and the one-off `post_backlog.py` GL back-fill. **One
  follow-up outside this repo:** Central Command checks
  `alembic_version` to version a client database; it must read
  Laravel's `migrations` table now (docs/central-command-schema-contract.md).
- [ ] **Outlook Add-in and Gmail add-on: built and tested 2026-09-25,
  waiting only on going live** -- served by the app at `/outlook-addin/`
  and `/gmail-addon/`, a Maintenance → Email Add-ins page that downloads
  each one's files filled in with the server's address; Outlook signs in
  with the usual login and sign-in code, Gmail with a one-time connect
  code from `/connect-addin`. Dennis chooses which to use first. Still
  needs the server on HTTPS, then a Microsoft 365 administrator to
  upload the Outlook manifest or the Apps Script project installed in
  the helpdesk Google account ([outlook-addin.md](outlook-addin.md)).
  The Helpdesk mailbox is set up and sending (a Gmail App Password). (~~The SMTP transport has no connect timeout~~ -- already
  fixed 2026-09-15: `Mailer` bounds the connect to 15 seconds for the
  duration of a send; this line had not been ticked. Confirmed
  2026-09-25.)
- [x] **Promo video is a `<video src>` URL only (no upload), which the
  Announcements screen didn't say.** First marked settled 2026-09-16
  by pointing to guidance added in the separate websoft-central-command
  repository rather than a code change here -- **superseded the same
  day**: Dennis asked directly, in this repo, "have the video advert
  change to a source file rather than a public URL." Built for real:
  an upload option alongside the existing URL field (whichever was set
  most recently wins -- only one is ever live), stored on disk under
  `uploads_dir`/ad_banner (`App\Services\AdBannerVideo`), served
  through its own unauthenticated endpoint (`GET /api/announcements/
  video/{filename}`, since the Login page plays it before anyone has
  signed in). See open-business-decisions.md #28.3/#30.2.
- [x] **Same day, follow-up: the promo video was one shared setting,
  and Dennis wanted the Login page and the in-app banner set
  independently.** `ad_banner_settings` is now keyed by `slot`
  (`login` / `app`) instead of a fixed `id = 1` singleton, each with
  its own upload-or-URL; Central Command also gained the ability to
  push a video URL to either slot independently. See
  open-business-decisions.md #45.

## Partially open

- [x] **Commission Management** -- ~~the GP-based report is built;
  approval workflow, clawback rules, and payout mechanism (6.3-6.5) are
  still fully open.~~ All 6 items (6.1-6.5) resolved and built:
  approval workflow (DRAFT→PENDING→APPROVED→PAID), automatic clawback
  on write-off, finance-administered payout with Mark Paid action.
  **Confirmed complete by Dennis 2026-09-16** after running through it
  live: "any further Commission Management rules beyond what's
  built... Complete as per now, nothing pending."
  → [open-business-decisions.md #6](open-business-decisions.md#6-commission-management)
- [x] **Smaller longstanding open questions (sections 7 & 8)** --
  settled and built 2026-09-12. Budget overrun detection + Sales Manager
  approval on PROJECT Job Orders (7.1); labour costing deferred (7.2);
  milestone completion approval gated to Sales Manager (7.3); ownership
  questions (8.1-8.3) confirmed as open-to-team via Group Authority.
  → [open-business-decisions.md #7-8](open-business-decisions.md#7-projects)

---
Last updated: 2026-09-16 (AI Assistant monthly token spending cap built; WhatsApp OTP and the Central Command config push confirmed done in the separate websoft-central-command repository; 2026-09-15: Python backend retired; system mailboxes in the database; Quotation status model SALES-008; Contract–Quotation link SALES-006; Maintenance / Company-Individual batch; eight-character company code; Service Record rules SRV-019/020, SLA removed; AI Assistant slices 1, 2, 3 + PDPA consent gate)
