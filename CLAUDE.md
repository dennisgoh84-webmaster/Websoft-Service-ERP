# Websoft Service ERP Solution

## Project Overview

| Key | Value |
|---|---|
| Project Name | Websoft Service ERP Solution |
| Repository | [github.com/dennisgoh84-webmaster/websoft-service-erp](https://github.com/dennisgoh84-webmaster/websoft-service-erp) |
| Company | Webmaster Consultancy Pte Ltd |
| Country | Singapore |
| Currency | SGD |
| Timezone | Asia/Singapore |
| Database | PostgreSQL |

## Purpose

Develop a custom ERP / business management system to eventually replace Odoo.

This is a greenfield project. No application code, database schema, or
business workflow assumptions exist yet. Documentation and rules are being
established first; detailed requirements and architecture will be developed
before any application coding begins.

## Initial Business Areas

These are the initial candidate business areas for the ERP. None of these
have detailed requirements yet — they are listed here only to scope the
eventual project.

- CRM
- Sales
- Customer Management
- Service Contracts
- Helpdesk / Service Operations (Job Orders)
- Projects
- Service Records
- Billing
- Accounts Receivable
- Accounts Payable
- Purchasing
- Inventory
- Hardware Management
- Commission Management — the commission **report**, its rate setting
  and **Commission Payouts** (generate / approve / pay / clawback) are
  built; further commission business rules remain deferred (see
  [docs/open-business-decisions.md](docs/open-business-decisions.md))
- Management Reporting
- AI Assistant

"Ticket"/"Timesheet" terminology has been renamed throughout to "Job
Order"/"Service Record" respectively, at Dennis's request.

## Approved Architecture Decisions

The following architecture decisions have been approved for the initial
Websoft Service ERP Solution project. These decisions must not be changed without
explaining the reason first (see Development Rules below).

1. **Backend:** PHP 8.4 / Laravel 11 (**changed 2026-09-14, in progress**
   -- was Python + FastAPI. Reason: a team/hosting constraint, not a
   technical problem with FastAPI. Same PostgreSQL schema design and
   the same JSON API contract, so the frontend is unaffected. Being
   carried out as a phased, module-by-module conversion, the same
   pattern already used for the Odoo replacement strategy below, with
   the existing Python backend (`backend/`) kept running unchanged
   until each module's PHP equivalent (`backend-php/`) is converted and
   verified -- see [docs/php-conversion-plan.md](docs/php-conversion-plan.md)
   for what has been converted so far and what remains.)
2. **Frontend:** React + TypeScript
3. **Database:** PostgreSQL
4. **Initial deployment:** Cloud/VPS deployment, with the architecture kept
   portable to AWS, Azure, or other infrastructure later.
5. **Odoo replacement strategy:** Phased, module-by-module replacement with
   a parallel-run period. A big-bang migration will not be used.
6. **Odoo historical data:** Important historical data will eventually be
   migrated into Websoft Service ERP Solution. Older data may be archived rather than
   fully operational.
7. **Multi-company:** The architecture should support multiple
   companies/entities in the future. The initial implementation is for
   Webmaster Consultancy Pte Ltd only.
8. **Singapore requirements:** The architecture must anticipate:
   - GST
   - InvoiceNow / Peppol
   - PDPA
   - Financial audit trails
   - Role-based access control
   - Data backup and recovery

## Development Rules

- Use a modular and maintainable architecture.
- Use PostgreSQL as the primary database.
- All important financial and operational transactions must have audit trails.
- Never permanently delete important business or financial records.
- Use soft-delete or archival where appropriate.
- Database changes must use migrations.
- Never modify production data directly.
- Authentication and role-based permissions are required.
- Validate data on both frontend and backend.
- Write automated tests for important business logic.
- Do not introduce unnecessary dependencies.
- Keep business logic separate from the user interface.
- Document major architectural decisions.
- Do not change the approved architecture without explaining the reason first.
- Never assume a business rule when requirements have not been provided.
- The test suite gates `main`, in both directions. Run the full suite
  (`cd backend-php && php artisan test`) and `./vendor/bin/pint --test`
  before every push to `main`:
  - **Red — never push.** A failing suite is a blocker, never something
    to note in the commit message and push anyway.
  - **Green — push.** Finished, verified work goes to `main`; it is not
    left sitting on a branch or in a worktree waiting for a later batch.
  Confirmed 2026-09-14, when development moved to working on `main`
  directly and the branch-review buffer went away.

## Documentation

- [docs/business-requirements.md](docs/business-requirements.md) — confirmed business rules (SRV-001..018, BILL/AR/PUR/INV/HW series) and open decisions still being gathered
- [docs/system-architecture.md](docs/system-architecture.md) — system architecture
- [docs/module-map.md](docs/module-map.md), [docs/workflows.md](docs/workflows.md), [docs/open-business-decisions.md](docs/open-business-decisions.md) — supporting planning docs
- [docs/planned-work.md](docs/planned-work.md) — confirmed future work, described in enough detail to record, not yet designed or built
- [docs/gl-posting-design.md](docs/gl-posting-design.md), [docs/customer-portal-design.md](docs/customer-portal-design.md) — designs for sub-ledger → GL posting + Bank step, and the Customer Helpdesk Portal, decided **and built** 2026-09-14
- [docs/backlog.md](docs/backlog.md) — short, checkable summary of everything pending, linking into the detail docs above
- [docs/ui-guidelines.md](docs/ui-guidelines.md) — screen label conventions and the Export (CSV/Excel) / Print (PDF/Word) pattern every screen follows
- [docs/php-conversion-plan.md](docs/php-conversion-plan.md) — the backend Python→PHP language conversion: reason, approach, stack, and what's converted so far vs. pending
- [DEV_SETUP.md](DEV_SETUP.md) — how to run the application locally

## Status

Active development has begun. A first working slice exists: the
**Service Operations core** (`backend/`, FastAPI + PostgreSQL; `frontend/`,
React + TypeScript), implementing the confirmed Service Operations and
Billing/AR/Purchasing/Inventory rules end-to-end (Customer → Contract →
Job Order → Service Record → Contract Hour Validation → Excess Review →
Invoice). It also includes Module Control / multi-company licensing
(Core / Administration — see [docs/system-architecture.md](docs/system-architecture.md)),
a summary dashboard, and dynamic filters on the main list views. See
[DEV_SETUP.md](DEV_SETUP.md) to run it.

**Backend language conversion to PHP/Laravel is in progress
(`backend-php/`, started 2026-09-14)** — see
[docs/php-conversion-plan.md](docs/php-conversion-plan.md) for the
reason, approach, and status. Converted and verified so far: Core /
Administration (auth, audit logging, Group Authority, Module Control,
Company Setup, Users/Staff Master), CompanyIndividual Management
(Customer/Supplier master, Contacts, Branches, Relationships, PDPA
consent/archive), Product/Service Catalog, Service Contracts + Job
Orders (the SRV-001..018 contract lifecycle, PROJECT milestone
scheduling), Service Records (SRV-003/004/007/015: hour rounding,
submission deadline, the approval queue with suggested-deduction
multipliers, the contract-deduction vs. excess-usage split, and Job
Order auto-close -- Job Orders' budget-overrun figure is now a real
query against approved Service Records rather than a stub), and
Excess Usage (SRV-004/011/013: the treatment decision, restricted to
the same Service Lead/Sales Manager/Owner reviewer set as Service
Records, always with an auditable reason), and Billing/Invoicing
(BILL-001/002/005, SRV-008: GST applied via a proper TaxCode table,
due dates from customer payment terms, serial invoice numbering).
Billing closes the two known gaps flagged by earlier modules:
contract activation now issues its annual invoice (except Ad Hoc,
which has no upfront value), and a Billable excess-usage decision now
issues its own invoice at the contract's blended rate. Also converted:
Accounts Receivable (AR-002 write-offs -- the owner always may, anyone
else only below a configured threshold, nobody but the owner while
that threshold is unset; AR-003 dispute flagging, which never holds
collections; the 5-bucket aging report), and Accounts
Payable/Purchasing (PUR-001 PO approval on the same owner/threshold
pattern; PUR-002 2-way matching against the purchase order only;
PUR-003 auto-approval on a match, an EXCEPTION spelling out exactly
what differs on a mismatch; "confirm and import to AP"; AP aging).
Also converted: GL posting + Bank (`docs/gl-posting-design.md`,
ACC-001..004) -- every accounting event now posts a balanced double-
entry voucher (Sales Invoice, Supplier Bill, Payment Voucher, Receipt
Voucher), the explicit Bank step, and UNGL (a reversal, never a
delete); and AR-001 (recording a customer receipt, symmetric to
Accounts Payable's Payment Voucher -- manual allocation to invoices,
the Bank step, UNGL). Together these close every remaining gap the
modules above had flagged: invoices post to the General Ledger on
issue, a matched Accounts Payable bill posts on auto-approval, and
both Payment Vouchers and Receipt Vouchers are now fully built
(create, allocate, bank, unbank, UNGL). Three things fixed in passing
while converting these modules: `is_customer`/`is_supplier` were
missing from CompanyIndividual's create/update entirely, a leftover
gap from that module's own conversion that silently blocked anyone
from ever being marked a supplier; a Bank Accounts API field-name
mismatch (`balance_sgd` vs. the frontend's `current_balance_sgd`) that
the Playwright verification pass caught before it shipped; and a
caching bug in both AP's and AR's payment allocation (a payment's
unallocated balance is computed from a relation Eloquent caches after
first access, which didn't refresh between two allocations against
the same payment in one request -- fixed, with a regression test on
both sides). Also converted: Accounting Period management (create/
close/reopen a period, the per-document-type per-operation lock
matrix -- Close All/Open All plus single-cell toggling, owner-only
reopen -- and Year-End Closing, which posts one balanced journal
entry zeroing every Revenue/Expense account's movement for the fiscal
year into a chosen Equity account once every period in that year is
closed) and GL Trial Balance / the per-account transaction ledger
(including the `accounting_reports`-gated trial-balance duplicate
`AccountingReportsPage` depends on, kept alongside the
`finance_accounting`-gated one on the General Ledger screen since
Python itself serves both routes). This is the first time
`Periods::requireAllows()` -- wired into every posting/bank/reversal
path since the GL posting + Bank module -- is a real, non-stub check
rather than a permanent no-op, since no endpoint had ever created an
`AccountingPeriod` row before now. The manual Journal
Voucher CRUD endpoints (raising/posting/reversing a voucher from the
General Ledger screen) landed 2026-09-15, closing that gap, together
with CSV/Excel export for the voucher list, the trial balance and the
account ledger. Also converted:
Quotations (create/list/get/send/accept/reject, single-rate GST
totals, and the confirmed 2026-09-10 accept-to-auto-Contract
conversion -- lines split by unit of measure, "Hours"/"Hour" lines
summing into one Service Support contract and every other line into
one Annual contract, a quotation mixing both never blending them into
one). Also converted: the Stock Master half of Inventory / Stock (the
`stock_master` module key -- setup masters Categories/Groups/
Brands+Models/Usages, Warehouses, the stock item with its extended
fields and picture/document attachments, and read-only per-warehouse
stock levels), and its stock movements -- Goods Receive Note, Goods
Transfer Note, Goods Return Note and Stock Adjustment, each on its own
module key, carrying both confirmed Inventory rules: INV-002 (stock
valued at weighted average cost -- only a receipt re-weights it, a
transfer carries the source warehouse's cost across rather than
revaluing, and stock can never go negative) and INV-001 (a stock
adjustment moves nothing until it is approved; a draft or rejected one
provably never touches a stock level), plus the stock operation
reports (valuation, reorder alert and the movements journal, on their
own module key so a manager can read them without any rights to move
stock). Three things found while converting it: the Warehouses
and Stock Item screens' Activate/Deactivate buttons could never have
worked against `backend/` (its PATCH body required `code`/`name` and
had no `is_active` field at all) -- fixed here with regression tests;
the stock movements ledger's timestamp column stored only whole
seconds, so same-second movements came back in an arbitrary order and
the journal could show a transfer's receipt above the receipt that
funded it -- fixed by an additive migration, with a regression test;
and `backend/app/routers/stock.py` writes **no audit trail at all**
for any of its six module keys, so the PHP version adds one, flagged
in docs/php-conversion-plan.md as a gap in `backend/` worth raising.
Also converted: Incidents (the Helpdesk front door for an
incoming call or email -- logging one with its one automatic
customer-by-email match, the "needs a callback" status + assignee,
Close, and converting to a draft Sales Quotation or a Job Order
against a valid contract, each auto-creating the real record with a
back-reference rather than just a routing flag, plus both Outlook
Add-in endpoints including the confirmed fallback-to-plain-Incident
rule). Its convert-to-software-task route landed with the Software
Tasks module (2026-09-15), so this module now has no known gaps.
Also converted: the **Company Dashboard** summary -- the app's landing
page, which until now reported "Company Dashboard summary
unavailable" on every login against `backend-php/`. It aggregates
only over modules already converted (contracts/hours, open Job
Orders, undecided Excess Usage, SRV-015 late Service Records,
invoices, AR/AP outstanding + overdue, and whether the GL trial
balance balances), reusing each owning service rather than
re-querying, so no tile reports a placeholder figure; it is
deliberately ungated by Module Control, matching the Python route,
which has no `require_module_access` either. Also converted:
**Document Control** (the document-numbering admin screen -- the
running-number counters plus each document kind's number format;
both are FULL-only and both require a reason recorded to Event Logs,
and a format change only ever affects numbers issued from that point
on) and **Document Attachments + eSignature** (the generic panel
mounted on ~12 document detail pages, every one of which previously
404'd: file upload/list/download/soft-delete, stored on disk under
the same layout the Python backend uses, 20MB per file, any file
type, plus drawn electronic signatures). **Correction to an earlier
note:** the `.docx` export / "Email X" gaps recorded against Service
Records, Invoices, Purchase Orders and Quotations are **not** closed
by the Documents conversion -- that wiring is a separate stack of
Python services (`mailer.py`, `pdf_convert.py`, `docx_forms.py`,
`document_email.py`), **which has since been converted in its own
right -- see the document generation stack below.** Also
converted: **Announcements + Ad Banner** (the platform announcements
and promo video URL shown on the Login page and, smaller, on every
page after signing in, plus the admin screen behind them) --
`/announcements/public` is unauthenticated and is called on every
page load by the app layout, which made it the most frequently 404'd
request against `backend-php/` until now. These are deliberately
global rather than company-scoped (they describe the software itself,
and the Login page shows them before any company is selected), and
the table's shape is kept identical to the Python model on purpose:
[docs/planned-work.md #8a](docs/planned-work.md) has the future,
separate Server Company Central Command application pushing
advertisements by writing straight into it, which makes that shape a
schema contract.

Also converted: the **Customer Helpdesk Portal**
(`docs/customer-portal-design.md`, PORTAL-001..006) -- the
customer-side login as a genuinely separate auth realm: portal users
live in their own `portal_users` table, never in staff `users`, and
carry a `purpose="portal"` token that every staff endpoint refuses,
while every portal endpoint refuses a staff token in return (a
distinct middleware, never a relaxed mode of the staff one; tested
explicitly in both directions). Ported exactly: the
5-wrong-passwords/15-minute lockout, the same password-complexity
policy staff use, the staff-side enable/disable/reset-password
actions with their PDPA consent gate, and PORTAL-004's "archiving a
Company/Individual disables every portal login under it,
immediately". The customer sees only their own contracts and hour
balance, job orders, service records, invoices and payments
(PORTAL-005), can drill a contract into its own service records
(PORTAL-006), and can raise an Incident that lands in the staff
Helpdesk queue as `source=portal` through the same service function
the staff screen and the Outlook Add-in use -- which closes the
Incidents module's second known gap above. Every portal query is
scoped to the token's own customer: another customer's document id
returns 404, never 403, and a filter naming another customer's
contract returns an empty list rather than their rows. This module
also adds the two foreign keys (`login_otps.portal_user_id`,
`incidents.raised_by_portal_user_id`) earlier migrations had
deferred until `portal_users` existed.

Also converted: the **document generation stack and every `.docx` /
"Email X" endpoint** (`docx_forms.py`, `pdf_convert.py`,
`document_email.py`) -- all seven Word forms (Sales Invoice, Sales
Quotation, Receipt Voucher, Purchase Order, Payment Voucher, Service
Record, Statement of Accounts), DOCX → PDF via LibreOffice headless,
and the shared "Email this document" helper. This is the stack the
Documents note above identified as the real blocker, so converting it
closes the `.docx`/"Email X" KNOWN GAPs recorded against Service
Records, Invoices, Purchase Orders, Payment Vouchers, Receipt
Vouchers and Quotations, plus the AR Customer Statement endpoints
(the statement itself is converted with them). The Python module's
design decision is kept deliberately: the PDF attached to an email is
the *same* .docx bytes the Word button serves, converted by shelling
out to `soffice`, so one template feeds both formats and they can
never drift -- which makes **`libreoffice-writer`** (not just
`libreoffice-core`) a system dependency, and adds
`phpoffice/phpword` as the only new PHP dependency, PHP having no
built-in DOCX writer. `App\Services\Mailer` gained attachment
support rather than a second mailer being written. Two bugs in
`backend-php/` were found and fixed on the way: `Mailer`'s
`SMTP_USE_TLS` setting was inert (both branches of a ternary were
identical, so TLS could never be switched off), and PHPWord's default
of writing document text unescaped meant a literal "&" -- which every
Service Record carries in "Signature & Company Stamp" -- produced a
file Word silently repairs but LibreOffice refuses, so the Word
download looked fine while the PDF behind every Email button failed.
**Closed since:** CSV/Excel export, a different Python service
(`exports.py`), is ported as `App\Services\Exports` and wired into
every list screen, so **every export route Python has now exists in
`backend-php` too**. Each controller's list filter is shared with its
exports, so an Export button always returns what is on screen. A
fidelity bug was fixed on the way: the CSV writer used PHP's
`fputcsv()`, which quotes any field containing a space and ends
records with a bare newline, where Python quotes only where a field
needs it and ends records with CRLF -- so every export was emitting
`SR,"Standard Rated",9.00` against Python's `SR,Standard Rated,9.00`.
The older `ExportService` (its "Excel" was an HTML table named
`.xls`) has since been **retired**: the two report screens built
directly in `backend-php` -- Contract Operation Report and the Sales
Dashboard drill-downs -- now use the same writer, so there is exactly
one. Those five downloads change from `.xls` to a genuine `.xlsx`
(the `/export.xls` routes are gone), which is what
[docs/ui-guidelines.md](docs/ui-guidelines.md) section 2 had specified
all along.

`backend/` (Python) is untouched and keeps running as the system of
record until each remaining module is converted, module by module,
the same way.

Also converted (2026-09-15), the last six small maintenance modules: **Tax Types**, **GL
Types**, the **Currency Rate Table**, **Setup Lists** (Nationality /
Country / State / Area Code / Currency / Industry -- deliberately
global rather than company-scoped, since a country's name does not
differ per company), **Reference Codes** (the Reference Monitor, whose
endpoint was the last remaining 404 against `backend-php`), and
**Support Monitoring**. Three schema gaps were closed along the way:
`accounts.gl_type_id`, which Python has always had and `backend-php`
was missing outright, and the two foreign keys
(`products.default_reference_code_id`,
`quotation_lines.reference_code_id`) that earlier migrations had
deferred until `reference_codes` existed. Also converted since: **Software Tasks** (which turned Support
Monitoring's "Un-Test S/T" from a placeholder 0 into a real count,
added the `incidents.converted_software_task_id` foreign key, and
landed the convert-to-software-task route that was the Incidents
module's last gap -- Incidents now has none), **Event Logs** (the
read-only face of the audit trail; exporting it is itself audited),
the **Bank Book** (bank transactions, voiding with a reason,
per-line reconcile and full reconciliation sessions, plus the
`bank_reconciliations` table), and the **Mobile Web App**
(planned-work #1: own-Job-Orders-only, time in/out replacing keyed
minutes, work photos and videos, and customer sign-off with a
watermarked chop photo). Also converted: **Management Reporting**
(the whole of `reports.py`) -- the four **Operations Reports**
(Contracts, Job Orders, Service Records, Company/Individual Product
Usage) and the **Accounting Reports** (AR aging, AP aging, trial
balance, GST return, Sales GP, and Commission with its rate setting),
each as JSON plus CSV and Excel, every export audited with its report
name, format and row count. The two aging reports deliberately call
the same services the AR and AP screens call rather than carrying
Python's duplicated bucketing loop, so the figures on those screens
can never drift apart. Commission adds a `commission_settings` table:
the formula is confirmed but the percentage is Dennis's to set, so it
starts at zero and a report run before it is set reports zero rather
than an invented rate. Also converted: **Commission Payouts**
(generate a month's draft payouts, submit/approve/reject/pay/cancel,
the two month-wide batch actions, and the clawback an AR write-off
raises) -- the last router in `backend/app/routers/` without a PHP
equivalent, so **every Python router is now converted**. A generated
batch sums to exactly what the Commission report reports for the same
month, because both call the same calculation. **A real bug was found
in `backend/` while converting it:** its commission service allocates
payout numbers with three positional arguments against a
keyword-only signature, so `generate_payouts` and `create_clawback`
raise TypeError -- Commission Payouts has never worked there, and
because AR write-off calls `create_clawback`, writing off an invoice
would fail the moment a non-zero commission rate is set (a zero rate
returns early, which is why nobody has hit it). Worth raising with
Dennis; the PHP version does it properly. Two more things found while
converting the reports: the Job
Orders export's "overdue" column tests the status against a
`"resolved"` state that does not exist in this system, so a VOID job
order reads as overdue there while the `overdue_only` filter beside it
excludes VOID correctly -- carried across verbatim so the backends
match, and flagged in
[docs/php-conversion-plan.md](docs/php-conversion-plan.md) as a
`backend/` bug worth raising; and the reports' staff-name lookup now
resolves the ids in the result set rather than filtering `users` by
company, which had blanked out the name of anyone reached through
`UserCompanyAccess` rather than their home company.

**Sales module enhancements landed directly in `backend-php/` +
`frontend/`, not as part of the conversion above** (`backend/` has no
equivalent for any of these -- new business scope Dennis asked for; see
[docs/planned-work.md #11](docs/planned-work.md#11-sales-module-enhancements-job-implementation-template-multi-product-job-orders-contract-hour-sharing-contract-filters-contract-operation-report-contractquotation-reference-sales-dashboard-raised-earlier-built-2026-09-22)):
Product now carries a reusable Job Implementation Template (an ordered
task checklist); a Job Order can select multiple Products, each
importing its template's tasks onto the Job Order (deduped by name
across products, completion gated to Sales Manager/Owner); a Contract
keeps its own independent hour-sharing customer list, enforced when a
Job Order is opened against it; the Contracts list gained a
remaining-hours-less-than filter and an expiry-date-range filter; a new
Contract Operation Report (Expiry Listing, Renewal Due Listing --
reusing SRV-014's pre-expiry window exactly) with CSV/Excel export; and
a new Sales Dashboard (Contracts Due for Renewal, Total/2-/3-month AR
Outstanding reusing the AR Aging report's own bucket logic, each
drilling into its underlying rows, plus Top 10 Sales Billing Customer
/ Bottom 10 Non-Active Customer listings for "this Financial Year") --
its own standalone Main Menu page/route (`/sales-dashboard`, gated on
the `reporting` module), between Company Dashboard and My Ops
Dashboard, per Dennis's explicit request that it not be merged into
the Company Dashboard as originally built. **One of the two pragmatic defaults is now
settled:** "this Financial Year" was taken as the calendar year because
no fiscal-year-start field existed; since 2026-09-15 it is a real
Company Setup value (`financial_year_start_month`, Webmaster runs
1 Jul - 30 Jun, labelled by the year it ends in), so those listings now
report the real financial year. Still open: the Contract-Quotation link
is a free-text `quotation_reference` field, not a real linked record --
both recorded in
[docs/open-business-decisions.md #40](docs/open-business-decisions.md#40-sales-module-enhancements-financial-year-definition-and-contractquotation-link-raised-2026-09-22).
Quotations has since been converted to `backend-php/` (below), which
unblocks replacing that free-text field with a real link, but that
reconciliation hasn't been done yet -- the conversion pass was scoped
to Quotations only and deliberately didn't touch `Contract`. The Sales
Dashboard's two Quotations-pending KPIs still report not-available
regardless: `Quotation`'s status enum has no state distinguishing
"pending internal approval" from "sent, awaiting client confirmation,"
so there is nothing for either tile to count until that's a confirmed
business rule.

**Product-based Sales Invoicing landed 2026-09-15**, also directly in
`backend-php/` + `frontend/` rather than as a conversion (`backend/`
has no equivalent -- it is the behaviour Dennis asked for on stock
costing). `invoices` had always been header-only, so a Sales Invoice
had nowhere to put a product; there is now an `invoice_lines` table,
`POST /invoices`, and a "Raise Sales Invoice" form on the Invoices
page that shows on-hand quantity per line as it is filled in. **Lines
are optional**: every existing header-only invoice keeps working
untouched, with no backfill, and the auto-issued ones (contract
activation, excess-usage decision) still issue a single amount. A
stock line deducts through the same
`InventoryService::deductStock()` a Goods Issue Note uses, so INV-002
holds by construction rather than by a second implementation agreeing
-- insufficient stock refuses the WHOLE invoice, on-hand quantity can
never go negative, and units leave at the item's weighted average
without re-weighting it. The line stores the average **as at issue**,
so a later goods receipt cannot move a past invoice's gross profit;
that also makes `cost_sgd` a real cost basis on these, so the Sales GP
report shows a measured margin instead of its no-cost stand-in. GST is
applied once to the summed net, the same single-rate treatment every
other invoice and quotation uses -- per-line tax codes would be a new
business rule nobody has asked for. **Deliberately not done:** no
COGS/inventory journal is posted, because whether stock movements post
to the General Ledger is still an open question (see
[docs/backlog.md](docs/backlog.md)) and this was not the place to
answer it quietly.

No other business area has application code yet. **Further commission
business rules beyond what is built (the report, its rate and
Payouts), further Service Record business-rule decisions (open item
9.1), and Odoo migration planning are deferred for now at Dennis's
request** — see [docs/open-business-decisions.md](docs/open-business-decisions.md)
— and will be revisited once Service Operations and related areas are
finalized. Further modules are otherwise built incrementally, resolving
open decisions as each area is reached rather than blocking all
development on them upfront — pragmatic implementation defaults taken in
the meantime are called out in code comments, not silently assumed.
