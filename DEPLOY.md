# Deploying

The ERP is one Docker Compose stack (`docker-compose.yml` at the
repository root): Postgres, the PHP/Laravel backend, and nginx serving
the React app and proxying `/api/*` to the backend. The Mobile Web App
(`/mobile`) and the Customer Helpdesk Portal (`/portal`) are built into
the same frontend, so there is nothing separate to deploy for them.

| # | Part | Repo | Container(s) | Exposed port |
|---|------|------|-------------|--------------|
| 1 | **ERP** — desktop web | this repo | `frontend` (nginx) → `backend-php` (Laravel) → `db` (Postgres) | `:80` (`HTTP_PORT`) |
| 2 | **Mobile Web App** | this repo | in the ERP frontend at `/mobile` | same |
| 3 | **Customer Helpdesk Portal** | this repo | in the ERP frontend at `/portal` | same |
| 4 | **Central Command** | [websoft-central-command](https://github.com/dennisgoh84-webmaster/websoft-central-command) | its own stack | `:8080` (`CC_HTTP_PORT`) |

**For a test server, use the scripts:** [deploy/README.md](deploy/README.md)
covers install (`./deploy/install.sh`), upgrade (`./deploy/upgrade.sh`
-- backs up the database first), day-to-day commands, packaging for a
server without git, and what can go wrong. This file covers what those
scripts do not: the checks before you ship, HTTPS, and backups.

---

## 0. Before you deploy

Run these from a clean checkout of the commit you intend to ship. Both
failures are invisible in a running dev session and only surface on
the server.

```bash
# 1. The frontend image runs this -- if it fails, the build fails.
cd frontend && npm run build && cd ..

# 2. Migrations must apply to an EMPTY database, not just your dev one,
#    and the seeder must run on top of them.
cd backend-php
createdb migration_check -O websoft_app
DB_DATABASE=migration_check php artisan migrate:fresh --seed --force
dropdb migration_check
cd ..
```

And the suite, which gates `main` in both directions (CLAUDE.md):

```bash
cd backend-php && php artisan test && ./vendor/bin/pint --test
```

See [DEV_SETUP.md](DEV_SETUP.md#verifying-before-you-push-or-deploy)
for why `npx tsc --noEmit` does **not** substitute for step 1.

## 1. A server

Any small Linux box with Docker Engine + the Compose plugin: 2 vCPU /
4 GB RAM is comfortable for the ERP and Central Command together;
1 vCPU / 2 GB is enough for the ERP alone. The first build pulls PHP,
Postgres, nginx and LibreOffice (needed for the "Email X" buttons'
DOCX → PDF step) -- expect several minutes.

## 2. Install or upgrade

```bash
git clone https://github.com/dennisgoh84-webmaster/Websoft-Service-ERP.git
cd websoft-service-erp
./deploy/install.sh          # fresh server: writes .env, builds, migrates, seeds, starts
./deploy/upgrade.sh          # existing server: backup, pull main, rebuild, migrate, restart
```

`install.sh` generates every secret in `.env` (`POSTGRES_PASSWORD`,
`JWT_SECRET_KEY`, `APP_KEY`) and a random password for the seeded owner
account, printed once at the end. `.env.example` documents each key.

Two mailboxes, deliberately separate, neither falling back to the other:

- **System mailbox** -- `SMTP_*` in `.env`: login one-time codes,
  password resets, portal invites. Leave blank and a portal invite
  shows its temporary password on screen instead.
- **Company mailbox** -- Company Setup → Outbound email, in the app:
  what Invoices, Quotations and the other "Email X" documents are sent
  from, so they come from your own domain and pass SPF/DKIM.

## 3. Central Command

Deployed from its **own repository** with its own Compose stack; it
can share the server (its Postgres maps to host port 5433). It talks
to this ERP by writing straight into the ERP's PostgreSQL -- the tables
it touches are a schema contract, see
[docs/central-command-schema-contract.md](docs/central-command-schema-contract.md).

```bash
git clone https://github.com/dennisgoh84-webmaster/websoft-central-command
```

## 4. HTTPS (before sharing the link outside your own network)

The stack serves plain HTTP. The quickest way to put HTTPS in front of
both apps is Central Command's `scripts/setup-https.sh` (Caddy; a domain
gets a free Let's Encrypt certificate, a bare IP address gets Caddy's
own private one):

```
cd ~/central-command
sudo ./scripts/setup-https.sh 192.168.0.188:8443=8082 192.168.0.188:8444=8083
```

Then close the plain-HTTP port to everything but the server itself: set
`HTTP_BIND=127.0.0.1` in this repo's `.env` (and `CC_HTTP_BIND=127.0.0.1`
in Central Command's) and run `docker compose up -d`.

Or by hand:

- **Caddy** -- automatic Let's Encrypt:
  ```
  erp.your-domain.com {
      reverse_proxy localhost:80
  }
  cc.your-domain.com {
      reverse_proxy localhost:8080
  }
  ```
- **nginx + certbot** on the host, proxying to `127.0.0.1:80` / `:8080`.
- A tunnel (Cloudflare Tunnel, Tailscale Funnel) if you would rather
  not open inbound ports at all.

## 5. Backups

`./deploy/upgrade.sh` dumps the database into `backups/` before every
upgrade (last 10 kept). For a scheduled backup independent of upgrades:

```bash
docker compose exec db pg_dump -U websoft_app websoft_service_erp | gzip > backup-erp-$(date +%F).sql.gz
```

Restore (replaces the database's current contents):

```bash
gunzip -c backup-erp-*.sql.gz | docker compose exec -T db psql -U websoft_app websoft_service_erp
```

Uploaded files live in the `websoft-erp_uploads_data` volume; back it
up with `docker run --rm -v websoft-erp_uploads_data:/data -v "$PWD":/out alpine tar czf /out/uploads-$(date +%F).tgz -C /data .`.

## 6. Nightly self-test

Every night at 02:30 Singapore time the test server can check itself.
It builds a separate, throwaway copy of the app from the code checked
out: its own in-memory database, no mailbox, nothing published on the
host. It then walks every screen on a desktop and a phone, keys in the
main forms, and emails a pass/fail result with screenshots of anything
that failed ([docs/self-test.md](docs/self-test.md)):

```bash
sudo ./deploy/install-selftest.sh dennis@example.com
```

- The email is sent through **Maintenance → System Email**, so set that
  mailbox up first.
- Recipients are kept in `.env` as `SELFTEST_EMAIL_TO`
  (comma-separated).
- Run it now: `./selftest/run-server.sh`.
- Reports: `selftest/results/latest/report.html`.

## What runs where

```
┌────────────────────────── server ──────────────────────────┐
│  ERP stack (docker-compose.yml, project websoft-erp)        │
│    :80    nginx (frontend)                                  │
│             ├── /          → React SPA (desktop)            │
│             ├── /mobile    → React SPA (staff mobile)       │
│             ├── /portal    → React SPA (customer portal)    │
│             └── /api/*     → backend-php :8000              │
│    :8000  backend-php (Laravel; internal only)              │
│    :5432  PostgreSQL websoft_service_erp (internal only)    │
│                                                             │
│  Central Command stack (separate repo)                      │
│    :8080  cc-frontend → cc-backend → cc-db (:5433)          │
└─────────────────────────────────────────────────────────────┘
```
