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
Company Setup, Users/Staff Master) and CompanyIndividual Management
(Customer/Supplier master, Contacts, Branches, Relationships, PDPA
consent/archive). `backend/` (Python) is untouched and keeps running
as the system of record until each remaining module (Catalog,
Contracts, Job Orders, Service Records, Excess Usage, Billing, AR, and
everything else) is converted, module by module, the same way.

No other business area has application code yet. **Commission
Management, further Service Record business-rule decisions (open item
9.1), and Odoo migration planning are deferred for now at Dennis's
request** — see [docs/open-business-decisions.md](docs/open-business-decisions.md)
— and will be revisited once Service Operations and related areas are
finalized. Further modules are otherwise built incrementally, resolving
open decisions as each area is reached rather than blocking all
development on them upfront — pragmatic implementation defaults taken in
the meantime are called out in code comments, not silently assumed.
