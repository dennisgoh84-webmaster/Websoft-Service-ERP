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
CSV/Excel export endpoints. (The Customer Helpdesk Portal access
sub-resource -- `GET/POST /{customer_id}/contacts/{contact_id}/portal-access`
plus its `/reset-password` and `/disable` actions, and the PORTAL-004
archive cascade -- was the other gap listed here; it is now converted,
see the Customer Helpdesk Portal entry below.)

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
  **Not yet converted:** CSV/Excel export. (The `.docx`/email
  endpoints were the other gap here; both are converted now -- see
  "Document generation stack" below.) The Excess Usage
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
  **Not yet converted:** CSV/Excel export. (The `.docx` export and
  "Email Invoice" endpoints were the other gap here; both are
  converted now -- see "Document generation stack" below.)

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
  CLAUDE.md) and CSV/Excel export. (The Customer Statement endpoints
  and `.docx` export were also listed here; both are converted now --
  see "Document generation stack" below.)
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
  CSV/Excel export. (The Customer Statement endpoints, the `.docx`
  export and "Email Receipt" were also listed here; all are converted
  now -- see "Document generation stack" below.)
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
  **Not yet converted:** CSV/Excel export. (The `.docx` export and
  "Email Purchase Order"/"Email Payment Voucher" endpoints were the
  other gap here; all are converted now -- see "Document generation
  stack" below.)
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
  docblock. At the time, only the trial-balance route was in scope,
  since it is the one `AccountingReportsPage.tsx` needs; **the rest of
  `reports.py` has since been converted** -- see "Management Reporting
  -- Operations + Accounting Reports" below.
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
  **KNOWN GAP (not silently papered over):** CSV/Excel export is not
  converted. (The `.docx` export and the "Email Quotation" endpoint
  were listed here too; both are converted now -- see "Document
  generation stack" below, and `QuotationPrintPage.tsx`'s Word export
  and `QuotationsPage.tsx`'s Email button were both driven end to end
  through the real UI as part of that work.) `Invoice`'s existing GP-costing `KNOWN GAP` (tracing a
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
  read from). **Update 2026-09-15: that future task is done** -- see
  "Document generation stack" below; the correction above was right
  about where the wiring lived, and converting those four Python
  services is what actually closed all of those gaps.
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
- **Inventory / Stock -- movements: GRN, GTN, GRTN, Stock Adjustment**
  (`app/services/inventory.py` (264 lines) and the movement half of
  `app/routers/stock.py` → `App\Services\InventoryService`,
  `App\Models\GoodsReceiveNote`/`GoodsTransferNote`/`GoodsReturnNote`/
  `StockAdjustment` (+ their line models),
  `App\Http\Controllers\Api\GoodsReceiveNoteController`/
  `GoodsTransferNoteController`/`GoodsReturnNoteController`/
  `StockAdjustmentController`): the two confirmed Inventory rules, each
  gated by its own Module Control key (`goods_receive_note`,
  `goods_transfer_note`, `goods_return_note`, `stock_adjustment` --
  VIEW to list, FULL to create/confirm/approve, identical to the
  Python router, so receiving rights never carry transfer or
  adjustment-approval rights with them).
  **INV-002 (weighted average cost)** is `InventoryService`'s
  `receiveStock`/`deductStock`/`adjustStockIncrease`, ported 1:1: a
  receipt is the only event carrying new cost information, so the only
  one that re-weights the average
  (`(existing_qty x existing_avg + received_qty x received_cost) /
  new_qty`, quantized 4dp HALF_UP); a deduction leaves the average
  alone and leaves at it; a positive adjustment adds at the current
  average without moving it; a transfer reads the SOURCE warehouse's
  average *before* deducting and receives at exactly that cost, so
  moving stock never revalues it; and a resulting quantity of zero or
  less leaves the average untouched rather than inventing one. Stock
  can never go negative -- `deductStock` refuses the whole movement
  with Python's own `Insufficient stock: have X, need Y` message, and
  every confirm/approve runs in one DB transaction so a multi-line
  document is all-or-nothing.
  **INV-001 (adjustment approval)** is the `draft → submit →
  pending_approval → approve | reject` machine on `StockAdjustment`:
  `InventoryService::approveAdjustment()` is the *only* path by which
  an adjustment ever touches a stock level, so a draft or a rejected
  adjustment provably moves nothing. Who counts as "a manager" stays
  Group Authority's decision (FULL on `stock_adjustment`), exactly as
  in `backend/` -- no named-role check and no "creator cannot approve
  their own" rule is invented here, because neither was ever confirmed.
  **Money/cost precision:** all arithmetic goes through
  `App\Support\Money`, never raw PHP float math, at the two precisions
  the Python columns actually use -- 4dp for a unit/average cost
  (`Numeric(14, 4)`) and 2dp for an extended total (`Numeric(14, 2)`).
  Pinned by worked examples in `tests/Feature/InventoryServiceTest.php`
  the way `tests/Unit/MoneyTest.php` pins SRV-008, including a
  deliberately recurring one: 15 units at $110.0000 plus 3 at $55.50 =
  $1,816.50 / 18 = 100.91666... → **$100.9167**, so a rounding
  regression fails a test rather than silently mis-valuing stock.
  **QUIRK PRESERVED (flagged, not fixed):** the stock router does its
  own count-based document numbering (`GRN-00001` = "count this
  company's GRNs, add one") instead of `app/services/numbering.py`,
  whose `PREFIXES` table has no grn/gtn/grtn/adj entry and whose
  counter row is locked for the transaction. A count can repeat a
  number if a document is ever removed, and two simultaneous creates
  could collide. Kept byte-identical because changing it would change
  document numbers users already see -- worth raising with Dennis as a
  follow-up (moving these four onto `App\Services\Numbering` is a
  small change once he picks a format).
  **Also hardened (`backend/` does not check this):**
  `App\Services\StockDocumentGuard` verifies every referenced
  warehouse, stock item and supplier belongs to the caller's own
  company. The Python router assigns those uuids straight from the
  request body; the FK proves the row exists but not whose it is, so a
  user could receive stock into another company's warehouse and the
  resulting `stock_levels` row would carry their own `company_id`
  while pointing at a foreign warehouse -- corrupting both companies'
  stock reports. Refused with a 404 here.
  **KNOWN GAP (carried over from `backend/`, not introduced here):**
  no stock document posts anything to the General Ledger -- no
  Dr Inventory / Cr GRNI on a receipt, no supplier credit note on a
  return, no stock write-off entry on an adjustment. The Python
  service has no posting either, and PUR-002 confirms Accounts Payable
  matches a supplier bill against the **Purchase Order only** ("a
  separate Goods Receipt match is not required"), so there is no
  confirmed rule saying a receipt should post. Inventing one would be
  inventing a business rule; recorded here instead.
  15 business-logic tests (`tests/Feature/InventoryServiceTest.php` --
  the weighted-average worked examples above, the deduct-at-average
  rule, the negative-stock guard, each document's confirm, and every
  INV-001 case including a zero-quantity line and an overdrawing
  adjustment being refused whole) + 17 API-level tests
  (`tests/Feature/StockMovementTest.php` -- the full
  receive → transfer → return → adjust flow through the real routes,
  the same-warehouse transfer guard, double-confirm, and each of the
  four module keys gating independently, plus no-Group, VIEW-only,
  Module-Control-disabled and cross-company cases).
- **Inventory / Stock -- stock operation reports** (the
  `/api/stock/movements` and `/api/stock/reports/*` half of
  `app/routers/stock.py` → `App\Http\Controllers\Api\StockReportController`):
  the stock movements journal (newest first, filterable by item and
  warehouse, same `limit = 200` default), the INV-002 stock valuation
  report (quantity x weighted average cost per item per warehouse,
  optionally filtered to one warehouse, only positive holdings valued)
  and the reorder report (items whose total stock **across all
  warehouses** is at or below their reorder level; an item with no
  reorder level set has not opted in and is never reported). All three
  on the `stock_operation_reports` key at VIEW -- its own key, so a
  manager can read the stock reports with no rights to move stock, and
  full Stock Master authority does not grant them (note the movements
  journal is gated by the *reports* key, not `stock_master`, exactly as
  in the Python router). The valuation total is accumulated at full
  precision and quantized once at the end, matching the Python report
  -- summing already-rounded per-row values instead would drift by
  cents on a large holding.
  **BUG FOUND AND FIXED during the live verification walk:**
  `stock_movements` is an ordered ledger, but Laravel's `timestampTz()`
  defaults to **whole-second precision** (the convention every other
  table in this backend uses, and harmless for them), so
  `ORDER BY created_at DESC` returned same-second movements in an
  arbitrary order -- the journal showed a transfer's receipt above the
  receipt that funded it. The Python column is
  `DateTime(timezone=True)`, i.e. microseconds. Fixed by a separate,
  additive migration (`2026_09_23_000300_...`, never an edit to the
  already-applied create migration) narrowing the change to that one
  ledger column; pinned by
  `test_movements_written_within_the_same_second_still_order_correctly`.
  Rows written inside the *same* transaction still share one timestamp
  (Postgres `CURRENT_TIMESTAMP`/`now()` is the transaction start time)
  -- identical in `backend/`, so that tie is left exactly as it is.
  12 API-level tests (`tests/Feature/StockReportTest.php` -- the
  valuation worked example and its warehouse filter, zero-quantity and
  cross-company exclusion, the reorder report's at-or-below boundary
  and its all-warehouses sum, the movements journal's filters and
  ordering, and the `stock_operation_reports` key gating independently
  of `stock_master`).
  **Not yet converted:** CSV/Excel export -- the Python stock router
  has none either, so nothing is missing relative to `backend/`.

Verified end-to-end against the real React frontend with Playwright
(Chromium at `/opt/pw-browsers/chromium-1194`; screenshots in the
PR/commit history), walking a complete stock cycle on a freshly
migrated + seeded database: setup masters (Hardware category,
Networking group, Cisco brand + Catalyst 9300 model, Resale usage),
two warehouses (MAIN, BR01), a stock item (SW-9300 "48-port switch",
reorder level 5) whose detail page renders every joined lookup name;
then **GRN-00001** receiving 10 @ $100.00 and **GRN-00002** 5 @
$130.00 into MAIN (15 @ **$110.0000** -- the INV-002 worked example),
**GTN-00001** transferring 6 to BR01 (both sides at $110.0000, so the
transfer moved stock without revaluing it), **GRTN-00001** returning 2
to the supplier, and **ADJ-00001** (-1, "Damaged in storage") --
refused with a 400 while still draft, changing nothing on submit, and
only moving stock on approval, exactly as INV-001 requires. The Stock
Operation Reports page then showed the correct end state: MAIN 6 @
$110.00 = $660.00, BR01 6 @ $110.00 = $660.00, **total $1,320.00**;
the Stock Item Detail page showed the same 12 units / $1,320.00 across
2 warehouses; and the Stock Movements tab listed all six movements
newest-first (adjustment -1, return out -2, transfer out -6, receive
+6, receive +5 @ $130.00, receive +10 @ $100.00). The bug fix above was
also exercised through the real UI, not just the API: a warehouse
created through the Warehouses form and then deactivated with its own
Deactivate button (the call that could only ever 422 against
`backend/`). The only 404s seen anywhere were the expected
not-yet-converted ones (`/api/announcements/public`,
`/api/dashboard/summary`) -- none from any stock endpoint.

- **Customer Helpdesk Portal** (`app/models/portal.py`,
  `app/routers/portal.py`, `get_current_portal_user` from
  `app/core/deps.py`, and the portal-access sub-resource of
  `app/routers/company_individuals.py` → `App\Models\PortalUser`,
  `App\Http\Middleware\AuthenticatePortal`,
  `App\Http\Controllers\Api\PortalAuthController`/`PortalController`,
  the portal-access actions on
  `App\Http\Controllers\Api\CompanyIndividualController`,
  `routes/api/portal.php`): the whole customer-facing portal
  (`docs/customer-portal-design.md`, PORTAL-001..006), converted in
  three stages -- auth realm, staff-side access management, data
  endpoints.
  - **A gap closed 2026-09-15 that was not a conversion gap.** Design
    §5 specifies Enable / Disable / Reset password per Contact on the
    Company/Individual detail page. The four endpoints behind it were
    converted here and are fully tested (`tests/Feature/PortalAccessTest.php`),
    but **no screen had ever called them** -- `frontend/` had no portal
    client functions at all, against either backend. So the portal
    login page rendered and refused every sign-in, because there was no
    way to create a portal user in the first place. The Contact Person
    table now carries a "Portal login" column with those three actions,
    the enabled/locked/must-change status, and the one-time temporary
    password shown when SMTP is not configured.
  - **PORTAL-001/003 (the second auth realm).** `portal_users` is its
    own table -- one login per Contact, one email per company, never a
    row in staff `users`. A portal session token carries
    `purpose="portal"`; `AuthenticatePortal` is a **distinct**
    middleware (alias `auth.portal`), never a relaxed mode of the
    staff `Authenticate`, and the boundary holds in both directions
    exactly as the Python source intends: staff auth only ever decodes
    `purpose="access"`, so a portal token is refused by every staff
    endpoint; this middleware only ever decodes `purpose="portal"`, so
    a staff access token -- and the short-lived `portal_otp`
    intermediate token -- is refused by every portal endpoint; and the
    two realms resolve their subject against different tables, so even
    a correctly-signed `purpose="portal"` token naming a staff user id
    resolves to nothing. Ported faithfully: the login sequence (email
    + password → OTP by email, or the real token straight away when
    SMTP is unconfigured -- the same deliberate fail-open staff login
    has), `verify-otp`, `change-password`, `forgot-password`,
    `reset-password-otp`, and `/me`. Password complexity is the same
    `App\Services\PasswordPolicy` staff passwords use -- there is no
    looser portal policy. Lockout is exactly the Python rule: 5 wrong
    passwords lock for 15 minutes on `PortalUser.failed_attempts` /
    `locked_until` (the 5th failure sets the lock and resets the
    counter), and a locked account is refused even with the correct
    password. OTPs reuse the existing `login_otps` table through its
    `portal_user_id` column, whose foreign key this module's migration
    finally adds (see below).
  - **PORTAL-004 (PDPA gate + archive cascade), staff side.**
    Enable/disable/reset-password live on
    `CompanyIndividualController`, not on the portal controllers, for
    the reason the Python router's own comment gives: it is a staff
    action gated by `company_individual_management` (VIEW to read
    status, EDIT to change it -- the same keys and levels as Python),
    not something a customer can reach. Enabling refuses a contact
    with no email, an archived customer, and a customer with no PDPA
    consent recorded -- the same three checks in the same order with
    the same message texts; no consent rule is invented. A 10-character
    alphanumeric temporary password is emailed as an invite when SMTP
    is configured and otherwise returned once for on-screen display
    (design §5's documented fallback, `invited_by_email` says which
    happened). Archiving a Company/Individual now disables every portal
    login under it immediately through the same shared disable path,
    closing the placeholder NOTE `CompanyIndividualController::archive()`
    carried while this module was pending.
  - **PORTAL-002/005/006 (data endpoints).** `/contracts` (with
    contracted/consumed/remaining hours), `/job-orders` (+ `?contract_id=`),
    `/job-orders/{id}` with its Service Records, `/service-records`
    (+ `?contract_id=`), `/invoices` and `/payments` (PORTAL-005), and
    `/incidents` GET + POST. Every query filters on the token's own
    `contact.customer_id` -- never on an id from the request -- so the
    contract filter applied to another customer's contract id returns
    an empty list rather than their rows, and an id-bearing path
    (job-order detail) 404s rather than 403s for another customer's
    id, which is the information leak design §9.4 tests against. The
    responses are deliberately thin: rounded SRV-007 minutes only
    (never raw or deducted), no `cost_sgd`/`gp_sgd`/`gst_rate` on an
    Invoice, no internal notes, no other contacts, no staff name
    beyond the assigned engineer. Money follows the Decimal convention
    above -- exact on the model, `(float)` only at the JSON boundary.
    A portal-raised Incident goes through the *same*
    `App\Services\IncidentService::createIncident()` the staff screen
    and Outlook Add-in use, with `source=portal`,
    `raised_by_portal_user_id` set, `created_by_user_id` null and the
    contact's details taken from the token -- so it lands in the staff
    Helpdesk queue and converts there like any other Incident. Audit
    `entity_type`/`action` strings match the Python call sites exactly
    (`portal_user` / `portal_access_enabled`, `portal_access_re_enabled`,
    `portal_access_disabled`, `portal_password_reset_by_staff`,
    `password_changed_self`, `password_reset_via_forgot_password`;
    `incident` / `created` with the `"<contact> (portal)"` actor name
    and no staff actor id).
  - **Schema gaps closed.** The `portal_users` migration also adds the
    two foreign keys earlier migrations explicitly deferred "until
    portal_users exists": `login_otps.portal_user_id` and
    `incidents.raised_by_portal_user_id`. Both are real `ForeignKey`
    columns in the Python models, so the PHP schema is now at parity
    rather than carrying two permanently unconstrained uuid columns.
    This also closes the Incidents entry's second KNOWN GAP above (a
    portal-raised Incident was unreachable); its first gap
    (convert-to-software-task) is untouched and still stands.
  - **PYTHON QUIRKS carried across deliberately, not fixed:** (a) a
    portal login resolves the `PortalUser` by email alone with no
    company filter, even though uniqueness is `(company_id, email)` --
    if the same address were ever enabled under two companies the
    first row wins; not a security hole (the password must still match
    that row), but a quirk rather than a decision. (b) `last_login_at`
    is only stamped in `verify-otp`, so it stays null for a login that
    fail-opens because SMTP is unconfigured. (c) Python does not audit
    a successful portal *login* (only password changes and the staff
    enable/disable/reset actions), so neither does this -- matched
    rather than "improved", since the audit-action vocabulary has to
    stay identical between the two backends.
  - **No KNOWN GAPs.** Every route
    `backend/app/routers/portal.py` exposes has a PHP equivalent, and
    so does every portal-access route in
    `backend/app/routers/company_individuals.py`. No new module key is
    registered: design §8 sketched a `customer_portal` key, but the
    Python implementation gates the staff side under
    `company_individual_management` and leaves the portal itself to its
    own auth realm, and this conversion follows the source, not the
    sketch.
  - **48 tests**: `tests/Feature/PortalAuthTest.php` (17 -- the login/
    change-password/forgot-password flows, lockout, disabled and
    archived-customer refusal, and the §9.4 boundary in both
    directions), `tests/Feature/PortalAccessTest.php` (16 -- the
    consent/email/archived refusals, enable → a real portal sign-in
    with the issued temporary password, reset/disable/re-enable audit
    actions, the archive cascade, and the full RBAC/Module-Control/
    multi-company-404 template), `tests/Feature/PortalDataTest.php`
    (15 -- every data endpoint, each asserted against a **second
    customer's data present in the same company**, plus the 404-not-403
    rule and the staff-token refusal on every portal route).

**Verification method (Portal):** a real browser pass, not a `curl`
substitute -- Playwright drove the preinstalled Chromium at
`/opt/pw-browsers/chromium-1194` against `npm run dev` proxied at
`backend-php/`, on top of a scripted live-HTTP pass over the same flow.
Against a freshly `migrate:fresh --seed`ed database: as staff
(`dennis@websoft.example`), enabling portal access for a contact of a
customer with no PDPA consent returned 422 with the exact Python
message; after recording consent it returned a one-time temporary
password; signing in at `/portal` with that password in the browser
showed the forced password-change screen, then a Home page reading
"Acme Manufacturing Pte Ltd", `CON-2026-0001` 10.0 of 10.0 hrs
remaining, an account balance of $2,270.00 and `JO-2026-0001`, with
Contracts / Job Orders / Billing (INV-2026-0001, net $3,000.00, GST
$270.00, total $3,270.00, outstanding $2,270.00 after a $1,000.00
receipt) / Incidents each rendering with no console or network errors;
raising an Incident from the portal produced `INC-2026-0001`, which the
staff Incidents screen then listed as source `portal`, customer Acme,
sender `Alice Tan <alice@acme.example>`, and which converted to a Job
Order from the staff side, after which the portal's own Incidents list
showed the routed Job Order number. With a second customer ("Rival
Holdings", carrying a job order titled RIVAL SECRET WORK) present
throughout: that job order's id requested through the portal returned
404 (never 403), filtering by that customer's contract id returned `[]`
on both `/job-orders` and `/service-records`, and the string never
appeared in any portal payload. Cross-realm rejection was asserted
explicitly over real HTTP: the portal token returned 401 on
`/api/auth/me`, `/api/company-individuals`, `/api/contracts`,
`/api/job-orders`, `/api/service-records`, `/api/invoices`,
`/api/incidents` and on the staff portal-access disable route, and the
staff token returned 401 on all seven `/api/portal/*` endpoints. Also
driven live: staff disable → the live portal token 401s on the next
request; re-enable → 200; archiving the customer → 401 again with the
Contacts-tab status reading `enabled: false` (PORTAL-004); and five
wrong passwords → "Too many incorrect attempts. This login is locked
until HH:MM (SGT server time)." even with the correct password. The
resulting audit trail showed `portal_access_enabled`,
`portal_access_disabled`, `portal_access_re_enabled` and
`portal_password_reset_by_staff` attributed to Dennis, and
`password_changed_self` plus the `incident`/`created` entry attributed
to "Alice Tan (portal)" with no staff actor id.

- **Document generation stack + the `.docx` / "Email X" endpoints**
  (`app/services/docx_forms.py` (497 lines),
  `app/services/pdf_convert.py`, `app/services/document_email.py` ->
  `App\Services\DocxForms`, `App\Services\PdfConvert`,
  `App\Services\DocumentEmail`, plus the
  `App\Http\Controllers\Api\Concerns\SendsDocuments` trait and the
  fifteen endpoints that consume them). This is the stack the
  Documents-module correction above identified as the real blocker,
  and converting it closes the `.docx`/"Email X" KNOWN GAPs recorded
  against **Service Records, Sales Invoices, Purchase Orders, Payment
  Vouchers, Receipt Vouchers, Sales Quotations** and the **AR Customer
  Statement** all at once.

  **Converted:**
  - All **seven** Word forms: `invoice_to_docx`, `quotation_to_docx`,
    `receipt_to_docx`, `purchase_order_to_docx`,
    `payment_voucher_to_docx`, `service_record_to_docx`,
    `statement_to_docx` -- section for section, label for label, same
    table columns, same totals/GST treatment, same number and date
    formatting.
  - **DOCX -> PDF via LibreOffice headless**, keeping the Python
    module's deliberate design: the PDF attached to an email is the
    *same .docx bytes* the Word button serves, converted by shelling
    out to `soffice`, so one template feeds both formats and they can
    never drift. Not re-implemented as a second PDF layout.
  - The shared "Email this document" helper, with Python's own status
    mapping preserved: a PDF-conversion failure or an unconfigured
    mailer is **422**, an SMTP failure is **502**.
  - `App\Services\Mailer` gained **attachment support**
    (`attachment_filename`/`attachment_bytes`/`attachment_content_type`,
    defaulting to `application/pdf`, matching Python's `send_email`
    signature). The existing 3-argument calls (login OTP, password
    reset, portal invites) are unchanged. There is deliberately only
    one mailer -- the Auth one -- not a second copy.
  - The **AR Customer Statement** itself
    (`AccountsReceivableService::buildCustomerStatement()` +
    `GET /accounts-receivable/statement/{customer}`), which the .docx
    and Email endpoints both need and which was its own separate gap.
    Shared by all three exactly like Python's own
    `_build_customer_statement`, so "export what's on screen" always
    matches (2026-09-12).
  - Endpoints, same paths/verbs/RBAC as the Python routers -- `.docx`
    export at VIEW, "Email X" at EDIT, on each module's own existing
    module key (`billing`, `sales`, `service_records`,
    `accounts_payable`, `accounts_receivable`); no new module key:
    `GET|POST /invoices/{id}/export.docx|email`,
    `/quotations/{id}/...`, `/service-records/{id}/...`,
    `/accounts-payable/purchase-orders/{id}/...`,
    `/accounts-payable/payments/{id}/...`,
    `/accounts-receivable/payments/{id}/...`, and
    `/accounts-receivable/statement/{customer}` + its `/export.docx`
    and `/email`. Each Email endpoint writes the same audit entry as
    Python (`emailed` on the document, `statement_emailed` on the
    customer) and only after a successful send.

  **DEPENDENCY: `phpoffice/phpword`** (recorded here the same way
  `brick/math` was, per CLAUDE.md's "do not introduce unnecessary
  dependencies" rule). PHP has no built-in DOCX writer, and PHPWord is
  the direct counterpart to the Python backend's `python-docx`. The
  only alternative is hand-assembling OOXML -- the WordprocessingML
  parts, the content-type/relationship parts and the zip container --
  by hand, which is strictly worse: more code, no styling primitives,
  and a format that has to be kept valid by hand. No PDF library was
  added: LibreOffice does that, exactly as in `backend/`.

  **SYSTEM DEPENDENCY, and a correction to `backend/`'s own
  documentation:** `pdf_convert.py`'s docstring says this needs
  "`apt install libreoffice-core` or similar". **`libreoffice-core`
  alone is not enough** -- it carries no Writer import/export filter,
  so *every* conversion fails with `Error: source file could not be
  loaded` (verified here: even a plain `.txt` fails). The real
  requirement is **`libreoffice-writer`**. DEV_SETUP.md's Prerequisites
  section already says this correctly; it is the Python module's own
  docstring that is stale -- documented here for Dennis rather than
  edited in `backend/`.

  **Parity notes (python-docx vs. PHPWord -- byte-identical output is
  impossible and was never the goal):**
  - python-docx's `table.style = "Light Grid Accent 1"` references a
    built-in Word table style that ships inside python-docx's own
    default template. PHPWord's default template has no such style, so
    a bare reference would dangle and the table would render
    borderless. The same style *name* is registered on each document
    with an equivalent look (a full single-line grid in Word's Accent 1
    blue, shaded header row); PHPWord's table style cannot carry the
    header row's bold run formatting, so each form bolds its header
    cells explicitly. Rendered result matches; the markup does not.
  - python-docx turns a `"\n"` inside a run into a `<w:br/>`; PHPWord
    writes text verbatim, so `DocxForms::addRun()` splits on `"\n"`
    and emits a real text break.

  **Python quirks preserved rather than tidied up (each documented at
  the code):**
  - The Invoice form labels its tax row from the raw `Decimal`
    (`Tax 9.00% (SR)`) while the Quotation form labels it from
    `float(...)` (`Tax 9.0% (SR)`) -- the same rate, rendered
    differently on the two documents. Both pinned by
    `DocxFormsTest`. `DocxForms::pyFloat()` reproduces Python's float
    repr (PHP's `(string) 9.0` is `"9"`, Python's is `"9.0"`).
  - `receipt_to_docx` prints the payment method enum's raw value
    (`bank_transfer`, not "Bank Transfer"), while `purchase_order_to_docx`
    title-cases the PO status (`Pending Approval`). Both carried across.
  - The Payment Voucher form reads `payment.method` without `.value`
    because that column really is a plain `String(30)` on
    `SupplierPayment`, unlike `Payment.method` -- not a bug, and not
    "corrected".

  **Bug found and fixed in `backend-php/` (ours to fix, unlike
  `backend/`):** `Mailer`'s DSN builder read
  `config('websoft.smtp_use_tls') ? 'smtp' : 'smtp'` -- both branches
  of the ternary identical -- so `SMTP_USE_TLS=false` was inert and TLS
  could never actually be switched off, where Python's `mailer.py`
  calls `smtp.starttls()` only when the flag is true. Fixed by mapping
  the flag onto Symfony Mailer's real controls, verified against the
  constructed transport rather than the DSN string alone: true ->
  `?require_tls=true` (a plain connection that upgrades via STARTTLS
  and *fails* if the server won't, matching Python's unconditional
  `starttls()`), false -> `?auto_tls=false` (opportunistic STARTTLS
  switched off). Deliberately **not** the `smtps://` scheme for the
  true case -- that is implicit TLS-on-connect (SMTPS), a different
  wire protocol from the STARTTLS upgrade Python performs. Pinned by
  `tests/Feature/MailerTest.php`.

  **Bug found and fixed while verifying (caught by the browser pass,
  not by any unit test):** PHPWord writes run text into `<w:t>`
  verbatim by default (`outputEscapingEnabled` is false out of the
  box), so a literal `&` produced malformed XML. Word silently repairs
  such a file, so the **Word download looked fine**, but LibreOffice
  refuses it outright -- which broke the PDF conversion behind every
  Email button. It bit the Service Record form on *every* document
  ("Signature & Company Stamp"), and would have bitten any customer
  named "Smith & Sons" on all seven. python-docx escapes
  unconditionally, so switching escaping on is what actually matches
  `backend/`. Pinned at both levels:
  `DocxFormsTest::test_ampersands_in_document_text_are_escaped` and
  `PdfConvertTest::test_a_service_record_with_an_ampersand_still_converts`.

  **42 tests** -- `tests/Feature/DocxFormsTest.php` (13: every one of
  the seven forms really generated, the `.docx` unzipped and its
  WordprocessingML asserted on -- title, letterhead, table headers and
  the exact money/date strings -- plus the omit-when-absent branches
  (no due date, no allocations, no deduction row, no payment terms),
  the preserved Decimal-vs-float tax label, the escaping regression,
  and a valid-OOXML-package check that the table style is really
  defined and not a dangling reference);
  `tests/Feature/PdfConvertTest.php` (4: a real LibreOffice round trip
  asserting `%PDF-` + a trailer + a plausible size, no temp files left
  behind, the ampersand regression, and the unconfigured-mailer 422);
  `tests/Feature/MailerTest.php` (5: the TLS fix, in both directions,
  against the constructed Symfony transport); and
  `tests/Feature/DocumentExportTest.php` (20: every export endpoint
  returning a real Word package with the right media type and
  filename, every Email endpoint's "no email on file" 422 and its
  "not configured" 422 with no audit entry written, VIEW-vs-EDIT
  gating, Module-Control fail-closed, no-Group 403, multi-company 404,
  and the Customer Statement JSON including its `as_at` handling and
  paid-invoice exclusion).

  **KNOWN GAP (not silently papered over):** CSV/Excel export is
  untouched by this work and remains the one export format missing
  across the converted modules -- it is a different Python service
  (`app/services/exports.py`), listed on its own in "Not yet
  converted" below.

  **NOTE for Dennis, not assumed either way:** these Email endpoints
  send through the single system mailbox (`SMTP_*` in
  `backend-php/.env`), because that is exactly what `backend/` does
  today. [planned-work.md #8c](planned-work.md) records a decision
  (2026-09-15) that customer-facing document email should instead go
  out from a **per-company** mailbox configured in Company Setup ->
  Maintenance, with the system mailbox reserved for login OTP and
  password reset. That split is not built in either backend yet, so
  this conversion does not anticipate it; when it is built, the change
  is confined to which settings `DocumentEmail` hands to `Mailer`.

**Verification method (document stack):** a real browser pass, not a
`curl` substitute. `backend-php/` was run on its own database
(`websoft_docx_verify`) and port (8123) with the Vite dev server (5199)
proxied at it, and Playwright drove the preinstalled Chromium at
`/opt/pw-browsers/chromium-1194`. Signed in as
`dennis@websoft.example` and clicked the **actual Word button** on the
Sales Invoice, Sales Quotation, Service Record, Purchase Order,
Receipt Voucher and Payment Voucher print pages and on the Statement
of Accounts panel of the Invoices page -- all seven downloaded real
`PK`-magic Word packages (`INV-2026-0001.docx`, `QUO-2026-0001.docx`,
`SR-2026-0001.docx`, `PO-2026-0001.docx`, `RV-2026-0001.docx`,
`PV-2026-0001.docx`,
`Statement-Acme Logistics Pte Ltd-2026-09-15.docx`), each of which was
then unzipped and confirmed to carry the right letterhead, document
number, dates and totals, and converted through LibreOffice to a real
PDF. Then clicked the **actual Email button** on all seven: every one
reached its endpoint and returned 422 "Email sending is not configured
yet..." surfaced in the UI banner -- not the 404 they all returned
before this work. The only remaining 404 in the whole pass was
`GET /api/reference-codes`, a pre-existing gap for a module that isn't
converted. The first run of this same pass is what caught the
ampersand/escaping bug above: the Service Record's Word download
succeeded while its Email button failed with "source file could not be
loaded".

**What the Email path could NOT be exercised against:** there is no
SMTP server in this environment, so a *successful* send was never
driven end to end. Everything up to the socket was: the document is
really built, really converted to PDF and really attached, and the
unconfigured-mailer outcome -- which Python treats as a first-class
result, a clear 422 rather than pretending to have sent -- is asserted
at both the service and HTTP levels. The TLS behaviour is verified
against the constructed Symfony transport, not by connecting.

### Tax Types + the shared CSV/Excel export helper (converted 2026-09-15)

- **`App\Services\Exports`** -- the port of
  `backend/app/services/exports.py` (`rows_to_csv` / `rows_to_excel`),
  including its column-width sizing (longest cell + 2, clamped 10..50)
  and its bold header row. Every converted module's `/export.csv` and
  `/export.xlsx` endpoint uses these two functions, exactly as every
  Python router uses Python's two.

  **DEPENDENCY: `phpoffice/phpspreadsheet`** (recorded the same way
  `brick/math` and `phpoffice/phpword` were, per CLAUDE.md's "do not
  introduce unnecessary dependencies" rule). PHP has no built-in XLSX
  writer, and PhpSpreadsheet is the direct counterpart to the Python
  backend's `openpyxl`. Python's `/export.xlsx` returns a genuine OOXML
  package with the
  `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`
  content type, so matching that contract requires a real writer; the
  alternative is hand-assembling the OOXML zip, which is strictly worse
  for the same reasons PHPWord was adopted.

  **NOT the same class as the pre-existing `App\Services\ExportService`,
  deliberately.** That one predates the conversion, serves the
  backend-php-only report screens (Contract Operation Report, Sales
  Dashboard drill-downs), and writes "Excel" as an HTML `<table>` served
  with a `.xls` name -- a long-standing technique Excel opens, but *not*
  what Python's `/export.xlsx` returns. A converted endpoint therefore
  cannot use it without changing the API contract, so the two coexist.
  **Follow-up, not done here:** move those newer screens onto `Exports`
  and retire `ExportService`, so there is one export writer rather than
  two. Out of scope of this pass, which converts modules rather than
  changing already-working screens' output format.

- **Tax Types** (`app/routers/tax_codes.py` ->
  `App\Http\Controllers\Api\TaxCodeController`, 11 dedicated tests):
  list (hiding inactive unless `include_inactive`), create (409 on a
  duplicate code), patch, and both exports. The `tax_codes` table itself
  already existed -- Billing/Invoicing created and reads it -- so this
  adds only the maintenance screen's endpoints over it.

  PYTHON DETAILS CARRIED ACROSS DELIBERATELY: `TaxCodeCreate` has no
  `is_active` field, so a tax code cannot be created pre-deactivated
  (the column default applies) -- pinned by a test; `rate_percent` is
  bounded 0..100 on both create and update; the patch handler records
  only fields whose value actually changed, stringified on both sides,
  matching Python's per-field `old != new` comparison; and `TaxCodeOut`
  types `rate_percent` as a float, so the wire format is a bare number
  rather than the numeric string Eloquent's `decimal:2` cast would
  otherwise produce.

  ONE HARMLESS ENCODING DIFFERENCE, pinned rather than chased: PHP's
  `json_encode` writes `9.0` as `9` where Python writes `9.0`. Both
  parse to the same JavaScript Number, so this is not a contract
  difference, and no converted module sets `JSON_PRESERVE_ZERO_FRACTION`
  to force it. The test asserts the value is not a *string*, which is
  the part that would actually break the frontend.

### GL Types + Currency Rate Table (converted 2026-09-15)

- **GL Types** (`app/routers/gl_types.py` ->
  `App\Http\Controllers\Api\GLTypeController`): the optional finer
  classification within one of the 5 AccountType classes that a Chart
  of Accounts row can carry. List (ordered by account_type then code,
  hiding inactive unless `include_inactive`), create (409 on a
  duplicate code, and -- like Tax Types -- no `is_active` on create),
  patch recording only genuinely changed fields.

  **SCHEMA GAP CLOSED: `accounts.gl_type_id`.** Python's `Account` has
  carried this column all along; `backend-php`'s `accounts` table never
  did, because the GL posting conversion scoped GLType out (recorded in
  `App\Models\Account`'s docblock). The column was therefore *missing*,
  not merely unused. Added here with its foreign key. `AccountOut` does
  not expose `gl_type_id` in Python either, so this is schema parity
  only -- no API change, and the Chart of Accounts endpoints are
  untouched.

- **Currency Rate Table** (`app/routers/currency_rates.py` ->
  `App\Http\Controllers\Api\CurrencyRateController`): list (filterable
  by `currency_code`, case-insensitively, ordered by code then newest
  effective date first), create, patch. Setup data only -- nothing in
  the app converts an amount using these rates, since the system is
  single-currency (SGD) and multi-currency remains open item 4b.5.

  TWO PYTHON BEHAVIOURS CARRIED ACROSS DELIBERATELY, both pinned by
  tests: (a) the list filters by `currency_code` only and **never** by
  `is_active`, so a deactivated rate still appears -- there is no
  `include_inactive` parameter here, unlike GL Types and Tax Types;
  (b) `CurrencyRateUpdate` carries only `rate_to_base` and `is_active`,
  so a rate's currency and effective date are not editable -- together
  with the company they are the row's identity.

  **FINDING in `backend/`, documented not fixed:** `create_currency_rate`
  does not pre-check the `uq_currency_rate` (company, currency,
  effective_date) constraint, unlike `gl_types.py` and `tax_codes.py`,
  which both pre-check theirs and return a 409. A duplicate currency
  rate therefore surfaces as a database integrity error rather than a
  clean 409. The PHP conversion matches this rather than silently
  improving it; worth raising with Dennis as a small inconsistency in
  `backend/`.

  13 dedicated tests cover both modules.

### Setup Lists + Reference Codes (converted 2026-09-15)

- **Setup Lists** (`app/routers/setup_lists.py` ->
  `App\Http\Controllers\Api\SetupListController`): Nationality,
  Country, State, Area Code, Currency and Industry, all in one generic
  `setup_list_items` table. List (ordered by list_type, sort_order then
  code, filterable by `list_type`, hiding inactive unless asked),
  create, patch, and both exports.

  **GLOBAL, not company-scoped** -- the one structural thing to know
  about this module. A country's name does not differ per company, so
  every company shares one list per `list_type`. That makes the usual
  "another company's row is a 404" test inapplicable; its opposite is
  pinned instead (company B sees the row company A created), the same
  way the Announcements conversion handled being global. Still gated on
  `core_administration`, so it remains editable only with rights to it.

  PYTHON DETAILS CARRIED ACROSS: uniqueness is `(list_type, code)`, not
  `code` alone, so the same code may exist under two different list
  types -- pinned; `list_type` is absent from `SetupListItemUpdate`, so
  an item cannot be moved between lists once created -- pinned;
  `parent_code` (a State's owning Country) stays a **plain string, not
  a foreign key**, because the parent can live in a different
  `list_type`; and the CSV/Excel export writes a missing `parent_code`
  as an empty string while the JSON keeps it null.

- **Reference Codes / Reference Monitor**
  (`app/routers/reference_codes.py` ->
  `App\Http\Controllers\Api\ReferenceCodeController`): GL sub-codes
  under one Chart of Accounts row, so one account (e.g. 45001 "Sales of
  Software Revenue") can be broken down for document selection. List
  (filterable by `account_id`, joining the account's code and name),
  create, patch, and both exports. Gated on `finance_accounting`, the
  same module as Chart of Accounts, since it is a direct extension of
  it.

  `GET /api/reference-codes` was the last remaining 404 seen in smoke
  tests against `backend-php` -- the document-generation pass recorded
  it as the only failing request in its whole Playwright run. It is now
  served.

  PYTHON ORDERING DETAIL CARRIED ACROSS: create checks the account
  **before** the duplicate-code check, so a cross-company `account_id`
  returns 404 "Account not found" even when the code would also have
  clashed -- pinned by a test. Patch re-validates `account_id` the same
  way, so a reference code cannot be re-pointed at another company's
  account.

  **SCHEMA GAPS CLOSED: the two deferred foreign keys.**
  `products.default_reference_code_id` and
  `quotation_lines.reference_code_id` have both carried their column
  without its constraint since their own migrations, each saying so in
  a comment and deferring "until reference_codes exists". Both
  constraints are added here -- the same pattern the Customer Helpdesk
  Portal used for `login_otps.portal_user_id` and
  `incidents.raised_by_portal_user_id`.

  14 dedicated tests cover both modules.

### Support Monitoring (converted 2026-09-15)

- **Support Monitoring** (`app/routers/monitoring.py` +
  `app/services/monitoring.py` -> `App\Services\Monitoring` +
  `App\Http\Controllers\Api\MonitoringController`, 12 dedicated
  tests): the per-staff Job Order workload and contract-hours
  throughput board, so a supervisor can see who is overloaded. One
  read-only endpoint, gated on `reporting` at VIEW.

  PYTHON BEHAVIOURS CARRIED ACROSS DELIBERATELY, each pinned by a test:
  - The **owner is excluded** from the staff rows (`role != OWNER`), as
    are inactive users -- it is a board of the staff being supervised.
  - A Job Order **assigned to someone outside that staff map** (the
    owner, or a deactivated user) counts in the summary totals but
    appears in **nobody's row** -- it is not the same as unassigned,
    because `assigned_to_user_id` is set, so it does not reach the
    Un-Assigned row either.
  - `avg_daily_contract_hours` divides by the number of days **elapsed
    so far this month**, not by the number of days actually worked, so
    it reads as "how many hours a day is this person contributing on
    average" rather than being inflated by counting only active days.
  - That averaging loop runs over the staff rows **only**, so the
    synthetic Un-Assigned row keeps `0.0` even when it has open job
    orders.
  - Only `contract_deduction` records contribute hours; excess-usage
    records still count as records for the month.

  OPEN ITEM carried across unchanged, not guessed: "due soon" has no
  confirmed lead time. `DUE_SOON_LEAD_DAYS = 2` is a pragmatic default
  (SRV-009 remains deferred), used only to bucket a Job Order that
  already has a manually-set `due_date`.

  **KNOWN GAP: "Un-Test S/T" always reports 0.** The metric counts
  Software Task rows assigned to a tester but not yet marked tested.
  The Software Tasks module is not converted, so there is no
  `software_tasks` table to count. The field is present and zero so the
  screen renders and the response shape is unchanged; a test pins it
  explicitly so the zero cannot later be mistaken for a verified real
  count. When Software Tasks lands, the counting loop in
  `App\Services\Monitoring` is the only thing that needs filling in --
  Python's loop increments the summary total for **every** untested
  task whether or not it has a tester assigned, and that detail is
  recorded in the code comment there.

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

### Stock costing revision + Goods Issue Note (2026-09-15)

Confirmed with Dennis 2026-09-15. Partly a revision of converted
behaviour and partly new scope, so it is recorded here rather than as a
conversion entry.

**1. The weighted average moved from per-warehouse to per-item.**
`backend/`'s `StockLevel` holds an `avg_cost` per (item, warehouse);
`backend-php` now holds one per item on `stock_items`, across all
locations and branches, and `stock_levels` carries quantity only. This
is a DELIBERATE DIVERGENCE FROM `backend/`, not a conversion defect --
recorded in `docs/business-requirements.md` under INV-002.

Two consequences, both intended: a transfer is cost-neutral by
definition rather than by a special rule (`confirmGtn` no longer reads
and carries a source cost across, and its inbound half is a new private
`transferIn` that adds quantity without re-weighting); and every
location values at the same unit cost, so the Stock Valuation report's
per-warehouse rows are slices of one company-wide valuation.

**2. Stock Master item detail gains Avg Cost and Cost Value.**
`cost_value` is recomputed from the authoritative per-warehouse
quantities after every movement rather than incremented, so it cannot
drift out of step with them.

**3. Every movement records the running balance it produced**
(`qty_after`, `avg_cost_after`, `cost_value_after`), so a recalculation
can be tallied back against history. NULLABLE on purpose: rows written
before this change have no recorded balance, and back-filling an
invented one would be a fabricated audit trail. Null means "not
recorded", never "zero".

**4. Negative cost is unreachable.** A deduction larger than what the
warehouse holds is refused outright (never partial), stock at another
branch does not make a shortfall good, and a receipt at a negative unit
cost is refused at entry -- with no negative quantity and no negative
receipt cost there is no route to a negative average.

**5. An adjustment-up may now carry its own unit cost** and re-weight
like a receipt, for opening balances and found stock
(`stock_adjustment_lines.unit_cost`, nullable). Omitting it keeps the
previous behaviour: a pure count correction that reuses the current
average and moves no weighting. This too diverges from `backend/`,
which never re-weights on an adjustment.

**6. Goods Issue Note (GIN) -- NEW, not a conversion.** `backend/` has
no equivalent router or model. It is what finally writes the `issue`
stock movement type, which existed but nothing ever produced. Shaped
like the Goods Return Note beside it (draft -> confirm, count-based
`GIN-00001` numbering, the same guards) so the stock module keeps one
document pattern. Its own `goods_issue_note` module key.

A GIN line carries NO unit cost on entry: stock leaves at the item's
average, so a cost supplied there would be a second, contradictory
source of truth. Confirming stamps the average that actually applied
onto the line, so the document still reads correctly after the average
later moves. A line the warehouse cannot cover throws, rolling the
whole document back -- pinned by a multi-line test where the first line
would have succeeded.

**KNOWN GAP, confirmed as intended:** a confirmed GIN posts nothing to
the General Ledger. Dennis confirmed 2026-09-15 that stock stays a
sub-ledger, so there is no COGS entry -- consistent with every other
stock document, none of which post either.

**STILL OPEN -- the Sales Invoice half of the request.** Dennis also
asked that a Sales Invoice picking stock be refused when quantity is
insufficient, and deduct at average cost. That is NOT built, and the
blocker is larger than it first appears: `invoices` is a HEADER-ONLY
table. There are no invoice lines, no product selection, and no manual
raise-an-invoice flow -- every invoice today is auto-issued from a
contract activation (BILL-001) or an excess-usage decision (SRV-008)
with a single `amount_sgd`. Making an invoice pick stock therefore
means building product-based sales invoicing first, which is a
substantially larger piece of scope than the costing work and is
recorded in docs/backlog.md for Dennis to scope rather than assumed.

### Software Tasks (converted 2026-09-15)

- **Software Tasks** (`app/routers/software_tasks.py` ->
  `App\Http\Controllers\Api\SoftwareTaskController`, 10 dedicated
  tests): list (filterable by programmer, tester, and untested-only),
  create, patch, mark-tested, reopen-testing, and both exports. Gated on
  `software_development`.

  Confirmed 2026-09-10 as a deliberately minimal first slice -- no
  status workflow beyond `is_tested` -- and converted as such rather
  than extended.

  **CLOSES THREE RECORDED GAPS:**
  1. **Support Monitoring's "Un-Test S/T" placeholder.** That figure
     reported a hard 0 because there was no `software_tasks` table; it
     is now a real count. Note the asymmetry, faithful to Python: the
     SUMMARY total counts every untested task whether or not a tester is
     assigned, while a per-staff row only gains one assigned to that
     tester -- so the summary can legitimately exceed the sum of the
     rows, by exactly the unassigned ones. Pinned by a test.
  2. **`incidents.converted_software_task_id`**, the foreign key that
     migration deferred explicitly "until software_tasks exists" -- the
     same pattern used for `portal_users` and `reference_codes`.
  3. **The Incidents convert-to-software-task route**, that module's
     last remaining KNOWN GAP. Unlike its convert-to-Quotation and
     convert-to-Job-Order siblings this needs neither a customer nor a
     contract -- a bug report is a bug report whether or not the caller
     was ever identified, which is why Python checks neither. Pinned.

  HARDENING beyond Python, consistent with the Stock conversion: an
  `assigned_programmer_id` or `tester_user_id` belonging to another
  company is refused with a 404. Python passes both straight through.

  With this, the Incidents module has no KNOWN GAPs left.

### Event Logs + Bank Book (converted 2026-09-15)

- **Event Logs** (`app/routers/event_logs.py` ->
  `App\Http\Controllers\Api\EventLogController`, 9 dedicated tests):
  the readable face of the audit trail every other module writes to.
  List with filters (entity type, action, actor, date range, free text
  across details/reason/actor/entity/action), plus both exports.
  Read-only by design -- there is no create, update or delete endpoint,
  which is what makes the trail worth having. Gated on `event_logs`.

  DETAILS CARRIED ACROSS, each pinned: `date_to` compares against the
  START of the following day, so it includes everything logged on that
  date; the list limit is clamped to 1..500 and an export to 5000 rows
  rather than refused; and entries with a **null company_id** (written
  before company stamping existed) are shown to EVERYONE rather than
  hidden -- hiding them would silently shorten an audit trail.

  **Exporting the trail is itself audited**, with the filters used and
  the row count, because bulk-reading the audit trail would otherwise
  be the one action it does not record.

- **Bank Book -- transactions and reconciliation**
  (`app/routers/bank_transactions.py` + `services/bank_book.py` ->
  `App\Http\Controllers\Api\BankTransactionController` +
  `App\Services\BankBook`, 11 dedicated tests): the ledger with its
  running balance, adding a line, voiding one, per-line
  toggle-reconciled, and full reconciliation sessions. Adds the
  `bank_reconciliations` table; `bank_transactions` already existed,
  created by the GL posting + Bank step conversion, but nothing had
  ever recorded a reconciliation against it.

  DELIBERATELY ITS OWN LEDGER, separate from the General Ledger's
  Journal Vouchers -- nothing auto-posts to the GL except manually
  entered Journal Vouchers, so tying a day-to-day Bank Book to it would
  mean keying every bank line as a JV first.

  RULES PINNED BY TESTS: a line is either a debit or a credit, never
  both and never neither (the same convention `JournalLine` uses); a
  **voided line stays visible in the ledger but stops moving the
  balance** and no longer counts as unreconciled, since CLAUDE.md
  forbids deleting financial records; a reconciliation snapshots the
  ledger balance AS AT THE STATEMENT DATE, so a later-dated transaction
  does not distort it; and reconciling a transaction belonging to
  another account is refused whole.

  **REFACTOR, not a straight port:** `BankAccountController` computed
  the account list's current balance inline. That logic now lives in
  `App\Services\BankBook` and both callers read it -- mirroring why
  Python keeps `bank_book.py` shared, and pinned by a test asserting
  the account list and the ledger closing balance agree.

### Mobile Web App (converted 2026-09-15)

- **Mobile Web App** (`app/routers/mobile.py` ->
  `App\Http\Controllers\Api\MobileController` +
  `App\Services\MobileFileStorage`, 16 dedicated tests): all 11
  endpoints -- own Job Orders and their detail, time in/out, work
  photo/video upload/list/download/soft-delete, customer sign-off and
  its retrieval, and the open-time-in check. Gated on
  `service_records`: the mobile app is a different front door to the
  same module, not a module of its own.

  Adds `service_record_attachments` and `service_record_signoffs`.
  DISTINCT from `document_attachments`, deliberately and as in Python:
  that is the generic any-file panel on ~12 document pages, while these
  carry rules it does not -- images and videos only, and a chop photo
  watermarked so it cannot be reused on another record.

  RULES PINNED BY TESTS: a Job Order not assigned to the caller is
  **403 while another company's is 404** ("not yours" is a different
  fact from "does not exist"); only one open time-in at a time, naming
  the record that is already open; time-in on an OPEN Job Order claims
  it to ASSIGNED; elapsed time is ceil'd to at least one minute then
  rounded UP to the 15-minute increment, so a 20-second call still
  bills the minimum; one sign-off per Service Record; and a deleted
  attachment is soft-deleted with **the file left on disk**.

  **The chop-photo watermark is a GD port of Python's PIL code**, and
  no new dependency was needed: GD is bundled with PHP and is compiled
  here with JPEG and FreeType support, with the same DejaVu font the
  Python code asks for present. Both marks are reproduced -- the
  semi-transparent bottom strip carrying "SR-NUMBER | timestamp" and
  the larger, fainter SR number across the centre. Note GD's alpha runs
  0-127 INVERTED relative to PIL's 0-255, so every alpha is converted
  rather than copied; and `imagettftext` takes a baseline where PIL
  takes a top-left corner, so the text height is added back. A test
  reads the stored pixels to prove the strip is actually drawn rather
  than trusting that the call returned bytes.

  **BUG AVOIDED, worth recording:** an attachment's stored filename is
  named after its id, and `ServiceRecordAttachment::create(['id' =>
  ...])` silently DROPS that id because `id` is not mass-assignable --
  leaving the row and the file on disk disagreeing about which id they
  belong to. The id is now set explicitly after construction. The
  download path reads `stored_filename` from the row, so this would not
  have failed a naive test; it was caught by the sign-off's foreign key
  to the chop attachment.

### eApproval Master (converted 2026-09-15)

- **eApproval Master** (`app/routers/approvals.py` +
  `services/approvals.py` -> `App\Http\Controllers\Api\ApprovalController`
  + `App\Services\ApprovalService`, 21 dedicated tests): the generic,
  authority-based, value-gated approval framework of planned-work #4.
  Five new tables -- authorities, their members, rules, requests and
  decisions. Gated on `core_administration`: configuring authorities
  and rules needs FULL, submitting and deciding EDIT, reading VIEW.

  RULES PINNED BY TESTS, each a place a plausible-looking shortcut
  would be wrong:
  - A threshold is **at or above**, so the boundary amount itself
    matches.
  - Called **without** an amount, only rules with NO threshold match --
    a thresholded rule is not matched by default when the caller cannot
    say what the document is worth.
  - **Any rejection rejects the whole request immediately**, whatever
    the mode and however many approvals it already has. One approver
    saying no is not outvoted.
  - `any_one` resolves on the first approval; `all_must` waits for
    every current member.
  - Re-submitting the same document under the same rule returns the
    EXISTING pending request rather than raising a second one, so a
    double click does not create two things to approve.
  - An approver who has already decided stops seeing the request in
    their pending list while it waits for colleagues.
  - Requests and decisions are **never deleted**, so the per-entity
    view keeps approved and rejected items visible -- planned-work #4
    asks for this specifically, because today's Service Record approval
    screen drops an item the moment it is acted on.

  HARDENING beyond Python, consistent with earlier conversions: an
  approver must be a user of the same company; a Bank Authority's
  `bank_account_id` must belong to it; and deciding on another
  company's request is a **404 rather than an approval error**, which
  would otherwise leak that the request exists.

  Entity types reuse `App\Models\DocumentAttachment::ENTITY_TYPES`
  rather than a second list, mirroring how Python shares one
  `DocumentEntityType` enum between attachments and approvals.

  **STILL OUTSTANDING from the original request** (not conversion
  gaps -- `backend/` does not do these either): folding the existing
  Service Record approval onto this framework, and the approval screen
  itself.

### My Ops Dashboard (converted 2026-09-15)

- **My Ops Dashboard** (`app/routers/ops_dashboard.py` ->
  `App\Http\Controllers\Api\OpsDashboardController`, 13 dedicated
  tests): the personal freeform task board per staff member, plus the
  read-only rollup of real ERP work already assigned to them. Two new
  tables (`ops_task_categories`, `ops_tasks`); the rollup introduces no
  model of its own, being filtered reads of Job Orders and Software
  Tasks. Gated on `ops_dashboard`.

  VISIBILITY, confirmed 2026-09-11 and pinned: everyone sees their own
  board; Owner, Service Lead and Sales Manager may also view AND edit
  someone else's -- the same "manager-ish" role set already used for
  Service Record approval and Excess Review. Note the board is personal
  but still module-gated, so a staff member needs group authority on
  `ops_dashboard`, not merely an account.

  DETAILS CARRIED ACROSS, each pinned by a test:
  - A task's owner follows its CATEGORY, not the caller -- a manager
    creating a task on someone's board creates it owned by them.
  - `in_progress_count` deliberately counts WATCH as well: a watched
    item is live work, not an untouched one.
  - `clear_follow_up_staff` / `clear_follow_up_date` are the only way to
    REMOVE a follow-up, because omitting a field in a partial update
    means "leave it alone". Without them there would be no way to
    express the difference.
  - Only a real status CHANGE is audited; an edit that leaves the status
    where it was records nothing.
  - Archiving sets `is_active` false -- a task is never deleted.
  - The Job Order rollup sorts undated work LAST (`NULLS LAST`), so a
    job order with no due date does not sort above one due today.
  - Someone who is both programmer and tester on the same Software Task
    appears TWICE in the rollup, once per role, because they owe two
    different things on it.

  `owner_label`, `due_label` and `cadence_label` stay free text rather
  than links or dates: this is a personal working board, not a second
  scheduling system. The one structured follow-up exists so a task can
  actually chase someone.

### Company financial year + per-company mailbox (2026-09-15)

New scope from Dennis, not a conversion -- `backend/` has neither.

**FINANCIAL YEAR.** `companies.financial_year_start_month` (default 7).
A financial year is **labelled by the calendar year it ENDS in**, so
with a July start FY2027 is Jul 2026 - Jun 2027.
`SalesDashboardService::financialYearRange()` now takes a company and
derives the window from it, replacing the hardcoded calendar year it
carried as a KNOWN GAP ("no fiscal-year-start field exists anywhere in
the system"). `currentFinancialYear()` answers which year today falls
in by the same rule. Decision recorded at
docs/open-business-decisions.md #40.1.

**This changes existing figures**, deliberately: the Sales Dashboard's
Top 10 / Bottom 10 customer listings were reporting Jan-Dec for a
company that actually runs Jul-Jun. A January start reproduces the old
behaviour exactly, and the existing tests were re-pointed at an
explicit January-start company so they keep pinning the RANKING logic
rather than the window.

**PER-COMPANY MAILBOX.** Seven SMTP columns on `companies`, and
`App\Services\Mailer` now has two independent paths with **no fallback
in either direction**:

| | System mailbox | Company mailbox |
|---|---|---|
| Source | `.env` -> `config/websoft.php` | the company's own row |
| Used by | login OTP, password reset | Email Invoice / Quotation / PO / Receipt / Statement |
| Entry point | `send()` / `isConfigured()` | `sendAs()` / `isConfiguredFor()` |

Why no fallback: auth email runs BEFORE a company is chosen, so it
could not resolve a company mailbox even in principle; and a
customer-facing invoice sent from the system mailbox would come from
the wrong domain, failing SPF/DKIM at the receiving server -- so it
lands in spam or is rejected, and if it arrives it carries the wrong
brand. A clear "not configured for this company" error is strictly
better than a silently misdelivered invoice. The accepted cost is that
a newly created company has document email switched off until someone
fills its mailbox in.

**THIS IS A DELIBERATE DIVERGENCE FROM `backend/`**, which sends every
document email from the single system mailbox.

SECURITY: the SMTP password is `encrypted` at rest, listed in the
Company model's `$hidden` so it is never serialised (Company Setup
returns the model directly, so without that it would be handed back on
every read), and the audit trail records only `(set)` / `(none)` rather
than the credential. All three are pinned by tests.

A **Send test email** endpoint (`POST /companies/{id}/test-email`,
FULL) proves a mailbox works at setup time rather than letting it be
discovered broken on a real customer invoice; an SMTP failure there is
a 502, an unconfigured mailbox a 422.

13 dedicated tests.

### Journal Voucher CRUD + ledger exports (converted 2026-09-15)

- **Manual Journal Vouchers** (the `/ledger/vouchers*` half of
  `app/routers/ledger.py` -> `App\Http\Controllers\Api\LedgerController`,
  12 dedicated tests): list (filterable by voucher type and status),
  get, create, post, reverse, and CSV/Excel export -- closing the KNOWN
  GAP recorded when GL posting was converted.

  `App\Services\Ledger` already had `createJournalEntry()`,
  `postEntry()` and `reverseEntry()`, built for the GL posting + Bank
  module and used internally by `App\Services\Posting`. So this adds
  only the endpoints a person drives by hand; the posting rules stay in
  one place for automatic and manual entries alike.

  RULES PINNED BY TESTS: a voucher is a DRAFT unless `post` is asked
  for; an unbalanced voucher is refused whole, writing nothing;
  **reversal writes a mirror entry and leaves the original exactly as
  it was**, marked reversed, so the mistake and its correction both stay
  on record (CLAUDE.md forbids deleting financial records) and the
  endpoint returns the REVERSAL, as Python does; a reversal needs a
  reason; and raising a draft needs EDIT while committing it to the
  ledger needs FULL.

  HARDENING beyond Python: every line's `account_id` must belong to the
  caller's company. Python relies on the foreign key alone.

  **A REAL DIFFERENCE CAUGHT HERE, worth recording:** `total_debit`,
  `total_credit` and `is_balanced` are COMPUTED PROPERTIES over the
  lines in Python, not columns -- and `is_balanced` additionally
  requires the total to be **greater than zero**, so an all-zero
  voucher is not "balanced", it is empty. A first pass that read them
  as model attributes returned 0 for every draft and would have called
  an empty voucher balanced. They now go through the model's existing
  `totalDebit()`/`totalCredit()` Money accessors, with the non-zero
  condition reproduced.

- **CSV/Excel export** for the voucher list, the trial balance and the
  per-account GL ledger, using `App\Services\Exports`. This is the
  first instalment of the tracked CSV/Excel gap; the remaining modules
  still need theirs.

### Management Reporting -- Operations + Accounting Reports (converted 2026-09-15)

The whole of `app/routers/reports.py` (931 lines) and
`app/services/reports.py` (433 lines), minus the trial-balance endpoint
already converted with the General Ledger. `App\Services\ReportsService`
holds the queries; the endpoints split across two controllers on two
different Module Control keys, exactly as Python splits them.

- **Operations Reports** (`operations_reports` ->
  `App\Http\Controllers\Api\OperationsReportController`,
  `routes/api/operations_reports.php`, 14 tests): Contracts, Job
  Orders, Service Records and Company/Individual Product Usage, each as
  JSON plus a CSV and an XLSX export -- 12 endpoints.

  Gated at VIEW: these are read-only views over data other modules own,
  so a manager can be given the reports without being given the ability
  to change anything they report on.

  **The JSON and the export are deliberately different shapes**, as in
  Python: the JSON endpoints return the same record shapes their own
  screens already use (`ContractOut`, `JobOrderOut`, `ServiceRecordOut`
  -- hours and minutes as numbers), because
  `frontend/src/pages/OperationsReportsPage.tsx` types them
  `Contract[]`/`JobOrder[]`/`ServiceRecord[]` and does its own
  formatting; the export rows are the flattened, name-resolved,
  fixed-decimal rows a spreadsheet wants. A first pass that returned
  the export rows from the JSON endpoints would have broken every
  column on that screen.

  RULES PINNED BY TESTS: "expiring within N days" is forward-looking,
  so something that expired last week is excluded; a Service Record
  reaches its customer only through its Job Order; `overdue_only`
  excludes CLOSED and VOID; and every export writes an audit entry
  naming the report, the format and the row count.

  **A PYTHON QUIRK CARRIED ACROSS VERBATIM, flagged rather than
  corrected:** the Job Orders export's `overdue` column tests the
  status against the strings `"resolved"` and `"closed"`, but
  `JobOrderStatus` has no `resolved` state -- so only `closed` actually
  excludes a job order, and a VOID one still reads as overdue, even
  though the `overdue_only` *filter* two functions away excludes VOID
  correctly. The two therefore disagree in Python today. Preserved so
  the backends match; worth raising with Dennis as a `backend/` bug.

  Also fixed here: the staff-name lookup resolves the ids that actually
  appear in a report's rows rather than filtering the `users` table by
  its own company column -- a staff member reaches a company through
  `UserCompanyAccess`, so the company-scoped version blanked out the
  name of anyone whose home company differed. Python loads every user
  for the same reason.

- **Accounting Reports** (`accounting_reports` ->
  `App\Http\Controllers\Api\ReportController`, `routes/api/reports.php`,
  22 tests): AR aging, AP aging, the trial balance, the GST return,
  Sales GP, and Commission with its rate setting -- 20 endpoints.

  **AR and AP aging are not re-implemented.** Python's `reports.py`
  copies both bucketing loops out of the AR and AP routers; here both
  screens call the same `App\Services\AccountsReceivableService::agingRows`
  / `App\Services\PayablesService::agingRows`, so the Accounting
  Reports screen and the AR/AP screens cannot drift -- which is the
  property Python's own comment says the copy exists to preserve. A
  test asserts the two endpoints return byte-identical JSON.

  NEW TABLE: `commission_settings` (one row per company, keyed by the
  company itself so it can never hold two competing rates), mirroring
  Python's model. The rate starts at zero and is never invented -- see
  docs/open-business-decisions.md #34 -- so a Commission report run
  before an administrator sets a rate reports zero, not a guess.
  Reading the rate needs VIEW; setting it needs FULL, and every change
  is audited with the old and new rate.

  RULES PINNED BY TESTS: commission is rate% x gross profit on the
  share of an invoice a receipt actually settled, and the allocation is
  converted to its share of NET revenue first, so **GST never inflates
  commission**; an invoice whose contract names no salesperson is
  reported against "Unassigned" rather than dropped; a receipt outside
  the period is ignored; input tax is one `PURCHASES` total because a
  supplier bill carries no tax code of its own; and a null `cost_sgd`
  on Sales GP reads as zero cost (100% GP) with `has_cost_basis: false`
  marking the row, never as a silently excluded invoice.

  `Invoice::gp_percent`, a computed property on Python's model that
  `backend-php`'s Invoice has no equivalent of, lives in
  `ReportsService` beside the only two reports that need it rather than
  widening the model for one report family.

  STILL A KNOWN GAP: Commission *Management* (payouts) is a separate,
  deferred module -- this converts only the report and its rate
  setting, which is all `reports.py` carries.

### Commission Payouts (converted 2026-09-15)

- **Commission Payouts** (`app/routers/commissions.py` +
  `app/services/commissions.py` + `app/models/commissions.py` ->
  `App\Http\Controllers\Api\CommissionPayoutController`,
  `App\Services\CommissionService`, `App\Models\CommissionPayout`,
  `routes/api/commissions.php`, 17 tests): the last router in
  `backend/app/routers/` without a PHP equivalent. Generate a month's
  DRAFT payouts, submit, approve, reject, pay, cancel, the two
  month-wide batch actions, and the clawback an AR write-off raises
  (open-business-decisions 6.3/6.4/6.5).

  Gated on `accounting_reports`, the key Python uses and the one
  `frontend/src/components/Layout.tsx` already tests for this screen.
  The authority split is Python's: VIEW reads, EDIT submits, FULL
  generates/approves/rejects/pays/cancels -- so preparing a batch and
  approving it are separable duties.

  RULES PINNED BY TESTS: a month cannot be generated twice (regenerating
  would silently double what is owed -- the existing batch must be
  cancelled first, which leaves the cancellation on record); a payout
  reaches PAID only through PENDING_APPROVAL and APPROVED, each wrong-state
  transition refused with a 422; a rejection returns it to DRAFT with the
  reason appended to its notes; **a PAID payout can never be cancelled**,
  and the row survives; the batch actions touch only their own month; a
  clawback is a NEW NEGATIVE ROW, auto-approved, never an edit to the
  earning it reverses (CLAUDE.md forbids deleting financial records); and
  a generated batch sums to exactly what the Commission report reports for
  the same month -- both call the same `CommissionService::commissionFor()`
  rather than each carrying the arithmetic.

  **A REAL BUG FOUND IN `backend/`, worth raising with Dennis:**
  `app/services/commissions.py` allocates payout numbers with
  `next_document_number(db, company_id, "CP")` -- three positional
  arguments against a signature whose parameters after `db` are
  KEYWORD-ONLY (`def next_document_number(db, *, company_id, doc_kind,
  on=None)`). That raises `TypeError` before any number is allocated, so
  **`generate_payouts` and `create_clawback` cannot run in `backend/` at
  all** -- Commission Payouts has never worked there. It is worse than a
  dead screen: `accounts_receivable.py`'s write-off endpoint calls
  `create_clawback`, so **writing off an invoice would fail the moment a
  non-zero commission rate is set**. A zero rate returns early, which is
  why nobody has hit it yet. The PHP version calls
  `App\Services\Numbering` properly.

  Also corrected here: that same call passes `"CP"` -- a PREFIX -- where
  the parameter is a document KIND, which would store `doc_kind = "CP"`
  in `document_sequences` and only render as "CP" via the 3-letter
  fallback. The PHP version registers `'commission_payout' => 'CP'` in
  `Numbering::PREFIXES`, so Document Control can customise the format
  like every other document.

  This also closes the last KNOWN GAP recorded against Accounts
  Receivable: its write-off endpoint now raises the commission clawback,
  as Python's does.

### CSV/Excel export on every list screen (converted 2026-09-15)

The last export format missing from `backend-php` -- the `.docx`/PDF/
Email half landed with the document generation stack, and
`App\Services\Exports` (the port of `exports.py`) landed with Tax
Types. This wires that helper into the eighteen list screens that were
still without it, so **every `export.csv`/`export.xlsx` route in
`backend/app/routers/` now has a PHP equivalent** (89 export routes in
`backend-php` against Python's 79 -- the surplus is this project's own
report screens).

Wired up: Chart of Accounts, Bank Accounts, Catalog, Company/
Individuals, Groups, Users, Contracts, Job Orders, Service Records,
Excess Usage, Invoices, Quotations, AR aging, AR receipts, AP aging,
AP bills, AP payment vouchers and Purchase Orders. Field lists and row
shapes are Python's, column for column.

- **`App\Http\Controllers\Api\Concerns\SendsExports`** -- the two
  download responses, in one trait rather than repeated per
  controller, the same way `SendsDocuments` already holds the .docx
  one. The two report controllers moved onto it too.

- **The export always returns WHAT IS ON SCREEN.** Each controller's
  list filter is extracted into one `filtered()` the index endpoint
  and both exports share, so an export can never quietly ignore a
  filter the screen applied. (Python's job-order export helper takes
  four of that screen's five filters and drops `job_order_type`, so
  there a filtered screen CAN export rows it is not showing; sharing
  one filter here means it cannot. This is a deliberate divergence,
  and the only behavioural one in this pass.) Every test asserts the
  excluded row is absent, not merely that the included one is present
  -- a "did it download" assertion would pass against an export that
  ignored every filter.

- **A REAL FIDELITY BUG FIXED IN `App\Services\Exports`:** its CSV
  writer used PHP's `fputcsv()`, which quotes any field containing a
  SPACE and ends records with `\n`. Python's `csv.writer` defaults
  quote only on a delimiter, quote or line break (QUOTE_MINIMAL) and
  end records with `\r\n`. So every converted export was emitting
  `SR,"Standard Rated",9.00` where Python emits
  `SR,Standard Rated,9.00`. Both open identically in Excel, which is
  why it went unnoticed, but it is exactly the drift this conversion
  exists to avoid. Replaced with a small writer matching Python's
  defaults; the one test that had encoded the PHP spelling was
  corrected (it was asserting the bug).

**Followed up the same day:** `App\Services\ExportService` -- the
older writer whose "Excel" was an HTML `<table>` served with a `.xls`
name -- is **retired**. The two report screens built directly in
`backend-php` (Contract Operation Report, Sales Dashboard
drill-downs) now go through `Exports` as well, via a second pair of
entry points, `tableToCsv`/`tableToExcel`, that take a header row of
human labels plus positional rows. Those screens have no Python
counterpart and label their columns for a reader ("Contract Number",
not `contract_number`), so the labels stay; only the writer changes.
It was left out of the conversion pass itself on purpose, because it
changes what those screens download: five endpoints move from
`/export.xls` to `/export.xlsx` (the old paths now 404) and the file
becomes a genuine OOXML package. Excel had been warning that the old
file's format and its extension disagreed, because they did.
docs/ui-guidelines.md section 2 had specified `export.xlsx` and
"never write a CSV/XLSX writer by hand" from the start, so this
brings those two screens into line with the project's own convention
rather than setting a new one.

### Product-based Sales Invoicing (2026-09-15, NOT a conversion)

New scope, built directly in `backend-php/` + `frontend/` --
`backend/` (Python) has no equivalent. It delivers what Dennis asked
for alongside the stock-costing work: a Sales Invoice that picks
stock, refuses when the quantity is insufficient, and deducts at
weighted average cost. That had been blocked because `invoices` was
header-only: one amount, no lines, no product selection, every
invoice auto-issued from another module's decision.

- **`invoice_lines`** (new table) + `POST /invoices`
  (`BillingService::issueSalesInvoice`), and a "Raise Sales Invoice"
  form on the Invoices page showing on-hand quantity per line.

- **Lines are OPTIONAL**, decided rather than assumed. Existing
  header-only invoices keep working with no backfill, and the
  auto-issued ones still issue a single amount. Nothing that reads
  `invoices` has to learn about the new table to stay correct.

- **The stock rules are not re-implemented.** Each stock line goes
  through `App\Services\InventoryService::deductStock()` -- the same
  call a Goods Issue Note makes -- so INV-002 holds by construction:
  an insufficient quantity refuses the whole invoice, on-hand
  quantity can never go negative, and units leave at the item's
  weighted average without re-weighting it.

- **No draft state.** BILL-002 already says invoices issue directly,
  and a draft would leave stock reserved with nothing to release it.
  The whole act is one transaction, so a refusal on line 2 unwinds
  line 1's deduction with it -- tested explicitly.

- **Cost is recorded as at issue**, not looked up later: the item's
  average moves with every later receipt, and a past invoice's gross
  profit must not move with it. That also gives these invoices a real
  `cost_sgd`, so Sales GP reports a measured margin rather than the
  stand-in it shows where no cost is known. A service-only invoice
  keeps a null cost -- unknown is not zero.

- Posts to the GL on issue like every other invoice, mapped to the
  seeded **4030 Hardware sales** account rather than a newly invented
  one. **Deliberately NOT posting a COGS/inventory journal:** whether
  stock movements post to the GL is a still-open question, and a
  conversion-adjacent build is not the place to settle it.

15 tests, one per rule Dennis named plus cross-company scoping (a
valid uuid from another company satisfies the foreign key, so the
endpoint checks ownership itself). Verified end to end against the
running app as well: invoice issued, GST applied, GL posted, stock
10 -> 6 at average cost, and an over-issue refused with the level
unchanged.

## Not yet converted (pending, in rough priority order)

Everything below still only exists in `backend/` (Python). Each is a
phase of its own, following the same pattern as CompanyIndividual
Management above -- model(s) + migration(s) + controller + routes +
smoke test:

1. **Nothing.** Every router in `backend/app/routers/` now has a PHP
   equivalent. What remains below is retrofitting and follow-ups, not
   conversion.
2. Consolidating the two export writers. Every Python export route is
   converted (see "CSV/Excel export on every list screen" above), and
   they all use `App\Services\Exports` -- real CSV, real .xlsx. The
   older `App\Services\ExportService`, whose "Excel" is an HTML table
   with a `.xls` name, still serves the report screens built directly
   in `backend-php/` (Contract Operation Report, Sales Dashboard
   drill-downs). Moving those onto the real writer changes what those
   screens download, so it wants saying out loud rather than doing
   silently.
3. Follow-ups raised by the Inventory/Stock conversion, each small and
   waiting on a decision rather than on code: moving the four stock
   documents off their count-based numbering onto
   `App\Services\Numbering` (needs Dennis to pick a format, since it
   changes document numbers users already see), and deciding whether
   any stock document should post to the General Ledger (today none
   does, in either backend -- see that module's KNOWN GAP above).

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
