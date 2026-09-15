# System Architecture

Placeholder document.

This file will contain the detailed system architecture for Websoft Service ERP Solution,
including the application stack, module boundaries, database design
conventions, authentication/authorization approach, and integration
strategy — consistent with the development rules in the root
[CLAUDE.md](../CLAUDE.md) (modular architecture, PostgreSQL, audit trails,
migrations, etc.).

The high-level stack and architectural direction have now been approved (see
[CLAUDE.md](../CLAUDE.md)). This document records that preliminary
architecture at a conceptual level. It does **not** yet define database
tables, API endpoints, module boundaries in detail, or any other
implementation-level design — those will be developed in follow-up work
before application coding begins.

## Preliminary Architecture

### Frontend — React + TypeScript

- Single-page application built with React and TypeScript.
- Expected to be organized by business module (e.g. CRM, Billing,
  Helpdesk) to keep it maintainable as more modules are added, in line
  with the "modular and maintainable architecture" development rule.
- Detailed component structure, state management approach, and UI library
  choices are not yet decided.

### Backend — PHP / Laravel

- PHP 8.4 / Laravel 11 backend (`backend-php/`).
- Expected to be organized into modules/services aligned with the business
  areas in CLAUDE.md, keeping business logic separate from the API/HTTP
  layer per the development rules.
- Detailed service boundaries, folder structure, and internal layering are
  not yet decided.

### Database — PostgreSQL

- PostgreSQL is the system of record for all business and financial data.
- Schema changes will go through migrations (per the development rules) —
  the specific migration tooling has not yet been chosen.
- No tables or schemas have been designed yet.
- Multi-company support (future) and archival of historical Odoo data
  (future) are expected to influence schema design, but no specific
  approach (e.g. shared schema with company_id, separate schemas, etc.)
  has been decided yet.

### Authentication and role-based access

- The system requires authentication and role-based access control (RBAC)
  for all users.
- Expected to cover distinct roles across business areas (e.g. finance,
  sales, service operations) with different permission levels.
- Specific auth mechanism (e.g. session-based, JWT, SSO/OAuth provider),
  identity provider, and the detailed role/permission model are not yet
  decided.

### Audit logging

- Financial and operational transactions must produce audit trails (per
  the development rules) — who did what, when, and (where relevant) the
  before/after state.
- Records are never permanently deleted; soft-delete/archival is used
  where appropriate instead.
- The specific audit logging mechanism (e.g. application-level audit
  tables, database-level triggers, change-data-capture) has not yet been
  decided.

### File / document storage

- The system will need to store documents/files associated with business
  records (e.g. contracts, invoices, attachments).
- Storage location/technology (e.g. object storage such as S3-compatible
  storage vs. local/VPS disk) has not yet been decided, but the choice
  should keep the architecture portable across cloud providers per the
  approved deployment direction.

### Background jobs

- Some work is expected to run outside the request/response cycle (e.g.
  scheduled reports, data synchronization, sending notifications, future
  Odoo data migration jobs).
- A background job/task queue mechanism will be needed; the specific
  technology has not yet been decided.

### API architecture

- The Laravel backend exposes APIs consumed by the React frontend and
  potentially other future integrations.
- Expected to be organized per business module, versioned appropriately,
  and validated on the backend (per the development rules) in addition to
  frontend validation.
- Whether the API is a single monolithic service or split further
  has not yet been decided; a modular monolith is the likely starting
  point given the phased Odoo replacement strategy, but this is not yet
  finalized.

### Integration architecture

- The system will need to integrate with external systems over time,
  including (at minimum):
  - Odoo, during the phased replacement/parallel-run period.
  - InvoiceNow / Peppol, for e-invoicing (future).
  - Other services as identified (e.g. payment, banking, tax reporting).
- Integrations should be designed so individual integrations can be added
  or retired independently as modules are phased in, without requiring
  changes to unrelated parts of the system.
