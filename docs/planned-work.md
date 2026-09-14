# Planned Work

Confirmed future work that has been described in enough detail to record,
but is **not yet designed or built** -- distinct from
[open-business-decisions.md](open-business-decisions.md) (undecided items
blocking something already in progress) and
[business-requirements.md](business-requirements.md) (requirements for
areas actively being built). An item here moves to those documents (and to
actual implementation) once its turn comes.

---

## 1. Mobile web app for Support Staff -- on-site Job Order / Service Record capture (raised 2026-09-11, built 2026-09-12)

**Built 2026-09-12.** All 8 open questions were settled with Dennis before
building (see docs/open-business-decisions.md for the settled decisions).

**Settled decisions:**
1. Same login credentials, filtered to own assigned Job Orders only
2. Time in/out replaces manual minutes (auto-computed from elapsed time)
3. Camera-only capture + SR-number watermark overlay for chop photos
4. Live connection assumed (no offline mode)
5. Finger-drawn signature on canvas + typed signer name
6. Photos + videos, no limit on count/size

**What was built:**

- **Backend:** `app/models/attachments.py` (ServiceRecordAttachment +
  ServiceRecordSignoff models), `app/services/file_storage.py` (local
  disk storage + Pillow-based chop watermarking), `app/routers/mobile.py`
  (11 endpoints under `/api/mobile`), migration
  `a1b2c3d4e5f6_mobile_web_app.py` (time_in/time_out on service_records,
  attachment_kind enum, service_record_attachments + signoffs tables).
- **Frontend:** `frontend/src/pages/MobileApp.tsx` -- complete mobile web
  app at `/mobile` route: login, job order list (own assigned), time
  in/out with live elapsed timer, work description capture, camera photo/
  video attachments, 3-step sign-off wizard (name → finger-drawn
  signature → chop photo capture with watermark).
- **Infrastructure:** Docker volume for persistent uploads, nginx
  `client_max_body_size 500m` for large uploads, Pillow dependency.

---

## 2. Incident Module -- Support Staff call/issue log, with routing to Sales/Job Order/Software Tasks (raised 2026-09-11, deferred until after Company/Individual)

**Built 2026-09-12 -- see docs/open-business-decisions.md #36** for the
settled rules and `app/models/incidents.py` / `app/routers/incidents.py`
/ `frontend/src/pages/IncidentsPage.tsx` for the implementation. The
Outlook Add-in half is scaffolded but not deployable/testable here --
see `outlook-addin/README.md`. Left below for the original request
wording and design history.

