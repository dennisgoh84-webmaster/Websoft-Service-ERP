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
- Commission Management — **deferred for now** (see [docs/open-business-decisions.md](docs/open-business-decisions.md))
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
`AccountingPeriod` row before now. **Known gap:** the manual Journal
Voucher CRUD endpoints (raising/posting/reversing a voucher from the
General Ledger screen) were outside this pass's scope and remain
unconverted -- see docs/php-conversion-plan.md. Also converted:
Quotations (create/list/get/send/accept/reject, single-rate GST
totals, and the confirmed 2026-09-10 accept-to-auto-Contract
conversion -- lines split by unit of measure, "Hours"/"Hour" lines
summing into one Service Support contract and every other line into
one Annual contract, a quotation mixing both never blending them into
one). `backend/` (Python) is untouched and keeps running as the
system of record until each remaining module is converted, module by
module, the same way.

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
a new Sales Dashboard section below the Company Dashboard (Contracts
Due for Renewal, Total/2-/3-month AR Outstanding reusing the AR Aging
report's own bucket logic, each drilling into its underlying rows,
plus Top 10 Sales Billing Customer / Bottom 10 Non-Active Customer
listings for "this Financial Year"). **Two pragmatic defaults, flagged
for Dennis to confirm rather than silently assumed:** "this Financial
Year" is taken as the calendar year (no fiscal-year-start field exists
anywhere in the system yet), and the Contract-Quotation link is a
free-text `quotation_reference` field, not a real linked record (the
Quotations module exists in `backend/` Python but has not been
converted to `backend-php/`, and this work is scoped to `backend-php/`
only) -- both recorded in
[docs/open-business-decisions.md #40](docs/open-business-decisions.md#40-sales-module-enhancements-financial-year-definition-and-contractquotation-link-raised-2026-09-22).
The Sales Dashboard's two Quotations-pending KPIs report not-available
for the same reason, rather than fabricated.

No other business area has application code yet. **Commission
Management, further Service Record business-rule decisions (open item
9.1), and Odoo migration planning are deferred for now at Dennis's
request** — see [docs/open-business-decisions.md](docs/open-business-decisions.md)
— and will be revisited once Service Operations and related areas are
finalized. Further modules are otherwise built incrementally, resolving
open decisions as each area is reached rather than blocking all
development on them upfront — pragmatic implementation defaults taken in
the meantime are called out in code comments, not silently assumed.
