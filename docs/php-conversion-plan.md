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
  `app/routers/company_individuals.py` →
  `App\Models\CompanyIndividual`/`Contact`/`Branch`/
  `CompanyIndividualGroup`, `App\Http\Controllers\Api\CompanyIndividualController`):
  full customer/supplier master CRUD, deactivate/reactivate,
  archive/unarchive (soft-archive-in-place, never deleted), PDPA
  consent + signed-agreement-document upload (server-stamped
  timestamp, audit-logged without the file content), audit-log
  read-back, and nested Contacts/Branches CRUD.

Verified end-to-end (migrate → seed → boot → curl): login issuing a
`purpose=access` JWT, `/auth/me`, RBAC 403 for a user with no Group,
customer create/list, nested contact create, PDPA consent, archive,
and the resulting audit trail entries (including IP/User-Agent
capture) -- see `database/seeders/DatabaseSeeder.php` for the demo
dataset used.

**Not yet converted from `app/routers/company_individuals.py`:**
CSV/Excel export endpoints, the Customer Helpdesk Portal access
sub-resource (depends on the Portal module below), and
company/individual Relationships.

## Not yet converted (pending, in rough priority order)

Everything below still only exists in `backend/` (Python). Each is a
phase of its own, following the same pattern as CompanyIndividual
Management above -- model(s) + migration(s) + controller + routes +
smoke test:

1. **Users / Staff Master** (`app/routers/users.py`) -- needed before
   Group/Company admin can be driven from the UI instead of a seeder.
2. **Catalog** (`app/models/catalog.py`, `app/routers/catalog.py`) --
   Product/Service master, referenced by Contracts and Quotations.
3. **Service Contracts** (`app/models/contracts.py`,
   `app/services/contracts.py`, `app/routers/contracts.py`) -- SRV-001,
   002, 003, 005, 010, 012, 016, 017, 018.
4. **Job Orders** (`app/models/job_orders.py`, `app/routers/job_orders.py`).
5. **Service Records** (`app/models/service_records.py`,
   `app/services/service_records.py`, `app/routers/service_records.py`)
   -- SRV-007 (hour rounding), SRV-015 (submission timeframe).
6. **Excess Usage** (`app/services/excess_usage.py`,
   `app/routers/excess_usage.py`) -- SRV-004, 008, 011, 013 (Nico/
   Cherish review, blended-rate billing, treatment categories); the
   business logic here has the most riding on getting the rounding/
   balance-never-negative arithmetic exactly right, so it should get a
   dedicated test suite before being trusted, not just a smoke test.
7. **Billing / Invoicing** (`app/services/billing.py`,
   `app/routers/billing.py`) -- BILL-001..006.
8. **Accounts Receivable** (`app/services/accounts_receivable.py`,
   `app/routers/accounts_receivable.py`) -- AR-001..003.
9. Everything else in `backend/app/routers/` not listed above
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
