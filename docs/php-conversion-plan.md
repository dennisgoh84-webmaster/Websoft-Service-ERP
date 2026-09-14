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
  **Not yet converted:** the commission clawback the Python write-off
  endpoint triggers (Commission Management is deferred, per
  CLAUDE.md), CSV/Excel/.docx export.
- **AR-001** (`app/models/payments.py`, the payment half of
  `app/routers/accounts_receivable.py` →
  `App\Models\Payment`/`PaymentAllocation`,
  `App\Http\Controllers\Api\PaymentController`): recording a Receipt
  Voucher (allocation to invoices is a manual, optional-at-creation
  decision -- a receipt can sit unallocated on the customer's account
  until Finance decides what it settles), posting it to the GL on save
  (Dr bank / Cr AR, via the now-built `App\Services\Posting::postReceipt()`),
  the explicit Bank step, and UNGL. Symmetric to the Accounts Payable
  Payment Voucher work, unblocked the same way once GL posting + Bank
  existed.
  **Bug fix found and fixed in the process, in both AR and AP:**
  `allocatePayment()`/`allocateSupplierPayment()` compute a payment's
  unallocated balance by summing its `allocations` relation, which
  Eloquent caches after first access -- allocating two invoices/bills
  against the *same* payment instance in one request (e.g. the
  `allocations` array on `POST /payments`) meant the second line's
  balance check saw a stale, pre-first-allocation collection and could
  have let a payment be over-allocated. Fixed by refreshing the
  relation (`$payment->load('allocations')`) after each write in both
  services; each now has a dedicated regression test covering two
  allocations in one request.
  9 business-logic tests added to `AccountsReceivableServiceTest.php`
  + 9 API-level tests (`tests/Feature/PaymentTest.php`).
  **Not yet converted:** the Customer Statement endpoints, CSV/Excel/
  .docx export, "Email Receipt".
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
- **Accounting Period management + GL Trial Balance** (`app/models/periods.py`,
  `app/services/periods.py` (368 lines), `app/routers/periods.py`
  (252 lines), and the trial-balance/account-transactions halves of
  `app/services/ledger.py`/`app/routers/ledger.py` →
  `App\Models\FiscalYearClosure` (new -- `AccountingPeriod`/
  `PeriodLock` already existed, schema-only, from the GL posting +
  Bank module), `App\Services\Periods` (extended: `seedLocksForPeriod`,
  `toggleLock`, `setAllLocks`/`closePeriod`/`reopenPeriod`, the derived
  open/closed/partial status, `closeFiscalYear`), `App\Services\Ledger`
  (extended: `accountTransactions`, `accountBalances`),
  `App\Http\Controllers\Api\PeriodController`/`LedgerController`):
  the full per-document-type x per-operation lock matrix
  (`VALID_DOC_OPERATIONS` -- SALES_INVOICE/RECEIPT_VOUCHER/
  PAYMENT_VOUCHER/PURCHASE_BILL/JOURNAL_VOUCHER x UPDATE/REVERSE/BANK/
  UNBANK/GL/UNGL, only the valid combinations seeded per period),
  "Close All"/"Open All" plus single-cell toggling, the derived
  OPEN/CLOSED/(frontend-only) Partial status, owner-only reopen, and
  Year-End Closing (every period in the fiscal year must already be
  closed; the Retained Earnings account must be an Equity account;
  posts one balanced closing journal entry zeroing every Revenue/
  Expense account's movement for the year into it, `bypassPeriodCheck`
  honoured so the entry can post despite its own period being closed;
  records a `FiscalYearClosure`, never deletable, undone only by
  reversing the closing voucher like any other posted voucher). This
  is the FIRST time `App\Services\Periods::requireAllows()` -- wired
  into every posting/bank/reversal path since the GL posting + Bank
  module -- becomes a real, non-stub check: no endpoint had ever
  created an `AccountingPeriod` row before this. The existing
  Billing/AR/AP/GL posting/Bank test suites were re-run after wiring
  this in and needed no changes (none of their fixtures create a
  period, so `requireAllows()` stays a correct no-op for them, per its
  own opt-in-protection default). Also ported: `account_transactions()`
  (the per-account GL drill-down ledger with running balance,
  including the brought-forward opening balance when `date_from` is
  set) and `account_balances()` (the trial balance computation) on
  `App\Services\Ledger`, exposed via `GET /ledger/trial-balance` and
  `GET /ledger/transactions/{account}`.
  **FINDING (per this task's own instruction to check):**
  `backend/app/routers/reports.py`'s `_trial_balance_report`/
  `trial_balance_report` (~line 603-660) IS a genuine duplicate of
  `ledger.py`'s trial balance -- both call the identical
  `ledger_svc.account_balances()` and build the identical
  `TrialBalance` shape -- but it is not dead code: it lives at a
  different route (`/reports/accounting/trial-balance` vs.
  `/ledger/trial-balance`) gated by a *different* Module Control key
  (`accounting_reports`, Python's `ACCOUNTING_MODULE` constant, vs.
  `finance_accounting` for the General Ledger screen), and
  `frontend/src/pages/AccountingReportsPage.tsx` depends on its own
  route independently of `GeneralLedgerPage.tsx`. Ported as
  `App\Http\Controllers\Api\ReportController::trialBalance()`,
  mirroring Python's own structure (each router keeps its own small
  private presentation helper around the same service call, rather
  than one being rewritten to call the other) -- see that class's
  docblock. The rest of `reports.py` (AR/AP aging duplicates -- already
  served under their own modules' routes, see
  `AccountsReceivableController`/`AccountsPayableController`'s
  `agingReport()` -- GST Return, Sales GP, Operations Reports,
  dashboards, and CSV/Excel export for all of these) is **not**
  converted; only the trial-balance route was in this task's scope,
  since it is the one `AccountingReportsPage.tsx` needs.
  **KNOWN GAP (not silently papered over):** the *manual* Journal
  Voucher CRUD endpoints (`GET /ledger/vouchers`, `POST /ledger/vouchers`,
  `POST /ledger/vouchers/{id}/post`, `POST /ledger/vouchers/{id}/reverse`)
  were never in this task's assigned scope (the task named only the
  trial-balance/account-transactions half of `ledger.py`) and remain
  unconverted -- `App\Services\Ledger::createJournalEntry()`/
  `postEntry()`/`reverseEntry()` already exist (built for GL posting +
  Bank, used internally by `App\Services\Posting`), only the endpoints
  a person would use to raise a manual Journal Voucher from the
  General Ledger screen are still missing a controller. This means
  `GeneralLedgerPage.tsx`'s "Vouchers" list/"Raise a Journal Voucher"
  form still 404 against `backend-php/` (pre-existing, not introduced
  by this conversion) -- its Trial Balance card above them now works
  correctly. Tracked here rather than silently left unmentioned; not
  yet added to the priority list below since nothing has asked for it
  yet.
  **Not yet converted:** CSV/Excel export (the Python router has none
  for Accounting Periods either).
  26 business-logic + API-level tests for Periods
  (`tests/Feature/PeriodsServiceTest.php`, 16 -- the lock matrix, the
  derived open/closed/partial status, every Year-End Closing
  precondition, and the exact worked example: a SGD 1,000 contract
  invoice's revenue account closed with one balanced entry, Dr revenue
  1000 / Cr Retained Earnings 1000; `tests/Feature/PeriodTest.php`, 10
  -- CRUD, owner-only reopen/Year-End Closing, RBAC, multi-company
  404) + 16 for the ledger/report additions
  (`tests/Feature/LedgerServiceTest.php`, 5 new -- debit=credit trial
  balance totals, draft/reversed vouchers excluded, running-balance
  math including the opening-balance carry-forward;
  `tests/Feature/LedgerTest.php`, 7; `tests/Feature/AccountingReportsTest.php`,
  4 -- including the `accounting_reports` vs. `finance_accounting`
  module-key independence the FINDING above describes).

