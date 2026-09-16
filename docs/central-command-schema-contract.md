# Central Command Schema Contract

This document defines the tables in each client ERP database that
**Server Company Central Command** reads from and writes to.  Central
Command is a separate application that Web Master Consultancy operates
to manage all client company ERP instances from one place; it lives in
its own repository:
[dennisgoh84-webmaster/websoft-central-command](https://github.com/dennisgoh84-webmaster/websoft-central-command).

The tables listed here are the API boundary between the two systems.
**This doc is kept in both repos** — breaking changes to these tables
must be coordinated with Central Command and mirrored in its copy.

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

**Central Command's own code**: `App\Services\ClientDbService::pushAnnouncements()`
**Client source model**: `backend-php/app/Models/Announcement.php`

### `ad_banner_settings` table

| Column | Type | Purpose |
|---|---|---|
| `id` | `integer` PK | Singleton row (always id = 1) |
| `video_url` | `varchar(1000)` nullable | URL of the promo video shown on login + dashboard |
| `updated_at` | `timestamptz` | Auto-updated on write |

**Central Command writes**: `UPDATE` on the singleton row (id = 1) to
set or change the promo video URL.

**Central Command's own code**: `App\Services\ClientDbService::pushVideoUrl()`
**Client source model**: `backend-php/app/Models/AdBannerSettings.php`

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

**Central Command's own code**: `App\Services\ClientDbService::pushLicenseChange()`
**Client source model**: `backend-php/app/Models/CompanyModule.php`

### `modules` table (read-only reference)

| Column | Type | Purpose |
|---|---|---|
| `key` | `varchar(50)` PK | Module identifier (e.g. `crm`, `billing`) |
| `name` | `varchar(100)` | Human-readable name |
| `description` | `text` nullable | What the module does |
| `is_built` | `boolean` default false | Whether application code for this module exists |

**Central Command reads**: to discover the available module keys when
building UI for license management.  Does not write to this table.

**Client source model**: `backend-php/app/Models/ModuleCatalog.php`

---

## 3. Client Identification

### `companies` table (relevant columns)

| Column | Type | Purpose |
|---|---|---|
| `id` | `uuid` PK | Company identifier within this client database |
| `code` | `varchar(20)` unique | System-generated short code from the name — 3+2+2 letters of its first three words then a running number zero-padded to eight characters in all, e.g. `WEBCOPT1`, `ACMMA001`, `ACM00001` (2026-09-15); never edited, survives a rename |
| `name` | `varchar(200)` | Company name |
| `uen` | `varchar(50)` nullable | Singapore UEN / business registration number |

Central Command uses the `companies` table to identify which companies
exist in a client database and map them to its own client registry.

**Client source model**: `backend-php/app/Models/Company.php`

---

## 4. How the client ERP enforces licenses

The enforcement point is `App\Services\Authority::requireModuleAccess()`
(`backend-php/app/Services/Authority.php`). On every API call that
touches a gated module, this function:

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

**Contract change 2026-09-15, resolved on Central Command's side
2026-09-16.** The client ERP retired its Python/Alembic backend — there
is no `alembic_version` table on any client database any more. Central
Command now checks Laravel's own `migrations` table instead:

```sql
SELECT migration FROM migrations ORDER BY batch DESC, id DESC LIMIT 1
```

That row's `migration` value (the latest applied migration filename,
e.g. `2026_09_30_000100_create_system_mail_settings_table`) is what
Central Command calls the client's **migration head** — stored on
`clients.last_known_migration_head`, checked before every write via
`App\Services\ClientDbService::checkMigrationHead()`, and compared
against registered ERP versions on the Version Control page.

`checkMigrationHead()` also optionally refuses a client whose head is
behind `centralcommand.min_client_migration_head`
(`CC_MIN_CLIENT_MIGRATION_HEAD`) — unset by default (no floor), since
Laravel's date-prefixed migration filenames already sort correctly as
plain strings, no version parsing needed. Renamed 2026-09-16 from the
dead pre-Laravel `min_client_alembic_head` config, which nothing ever
actually read.

Current head: the last file in `backend-php/database/migrations/`.

---

## 6. Settled decisions (resolved 2026-09-12, schema versioning updated 2026-09-16)