- Specific integration patterns/technology have not yet been decided.

### Deployment architecture

- Initial deployment target is a cloud/VPS environment.
- The architecture should avoid unnecessary coupling to any one
  infrastructure provider, so that it remains portable to AWS, Azure, or
  other infrastructure later.
- Specific deployment tooling (e.g. containerization, orchestration,
  CI/CD pipeline) has not yet been decided.

### Backup and disaster recovery

- Data backup and recovery is a required operational capability.
- Given the financial and operational nature of the data, backups must
  support point-in-time recovery of the PostgreSQL database at minimum.
- Specific backup frequency, retention policy, and disaster recovery
  procedures have not yet been decided.

### Future: Odoo migration

- Odoo will be replaced in phases, module by module, with a parallel-run
  period for each module rather than a big-bang cutover.
- Important historical data will eventually be migrated from Odoo into
  Websoft Service ERP Solution; some historical data may be migrated into an archival
  form rather than as fully operational records.
- A dedicated migration approach (e.g. ETL scripts, one-time import jobs,
  ongoing sync during parallel-run) will need to be designed once the
  phasing order and per-module requirements are known.

### Future: InvoiceNow integration

- Singapore's InvoiceNow / Peppol e-invoicing network must eventually be
  supported for invoicing/billing.
- This will require a Peppol Access Point integration (direct or via a
  third-party provider) — not yet selected.
- Detailed design is deferred until the Billing/Accounts Receivable
  business requirements are defined.

### Future: AI integration

- "AI Assistant" is listed as an initial candidate business area in
  CLAUDE.md.
- Any AI integration will need to respect PDPA and the system's audit
  trail and access-control requirements (e.g. controlling what business
  data an AI feature can access or act on).
- No specific AI capabilities, providers, or integration points have been
  decided yet.

## Detailed Conceptual Architecture

This section builds on the Preliminary Architecture above now that the
module structure ([module-map.md](module-map.md)) and workflows
([workflows.md](workflows.md)) have been documented. It goes one level
deeper conceptually — still without selecting specific libraries,
frameworks, or infrastructure products, except where a specific choice
carries a strong architectural reason (noted explicitly where used). It
remains subject to the open business decisions tracked in
[open-business-decisions.md](open-business-decisions.md), several of which
will influence implementation detail (e.g. multi-company data scoping,
approval workflows) without changing the shape described here.

### Frontend architecture

- A single React + TypeScript application, organized by business module
  (mirroring [module-map.md](module-map.md)) rather than by technical
  layer, so a module can be developed, tested, and eventually
  parallel-run against its Odoo equivalent with minimal cross-module
  coupling.
- Cross-module shared concerns (authentication state, RBAC-driven
  navigation/visibility, shared UI components, notifications) live in a
  common layer used by every module's UI, so each module doesn't
  reinvent them.
- The frontend treats the backend as the sole source of truth and sole
  place where business rules are enforced (per the "validate on both
  frontend and backend" development rule) — frontend validation is a
  usability layer, not a security boundary.
- Because Reporting and the AI Assistant are cross-module by nature, they
  are expected to consume the same per-module APIs as the modules'
  own screens, rather than a separate privileged path.

### Backend architecture

**Backend language: PHP 8.4 / Laravel 11** (`backend-php/`). The
backend was converted from Python/FastAPI between 2026-09-14 and
2026-09-15, for a team/hosting constraint rather than a technical
problem with FastAPI — see [php-conversion-plan.md](php-conversion-plan.md)
for the reason, approach and findings. It was a backend-language
change only: the modular-monolith structure, module boundaries,
PostgreSQL schema design and API contract described below carried
across unchanged, so the React frontend was unaffected. The Python
backend was retired and removed from the tree on 2026-09-15.

- A backend structured as a **modular monolith**: one
  deployable service, internally organized into modules that mirror
  [module-map.md](module-map.md), each owning its own data access and
  business logic and exposing it to other modules through explicit
  interfaces rather than direct cross-module database access.
- Rationale for a modular monolith as the starting point (a specific
  architectural choice, not a library choice): the phased,
  module-by-module Odoo replacement strategy needs modules that can be
  built, tested, and cut over independently, but a full microservices
  split would add operational complexity (service discovery, distributed
  transactions, multiple deployments) that isn't justified yet for a
  single-company, single-team deployment. Splitting a well-bounded module
  out into its own service later remains possible if a specific module
  (e.g. Integrations, or a high-load Reporting/AI layer) needs to scale
  or deploy independently.
- Business logic is kept in a layer separate from the API/HTTP layer
  (per the development rules), so the same business logic can be reused
  by the API, background jobs, and integration/migration code without
  duplicating rules.
- Each module's business logic is the only code allowed to enforce that
  module's business rules (e.g. only Service Contracts logic decides how
  contract hours are deducted) — this keeps the eventual implementation
  of the decisions in [open-business-decisions.md](open-business-decisions.md)
  localized to one place per rule.
