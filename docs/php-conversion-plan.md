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
  **Stub, not a silent gap:** `computeBudgetOverrun()` always reports 0
  consumed minutes -- the real figure sums approved Service Record
  minutes, and Service Records isn't converted yet; see that method's
  docblock. Nothing in this backend ever auto-closes a Job Order yet
  either (that's driven by Service Record approval) -- VOID and the
  other manual states work fully.
  **Not yet converted:** CSV/Excel export.

Verified end-to-end for both modules against the real React frontend
(screenshots in the PR/commit history): contract creation blocked
below the 10-hour minimum with the SRV-002 message, activation,
renewal (both the seamless-backdated case and the SRV-018
force-start-date case), the contract detail page showing the exact
$300.00/hr blended rate, and a PROJECT-type Job Order with all 5
milestones auto-created in order. The only 404s seen were for
not-yet-converted modules (Announcements, Dashboard, Excess Usage,
Invoices/Billing, Documents, Service Records) -- none from Contracts
or Job Orders' own endpoints.

## Not yet converted (pending, in rough priority order)

Everything below still only exists in `backend/` (Python). Each is a
phase of its own, following the same pattern as CompanyIndividual
Management above -- model(s) + migration(s) + controller + routes +
smoke test:

1. **Service Records** (`app/models/service_records.py`,
   `app/services/service_records.py`, `app/routers/service_records.py`)
   -- SRV-007 (hour rounding), SRV-015 (submission timeframe). Next in
   line: `Contract::deductMinutes()`/`ContractService` are already
   ported and waiting for this module's approval flow to call them.
2. **Excess Usage** (`app/services/excess_usage.py`,
   `app/routers/excess_usage.py`) -- SRV-004, 008, 011, 013 (Nico/
   Cherish review, blended-rate billing, treatment categories); the
   business logic here has the most riding on getting the rounding/
   balance-never-negative arithmetic exactly right, so it should get a
   dedicated test suite before being trusted, not just a smoke test.
3. **Billing / Invoicing** (`app/services/billing.py`,
   `app/routers/billing.py`) -- BILL-001..006. Bumped up in priority:
   Contract activation's known gap (see above) depends on this.
4. **Accounts Receivable** (`app/services/accounts_receivable.py`,
   `app/routers/accounts_receivable.py`) -- AR-001..003.
5. Everything else in `backend/app/routers/` not listed above
   (Quotations, Incidents, Accounts Payable/Purchasing, Inventory/
   Stock, GL posting + Bank step, Reporting/dashboards, Event Logs,
   Document Control, Periods, Announcements, Software Tasks, Ops
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