All 6 open questions resolved — Central Command is now built, in its own
repository
([websoft-central-command](https://github.com/dennisgoh84-webmaster/websoft-central-command)).

1. **Client DB connection registry** → DECIDED: Central Command's own
   database has a `clients` table with host, port, db_name, username,
   password, TLS flag per client.  Admin adds clients via the UI.
2. **Network access** → DECIDED: Internet with TLS + auth.  Each
   client's PostgreSQL is exposed with TLS encryption and credentials.
3. **Schema versioning** → DECIDED: read the client's migration head
   before writing, refuse if incompatible. ~~`alembic_version` table~~
   → Laravel's `migrations` table since 2026-09-15 (see §5).
4. **Ad targeting rules** → DECIDED: Manual per-client.  Admin assigns
   ads to specific client instances via the UI.
5. **Audit trail** → DECIDED: Central Command's own `push_logs` table
   only.  Don't log in the client's Event Logs.
6. **Scope beyond ads and licenses** → DECIDED: Yes, config updates
   too.  Push SQL-based configuration changes (tax rate updates, new
   default settings) to client databases.

---

## 7. Server/system configuration push (`planned-work.md #8c`, built 2026-09-16)

Central Command owns the client install's **system-level** mailboxes
centrally and pushes them down, rather than each install hand-editing
`backend-php/.env`. Distinct from each company's own document-email
mailbox (Company Setup → Outbound email), which stays entirely
client-side and Central Command never touches.

### `system_mail_settings` table

| Column | Type | Purpose |
|---|---|---|
| `purpose` | `varchar(20)` PK | `otp` (sign-in codes, password resets, portal invites) or `helpdesk` (Outlook Add-in acknowledgements) |
| `host` | `varchar(255)` nullable | SMTP host |
| `port` | `integer` default 587 | SMTP port |
| `username` | `varchar(255)` nullable | SMTP username |
| `password` | `text` nullable | **Encrypted at rest via this app's own Eloquent `encrypted` cast** — see below |
| `use_tls` | `boolean` default true | STARTTLS on/off |
| `from_email` | `varchar(255)` nullable | Sender address |
| `from_name` | `varchar(255)` nullable | Sender display name |
| `updated_at` | `timestamptz` | Auto-updated on write |

**Central Command writes**: `UPSERT ... ON CONFLICT (purpose) DO UPDATE`,
one row per purpose. A push with an empty password leaves the client's
existing password untouched (`COALESCE` against the current value)
rather than clearing it.

**The password column is not a plain write target.** This app's own
`SystemMailSetting` model casts it `'password' => 'encrypted'` —
Laravel transparently encrypts on write and decrypts on read using
*this install's own* `APP_KEY`. A raw plaintext write would leave a
value this app cannot decrypt, breaking mail silently. Central Command
reproduces this app's own encryption instead of trying to work around
it: it stores this client's `APP_KEY` (`clients.app_key` on its own
side, never returned by its API, same treatment as `db_password`) and
uses Laravel's own `Illuminate\Encryption\Encrypter` — available in
Central Command's own `vendor/laravel/framework`, since both apps are
Laravel — to produce ciphertext byte-for-byte identical to what this
app's own cast would have written.

**Central Command's own code**: `App\Services\ClientDbService::pushSystemMailSetting()`
and `::encryptForClient()`
**Client source model**: `backend-php/app/Models/SystemMailSetting.php`

---

## 8. AI Assistant settings (added 2026-09-15)

### `ai_settings` table

One global row, key `default`, like `system_mail_settings` — install-
level, not per company. Central Command does not push to this table
yet (no code in `ClientDbService` writes it); per-company licensing is
the `ai_assistant` key in `company_modules` (§2), a paid add-on that
gates the owner too.

| Column | Type | Notes |
|---|---|---|
| `key` | `varchar(20)` PK | Always `default` |
| `api_key` | `text` nullable | Encrypted with this app's `APP_KEY` (Laravel `encrypted` cast) — a future push must write it through the same encryption as System Mail Settings, never plaintext |
| `model` | `varchar(60)` | Default `claude-opus-5` |
| `redact_personal_data` | `boolean` | Default `true` (decision 12.1) |
| `assistant_name` | `varchar(40)` | Default `Websoft AI` |
| `assistant_avatar` | `text` nullable | `data:image/...;base64,...`, ≤ 400 000 chars |
| `updated_at` | `timestamptz` nullable | |

**Client source model**: `backend-php/app/Models/AiSetting.php`

---

Last updated: 2026-09-16 (fixed against a real client install: `companies.registration_number` renamed to `uen`, `min_client_migration_head` push guard wired up and renamed from the dead pre-Laravel `min_client_alembic_head`; schema versioning switched to Laravel migrations; System Mail Settings push built; AI Assistant settings table added 2026-09-15)