Requested as: Support Staff log incoming calls/issues, which get routed
to Sales (a Quotation), Support (a Job Order), Software Tasks, or
"someone to return call." Explicitly deferred by Dennis until after the
Company/Individual work ("settle company/individual first then new
incident module later") -- recorded here so the design details already
confirmed aren't lost in the meantime.

**Confirmed so far:**

- Converting an Incident **auto-creates** the real target record (a
  Quotation, Job Order, or Software Task pre-filled from the Incident,
  with a back-reference to it) -- not just routing/assigning the
  Incident for someone else to act on manually (confirmed 2026-09-11,
  see docs/open-business-decisions.md #19.7).
- **Outlook integration, confirmed 2026-09-11 as an Outlook Add-in with
  two buttons**, not a fully automatic mailbox-polling approach:
  - **"Convert to Incident"** -- sends the open email's sender,
    subject, and body to the API to create an Incident.
  - **"Convert to Job Order"** -- before allowing this one, the system
    must **check for a valid contract** for that customer first, and
    only allow the conversion if one exists. (Open question: what
    happens when there is no valid contract -- block with an error
    telling the sender to create/find a contract first, or fall back to
    creating an Incident instead and let staff route it manually? Not
    yet decided.)
  - Both buttons are one click each, not automatic -- Support Staff
    still triggers the conversion themselves, reading the email as
    normal first.
  - Needs an Azure AD app registration and Outlook Add-in manifest;
    standard Microsoft Graph/Office Add-in mechanics, nothing exotic.

**Still open** (from the original recording, unchanged): the full
Incident data model (fields, statuses), what "Sales decide Quote or
Directly go to Software Tasks" looks like as a routing UI, and whether
"someone to return Call" creates any record at all or is just an
assignment/reminder.

**Not yet started.** No models, routes, or UI exist for this.

---

## 3. eSignature + eDocument attachments -- all operations and accounting documents (raised 2026-09-12, "put on the waiting list... at the end then we build it in")

Requested as a capability spanning every document in the system (Job
Orders, Service Records, Quotations, Invoices, Receipts, Payment
Vouchers, Purchase Orders, Statements, etc.), explicitly deferred by
Dennis to be built as one pass at the end, not per-document as each is
touched.

**Confirmed so far:**

- **eSignature** on documents -- captured electronically rather than the
  current "Signature & Company Stamp: ___" blank line printed forms
  leave for a physical pen signature. Overlaps with the Support Staff
  mobile app's company-chop-photo + signature workflow (item 1 above),
  which is a specific, more detailed instance of this same need for
  Service Records -- the two should be designed together, not
  separately, when this is picked up.
- **eDocument attachments** -- the ability to attach supporting files
  (photos, PDFs, scanned documents, etc.) to a document record. No
  document type in this system can carry an attachment today; every doc
  is data-only.
- Scope is **every** operations and accounting document, not a specific
  one -- this is a platform capability, not a one-off feature on a
  single form.

**Not yet started -- no models, storage, or UI exist for this.** Real
open questions once this is picked up (not resolved here, just flagged):
what "signing" actually means (drawn signature, typed name + timestamp,
third-party eSign provider like DocuSign, or the mobile app's own
photo-based chop capture), where attachment files are stored (this app
currently only inlines small base64 images for logos -- real file/object
storage is a different scale), size/type/count limits per document, and
whether attachments and signatures follow the same never-delete/
soft-delete posture as every other record here (almost certainly yes,
but not assumed).

---

## 4. eApproval Master -- authority-based, value-gated, multi-staff approvals across documents (raised 2026-09-12)

Requested as a generic approval framework to eventually replace the
one-off approval logic that exists today (Service Record approval,
built specifically and only for Service Records -- see
app/routers/service_records.py / ServiceRecordApprovalPage.tsx) with a
single configurable authority system covering multiple document types.

**Confirmed so far:**

- **Specific authority for specific approvals** -- e.g. Purchase Order
  approval above a certain value requiring an eSignature (this already
  exists in a narrow form as PUR-001's value threshold + owner
  approval, see docs/open-business-decisions.md #4.4 -- eApproval Master
  would generalise that pattern rather than replace its business rule).
- **Payment Voucher preparation approval based on "Bank Authority"** -- a
  new authority concept, distinct from Group Authority (module-level
  CRUD access): who is allowed to prepare/approve a payment against a
  given bank account. Not yet modelled anywhere in this system.
- **Can be more than one staff** -- an authority (e.g. Bank Authority for
  a given account, or PO approval above a threshold) can be assigned to
  multiple people, not just a single approver.
- **Folds in Service Record approval** -- when eApproval Master is
  built, Dennis wants Service Record approval brought into the same
  module rather than staying as its own separate, hard-coded mechanism.
  This is a migration of existing behaviour, not new business rules for
  Service Records themselves (SRV-004 approver-role logic, deduction
  minutes, etc. stay as already confirmed).
- **The approval screen itself, once generic, must:**
  - Show any **attachments** on the document being approved (depends on
    item 3 above existing first).
  - **Never make an approved/rejected item disappear** from the screen
    once acted on -- the approver (and others) must be able to see
    clearly, after the fact, what was **approved**, **rejected**, or is
    still **for review**, with the status visibly distinguishing the
    three states. (Today's Service Record Approval page only lists
    *pending* records -- approved ones drop off the list entirely, since
    that page was built narrowly for the one-time decision. eApproval
    Master's screen is a different, retained-history view.)

**Not yet started -- no models, routes, or UI exist for this.** Depends
on item 3 (attachments) for the "show attachments" requirement to be
meaningful. Real open questions once this is picked up: how an
"authority" is modelled (a new table distinct from Group/GroupModuleAuthority,
or an extension of it), how Bank Authority relates to the existing
BankAccount model (app/models/treasury.py), whether approval is
single-approver-sufficient or requires all assigned approvers, and
exactly which document types move onto this framework first.

---

## 5. Product "Is Stock" flag + Stock Master item selection -- pending Websoft Stock Distribution ERP (raised 2026-09-12)

Requested as: define on the Product Master whether an item is stock-
tracked, ahead of a separate Websoft Stock Distribution ERP project
(a different GitHub repository) that will define the actual stock
inventory flow and documents. Recorded, not built -- the request was
explicitly to "record that" this needs defining, since the logic it
depends on doesn't exist yet.

**Confirmed so far:**

- Product Master needs an **"Is Stock"** flag: ticked means the item's
  quantity needs to be monitored as stock inventory; unticked means it
  doesn't (e.g. a service line item, as most of this catalog is today --
  see app/models/catalog.py `Product`, which has no such flag yet).
- Once Dennis finishes the **Websoft Stock Distribution ERP** (a
  separate project), this system will need to **follow that project's
  Stock Inventory Flow and Documents logic** -- i.e. this app's stock
  handling is meant to mirror/integrate with that other system's design,
  not invent its own.
- **Item selection in documents changes**: once stock exists, choosing a
  line item on a document -- starting with Quotation -- will pick from a
  **Stock Master** instead of today's Product Master, for stock-tracked
  items at least. Whether non-stock (service) items keep using Product
  Master, or everything moves to one merged master, is not stated.

**Not yet started -- no field, model, or UI change made.** Deliberately
so: the Websoft Stock Distribution ERP's own logic doesn't exist yet to
follow, so building against a guessed version of it would very likely
have to be redone. Real open questions once that other project is far
enough along to reference: exact Stock Master field set, how it relates
to (or replaces) today's Product/ProductType model, whether Quotation
Lines/Invoice Lines/PO lines all switch together or one document at a
time, and how stock quantity movements themselves get recorded in this
system (received, issued, adjusted) versus in the Stock Distribution ERP.

---

## 6. Odoo migration program -- Contacts, Subscriptions, Timesheets, Sales Quotations/Invoices/Receipts, Chart of Accounts (raised 2026-09-12)

Requested as: eventually build a migration program to transfer data out
of the existing Odoo system and into this one, mapping Odoo's records
onto this system's equivalent (and differently-named) entities. Recorded
per Dennis's request ("record this that we will eventually also need
a migration program") -- explicitly a future need, not to be built now.

**Confirmed mapping, as given (Odoo source -> Websoft Service ERP target):**

| Odoo | Websoft Service ERP |
|---|---|
| Contacts | Company/Individual (`app/models/company_individuals.py` `CompanyIndividual`) |
| Subscriptions | Contracts (`app/models/contracts.py` `ServiceContract`) |
| Timesheets | Service Records (`app/models/service_records.py`) |
| Sales Quotations | Sales Quote (`app/models/quotations.py` `Quotation`) |
| Sales Invoices | Sales Invoice (`app/models/invoices.py` `Invoice`) |
| (Sales) Receipts | Receipt(s) (`app/models/payments.py` `Payment`, the Receipt Voucher) |
| Chart of Accounts | Chart of Accounts (`app/models/accounting.py` `Account`) |

## 7. WhatsApp OTP as a second login factor (raised 2026-09-12, deferred)

Requested alongside email OTP as: "enhance security with OTP upon
login either email or handphone whatsapp." Email OTP is built (see
docs/open-business-decisions.md #27.3) using the existing SMTP mailer;
WhatsApp OTP is deferred because it needs infrastructure this system
doesn't have yet -- an automated WhatsApp Business API account
(Twilio's WhatsApp API or Meta's Cloud API) that can send a templated
message and have this backend poll/receive the delivery status. Every
other WhatsApp touchpoint in this system today (Print/Email/WhatsApp
icon buttons on documents) is a manual `wa.me` deep link a staff member
opens and sends themselves -- there is no automated send path to build
on. Build once such an account is provisioned: add a `whatsapp_otps`-
style flow mirroring `LoginOtp`, and let the user choose email or
WhatsApp at the OTP step.

This aligns with CLAUDE.md's already-approved Odoo replacement strategy
(phased, module-by-module, with a parallel-run period and no big-bang
migration) and its note that "important historical data will eventually
be migrated" -- this item is the concrete migration-program request for
that strategy, scoped to the record types above.

**Not yet started -- no migration scripts, field-mapping tables, or
import tooling exist.** Real open questions once this is picked up (not
resolved here, just flagged, per CLAUDE.md's "never assume a business
rule when requirements have not been provided"):

1. **Access to Odoo data** -- direct DB access to Odoo's PostgreSQL
   instance, or via Odoo's XML-RPC/JSON-RPC API? Read-only, one-off
   extract, or a repeatable/re-runnable sync during the parallel-run
   period?
2. **Field-level mapping** -- each Odoo model above has many fields;
   which map 1:1 to this system's equivalent model, which need
   transformation (e.g. Odoo's Subscription recurrence/billing fields
   against this system's ServiceContract hours-bucket model, which has
   no direct Odoo equivalent), and which have no target field at all yet.
3. **Identity/reference mapping** -- how an Odoo record's ID is
   correlated with the newly-created Websoft record afterward (for
   re-runs, verification, and so linked records -- e.g. an Invoice's
   Contact -- resolve correctly), and whether that mapping table itself
   needs to be a permanent audit record per this project's "never
   permanently delete" / audit-trail rules.
4. **Historical vs. operational data** -- CLAUDE.md already notes older
   Odoo data "may be archived rather than fully operational"; which of
   the record types above (if any) get imported as read-only/archived
   history versus fully live, editable records.
5. **Numbering/sequence collisions** -- this system generates its own
   document numbers (e.g. `SQ-` quote numbers, `INV-` invoice numbers)
   via `DocumentSequence`; how imported Odoo records get numbered
   (keep Odoo's original reference as a separate field, or renumber into
   this system's sequences) is undecided.
6. **Cut-over sequencing** -- given the phased, parallel-run strategy,
   whether all seven record types migrate together or Company/Individual
   (Contacts) and Chart of Accounts migrate first as foundational/
   reference data, with the transactional documents (Contracts,
   Service Records, Quotes, Invoices, Receipts) following once their
   linked Company/Individual and account records already exist here.

---

## 8. Server Company Central Command -- remote ad/banner push + license enforcement (raised 2026-09-12)

Requested as: a **separate application** (its own repository and
deployment) that Web Master Consultancy operates to manage all client
company ERP deployments from one place. Two confirmed capabilities:

### 8a. Advertisement / banner push

Instead of each client company managing their own announcements locally,
Central Command pushes advertisements and banners **directly into each
client's PostgreSQL database**. Different client companies can see
different ads -- the selection is made at Central Command, not at the
client side.

This ERP already has an Announcements module (`app/models/announcements.py`,
`AnnouncementsPage.tsx`) which Central Command would likely write into.
The client-side schema is the contract Central Command depends on.

### 8b. License enforcement

Non-paying customers have their module licenses expired remotely. Central
Command writes directly to the client's database to disable/expire module
access (the existing `module_controls` table is the likely target -- it
already has `is_active` and `expires_at` fields per module per company).

### Confirmed architecture decisions

| Decision | Answer |
|---|---|
| Central Command vs. this repo | **Separate app/repo** -- its own standalone application |
| Communication model | **Direct DB push** -- Central Command connects to each client's PostgreSQL |
| Client deployment model | **Separate per client** -- each client has its own Docker stack + database |

### What this means for this ERP repo (client side)

- The existing `module_controls` and `announcements` tables become a
  **schema contract** -- Central Command depends on their structure.
  Breaking changes to these tables need to be coordinated with the
  Central Command app.
- No new code in this repo yet -- the client ERP already reads from
  these tables. Central Command is the new writer.
- The client ERP may eventually need a **registration/heartbeat**
  mechanism so Central Command knows which client databases exist and
  how to connect to them, but that's a Central Command concern.

### Open questions (not resolved, flagged for when this is picked up)

1. **Client DB connection registry** -- how does Central Command
   discover and store connection details (host, port, credentials) for
   each client's PostgreSQL? A config file, a Central Command database
   of registered clients, or something else?
2. **Network access** -- each client runs its own Docker stack; is
   PostgreSQL exposed to the internet (with TLS + auth), or is Central
   Command on the same private network / VPN as the clients?
3. **Schema versioning** -- when this ERP's migrations change the
   `module_controls` or `announcements` schema, how does Central
   Command stay compatible? Does it check schema version before writing?
4. **Ad targeting rules** -- what criteria determine which client sees
   which ad? Per-company manual selection, by industry, by
   subscription tier, by region?
5. **Audit trail** -- should Central Command's writes to client DBs be
   logged in the client's Event Logs (the existing audit system), or
   only in Central Command's own logs?
6. **Scope beyond ads and licenses** -- will Central Command eventually
   push other things (system announcements, configuration updates,
   software update notifications)?

**Built 2026-09-12; moved to its own repository 2026-09-13** —
[websoft-central-command](https://github.com/dennisgoh84-webmaster/websoft-central-command),
as the original request intended. No Central Command code lives in this
ERP repo. This section stays here because the client-side schema
contract (the tables Central Command writes to) lives in this codebase —
see [central-command-schema-contract.md](central-command-schema-contract.md).

---

## 9. Sub-ledger → General Ledger posting + Bank step (raised 2026-09-14, built 2026-09-14)

**Built 2026-09-14.** The full design — account map, posting rules per
document, Bank/Unbank and UNGL lifecycle, migration and back-fill plan,
endpoints, frontend changes and test plan — is in
[gl-posting-design.md](gl-posting-design.md), all §8 test-plan items
verified. Rules ACC-001..004 in
[business-requirements.md](business-requirements.md); decision record in
[open-business-decisions.md #38](open-business-decisions.md#38-sub-ledger--general-ledger-posting-and-the-bank-step-raised-and-settled-2026-09-14).

Built in order: migration → posting service + auto-post hooks →
Bank/Unbank and UNGL endpoints → `post_backlog.py` + frontend chips and
dropdowns → verified against the test plan.

## 10. Customer Helpdesk Portal (raised 2026-09-14, built 2026-09-14)

**Built 2026-09-14.** Data model, auth flow, the staff-side
enable/disable, portal endpoints, frontend mount at `/portal`, module
key and test plan are in
[customer-portal-design.md](customer-portal-design.md), all §9 test-plan
items verified (including the token boundary tests). Rules
PORTAL-001..004 in [business-requirements.md](business-requirements.md);
decision record in [open-business-decisions.md #39](open-business-decisions.md#39-customer-helpdesk-portal-raised-and-settled-2026-09-14).

Built in order: models + migration → auth + token boundary (boundary
tests written and run first) → staff enable/disable on Contacts →
portal endpoints → `/portal` frontend → verified.

## 11. Sales module enhancements: Job Implementation Template, multi-Product Job Orders, Contract hour-sharing, Contract filters, Contract Operation Report, Contract–Quotation reference, Sales Dashboard (raised earlier, built 2026-09-22)

**Built 2026-09-22.** Dennis's feature list, clarified with him in an
earlier conversation and taken as settled here (not re-derived) — see
rules SALES-001..007 in
[business-requirements.md](business-requirements.md#sales-module-enhancements-business-rules-confirmed)
and the two open pragmatic defaults recorded in
[open-business-decisions.md #40](open-business-decisions.md#40-sales-module-enhancements-financial-year-definition-and-contractquotation-link-raised-2026-09-22).

**NOT a Python→PHP conversion** — unlike every other entry in this
document and in [php-conversion-plan.md](php-conversion-plan.md), this
is new business scope with no `backend/` (Python) equivalent, built
directly and only in `backend-php/` + `frontend/`, per Dennis's
request. `backend/` was not touched.

Built, in dependency order:

1. **Product Job Implementation Template** — `ProductImplementationTemplate`
   + `ProductImplementationTemplateTask` models/migration,
   `ProductController::getImplementationTemplate`/`setImplementationTemplate`
   (`PUT` replaces the whole ordered list, mirroring how Contract
   product coverage is edited), a frontend editor on Product/Service
   Catalog.
2. **Job Order multi-Product selection + template import** —
   `job_order_products` pivot + `JobOrderImplementationTask` (mirrors
   `ProjectMilestone`'s completion-tracking spirit with a simpler
   pending/completed lifecycle, gated to Sales Manager/Owner per 7.3),
   `App\Services\JobOrderImplementationTaskService` (documented dedupe
   rule: an identically-named task from a second selected product's
   template is not copied twice), new `POST /job-orders/{id}/products`
   to add products after creation, new
   `.../implementation-tasks/{id}/complete|reopen` endpoints, frontend
   multi-select on the create form + a Products & Implementation Tasks
   card on the Job Order detail page.
3. **Contract hour-sharing list** — `ContractSharedCustomer`
   model/migration, `Contract::allowsCustomer()`, `POST`/`DELETE
   /contracts/{id}/shared-customers`, `JobOrderController::store()` now
   validates the requesting customer against the contract's own
   customer or this list (422 `ContractRuleViolation` otherwise), a
   Sharing of Hours card on the Contract detail page.
4. **Contract filters** — `remaining_hours_lt` and
   `expiry_from`/`expiry_to` added to `ContractController::index()`
   (distinct from the pre-existing coverage-window overlap filter), two
   new controls on the Contracts list page's filter bar.
5. **Contract Operation Report** — `ContractService::expiryListing()`
   (default 90-day-forward window when no range given, always includes
   already-expired contracts regardless of range) and
   `::dueForRenewal()` (reuses `needsPreExpiryCheck()`'s SRV-014 window
   exactly), new `ContractReportController` + `/reports/operations/
   contracts/{expiry-listing,renewal-due-listing}` endpoints with
   CSV/Excel export (`App\Services\ExportService`, a small new shared
   CSV/HTML-table-as-.xls writer — see its docblock for why this avoids
   adding a real XLSX library as a dependency), two new report types on
   the existing Operations Reports page.
6. **Contract–Quotation reference** — `quotation_reference` +
   set_at/set_by columns on `contracts`, `ContractService::setQuotationReference()`
   (only while Renewed or Expired, always audit-logged),
   settable inline on `renew()` or via its own endpoint, shown/edited
   on the Contract detail page. KNOWN GAP / pragmatic default — see
   open item 40.
7. **Sales Dashboard** — `App\Services\SalesDashboardService` +
   `SalesDashboardController`, `/sales-dashboard/summary` (contracts
   due for renewal, total/2-month/3-month AR outstanding via the same
   `AccountsReceivableService::agingBucketFor()` bucketing the AR Aging
   report uses, Quotations-pending KPIs always `not_available` — KNOWN
   GAP, not fabricated) plus drill-down endpoints
   (`ar-breakdown`, `top-billing-customers`,
   `bottom-non-active-customers`) each with CSV/Excel export, a new
   `SalesDashboardSection` component mounted below the existing Company
   Dashboard (rendered independently of that dashboard's own summary
   state, since `/dashboard/summary` itself is not yet converted to
   `backend-php/` — a separate, pre-existing gap this work does not
   attempt to close).

Tests: business-logic coverage for every worked example above
(`ContractServiceTest`, `SalesDashboardServiceTest` — the financial-
year boundary, the AR bucket figures, the Top 10/Bottom 10 ranking) plus
API-level RBAC/multi-company/audit coverage per module
(`ProductImplementationTemplateTest`, `ContractSharedCustomerTest`,
`ContractReportTest`, `SalesDashboardTest`), following the same
template `CompanyIndividualTest.php` set for the PHP conversion work —
happy path, 403 no-Group, 403 VIEW-only on a write, 403 Module Control
disabled, 404 cross-company. `./vendor/bin/pint` and the full
`php artisan test` suite stayed green throughout.