Verified end-to-end for all nine modules above against the real React
frontend (screenshots in the PR/commit history), including the
Invoices page's Aging widget -- which previously 404'd (a confirmed
gap noted when Billing shipped) -- now rendering all 5 buckets with
the real activation invoice correctly showing as "Current / not yet
due" ($3,270.00); the Purchase Orders / Accounts Payable pages
showing a PO raised, approved, imported to AP as a matched, auto-
approved (and now GL-posted) bill; the full GL posting + Bank loop --
Chart of Accounts (36 seeded rows), a Payment Voucher raised against
that bill, banked, and the Bank Master File's balance correctly
showing -$2,180.00 after the fix above; and a Receipt Voucher raised
against a real invoice, allocated, and banked, with the Invoices
page's Aging widget correctly dropping to $0.00 outstanding once
fully paid. The only 404s seen were for not-yet-converted modules
(Announcements, Dashboard, Documents) -- none from Contracts, Job
Orders, Service Records, Excess Usage, Billing, Accounts Receivable,
Accounts Payable, or GL posting's own endpoints.

Verified end-to-end for Accounting Period management / GL Trial
Balance separately (screenshots in the PR/commit history): the
Accounting Periods page showing two real periods, one correctly
badged "Open" and the other "Partial" after locking two individual
cells (SALES_INVOICE/UPDATE, RECEIPT_VOUCHER/BANK) rather than Close
All; the General Ledger page's Trial Balance card showing a real,
balanced $1,090.00 (AR) / $90.00 (GST output) / $1,000.00 (revenue)
trial balance from an activated contract's annual invoice, with
"balanced" correctly badged; the GL Transactions drill-down reached
by clicking that AR row, showing the one INV-2026-0001 line with a
running and closing balance of $1,090.00; and the Accounting Reports
page's own Trial Balance selection (a separate route/module gate from
the General Ledger's) rendering the identical, correctly-balanced
figures. The only 404s seen were the expected not-yet-converted ones
(Announcements, Dashboard, the pre-existing Journal Voucher CRUD gap
noted above, and AR Aging under Accounting Reports -- that report's
own route, distinct from the trial-balance one just converted, is
part of the still-unconverted `reports.py` module).

