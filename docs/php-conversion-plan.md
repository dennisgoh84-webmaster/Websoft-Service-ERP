# Backend Language Conversion: Python/FastAPI → PHP/Laravel

Status: **IN PROGRESS**, started 2026-09-14. Tracks the backend
language conversion requested by Dennis, and records the reason per
CLAUDE.md's "do not change the approved architecture without
explaining the reason first" rule.

## Reason

Driven by a team/hosting constraint (PHP is what the hosting
environment and team skillset support going forward), not a technical
problem with FastAPI. This is a **backend-language** change only:

- **Backend:** Python/FastAPI → **PHP 8.4 / Laravel 11**, same
  PostgreSQL database design, same JSON API contract (same routes,
  request/response shapes, and `{"detail": "..."}` error format) so the
  existing frontend does not need to change to point at either backend.
- **Frontend:** unchanged -- stays React + TypeScript. Nothing else in
  CLAUDE.md's Approved Architecture Decisions changes: PostgreSQL,
  modular monolith, multi-company, the Odoo replacement strategy, or
  any confirmed business rule (business-requirements.md's SRV-*/BILL-*/
  AR-*/PUR-* series).

## Approach

Consistent with this project's own established pattern for large
changes -- **phased, module-by-module, with the two implementations
able to run side by side** -- rather than a single big-bang rewrite.
This mirrors the Odoo replacement strategy already in CLAUDE.md,
applied to the backend's own language this time:

- The new backend lives in `backend-php/` (Laravel), alongside the
  existing `backend/` (FastAPI) -- both can run against their own
  Postgres database during the conversion. `backend/` is untouched by
  this work and keeps running as-is until a module's PHP equivalent is
  verified.
- Each module is converted as its own vertical slice: Eloquent
  model(s) + migration(s) + controller + routes + tests, faithfully
  mirroring the Python model/service/router of the same name --
  including its code comments, since those comments record confirmed
  business decisions (dates, who decided what) that must not be lost
  in translation.
- A module is only marked done here once it is migrated, seeded, and
  smoke-tested end-to-end (auth → RBAC → the module's own CRUD →
  audit trail), not just "code written".
- Pragmatic implementation defaults taken while converting (e.g. which
  PHP JWT library, how Laravel's validation rules map to a Pydantic
  schema) are called out in code comments, not silently assumed --
  same rule CLAUDE.md already applies to the rest of this project.

## Stack

| Concern | Python (`backend/`) | PHP (`backend-php/`) |
|---|---|---|
| Framework | FastAPI | Laravel 11 |
| ORM / migrations | SQLAlchemy 2 / Alembic | Eloquent / Laravel migrations |
| Auth | `python-jose` JWT, bcrypt | `firebase/php-jwt`, PHP `password_hash` (bcrypt) |
| Validation | Pydantic schemas | Laravel `Request::validate()` |
| Database | PostgreSQL | PostgreSQL (same schema design: UUID primary keys generated in the application layer, `company_id` scoping, soft-delete/archive flags, no hard deletes of business records) |

Laravel was chosen over a micro-framework (e.g. Slim) because this
system needs the same things FastAPI+SQLAlchemy+Alembic provided --
routing, an ORM, migrations, and a straightforward place to hang
authentication -- and Laravel is the standard, widely-supported choice
for that in PHP, consistent with "do not introduce unnecessary
dependencies" (the alternative would be re-assembling the same
capabilities from smaller packages by hand).

`backend-php/` is deliberately an **API-only** Laravel app: no Blade
views, no Vite/asset pipeline, no PHP session storage. Auth is
stateless (a JWT bearer token per request), matching
`docs/system-architecture.md`'s "Concurrent multi-user access" note
for the Python backend -- every request still opens and closes its own
database connection rather than sharing server-side state.

## Conventions

Rules every converted module follows, so the codebase stays
predictable and diffable against `backend/` rather than each module
reinventing its own approach.

### Decimal / money handling

