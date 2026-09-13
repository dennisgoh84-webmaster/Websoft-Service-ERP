# Central Command Schema Contract

This document defines the tables in each client ERP database that
**Server Company Central Command** reads from and writes to.  Central
Command is a separate application (its own repo and deployment) that Web
Master Consultancy operates to manage all client company ERP instances
from one place.  The tables listed here are the API boundary between the
two systems; breaking changes to these tables must be coordinated with
Central Command.

See [planned-work.md §8](planned-work.md#8-server-company-central-command----remote-adbanner-push--license-enforcement-raised-2026-09-12)
for full context and open questions.

---

## 1. Advertisement / Banner Push

Central Command pushes ads/banners into client databases.  Different
clients can see different ads -- the selection is made at Central
Command, not at the client side.

### `announcements` table

| Column | Type | Purpose |
|---|---|---|
| `id` | `uuid` PK | Unique identifier per announcement |
| `tag` | `varchar(30)` nullable | Short label shown as a badge (e.g. "New", "Update") |
| `text` | `text` not null | One-line announcement message |
| `sort_order` | `integer` not null, default 0 | Display order (ascending) |
| `is_active` | `boolean` not null, default true | Soft-delete: false hides the announcement |
| `created_at` | `timestamptz` | Auto-set on insert |

**Central Command writes**: `INSERT`, `UPDATE`, and soft-delete
(`is_active = false`).  The client ERP reads `WHERE is_active = true
ORDER BY sort_order` to render the announcement list.

**Source model**: `backend/app/models/announcements.py :: Announcement`

### `ad_banner_settings` table

| Column | Type | Purpose |
|---|---|---|
| `id` | `integer` PK | Singleton row (always id = 1) |
| `video_url` | `varchar(1000)` nullable | URL of the promo video shown on login + dashboard |
| `updated_at` | `timestamptz` | Auto-updated on write |

**Central Command writes**: `UPDATE` on the singleton row (id = 1) to
set or change the promo video URL.

**Source model**: `backend/app/models/announcements.py :: AdBannerSettings`

---

## 2. License Enforcement

Central Command disables/enables module licenses remotely for non-paying
or subscribing customers.

### `company_modules` table

| Column | Type | Purpose |
|---|---|---|
| `id` | `uuid` PK | Row identifier |
| `company_id` | `uuid` FK → `companies.id` | Which company this module license belongs to |
| `module_key` | `varchar(50)` FK → `modules.key` | Which module (e.g. `billing`, `finance_accounting`) |
| `enabled` | `boolean` default false | **The switch**: `false` disables the module for this company |
| `license_type` | `enum(included, add_on, trial)` | License category |
| `notes` | `text` nullable | Admin notes |
| `enabled_at` | `timestamptz` nullable | When the module was enabled |
| `updated_at` | `timestamptz` | Auto-updated on write |

**Central Command writes**: `UPDATE company_modules SET enabled = false`
to expire a module for a non-paying client, or `SET enabled = true` to
restore it.  May also update `license_type` and `notes`.

**Unique constraint**: `(company_id, module_key)` — one row per module
per company.

**Source model**: `backend/app/models/licensing.py :: CompanyModule`

### `modules` table (read-only reference)

| Column | Type | Purpose |
|---|---|---|
| `key` | `varchar(50)` PK | Module identifier (e.g. `crm`, `billing`) |
| `name` | `varchar(100)` | Human-readable name |
| `description` | `text` nullable | What the module does |
| `is_built` | `boolean` default false | Whether application code for this module exists |

**Central Command reads**: to discover the available module keys when
building UI for license management.  Does not write to this table.

**Source model**: `backend/app/models/licensing.py :: Module`

---

## 3. Client Identification

### `companies` table (relevant columns)

| Column | Type | Purpose |
|---|---|---|
| `id` | `uuid` PK | Company identifier within this client database |
| `name` | `varchar(200)` | Company name |
| `registration_number` | `varchar(50)` nullable | UEN / registration number |

Central Command uses the `companies` table to identify which companies
exist in a client database and map them to its own client registry.

**Source model**: `backend/app/models/core.py :: Company`

---

## 4. How the client ERP enforces licenses

The enforcement point is `backend/app/services/authority.py ::
require_module_access()`.  On every API call that touches a gated
module, this function:

1. Looks up `CompanyModule` for `(current_user.company_id, module_key)`
2. If the row doesn't exist or `enabled = false`, returns **403
   Forbidden** — the user cannot access that module
3. The frontend sidebar also checks module access and hides nav items
   for disabled modules (see `Layout.tsx :: can()`)

Central Command's `UPDATE company_modules SET enabled = false` therefore
takes effect on the next API call the client makes — no restart needed,
no cache to invalidate.

---

## 5. Schema versioning

Central Command should verify it understands the client database's
schema before writing.  Options (open question #3 from planned-work):

- Check Alembic's `alembic_version` table for the current migration head
- Verify expected columns exist via `information_schema.columns`
- Maintain a version registry in Central Command that maps migration
  heads to compatible Central Command versions

Current Alembic head: see `backend/alembic/versions/` for the latest
migration file.

---

## 6. Settled decisions (resolved 2026-09-12)

All 6 open questions resolved — Central Command is now built in
`central-command/` directory.

1. **Client DB connection registry** → DECIDED: Central Command's own
   database has a `clients` table with host, port, db_name, username,
   password, TLS flag per client.  Admin adds clients via the UI.
2. **Network access** → DECIDED: Internet with TLS + auth.  Each
   client's PostgreSQL is exposed with TLS encryption and credentials.
3. **Schema versioning** → DECIDED: Check `alembic_version` table.
   Read the client's migration head before writing, refuse if
   incompatible.
4. **Ad targeting rules** → DECIDED: Manual per-client.  Admin assigns
   ads to specific client instances via the UI.
5. **Audit trail** → DECIDED: Central Command's own `push_logs` table
   only.  Don't log in the client's Event Logs.
6. **Scope beyond ads and licenses** → DECIDED: Yes, config updates
   too.  Push SQL-based configuration changes (tax rate updates, new
   default settings) to client databases.

---

Last updated: 2026-09-12 (all open questions settled, Central Command built)