- **Concurrent multi-user access, CONFIRMED and already true of the
  implementation (2026-09-12).** Authentication is stateless (a JWT
  bearer token per request, no server-side session tied to a single
  connection), and every request opens and closes its own database
  session (`app/core/database.py`'s `get_db()`) rather than sharing one
  across requests -- so any number of staff can be signed in and working
  at the same time with no architectural limit, and PostgreSQL itself
  handles the concurrent reads/writes. On the frontend, the login token
  lives in the browser's `localStorage`, so multiple tabs/windows of the
  same signed-in user share one session automatically; the one thing
  this doesn't support is two *different* users signed in in two tabs of
  the *same* browser at once (the second login overwrites the shared
  token) -- different browsers, or a private/incognito window alongside
  a regular one, work fine for that.
- **Login security, CONFIRMED and built (2026-09-12; see
  [open-business-decisions.md](open-business-decisions.md) #27).**
  Password complexity (min 8 chars, at least one letter and one
  number), a forced password change on a staff member's first sign-in
  (and after any admin-initiated reset), and an email one-time code as
  a second factor once SMTP is configured. Every JWT this system
  issues carries a `purpose` claim (`"access"` for a real bearer
  token, `"password_change"` / `"otp"` for the two short-lived
  intermediate tokens the login sequence hands back), so an
  intermediate token can never be replayed against a protected
  endpoint even if it leaked. WhatsApp OTP is deferred -- see
  [planned-work.md](planned-work.md) #7.

### PostgreSQL architecture

- PostgreSQL is the single system of record; each backend module owns a
  distinct set of tables (once designed) rather than modules sharing
  tables directly, mirroring the backend's module boundaries.
- All schema changes go through migrations, applied in a controlled,
  repeatable way across environments (development, testing, production).
- **Multi-company (implemented 2026-09-10):** company-scoped data lives
  in shared tables with a `company_id` column, rather than a schema or
  database per company. Every company-owned record carries it —
  customers, contracts, job orders, service records, excess usage,
  invoices, groups, users and audit entries — and every query filters on
  the signed-in user's active company. See the Multi-company section
  below for how "active company" works.
- Financial and operational records are never hard-deleted; the schema is
  expected to support soft-delete/archival (e.g. a status or archived
  flag, and/or an archive strategy for old operational data) consistent
  with the development rules, rather than relying on application code
  alone to prevent deletion.
- Historical data migrated from Odoo (future) is expected to coexist with
  natively-created data in the same schema, distinguished by origin/
  reference metadata (e.g. a source-system identifier) rather than a
  separate parallel schema, so that Reporting and other modules can treat
  it uniformly — subject to the still-open decision on which historical
  data is "important" vs. archival-only.

### Authentication

- A single authentication mechanism is used across the whole system (all
  modules, including Reporting and the AI Assistant) rather than
  per-module logins.
- Authentication is a Core / Administration concern; no other module
  implements its own login or session handling.
- The specific mechanism (session-based vs. token-based, and whether an
  external identity provider/SSO is used) is not yet decided — this is
  an implementation choice within Core / Administration, not a
  cross-cutting architectural constraint, provided whatever is chosen
  supports the RBAC model below.

### RBAC (role-based access control) — Group Authority

- Permissions are defined and enforced centrally by Core / Administration
  and consulted by every module — modules do not define their own
  independent permission systems.
- **Confirmed design (2026-09-10, resolves open item 8.4): "Group
  Authority", split across two independent axes:**
  - **Group Authority** — general per-module access. Every staff member
    (Staff Master) belongs to **exactly one Group**. A Group has an access
    level per module: **None / View / Edit / Full** (None: module hidden;
    View: read-only; Edit: create/update within the module; Full: also its
    sensitive lifecycle actions, e.g. activating/renewing a contract,
    approving a service record, deciding excess usage, toggling module
    licensing). This is *which modules/functions a user's Group allows*.
  - **Named-responsibility roles** — a small fixed `role` field
    (owner/service_lead/sales_manager/support_engineer/finance), used
    *only* for the specific business rules already confirmed elsewhere
    (e.g. SRV-004/SRV-011: Nico, or Cherish as backup, decides excess
    usage). A route that implements such a rule enforces the Group
    Authority access level *and* the named role — both must pass.
  - The owner role always has Full Group Authority on every module
    regardless of its actual Group assignment, so the owner can never be
    locked out by a misconfigured Group.
  - Record-level ownership within a module (e.g. only the customers a
    given rep owns) is a separate, still-open question — see the
    ownership items in
    [open-business-decisions.md](open-business-decisions.md) (§8.1–8.3).
  - Implementation: `Group` + `GroupModuleAuthority` (per-module access
    level per Group) plus `User.group_id`, enforced backend-side via a
    `Authority::requireModuleAccess($user, $moduleKey, $minLevel)` check
    at the top of each controller action — see
    `backend-php/app/Models/Group.php`, `GroupModuleAuthority.php` and
    `backend-php/app/Services/Authority.php`.
- RBAC must be enforced in the backend (the authority), with the frontend
  using the same role/permission data only to shape what it displays —
  consistent with backend validation being the security boundary.

### Multi-company (Company Setup)

Confirmed and implemented 2026-09-10. CLAUDE.md's approved architecture
anticipated multiple entities; this is how it works in practice.

- **Company Setup** (`app/routers/companies.py`, Core / Administration)
  owns the company record: name, country, currency, timezone, and the
  **logo** shown at the top-left of the app. Companies are created and
  edited here; nothing is hard-deleted (an `is_active` flag retires one).
- The logo is stored inline on the Company record as an image data URI.
  Business *documents* still belong in external file storage (see File /
  document storage below) — a logo is small UI branding configuration
  needed on every page render, so it is deliberately treated differently
  rather than standing up file storage infrastructure for it. Uploads are
  capped (~300 KB) and validated as images on both frontend and backend.
- **Active company.** `User.company_id` is the company a staff member is
  *currently working in*. Everything they see is filtered by it, so
  switching company re-scopes the entire application in one write, with
  no per-module special-casing.
- **Allowed companies.** `UserCompanyAccess` lists the companies a staff
  member may switch to. Most staff have exactly one and never see the
  switcher; someone who runs more than one entity gets a row per company
  and a company selector in the top bar. The owner role can reach any
  company. Attempting to switch into a company you have no access to is
  rejected, and switching is recorded in Event Logs.
- **Scoping is enforced in queries, not just in the UI.** Every
  company-owned table carries `company_id` and every list query, summary
  count, and by-id lookup filters on the active company — a record
  belonging to another company reads as "not found" rather than being
  returned. (Job orders, service records, excess usage and invoices
  originally reached their company only indirectly through the customer
  or contract; they now carry `company_id` directly, which both closes
  that gap and keeps the filters simple.)
- Each company has its **own module mix** (see Module Control below), its
  own Groups and Group Authority matrix, its own staff, and its own Event
  Logs — so two entities can run the system very differently.
- **Group per company (confirmed by Dennis, 2026-09-10).** A staff member
  holds one Group **per company** they work in, not one globally. The
  assignment lives on the access row (`UserCompanyAccess.group_id`), so
  the same person can be Owner / Admin in one entity and Finance in
  another, with module access resolved against whichever company they
  are currently in. Managed in Staff Master → Company access & Groups.
  A Group can only ever be assigned within its own company, and a new
  company is created with one "Owner / Admin" group so there is always
  something to assign.

### Module Control / multi-company licensing

- Core / Administration also owns a lightweight **module catalog**: a
  fixed list of the business-area modules in
  [module-map.md](module-map.md), each with an `is_built` flag (does it
  have application code yet), and a **per-company enablement record**
  (enabled/disabled, license type) rather than a single global on/off
  switch.
- This exists specifically so the multi-company future in CLAUDE.md's
  approved architecture has somewhere to hang "which modules does this
  company have" from the outset, rather than retrofitting it once a
  second company exists.
- It is deliberately minimal: a flag and a license-type label, not seat
  counts, expiry dates, or billing integration — those would be a future
  decision, not assumed now.
- Only the owner role can change module enablement; every toggle is
  audit-logged like any other administrative action.

### Audit logging -- Event Logs module

- Audit logging is a cross-cutting capability provided centrally (by
  Core / Administration, `app/services/audit.py`) and used by every
  module that creates, edits, or deletes a record or generates a report,
  rather than each module inventing its own logging.
- **Confirmed design (2026-09-10):** the audit trail is exposed through
  its own gated master, **Event Logs** (module key `event_logs`,
  `app/routers/event_logs.py`) -- a Group Authority module in its own
  right (not folded into general `core_administration` access), so who
  can see the system-wide audit trail is a deliberate, separately
  configurable decision. Default seed: Owner / Admin only.
- Each entry captures: the action (created/updated/deactivated/
  deleted/etc.), the entity type + id it affected, **who** (both the
  acting user's id and a point-in-time snapshot of their name, so the
  trail still reads correctly if that person's name later changes or
  their account is deactivated), **when**, and for edits, a field-level
  **old value -> new value** diff (e.g. `role: support_engineer ->
  service_lead`) rather than just a free-text description -- sufficient
  to reconstruct the history of a record without altering the original
  (consistent with "never permanently delete important business or
  financial records"). A password reset never records the password
  itself.
- Every entry also captures the acting device: client **IP address**,
  browser **User-Agent**, and a **device id** -- a random identifier the
  frontend generates once and persists in that browser's local storage
  (`frontend/src/lib/deviceId.ts`), sent as an `X-Device-Id` header on
  every request. A web browser cannot expose a real hardware/PC serial
  number (blocked for security/privacy reasons at the browser level), so
  this identifies "this browser on this machine" rather than the
  physical device -- the closest practical equivalent without installing
  a native agent on staff machines, which has not been requested.
- Request-scoped context (IP/User-Agent/device id) is captured once per
  request by a middleware into request-scoped state, and
  `Audit::record()` reads it back automatically -- so business-logic
  functions deep in the call stack (contract activation, service record
  approval, etc.) don't each need a `Request` object threaded through
  just to log an action.
- Audit logging sits close to where changes are committed (the same
  backend business-logic/router layer that writes the change) so it
  cannot be bypassed by calling a data-access path directly.
- Generating a report or export is itself an auditable event (action
  `report_generated`, entity type `report`) -- e.g. exporting the Event
  Logs themselves as CSV logs who ran the export, with which filters,
  and how many rows it returned (`audit.record_report_generated()`).
  Any future report/export feature should log through the same helper.
- Reporting and the AI Assistant are expected to be read-only consumers
  of business data; whether their own read/query activity is itself
  audit-logged (relevant for PDPA) is an open question to track in
  [open-business-decisions.md](open-business-decisions.md) once those
  modules are designed in detail.
- The confirmed Service Operations rules give a concrete example of this
  requirement in practice: an excess-usage treatment decision (who
  reviewed it, what was decided, and why) must be recorded in an
  auditable way (SRV-004), consistent with the general audit-logging
  approach above rather than a bespoke mechanism of its own — see
  [business-requirements.md](business-requirements.md#service-operations-business-rules-confirmed).

### File / document storage

- Documents/files (contracts, invoices, attachments, delivery/
  installation records, etc.) are stored outside the relational database
  itself, with only references (metadata, location) kept in PostgreSQL —
  keeping large binary content out of the transactional database.
- Storage is expected to be provider-agnostic at the architecture level
  (e.g. accessed through an internal abstraction) so the specific storage
  technology can change without affecting modules that merely attach or
  retrieve files, in line with the "portable to AWS, Azure, or other
  infrastructure" deployment requirement.
- Access to stored files goes through the same authentication/RBAC checks
  as the record they're attached to — a file is never reachable by a URL
  that bypasses permission checks.

### Background jobs

- A background job mechanism runs work outside the request/response
  cycle: recurring contract billing runs, scheduled reports, renewal
  reminders, notification delivery, and (in future) Odoo sync and
  InvoiceNow submission.
- Background jobs call into the same module business-logic layer as the
  API, so a job (e.g. "generate recurring contract invoices") enforces
  the same rules as a user-triggered action would.
- Jobs that create financial or operational records are audit-logged the
  same way user-triggered actions are.
- A confirmed example of this pattern is the Service Operations
  pre-expiry check (SRV-006): scanning for open job orders, missing
  service records, unapproved excess usage, and unbilled billable excess ahead
  of a contract's expiry is naturally a background/periodic job rather
  than something a user must remember to run — see
  [business-requirements.md](business-requirements.md#service-operations-business-rules-confirmed).

### Notifications

- The system needs to notify users of events across modules — e.g. job order
  assignment, service record approval requests (and approvals overdue
  past SRV-019's week), contract
  renewal reminders, invoice approval requests, commission approval
  requests.
- Notifications are treated as a cross-cutting capability triggered by
  module business logic (e.g. Helpdesk triggers an assignment
  notification) rather than each module building its own delivery
  mechanism, so the channel(s) used (in-app, email, etc. — not yet
  decided) can evolve without changing every module.
- Notification content must respect RBAC/PDPA — a notification should
  not leak data the recipient would not otherwise be permitted to see.
- A confirmed example is routing excess service usage to Nico for review
  as soon as a contract's hours are exhausted (SRV-004), rather than
  requiring Nico to notice it by checking manually.

### API design

- The backend exposes APIs organized per business module,
  consumed primarily by the React frontend, and designed so the same
  APIs can later serve other consumers (e.g. a future customer portal,
  the AI Assistant, or integrations) without a separate API surface.
- Backend validation is authoritative for every API (per the development
  rules); frontend validation is a convenience layer only.
- APIs are expected to be versioned in a way that allows a module's API
  to evolve during its phased Odoo cutover without breaking other
  modules that depend on it (see the dependency map in
  [module-map.md](module-map.md)).

### Integration architecture

- Integrations (Odoo parallel-run sync, InvoiceNow/Peppol, and future
  connections such as banking) are isolated in the Integrations module
  behind clear interfaces, so an individual integration can be added,
  changed, or retired without changes to unrelated modules.
- Integrations call into the same module business-logic layer as the API
  and background jobs, rather than writing directly to another module's
  data — an Odoo sync job creating a Customer record, for example, goes
  through Customer Management's own logic (and its audit logging), not
  around it.
- Because InvoiceNow/Peppol submission and any future banking integration
  touch financial records, they participate in the same audit-logging
  and RBAC model as manual actions.

### Reporting

- Reporting / Management Dashboard is architected as a read-only consumer
  of data owned by other modules (per [module-map.md](module-map.md)), not
  a module that owns its own copy of transactional truth.
- Whether Reporting reads live operational data directly, or from a
  separate read-optimized/aggregated store fed from the same PostgreSQL
  database, is an implementation decision to be made once reporting
  volume and complexity are better understood — not decided here, and not
  a reason to duplicate business logic outside its owning module.
- Reporting must respect the same RBAC as the underlying data — a user
  should not see in a report what they could not see in the owning
  module.

### AI layer

- The AI Assistant is architected as a consumer of existing module APIs
  and Reporting data, operating within the requesting user's own
  permissions — it is not a privileged path that bypasses RBAC to answer
  a question.
- Any AI feature that reads customer or personal data must be designed
  with PDPA in mind (e.g. what data is sent to an AI provider, and
  whether that provider processes it outside Singapore) — this will need
  a specific decision once concrete AI capabilities are proposed, and is
  not resolved by this document.
- AI-driven actions that would change business data (as opposed to just
  answering questions) are expected to go through the same module
  business logic, validation, and audit logging as any other
  user-triggered action, rather than a separate write path — no AI
  capability is assumed to bypass the rules the rest of the system
  follows.

### Security

- Security is layered: network/deployment-level controls (see Deployment
  architecture), authentication, RBAC enforced in the backend, input
  validation on both frontend and backend, and audit logging for
  traceability.
- All data in transit is expected to be encrypted (e.g. HTTPS); whether
  data at rest requires additional encryption beyond what the hosting
  environment provides is to be assessed once a deployment target is
  chosen.
- PDPA considerations apply to personal data anywhere it is stored,
  displayed, exported, or sent to a third party (including any future AI
  provider or integration) — this needs a dedicated data-handling review
  once modules reach detailed design, tracked as a decision area in
  [open-business-decisions.md](open-business-decisions.md).
- Secrets/credentials (database, integration API keys, etc.) are kept out
  of source control and out of the application database, managed through
  the hosting environment's configuration/secrets mechanism — the
  specific mechanism depends on the deployment target chosen.

### Backup and disaster recovery

- The PostgreSQL database is the primary backup target, given it holds
  all transactional and financial data; backups must support
  point-in-time recovery, as already noted in the Preliminary
  Architecture.
- File/document storage (see above) needs its own backup/durability
  approach, consistent with whatever storage technology is chosen, since
  it sits outside the database.
- Disaster recovery should be considered at the level of "can the whole
  system be restored on different infrastructure" — consistent with the
  requirement to remain portable across cloud providers — rather than
  assuming recovery only within the original hosting environment.
- Specific backup frequency, retention period, and recovery time/point
  objectives (RTO/RPO) have not been decided and should be confirmed with
  Dennis before they are relied upon operationally.

### Development / testing / production environments

- At least three environment tiers are expected: development (active
  work), testing/staging (validation before release, including validating
  migrations and, during the phased rollout, Odoo parallel-run
  comparisons), and production (live business use).
- Each environment has its own PostgreSQL database — production data is
  never used directly for development or testing (per the "never modify
  production data directly" development rule); where realistic data is
  needed for testing, it is expected to be anonymized/sanitized rather
  than a raw production copy, for PDPA reasons.
- Database migrations are applied the same way, in the same order, in
  every environment, so testing/staging is a reliable predictor of what a
  migration will do in production.
- The parallel-run strategy for Odoo replacement implies that, for a
  module in parallel-run, production Websoft Service ERP Solution and production Odoo
  run side by side for real business use — this is a production-tier
  concern, not something exercised only in staging, and will need its own
  cutover checklist per module once that module's requirements are
  detailed.