The Python backend stores money as SQLAlchemy `Numeric(12, 2)`
(exact, DB-native decimal), computes with Python's `decimal.Decimal`
quantized to 2dp with `ROUND_HALF_UP` (see
`backend/app/services/billing.py`'s `blended_rate_per_hour` /
`issue_excess_usage_invoice` -- SRV-008's blended-rate billing), and
only converts to a plain `float` at the very last step, when a Pydantic
schema builds the JSON response. PHP has no built-in arbitrary-precision
decimal type and native float arithmetic (`0.1 + 0.2`) is unsafe for
money, so the equivalent here is:

- **DB column:** `$table->decimal('amount_sgd', 12, 2)` -- matches
  `Numeric(12, 2)` exactly.
- **Eloquent cast:** `'amount_sgd' => 'decimal:2'` for any field a
  service *computes with* -- keeps the exact on-disk value as a string
  for persistence/comparison. A field that is only ever stored
  configuration, never computed on (e.g. `Company`'s approval
  thresholds), is cast straight to `'float'` instead, matching
  Python's own choice to type those specific fields as plain
  `float | None` in `CompanyOut` rather than `Decimal`.
- **Arithmetic:** always through `App\Support\Money` (wraps
  `brick/math`'s `BigDecimal`), never raw `+ - * /` on a decimal
  attribute. `Money::quantize()` rounds to 2dp `HALF_UP` -- the exact
  same precision and rounding mode as every `.quantize(Decimal("0.01"),
  rounding=ROUND_HALF_UP)` call in `billing.py`. See
  `tests/Unit/MoneyTest.php`, which pins this against the SRV-008
  worked example (a SGD 3,000 / 10-hour contract's blended rate) and a
  recurring-decimal case, so a rounding regression fails a test rather
  than silently mis-billing a customer.
- **JSON response boundary:** cast the finished computation to float
  (`Money::toFloat()`) before it goes into a response array -- mirrors
  Python's `float(Decimal(...))` conversion at the schema boundary, so
  the wire format (a bare JSON number, e.g. `300.0`, not the string
  `"300.00"`) matches between the two backends and the existing
  frontend's `number` typing/arithmetic keeps working unchanged
  against either one. `brick/math` was added as a dependency for
  exactly this reason (PHP has nothing built in for it) -- not "do not
  introduce unnecessary dependencies" territory, since it is required
  for money arithmetic to be correct at all, the same justification as
  choosing Laravel itself.

### Naming rules

To keep `backend/` and `backend-php/` diffable side by side:

- **DB tables/columns:** identical snake_case names to the SQLAlchemy
  model (e.g. `company_individuals.legacy_customer_code`).
- **Model classes:** the PascalCase form of the Python class name
  (`CompanyIndividual`, `GroupModuleAuthority`), except where a Python
  name collides with something PHP-specific -- called out in the
  model's own docstring when it happens (e.g. `Module` → `ModuleCatalog`,
  since a PHP-legal `Module` class name reads as a framework concept,
  not this catalog).
- **Routes:** identical kebab-case paths to the FastAPI router's
  `prefix` and route strings (`/api/company-individuals/{id}/pdpa-consent`
  matches exactly).
- **JSON field names, request bodies, and enum string values:**
  identical to the Pydantic schema/SQLAlchemy enum (snake_case fields,
  lowercase enum values like `"view"`/`"full"`, `"individual"`/`"company"`)
  -- this is the actual API contract the existing frontend already
  codes against.
- **Audit `entity_type`/`action` strings:** identical to the Python
  call site (`"customer"`/`"pdpa_consent_recorded"`, etc.) -- Event
  Logs and any future reporting over the audit trail depend on these
  strings being stable regardless of which backend wrote the entry.
- **Controller methods:** camelCase, named after the Python route
  function (`deactivate_customer` → `deactivate()`); RESTful CRUD uses
  Laravel's own `index`/`store`/`show`/`update`/`destroy` where the
  Python function is a plain list/create/get/update/delete.

### After converting each module, check

1. `php artisan migrate` runs clean against a fresh dev DB (and
   `php artisan migrate:fresh --seed` still works end to end).
2. Every route the Python router exposes for this module either has a
   PHP equivalent registered in `routes/api/<module>.php`, or is
   listed here under "Not yet converted" with a reason -- never
   silently dropped.
3. Same RBAC on every route: the same `module_key` and minimum
   `AccessLevel` as the Python route's `require_module_access(...)`.
4. Same audit trail: same `entity_type`/`action` strings, same
   old-value/new-value diff shape, on every create/update/state-change
   route.
5. Any money/decimal field follows the Decimal/money handling
   convention above -- and if the module does real arithmetic on one
   (a rate, a total, a proration), a unit test pins the result against
   a worked example from `business-requirements.md`, the way
   `tests/Unit/MoneyTest.php` does for SRV-008.
6. Feature tests cover: the happy-path CRUD, a 403 for a user with no
   Group, a 403 for a VIEW-only Group attempting a write, a 403 when
   Group Authority is FULL but Module Control has the module disabled
   (fail-closed), and a 404 for another company's record (multi-company
   scoping) -- see `tests/Feature/CompanyIndividualTest.php` as the
   template every module's test file follows.
7. `./vendor/bin/pint` and `php artisan test` are both clean.
8. This doc's "Converted so far" / "Not yet converted" lists and
   `docs/backlog.md`'s entry are updated in the same commit as the code.
9. Where the module has an existing frontend page, a manual or
   Playwright smoke pass against `backend-php/` (proxy the frontend's
   `/api` dev-server target at `backend-php/`'s port) confirms no
   console/network errors -- the frontend was built against the Python
   contract, so this is the real end-to-end check that the PHP
   contract actually matches it, not just that PHP's own tests pass.

## Converted so far

Foundational layer (Core / Administration), faithfully mirroring the
Python modules of the same name -- field names, RBAC/audit semantics,
and code comments carried across:

- **Core infra**: `app/core/config.py` → `config/websoft.php`;
  `app/core/database.py` → Laravel's own Eloquent connection;
  `app/core/deps.py` → `App\Http\Middleware\Authenticate`.
- **Auth** (`app/services/auth.py` + `app/routers/auth.py` →
  `App\Services\Jwt`, `App\Services\PasswordPolicy`,
  `App\Http\Controllers\Api\AuthController`): password hashing +
  complexity policy, the full login sequence (must_change_password →
  otp_required → ok), forgot-password/reset-password-otp, and the
  `purpose`-claim JWT design (an intermediate token can never be
  replayed as a real access token).
- **Audit logging** (`app/services/audit.py` → `App\Services\Audit`,
  `App\Http\Middleware\CaptureAuditRequestContext`): same
  entity/action/actor/old-value/new-value/IP/User-Agent/device-id
  shape, same request-scoped-context-without-threading-a-Request
  design.
- **Group Authority + Module Control**
  (`app/services/authority.py`, `app/models/groups.py`,
  `app/models/licensing.py` → `App\Services\Authority`,
  `App\Models\Group`/`GroupModuleAuthority`/`ModuleCatalog`/
  `CompanyModule`, the `module:<key>,<level>` route middleware): same
  None/View/Edit/Full levels, same owner-role bypass of both Group
  Authority and Module Control, same "missing row = not enabled" fail
  closed default.
- **Company Setup / multi-company**
  (`app/routers/companies.py` → `App\Http\Controllers\Api\CompanyController`):
  list/create/update, switch active company, public branding endpoint.
- **Groups admin API** (`app/routers/groups.py` →
  `App\Http\Controllers\Api\GroupController`): CRUD + set per-module
  authorities.
- **Module Control read API** (`app/routers/modules.py` →
  `App\Http\Controllers\Api\ModuleController`): `my-access` (nav
  visibility) and the read-only catalog.
- **CompanyIndividual Management** (`app/models/company_individuals.py`,
  `app/routers/company_individuals.py`,
  `app/routers/company_individual_groups.py` →
  `App\Models\CompanyIndividual`/`Contact`/`Branch`/
  `CompanyIndividualGroup`/`CompanyIndividualRelationship`,
  `App\Http\Controllers\Api\CompanyIndividualController`/
  `CompanyIndividualGroupController`): full customer/supplier master
  CRUD, deactivate/reactivate, archive/unarchive (soft-archive-in-place,
  never deleted), PDPA consent + signed-agreement-document upload
  (server-stamped timestamp, audit-logged without the file content),
  audit-log read-back, nested Contacts/Branches CRUD, company/
  individual/contact Relationships, and the CompanyIndividual Groups
  tag CRUD used by the module's own group filter.

Verified end-to-end (migrate → seed → boot → curl, plus the existing
React frontend proxied at `backend-php/` instead of `backend/` --
screenshot in the PR/commit history): login issuing a `purpose=access`
JWT, `/auth/me`, RBAC 403 for a user with no Group, customer
create/list, nested contact create, PDPA consent, archive, and the
resulting audit trail entries (including IP/User-Agent capture) -- see
`database/seeders/DatabaseSeeder.php` for the demo dataset used.

**Not yet converted from `app/routers/company_individuals.py`:**
CSV/Excel export endpoints and the Customer Helpdesk Portal access
sub-resource (depends on the Portal module below).

- **Users / Staff Master** (`app/routers/users.py` →
  `App\Http\Controllers\Api\UserController`): staff directory
  (ungated read, per the Python route's own comment -- used by
  pickers across other modules), create/update/deactivate/reactivate,
  admin password reset (forces a change on next sign-in, same as a
  new account), photo upload, per-module audit log, and
  company-access & Groups management (a Group only ever applies
  within its own company; revoking the company a staff member is
  currently working in is blocked, matching the Python version
  exactly). **Not yet converted:** CSV/Excel export.
- **Product/Service Catalog** (`app/models/catalog.py`,
  `app/routers/catalog.py` → `App\Models\Product`,
  `App\Http\Controllers\Api\ProductController`): create/update/list,
  gated by the `sales` module (not `company_individual_management`,
  matching the Python router's own `MODULE` constant). Backs Sales
  Quotation lines once Quotations is converted. `default_reference_code_id`
  has no FK constraint yet (Reference Codes isn't converted). Found
  while converting, and deliberately **not** silently fixed (a straight
  conversion preserves behaviour, not just intent): the Python
  `ProductUpdate` schema accepts `is_stock`, but `update_product`'s
  field-update loop never actually applies it, so it is only settable
  at creation in both backends now -- pinned by
  `tests/Feature/ProductTest.php`'s
  `test_update_ignores_is_stock_same_as_python`, and worth flagging to
  Dennis as a possible oversight rather than a confirmed rule.
  **Not yet converted:** CSV/Excel export.
- **Service Contracts** (`app/models/contracts.py`,
  `app/services/contracts.py`, `app/routers/contracts.py` →
  `App\Models\Contract`/`ContractProduct`/`ExpiredHoursRecord`,
  `App\Services\ContractService`, `App\Http\Controllers\Api\ContractController`):
  the full SRV-001, 002, 005, 010, 012, 014, 016, 018 lifecycle --
  create (10-hour hard minimum enforced for Service Support, ignored
  for Annual, Ad Hoc requires a positive reference rate), activate
  (Draft → Active only), renew (SRV-016's 2-week seamless-backdating
  window; beyond it, SRV-018 requires an explicit `force_start_date`,
  never an automatic decision), expire (SRV-005's forfeit-to-
  `ExpiredHoursRecord`, never a credit/rollover), `needsPreExpiryCheck`
  (SRV-014) and `deductMinutes` (SRV-004, never negative) ported ahead
  of their only caller (Service Records) so the enforcement point
  exists when that module lands. Product coverage + per-seat license
  tracking, and the sales-staff owner field. 21 dedicated business-
  logic tests (`tests/Feature/ContractServiceTest.php`) pin the exact
  arithmetic (12-month term via `addMonthsNoOverflow` matching Python's
  `dateutil.relativedelta` month-clamping, the blended-rate example
  from the screenshot below, renewal date-math) against worked
  examples, not just smoke-tested.
  **Also added:** `App\Services\Numbering` (mirrors
  `app/services/numbering.py`'s document-numbering-with-locked-counter
  design -- `CON-2026-0001` etc.), needed by Contracts and Job Orders
  alike.
  **KNOWN GAP (not silently papered over):** the Python router's
  `POST /{contract}/activate` also issues the contract's annual invoice
  in the same transaction (BILL-001/002/005, via
  `app/services/billing.py` → GL posting → tax). Billing isn't
  converted yet, so activation here only changes status -- see
  `ContractController`'s class docblock. **Do not treat a contract
  activated through `backend-php/` as billed** until Billing is
  converted; this raises Billing's priority in the list below.
  **Not yet converted:** CSV/Excel export, `GET /{contract}/excess-usage`
  (needs `ExcessUsageRecord`, which needs Service Records first).
- **Job Orders** (`app/models/job_orders.py`,
  `app/routers/job_orders.py` → `App\Models\JobOrder`/`ProjectMilestone`,
  `App\Http\Controllers\Api\JobOrderController`): create (auto-creates
  the 5-step PROJECT milestone template), list/get, assign, manual due
  date (SRV-009 -- no SLA target derived from priority, confirmed
  deferred), urgent flag, void (reason required, audit-logged),
  owner-only reopen, budget-overrun approval (7.1, Sales Manager/Owner
  only) and PROJECT milestone CRUD + re-init template, with milestone
  completion gated to Sales Manager/Owner (7.3).
  `computeBudgetOverrun()` now does the real query (sums approved
  Service Record `rounded_minutes` for the Job Order, blended against
  the contract's rate) now that Service Records exists -- see
  `JobOrderTest::test_budget_overrun_sums_approved_service_record_minutes`.
  **Not yet converted:** CSV/Excel export.
- **Service Records** (`app/models/service_records.py`,
  `app/services/service_records.py`, `app/routers/service_records.py`
  → `App\Models\ServiceRecord`/`ExcessUsageRecord`,
  `App\Services\ServiceRecordService`,
  `App\Http\Controllers\Api\ServiceRecordController`): SRV-007 (round
  up to the nearest 15 minutes), SRV-015 (3-day submission deadline,
  `isLate()`), submission against an open Job Order, the Service
  Record Approval queue (`GET /pending-approval`, joining job order +
  employee names, computing `suggested_deducted_minutes` from the
  urgent/after-hours multiplier and `contract_remaining_minutes`), and
  approval itself (SRV-003/004): role-gated to Service Lead/Sales
  Manager/Owner, ANNUAL/AD_HOC contracts marked
  `not_hour_metered` (no deduction), otherwise a straightforward
  `ContractService::deductMinutes()` when the contract balance covers
  it, or a split into a real deduction (whatever remains, possibly 0)
  plus an `ExcessUsageRecord` for the shortfall when it doesn't --
  balance never goes negative. Approval also drives Job Order
  auto-close (closes when the *latest* record for the Job Order is
  Approved + Completed; an earlier Uncompleted visit doesn't block it
  once the final one is done). 17 dedicated business-logic tests
  (`tests/Feature/ServiceRecordServiceTest.php`) pin the rounding
  table, the multiplier logic, the exact excess-minutes split
  arithmetic, and every auto-close case; 7 API-level tests
  (`tests/Feature/ServiceRecordTest.php`) cover RBAC (Group Authority
  *and* the named-role approver check are independently enforced),
  multi-company isolation, and the approval-queue shape.
  **Not yet converted:** CSV/Excel export, the `.docx`/email endpoints
  (need the Documents module's mailer wiring). The Excess Usage
  *treatment-decision* endpoints (approve/write-off/bill an
  `ExcessUsageRecord`) are still pending -- see below; this module only
  creates those rows.

- **Excess Usage** (`app/services/excess_usage.py`,
  `app/routers/excess_usage.py` → `App\Services\ExcessUsageService`,
  `App\Http\Controllers\Api\ExcessUsageController`): SRV-004/011
  (decide treatment, restricted to Service Lead/Sales Manager/Owner --
  the same reviewer set as Service Records' approvers, matching the
  Python source's shared `EXCESS_REVIEWER_ROLES` import), a reason
  required on every decision (auditable), one-time decision (can't
  redecide), and the 5 SRV-013 treatment categories. Uses the same
  `service_contracts` module gate as the Python router, not a
  dedicated module key. 5 business-logic tests
  (`tests/Feature/ExcessUsageServiceTest.php`) + 6 API-level tests
  (`tests/Feature/ExcessUsageTest.php`), built against real
  contract-deduction/excess-split records produced by
  `ServiceRecordService` rather than a bare factory, so the whole
  Service Records → Excess Usage pipeline is exercised. A BILLABLE
  decision issues a real invoice via `App\Services\BillingService`
  (see below) -- `invoiced` is set true and the invoice amount is
  asserted (`test_billable_decision_issues_an_invoice_at_the_blended_rate`).
  **Not yet converted:** CSV/Excel export.
- **Billing / Invoicing** (`app/services/billing.py`,
  `app/models/billing.py`, `app/models/tax.py`,
  `app/routers/billing.py` → `App\Models\Invoice`/`TaxCode`,
  `App\Services\BillingService`/`Tax`,
  `App\Http\Controllers\Api\InvoiceController`): BILL-001 (full
  12-month contract value billed at activation), BILL-002 (no
  approval required -- issued directly in "outstanding" status),
  BILL-005 (revenue recognised on invoice, not on payment), and
  SRV-008 (excess usage billed at the contract's own blended rate).
  GST applied per `App\Models\TaxCode` (confirmed 2026-09-10:
  GST-registered, standard-rated, 9%) -- zero and never invented for
  an unconfigured company, exactly like the Python source. Due date
  from the customer's own `payment_terms_days`, none invented when
  unset. Serially numbered via the already-ported `App\Services\Numbering`.
  This closes both known gaps flagged by earlier modules: activating a
  contract now issues its BILL-001 annual invoice (skipped for AD_HOC,
  which has no upfront value), and a BILLABLE excess usage decision
  now issues its SRV-008 invoice. 5 business-logic tests
  (`tests/Feature/BillingServiceTest.php` -- the exact GST math, the
  blended-rate excess-usage amount, the due-date/no-due-date cases)
  + 5 API-level tests (`tests/Feature/InvoiceTest.php`, including both
  known-gap-closing paths). **Money-handling note:** found and fixed a
  precision bug during this conversion -- `App\Support\Money`'s
  `multipliedBy()` takes a plain scalar, and multiplying two computed
  Money values by round-tripping one through `toFloat()` first
  silently rounds it to 2dp *before* the multiply (e.g. 40/60 hours
  become "0.67" instead of staying at full precision), which would
  have under/over-billed excess usage by a cent in some cases. Added
  `Money::multipliedByMoney()` so two computed values multiply at full
  internal precision, quantized only once at the end -- matching how
  `backend/`'s `Decimal` arithmetic never rounds an intermediate
  operand. Covered by
  `test_excess_usage_invoice_is_billed_at_the_blended_rate`.
  **KNOWN GAP (not silently papered over):** the Python version posts
  every invoice to the General Ledger in the same transaction
  (ACC-001/003, `app/services/posting.py` -- Dr AR / Cr revenue / Cr
  GST output). GL posting is a large module of its own
  (`docs/gl-posting-design.md`) and isn't converted yet, so an invoice
  issued here is NOT posted to the GL -- `InvoiceController` always
  reports `gl_status: "not_posted"`, matching `InvoiceOut`'s own
  Python default so this is never misreported as posted.
  **KNOWN GAP:** GP costing (`cost_sgd`) traces a CONTRACT_ANNUAL
  invoice's cost back to the Sales Quotation that converted into the
  contract (open decision #32). Quotations isn't converted yet, so
  `cost_sgd` is always null here -- the same as the Python source's
  own "not created from a quotation" case, never an invented cost.
  **Not yet converted:** CSV/Excel export, the `.docx` export and
  "Email Invoice" endpoints (need the Documents module's mailer
  wiring, same gap as Service Records).

Verified end-to-end for all five modules against the real React
frontend (screenshots in the PR/commit history): contract creation
blocked below the 10-hour minimum with the SRV-002 message, activation
now issuing a real INV-numbered invoice with GST correctly applied
($3,000.00 net + $270.00 GST = $3,270.00 total, due date from the
customer's payment terms), renewal (both the seamless-backdated case
and the SRV-018 force-start-date case), the contract detail page
showing the exact $300.00/hr blended rate, a PROJECT-type Job Order
with all 5 milestones auto-created in order, submitting a Service
Record with 15-minute rounding visible, the Service Record Approval
queue showing the suggested deduction, the Excess Usage Review page
showing a real 2.00-hr excess record end to end, and the Invoices
page rendering the activation invoice with the correct net/GST/total
breakdown.
- **Accounts Receivable** (`app/services/accounts_receivable.py`,
  `app/routers/accounts_receivable.py` (partial) →
  `App\Services\AccountsReceivableService`,
  `App\Http\Controllers\Api\AccountsReceivableController`): AR-002
  (write off an invoice's outstanding balance -- owner always allowed,
  anyone else only below the company's configured
  `write_off_approval_threshold_sgd`, and *nobody* but the owner while
  that threshold is unset, per the safe reading of an undecided rule)
  and AR-003 (flag/clear a dispute -- collections continue regardless,
  nothing is put on hold) and its aging report (5 buckets: current,
  1-30, 31-60, 61-90, over 90 days past due, grouped by customer). An
  invoice with no due date is treated as current, never invented-late.
  10 business-logic tests (`tests/Feature/AccountsReceivableServiceTest.php`
  -- every threshold/role combination, the exact bucket boundaries,
  written-off invoices excluded from aging) + 6 API-level tests
  (`tests/Feature/AccountsReceivableTest.php`).
  **KNOWN GAP (not silently papered over):** AR-001 (recording a
  customer Payment and manually allocating it against invoices) is
  NOT converted. `Payment.bank_account_id` is a required foreign key
  into `bank_accounts` (the GL posting + Bank module's own table,
  `docs/gl-posting-design.md`), which doesn't exist in `backend-php/`
  yet -- so a receipt can't be recorded at all through this backend
  today, and no invoice's `amount_paid_sgd`/status here reflects a
  real payment. This is why AR needed real scoping, not just a
  smaller endpoint list: about half of the Python router (payments,
  allocation, statements, un-GL/un-bank) is genuinely blocked on a
  module that hasn't been converted yet, not merely deferred for
  time. Also not converted: the commission clawback the Python
  write-off endpoint triggers (Commission Management is deferred, per
  CLAUDE.md) and CSV/Excel/.docx export.
- **Accounts Payable / Purchasing** (`app/models/payables.py`,
  `app/services/payables.py`, `app/routers/payables.py` (partial) →
  `App\Models\PurchaseOrder`/`SupplierInvoice`,
  `App\Services\PayablesService`,
  `App\Http\Controllers\Api\PurchaseOrderController`/
  `SupplierInvoiceController`/`AccountsPayableController`): PUR-001
  (PO approval, value-based -- same owner/configurable-threshold
  pattern as AR-002/write-offs, `po_approval_threshold_sgd`), PUR-002
  (2-way match: a bill is compared to its PO only, no goods receipt),
  PUR-003 (a match auto-approves the bill for payment; a mismatch
  becomes an EXCEPTION spelling out exactly what differs -- supplier,
  amount, or an unapproved PO -- and stops there, resolution being
  open item 4.5), "confirm and import to AP" (turns an approved PO
  straight into its matching, pre-matched bill; guards against
  importing the same PO twice), and the AP aging report (mirrors AR's
  bucket logic exactly, reused from
  `AccountsReceivableService::agingBucketFor()` rather than
  reimplemented). A supplier is a `CompanyIndividual` flagged
  `is_supplier` (2026-09-12: folded into the customer master, not a
  separate table), same as Python.
  **Bug fix found and fixed in the process:** `is_customer`/
  `is_supplier` were missing from `CompanyIndividualController`'s
  create/update validation entirely -- a leftover gap from the
  CompanyIndividual Management conversion (Python's schema always
  accepted them) that meant no record could ever be marked a supplier
  through `backend-php/`, silently blocking this whole module. Fixed
  by adding both fields (`is_customer` defaults true, `is_supplier`
  false, matching Python's schema defaults), with a new test.
  A matched bill's GL posting and Payment Vouchers (SupplierPayment +
  allocations) were deferred here pending GL posting -- see the next
  entry, which closes both.
  17 business-logic tests (`tests/Feature/PayablesServiceTest.php` --
  every threshold/role combination, every 2-way-match outcome, the
  double-import guard, aging) + 8 API-level tests
  (`tests/Feature/PayablesTest.php`).
  **Not yet converted:** CSV/Excel/.docx export, "Email Purchase
  Order".
- **GL posting + Bank step** (`app/services/posting.py`,
  `app/services/ledger.py`, `app/models/accounting.py`,
  `app/models/treasury.py`, `app/models/periods.py` →
  `App\Models\Account`/`JournalEntry`/`JournalLine`/`BankAccount`/
  `BankTransaction`/`AccountingPeriod`/`PeriodLock`,
  `App\Services\Ledger`/`Posting`/`Periods`,
  `App\Http\Controllers\Api\AccountController`/
  `BankAccountController`/`SupplierPaymentController`): ACC-001..004
  -- every accounting event posts a balanced double-entry voucher
  (Sales Invoice on issue: Dr AR / Cr revenue / Cr GST output;
  Supplier Bill on auto-approval: Dr expense / Dr GST input / Cr AP;
  Payment Voucher on save: Dr AP / Cr bank), the explicit Bank step
  (ACC-002, a separate ledger from the GL, reversible with Unbank),
  and UNGL (ACC-004: a reversal voucher, never a delete; re-posting
  after UNGL is allowed since the reversal doesn't carry the
  document's own `source_type`/`source_id`). `App\Services\Ledger`
  enforces double-entry exactly (debits = credits, non-zero, a
  posted voucher immutable, corrections only via `reverseEntry()`'s
  mirror-image). This closes the known gaps flagged by every prior
  billing-adjacent module: `BillingService` now posts every invoice
  in the same step it's issued; `PayablesService::matchBillToPo()`
  now posts a matched bill; Accounts Payable's Payment Vouchers
  (`SupplierPaymentController`) are now fully built -- create,
  allocate, bank, unbank, UNGL.
  **Bug fix found and fixed in the process:**
  `BankAccountController::present()` returned the computed balance
  as `balance_sgd`; the frontend's `BankAccount` type expects
  `current_balance_sgd` (confirmed against both `frontend/src/lib/api.ts`
  and the Python schema) -- the Bank Accounts page rendered "$ NaN"
  until this was caught in the Playwright verification pass and fixed.
  **Scoped out (tracked here, not silently assumed done):** GLType (a
  purely optional reporting sub-classification nothing in `posting.py`
  reads), `BankReconciliation`, `CurrencyRate`, `FiscalYearClosure`,
  and Period management itself (creating/closing a period, toggling
  individual locks, Year-End Closing -- `app/services/periods.py` is
  368 lines, its router another 252). `App\Services\Periods::requireAllows()`
  is ported and wired into every posting/bank/reversal path exactly
  like Python's `require_period_allows`, but since no endpoint here
  ever creates an `AccountingPeriod` row, it is correctly always a
  no-op today -- the same "opt-in protection: a date with no period
  defined is unrestricted" default Python itself falls back to.
  AR-001 (recording a customer receipt) remains its own
  module-sized addition even though `bank_accounts` now exists to
  support it -- `App\Models\Payment` doesn't exist yet; see
  `AccountsReceivableController`'s docblock. GL Trial Balance / the
  per-account transaction ledger (`ledger.account_balances`/
  `account_transactions`) also aren't exposed by a controller yet.
  A new `App\Database\Factories\CompanyFactory::configure()` hook
  seeds a minimal 8-account chart for every factory-made `Company` in
  tests, since issuing an invoice or auto-approving a bill now posts
  to the GL as an integral step, not an optional one -- every test
  company needs somewhere to post to, the same as a real company
  would set one up before using Billing.
  28 tests (`tests/Feature/LedgerServiceTest.php` -- the core double-
  entry rules; `tests/Feature/PostingServiceTest.php` -- the exact
  Dr/Cr lines for each document type, the double-post guard, bank/
  unbank, UNGL-then-repost, a missing Chart of Accounts entry
  surfacing as a clear `PostingError` rather than a crash;
  `tests/Feature/AccountTest.php`, `tests/Feature/BankAccountTest.php`,
  `tests/Feature/SupplierPaymentTest.php`).
  **Not yet converted:** CSV/Excel export.

Verified end-to-end for all eight modules against the real React
frontend (screenshots in the PR/commit history), including the
Invoices page's Aging widget -- which previously 404'd (a confirmed
gap noted when Billing shipped) -- now rendering all 5 buckets with
the real activation invoice correctly showing as "Current / not yet
due" ($3,270.00); the Purchase Orders / Accounts Payable pages
showing a PO raised, approved, imported to AP as a matched, auto-
approved (and now GL-posted) bill; and the full GL posting + Bank
loop -- Chart of Accounts (36 seeded rows), a Payment Voucher raised
against that bill, banked, and the Bank Master File's balance
correctly showing -$2,180.00 after the fix above. The only 404s seen
were for not-yet-converted modules (Announcements, Dashboard,
Documents) -- none from Contracts, Job Orders, Service Records,
Excess Usage, Billing, Accounts Receivable, Accounts Payable, or GL
posting's own endpoints.

## Not yet converted (pending, in rough priority order)

Everything below still only exists in `backend/` (Python). Each is a
phase of its own, following the same pattern as CompanyIndividual
Management above -- model(s) + migration(s) + controller + routes +
smoke test:

1. **AR-001** (recording a customer receipt and allocating it against
   invoices) -- `App\Models\Payment` doesn't exist yet; its own
   module-sized addition, mirroring the now-built Accounts Payable
   Payment Voucher on the customer side. Bumped up in priority: GL
   posting + Bank now exists to support it, so nothing structural
   blocks it anymore.
2. **Accounting Period management** (create/close/reopen a period,
   the per-doc-type per-operation lock matrix, Year-End Closing) and
   **GL Trial Balance / the per-account transaction ledger** -- both
   scoped out of the GL posting + Bank conversion above; see that
   entry's docblock references.
3. Everything else in `backend/app/routers/` not listed above
   (Quotations, Incidents, Inventory/Stock, Reporting/dashboards,
   Event Logs, Document Control, Announcements, Software Tasks, Ops
   Dashboard, Customer Helpdesk Portal, Mobile Web App, Commissions
   [deferred, per CLAUDE.md]) -- lower priority than the Service
   Operations core above, since that core is what CLAUDE.md's Status
   section calls out as the one working slice today.

## Running the PHP backend locally

See [DEV_SETUP.md](../DEV_SETUP.md) (to be extended once more of this
conversion lands) for the Python backend; for `backend-php/` today:

```bash
cd backend-php
composer install
cp .env.example .env   # already has working local-dev defaults
php artisan migrate
php artisan db:seed    # demo company + Dennis (owner, demo1234) + 1 sample customer
php artisan serve --port=8001
```

`backend/` (Python) and `backend-php/` intentionally use **separate**
local databases (`websoft_service_erp` vs `websoft_php_erp` by
default) -- they are not meant to share data during the conversion.