- **Quotations** (`app/models/quotations.py`,
  `app/services/quotations.py`, `app/routers/quotations.py` (partial) →
  `App\Models\Quotation`/`QuotationLine`, `App\Services\QuotationService`,
  `App\Http\Controllers\Api\QuotationController`): the full document
  (create/list/get/send/accept/reject), single-GST-rate-per-document
  totals (`recomputeTotals()`, reusing `App\Services\Tax::applyGst()`
  exactly like Billing), and the confirmed 2026-09-10 accept -> auto-
  Contract conversion -- a quotation's lines split by unit of measure:
  "Hours"/"Hour" lines sum into one SERVICE_SUPPORT contract (SRV-002's
  10-hour minimum still applies with no override, so this half can
  legitimately fail to convert -- the quotation still accepts, the
  failure reason is appended to the response `message` instead), every
  other line sums into one ANNUAL contract (12-month term, no minimum).
  A quotation mixing both line kinds converts to **two** separate
  contracts, never one blend -- `converted_contract_id` and
  `converted_annual_contract_id` are independent, either/both/neither
  may be set. Each line defaults its `reference_code_id`/`cost_sgd`
  from the chosen catalog `Product`'s own defaults when not given
  explicitly on the line, same Reference Monitor/Costing pattern as
  Billing and Purchase Orders; a free-text (non-product) line has no
  default to fall back on, matching the Python router exactly --
  including that a `product_id` referencing another company's catalog
  item is still stored on the line as given (only the *default-filling*
  use of that product is skipped), which is how `app/routers/
  quotations.py`'s own `create_quotation` behaves, not a bug introduced
  here. `reference_code_id` has no FK constraint yet, same reason as
  `Product::default_reference_code_id` (Reference Codes isn't
  converted). Numbered via the already-ported `App\Services\Numbering`
  (`QUO-2026-0001`, prefix already present in `Numbering::PREFIXES`).
  Gated by the `sales` module, matching the Python router's own
  `MODULE` constant (same gate `Product` uses). 7 business-logic tests
  (`tests/Feature/QuotationServiceTest.php` -- the totals/GST math,
  hourly-only/non-hourly-only/mixed conversion, the SRV-002 partial-
  failure message, the neither-converts edge case) + 12 API-level
  tests (`tests/Feature/QuotationTest.php`).
  **KNOWN GAP (not silently papered over):** CSV/Excel export, the
  `.docx` export, and the "Email Quotation" endpoint are not converted
  -- same Documents-module-mailer-wiring gap already flagged for
  Service Records/Invoices/Purchase Orders. `QuotationsPage.tsx`'s
  Export/Email/WhatsApp buttons and `QuotationPrintPage.tsx`'s Word
  export therefore still 404 if clicked against `backend-php/` (the
  PDF/Print option uses the browser's own print dialog, not an API
  call, so it works); every other control on both pages works
  end-to-end. `Invoice`'s existing GP-costing `KNOWN GAP` (tracing a
  CONTRACT_ANNUAL invoice's cost back to the quotation that converted
  into it) is **not** closed by this conversion -- `Quotation` and
  `Contract` carry no link back to each other in either direction (the
  Python schema doesn't have one either: `converted_contract_id` only
  points forward, quotation -> contract), so `BillingService::
  costBasisForContract()` is unchanged and still always returns null.

Verified end-to-end against the real React frontend (screenshots in
the PR/commit history): creating a quotation with a 10-hour "Hours"
line correctly totalling $3,000.00 net / $270.00 GST / $3,270.00 (the
demo company's seeded 9% SR tax code), the Quotation Print page
rendering the identical breakdown on Webmaster's own letterhead
format, and Accept converting it end-to-end into a real Service
Support contract -- the quotation's status flipping to "accepted" and
a "Service Support contract" link appearing in the list, both driven
through the actual create -> send -> accept UI flow. The only 404s
seen were the expected not-yet-converted ones (Announcements,
Dashboard summary, and Reference Codes -- the last also already a
known gap for Product/Service Catalog's `default_reference_code_id`).

- **Incidents** (`app/models/incidents.py`, `app/services/incidents.py`,
  `app/routers/incidents.py` → `App\Models\Incident`,
  `App\Services\IncidentService`, `App\Http\Controllers\Api\IncidentController`):
  the Helpdesk front door for an incoming call or email
  (docs/open-business-decisions.md #36) -- logging one (with the one
  automatic customer match this system attempts: a case-insensitive
  match against an existing Contact's email address), the "someone to
  return the call" outcome (a `PENDING_CALLBACK` status + assignee on
  the Incident itself, confirmed 2026-09-12: no separate reminder/task
  record), Close (reason required, blocked once already Converted or
  Closed), and converting an Incident -- which **auto-creates** the
  real target record with a back-reference, not just a routing flag
  (confirmed 2026-09-11) -- to a draft Sales Quotation (one placeholder
  line at SGD 0, since an Incident only ever carries a subject, never
  product/price detail for Sales to price) or a Job Order (against a
  contract that must belong to the same customer and be ACTIVE/EXCEEDED,
  the exact same `VALID_CONTRACT_STATUSES` the Python service defines --
  a Job Order can still be raised against a contract whose hours are
  used up, SRV-001/SRV-008's excess-usage path exists for exactly that).
  Also converted: both Outlook Add-in endpoints
  (`POST /incidents/from-email`, `POST /incidents/from-email/convert-to-job-order`)
  -- registered before the `/{incident}/...` routes for the same reason
  the Python router gives (a wildcard route parameter would otherwise
  swallow `from-email` as a garbage incident id) -- including the
  confirmed 2026-09-12 fallback rule: if the sender's email doesn't
  resolve to a known Company/Individual, or that customer has no
  ACTIVE/EXCEEDED contract, "Convert to Job Order" falls back to
  creating a plain Incident instead of erroring, with the reason
  surfaced in `IncidentFromEmailResult.fallback_reason` -- exactly what
  "Convert to Incident" would have done. Lives under the existing
  `service_operations` module (already labelled "Helpdesk / Service
  Operations (Job Orders)" in the module catalog), not a new module
  key, same as the Python router. 18 business-logic tests
  (`tests/Feature/IncidentServiceTest.php` -- the email match, contract
  validity, every status-transition rule) + 17 API-level tests
  (`tests/Feature/IncidentTest.php`, including both Outlook Add-in
  fallback paths).
  **KNOWN GAPS (not silently papered over, both confirmed against the
  actual Python source rather than assumed):**
  - **No convert-to-software-task action or route.**
    `backend/app/services/incidents.py`'s `convert_to_software_task`
    creates a `SoftwareTask` row, but `backend/app/models/software_tasks.py`
    has no `backend-php/` equivalent yet (Software Tasks isn't
    converted -- see "Not yet converted" below) -- there is no model to
    create against, so `IncidentController` has no
    convert-to-software-task action and no route is registered for it
    at all, rather than a stub that would error or invent behaviour.
    `IncidentsPage.tsx`'s "Convert to Software Task" button therefore
    404s against `backend-php/` -- pre-existing to this conversion, not
    introduced by it.
  - **An Incident can never be raised through the Customer Helpdesk
    Portal here.** The Python model's `source=PORTAL` and
    `raised_by_portal_user_id` exist for exactly that
    (`app/routers/portal.py`, PORTAL-002), but the Portal itself isn't
    converted to `backend-php/` yet (see "Not yet converted" below), so
    no route here ever accepts those two fields -- both columns exist
    on the `incidents` table (for schema fidelity) and
    `raised_by_portal_user_id`/`portal_actor_name` are still on
    `IncidentService::createIncident()`'s signature (so the Outlook
    Add-in and a future Portal conversion can keep sharing the exact
    same function, same as the Python source's own design intent), but
    nothing in `backend-php/` can ever populate them. Only staff-side
    phone/email/other Incidents and the Outlook Add-in's email-sourced
    ones are reachable today.

**Verification method:** live API-level (`curl`) against `php artisan
serve`, not Playwright -- browser/Chromium launch was unavailable in
this session's sandbox (`npx playwright install chromium` produced no
installed browser and no usable error, the same blocked-CDN situation
an earlier session in this conversion hit), so per this task's own
fallback instruction a thorough scripted `curl` pass substituted for a
screenshot pass. Against a freshly `migrate:fresh --seed`ed database,
logged in as the seeded demo owner (`dennis@websoft.example`) and
exercised, end to end, over real HTTP: creating a Contact with a known
email, then `POST /incidents/from-email` with a differently-cased
version of that email auto-matching the right `customer_id` (no manual
step); `PATCH /{id}/customer` on a phone-sourced Incident with no
match; `POST /{id}/convert-to-quotation` producing a real draft
Quotation (`QUO-2026-0001`) with the one placeholder line visible in
the response, and the Incident flipping to `converted`; creating +
activating a real Service Support Contract, then
`POST /{id}/convert-to-job-order` against it producing a real Job
Order (`JO-2026-0001`) -- fetched right back via
`GET /job-orders/{id}` (a completely different controller) to confirm
the two present an identical shape, catching any drift between
`IncidentController`'s own `JobOrderOut`-shaped presenter and
`JobOrderController`'s; `POST /{id}/callback` then `POST /{id}/close`
as a status-transition pair; both Outlook Add-in endpoints -- an
unknown sender and a known sender with no active contract each
correctly falling back to a plain Incident with the right
`fallback_reason` text, and a known sender with a valid contract
correctly creating both the Incident (`converted`) and the Job Order
in one call; re-attempting a conversion on an already-converted
Incident correctly returning 422 with the exact Python message text;
a request with no Authorization header returning 401; and
`POST /{id}/convert-to-software-task` correctly returning a plain 404
(no route registered at all) rather than a 500 or invented behaviour,
confirming the KNOWN GAP fails safely. `php artisan test`'s own 351
passing tests (17 of them API-level, via Laravel's HTTP test client
against the same routes/middleware stack, just not a real running
server) cover the RBAC/multi-company-404 cases this pass didn't
re-drive manually.

- **Company Dashboard** (`app/routers/dashboard.py` →
  `App\Http\Controllers\Api\DashboardController`): the single
  aggregated `GET /dashboard/summary` endpoint behind
  `frontend/src/pages/DashboardPage.tsx` -- the app's landing page,
  which until now showed "Company Dashboard summary unavailable: Not
  Found" on every login against `backend-php/`, since this was the one
  route the frontend calls before anything else. No new model or
  migration: every figure is an aggregate over modules already
  converted, and each is taken from the service that owns it rather
  than re-queried by hand -- AR/AP outstanding + overdue via
  `AccountsReceivableService::agingRows()` /
  `PayablesService::agingRows()` (Python reads
  `app/services/reports.py`'s `ar_aging_rows`/`ap_aging_rows`, whose
  own docstrings state they are "the same bucketing as" those two
  modules' aging reports), and `gl_is_balanced` via
  `Ledger::accountBalances()`, the identical call Python's line ~91
  makes. Contracts/hours (SRV-014's 30-day pre-expiry window), open
  Job Orders, undecided Excess Usage records, SRV-015 late Service
  Records, and the invoice count/total are straight queries, matching
  the Python handler statement for statement.
  **No module gate, deliberately:** the Python route depends only on
  `get_current_user`, with no `require_module_access(...)`, so any
  authenticated user sees the summary for the company they are
  currently working in. Kept identical rather than "tightened" -- the
  tiles all link to screens that are themselves gated. Pinned by
  `DashboardTest::test_user_with_no_group_still_sees_the_summary_no_module_gate`,
  which asserts 200 where every other module's equivalent test asserts
  403, so the difference reads as a decision rather than an oversight.
  **Money handling:** all four AR/AP figures and `invoices_total_sgd`
  are accumulated through `App\Support\Money` and converted to float
  only in the response array; `gl_is_balanced` compares the two
  quantized totals as strings, so the comparison never goes through a
  float at all (Python compares `round(float(x), 2)`).
  `total_*_hours` stay plain float division by 60 -- hours, not money,
  same as Python.
  **NO KNOWN GAPS:** every module this endpoint aggregates over
  (Contracts, Job Orders, Service Records, Excess Usage, Billing,
  Accounts Receivable, Accounts Payable, GL posting) is already
  converted, so no tile reports a placeholder or invented figure.
  9 tests (`tests/Feature/DashboardTest.php` -- each tile's arithmetic
  including the SRV-014 window's already-expired edge case, the
  SRV-015 late/on-time/already-approved split, the AR outstanding vs.
  overdue split for an invoice with no due date, multi-company
  scoping, the no-module-gate decision, and 401 when unauthenticated).

- **Document Control** (`app/routers/document_control.py` →
  `App\Http\Controllers\Api\DocumentControlController`): the admin
  screen over document numbering (`frontend/src/pages/DocumentControlPage.tsx`)
  -- the running-number counters behind every serially-numbered
  document, and each document kind's number FORMAT (front prefix /
  digit padding / whether the year is included, confirmed
  2026-09-11). No new model or migration either: `DocumentSequence`
  and `DocumentNumberFormat` and `Numbering::format()` already existed,
  built with the Contracts conversion; only the controller over them
  was missing. Adjusting a counter and changing a format are both
  FULL-only and both require a reason, recorded to Event Logs with the
  same `document_sequence`/`last_number_changed` and
  `document_number_format`/`updated` entity/action strings Python
  uses. The formats list shows every doc kind built into the app
  (`Numbering::PREFIXES`) plus any this company has ever numbered, so
  a kind can be customized before its first document is issued, and
  marks a kind with no override row `is_custom: false` so the UI can
  say "default" vs "custom". A format change only affects numbers
  issued from that point on -- pinned by
  `DocumentControlTest::test_setting_a_format_changes_only_future_numbers`,
  which asserts an already-issued `CON-2026-0001` still reads
  `CON-2026-0001` after the prefix changes (CLAUDE.md: never modify
  existing business records).
  10 tests (`tests/Feature/DocumentControlTest.php`), including that
  EDIT -- enough to write in every other module -- is still a 403 here
  on both writes.

- **Document Attachments + eSignature** (`app/models/documents.py`,
  `app/services/documents.py`, `app/routers/documents.py` →
  `App\Models\DocumentAttachment`/`DocumentSignature`,
  `App\Services\DocumentService`, `App\Exceptions\DocumentFileError`,
  `App\Http\Controllers\Api\DocumentController`): the generic
  eDocument panel every document page carries (built 2026-09-12,
  planned-work.md #3) -- file upload/list/download/soft-delete and
  drawn electronic signatures, for all 12 document types in
  `DocumentEntityType` (quotation, invoice, receipt voucher, payment
  voucher, purchase order, supplier invoice, journal entry, job order,
  service record, contract, incident, commission payout). This
  unblocks `frontend/src/components/DocumentAttachmentsPanel.tsx` and
  `SignaturePanel.tsx`, which are mounted on ~12 detail pages and
  404'd on every one of them against `backend-php/` until now.
  Files are written to disk under the **identical** layout the Python
  version uses -- `<uploads_dir>/<company_id>/docs/<entity_type>/<entity_id>/<attachment_id>.<ext>`
  -- so the two backends can be pointed at the same `UPLOADS_DIR`
  during the conversion without either losing sight of the other's
  files. Same 20MB-per-file cap, same "any file type, no allow-list,
  unlimited count per document" rule (confirmed 2026-09-12 with
  Dennis); an extension-less filename stores under the bare id, as in
  Python. Both attachments and signatures are **soft-delete only**
  (`is_deleted`), and the file on disk is never removed --
  `DocumentTest::test_upload_list_download_and_soft_delete_an_attachment`
  asserts the file still exists after a delete. The signature
  endpoints never return `signature_data_uri` (Python's
  `DocumentSignatureOut` omits it too), so a signature list stays
  small and the drawn image is not re-served to a client that only
  needs to know who signed and when.
  Gated on `core_administration` (VIEW to read, EDIT to mutate),
  matching the Python router's single `MODULE` constant rather than
  each parent document's own module -- deliberate, so one panel
  component works on every document page.
  **Python quirk preserved with a check added:** Python declares
  `entity_type` as a `DocumentEntityType` path parameter, so FastAPI
  rejects an unknown value with a 422 before the handler runs. Laravel
  has no equivalent path-level coercion, so the same check is explicit
  in the controller and returns the same 422 -- without it an unknown
  entity type would have silently become a valid, unreachable bucket.
  **FINDING (flagged, not changed in `backend/`, which this work never
  touches):** `app/routers/documents.py` passes a **dict** into
  `audit.record()`'s `details` parameter at all three of its call
  sites, but that function's own signature types `details` as
  `str | None` and `AuditLogEntry.details` is a `Text` column
  (`app/routers/approvals.py` does the same in several places). PHP's
  `Audit::record()` types it `?string`, so the identical payload is
  JSON-encoded here -- the evident intent, and the same information
  either way. Worth raising with Dennis as a probable latent bug on
  the Python side rather than fixed silently on either.
  **KNOWN GAP -- and a correction to earlier gap notes:** converting
  this module does **NOT** unblock the `.docx` export / "Email X"
  endpoints that Service Records, Invoices, Purchase Orders and
  Quotations each flagged above as awaiting "the Documents module's
  mailer wiring". That attribution was wrong: the wiring is not in
  `documents.py` at all, it is three separate, still-unconverted
  Python services -- `app/services/mailer.py` (66 lines, SMTP),
  `app/services/pdf_convert.py` (62 lines, .docx → PDF), and
  `app/services/docx_forms.py` (497 lines, the document templates) --
  tied together by `app/services/document_email.py`. Nothing in this
  module reads any of them. Those four modules' Email/.docx gaps
  therefore all stand unchanged, and converting them is its own future
  task (config already has the `smtp_*` settings in
  `config/websoft.php`, so the eventual PHP mailer has somewhere to
  read from).
  21 tests across both halves
  (`tests/Feature/DocumentTest.php`, 11 -- a real multipart upload via
  `UploadedFile::fake()`, the on-disk path and content, download,
  soft-delete leaving the file in place, the 20MB rejection with the
  exact Python message, any-file-type and extension-less names, the
  unknown-entity-type 422, signatures with the data URI withheld,
  cross-company isolation on list/download/delete, and the full RBAC
  matrix; plus `tests/Feature/DocumentControlTest.php`, 10, above).

- **Announcements + Ad Banner** (`app/models/announcements.py`,
  `app/routers/announcements.py` → `App\Models\Announcement`/
  `AdBannerSettings`, `App\Http\Controllers\Api\AnnouncementController`):
  the platform announcements and promo video URL shown on the ad
  banner (Login page and, smaller, every page after signing in --
  `frontend/src/components/PromoVideoPanel.tsx`), plus the
  "Announcements & Ad Banner" admin screen
  (`frontend/src/pages/AnnouncementsPage.tsx`).
  `GET /announcements/public` is the one **unauthenticated** route
  here -- registered outside the `auth.jwt` group, the same way
  `companies.php` registers `GET /companies/public-branding` -- and is
  called on every page load by the app layout, which is why it was the
  single most frequently 404'd request in every prior smoke test
  against `backend-php/`. It now returns 200 everywhere.
  Deliberately **not company-scoped** (no `company_id` column):
  these are announcements about the software itself, and the Login
  page shows them before any company has been selected. Pinned by
  `AnnouncementTest::test_announcements_are_global_not_company_scoped`,
  which asserts a second company sees the *same* announcements -- the
  inverse of every other module's "another company's record is a 404"
  test, and deliberate.
  `AdBannerSettings` is a singleton row with an **integer** primary
  key (id = 1), not a UUID -- the only model here that does not use
  `HasUuidPrimaryKey` -- matching the Python model exactly; the row is
  created on first read, so a fresh install never 404s. Confirmed
  2026-09-12: Save = live immediately, no separate draft/publish step.
  Deleting an announcement is a **genuine delete**, not a soft-delete
  -- the Python router records the reasoning at its own handler
  (a marketing blurb with no downstream references, unlike the
  business/financial records CLAUDE.md's "never permanently delete"
  rule covers; `is_active` is the way to hide one without losing it),
  and that comment is carried across verbatim.
  **Python quirks preserved, not "improved":** reading the settings is
  FULL-only, not VIEW (unusual against every other read in the system,
  and pinned by a test); and `PATCH /settings` with no `video_url`
  clears the URL rather than leaving it alone, because
  `AdBannerSettingsUpdate` defaults the field to `None` -- so it is a
  full replace, not a partial update. Both have their own assertions.
  **Schema-contract note:** `docs/planned-work.md` #8a records that
  the future, separate "Server Company Central Command" application is
  planned to push advertisements by writing **straight into the
  `announcements` table** of each client database. The migration here
  therefore keeps column names, types and nullability identical to the
  SQLAlchemy model on purpose, and says so in its own comment. Nothing
  is built for Central Command in this repo -- this is only the
  receiving end, and it already works the moment a row appears,
  because `GET /public` reads the table directly with no cache.
  10 tests (`tests/Feature/AnnouncementTest.php`).

Verified end-to-end against the real React frontend (Playwright
against `backend-php/` on port 8004, screenshot in the PR/commit
history): the **Company Dashboard** landing page rendering a full
summary instead of "Company Dashboard summary unavailable: Not Found"
-- AR outstanding $3,270.00 (incl. $0.00 overdue), AP outstanding
$0.00, net receivable position $3,270.00, invoiced to date $3,000.00,
the GL Trial Balance tile correctly badged "Balanced", 1 active
contract, 0 expiring soon, 0 open job orders, 0 excess awaiting
review, 0 late service records, 1 invoice issued, and the
contracted-hours bar reading "0.0 used / 10.0 contracted -- 10.0 hrs
remaining" -- every figure from a real activated contract and its
BILL-001 invoice, with no failed requests on the page; the **Document
Control** page listing all 12 built-in document kinds with their
default prefixes and examples plus the live contract counter
previewing `CON-2026-0002` as its next number; the **Attachments /
Signatures** panel on a Contract detail page, with a real file
uploaded through the actual file input (listed with its type, size and
upload date, plus working download/delete buttons) and a real drawn
signature saved through the canvas ("Authorized signatory / Dennis
Goh", timestamped); and finally the **Announcements & Ad Banner**
page saving a promo video URL and adding a "What's New" item, with
that item then appearing in the app-wide ad banner on every other
page. **Zero failed
API requests and zero console errors across the whole pass** -- the
`/api/announcements/public` 404 that appeared in every earlier
verification run is gone, and no 404s remained for the Company
Dashboard, Documents or Document Control either.
- **Inventory / Stock -- Stock Master + setup masters** (the
  `stock_master` half of `app/models/inventory.py` and
  `app/routers/stock.py` → `App\Models\StockCategory`/`StockGroup`/
  `StockBrand`/`StockModel`/`StockUsage`/`Warehouse`/`StockItem`/
  `StockItemAttachment`/`StockLevel`/`StockMovement`,
  `App\Http\Controllers\Api\StockSetupController`/`WarehouseController`/
  `StockItemController`): the five setup master files (Categories,
  Groups, Brands + their child Models, Usages -- each with the
  create/update/`/toggle` shape the setup screens use, never a delete),
  Warehouses, the Stock Master item itself (including every extended
  2026-09-13 field and the joined category/group/brand/model/usage
  display names `StockMasterPage`/`StockItemDetailPage` render), item
  picture/document attachments (upload/download/remove, stored on disk
  under `config('websoft.uploads_dir')` keyed by the attachment's own
  uuid, never the user-supplied filename), and the read-only
  per-warehouse stock levels. A `StockModel` deliberately carries no
  `company_id` of its own, exactly like the Python model -- every model
  query is scoped by joining through its brand.
  **BOUNDARY deliberately preserved:** Product's "Is Stock" flag is
  *not* wired to `stock_items` here. `docs/backlog.md` and
  `docs/planned-work.md` #5 record that the full Product → Stock Master
  link-up is deferred pending the separate Websoft Stock Distribution
  ERP project; the Python schema stops at `stock_items.product_id` (one
  optional FK) and so does this conversion -- inventing that link would
  be inventing a rule nobody confirmed.
  **BUG FOUND AND FIXED (called out, not silently absorbed):** the
  Python `PATCH /stock/warehouses/{id}` and `PATCH /stock/items/{id}`
  routes type their request body as `WarehouseCreate`/`StockItemCreate`,
  whose `code` and `name` are **required** and which have no
  `is_active` field at all -- yet `WarehousesPage.tsx` and
  `StockItemDetailPage.tsx` both implement Activate/Deactivate as
  `update(id, { is_active: !x.is_active })`. Against `backend/` that
  can only ever 422, and would not have toggled the flag even if it
  validated. Both PHP routes make every field optional (which is what
  Python's own `model_dump(exclude_unset=True)` update loop was
  written for) and accept `is_active`, so the existing screens work;
  pinned by
  `test_warehouse_can_be_deactivated_with_is_active_alone` and
  `test_stock_item_can_be_deactivated_with_is_active_alone`.
  **FINDING -- audit trail (deliberate divergence):**
  `backend/app/routers/stock.py` writes **no audit entries at all**,
  for any of the six stock module keys. CLAUDE.md requires audit
  trails on important operational transactions and Event Logs reads
  that trail system-wide, so every create/update/state change in the
  PHP module records one. There is no Python call site to copy
  `entity_type`/`action` strings from, so they follow this codebase's
  existing convention: `stock_category`/`stock_group`/`stock_usage`/
  `stock_brand`/`stock_model`/`warehouse`/`stock_item` with
  `created`/`updated`/`activated`/`deactivated` (plus
  `attachment_uploaded`/`attachment_removed`). Worth raising with
  Dennis as a gap in `backend/`, not just a difference.
  **Also hardened (Python does not check this):** a stock item's
  lookup FKs (`category_id`/`group_id`/`brand_id`/`model_id`/
  `usage_id`/`product_id`) are verified to belong to the caller's own
  company. The Python router assigns the raw uuid straight through, so
  a cross-company id would leak another company's category or brand
  *name* into this company's Stock Master list through the joined
  display names. Refused with a 404 here rather than stored.
  **Pragmatic default called out in code, not assumed:** the Python
  attachment upload has no size limit at all; a 10 MB ceiling is set
  here (`StockItemController::MAX_ATTACHMENT_KB`).
  17 API-level tests (`tests/Feature/StockMasterTest.php`) covering the
  masters' CRUD/toggle shape, brand→model scoping, the attachment
  round-trip, both regression cases above, and the full RBAC matrix on
  `stock_master` (no Group, VIEW-only on a write, EDIT-level on a write
  -- Python gates stock writes at FULL, not EDIT -- Module Control
  disabled failing closed, and another company's record 404ing).
  `App\Support\Money::quantize()`/`toString()`/`toFloat()` gained an
  optional scale argument (default 2, unchanged for every existing
  caller) because a stock unit/average cost is `Numeric(14, 4)`, not
  2dp money -- see the Movements entry below for where that matters.

## New feature work landed directly in `backend-php/` (not a conversion)

2026-09-22: Dennis asked for a set of new Sales-area features (Job
Implementation Template, multi-Product Job Orders, Contract
hour-sharing, Contract filters, a Contract Operation Report, a
Contract–Quotation reference field, and a Sales Dashboard) with **no
`backend/` (Python) equivalent** -- built directly and only in
`backend-php/` + `frontend/`, not converted from anywhere. This
touched `ContractController`/`ContractService`, `JobOrderController`,
and `ProductController` (the same files the conversion work above
built), so if a route or model there looks unfamiliar against
`backend/`, check
[backlog.md](backlog.md#confirmed-scope-not-yet-built) /
[planned-work.md #11](planned-work.md#11-sales-module-enhancements-job-implementation-template-multi-product-job-orders-contract-hour-sharing-contract-filters-contract-operation-report-contractquotation-reference-sales-dashboard-raised-earlier-built-2026-09-22)
before assuming it was missed in the conversion -- it was never in
`backend/` to convert. Does not change this document's own "Converted
so far" / "Not yet converted" tracking above. **Note (added when
Quotations was converted below):** that work's own KNOWN GAP recorded
the Contract–Quotation reference as free text only "because Quotations
isn't converted to `backend-php/` yet" -- Quotations *is* now
converted (see above), so a real `Quotation` model exists to link
against. Reconciling the free-text field into a real reference is
left to that feature work's own follow-up, not done here -- this
conversion pass was scoped to Quotations only, per its own
instructions, and does not touch `Contract`/`ContractController` or
any file that new feature work owns.

## Not yet converted (pending, in rough priority order)

Everything below still only exists in `backend/` (Python). Each is a
phase of its own, following the same pattern as CompanyIndividual
Management above -- model(s) + migration(s) + controller + routes +
smoke test:

1. Everything else in `backend/app/routers/` not listed above
   (Inventory/Stock, the rest of `reports.py` (AR/AP aging duplicates,
   GST Return, Sales GP, Operations Reports) and its CSV/Excel
   exports, Event Logs, Software Tasks, Ops Dashboard, Customer
   Helpdesk Portal, Mobile Web App, Commissions [deferred, per
   CLAUDE.md]) -- lower priority than the Service Operations core
   above, since that core is what CLAUDE.md's Status section calls out
   as the one working slice today.
2. The shared document mailer stack -- `app/services/mailer.py` (66
   lines, SMTP), `app/services/pdf_convert.py` (62 lines, .docx ->
   PDF), `app/services/docx_forms.py` (497 lines, the document
   templates) and `app/services/document_email.py` (the shared "Email
   this document" helper tying the three together). Named separately
   here because four already-converted modules (Service Records,
   Invoices, Purchase Orders, Quotations) each carry a KNOWN GAP for
   their `.docx` export and "Email X" endpoints -- and this stack, NOT
   the Documents module converted above, is what actually unblocks all
   four at once. `config/websoft.php` already carries the `smtp_*`
   settings the eventual PHP mailer would read.

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
