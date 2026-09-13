# Web Master Central Command

A separate application from the client ERP instances. Central Command
manages all deployed Websoft Service ERP installations from one place.

## What it does

| Feature | Description |
|---------|-------------|
| **Client Registry** | Register each client ERP instance with its PostgreSQL connection details |
| **Connection Testing** | Test connectivity to client databases, verify Alembic version |
| **Advertisement Push** | Create announcements, assign to specific clients, push to their `announcements` table |
| **Video Banner Push** | Set promo video URLs and push to client `ad_banner_settings` |
| **License Management** | View and toggle module licenses (`company_modules`) on client databases |
| **Config Updates** | Draft SQL-based configuration changes, push to all or selected clients |
| **Push Logging** | Full audit trail of every push operation |

## Architecture

```
┌──────────────────────────────────────┐
│        Central Command               │
│  ┌────────────┐  ┌────────────────┐  │
│  │  React UI  │  │  FastAPI API   │  │
│  │  :5174     │  │  :8001         │  │
│  └────────────┘  └────────────────┘  │
│                       │              │
│               ┌───────┴───────┐      │
│               │ CC Database   │      │
│               │ (PostgreSQL)  │      │
│               └───────────────┘      │
└──────────────────────────────────────┘
         │              │
    TLS + auth     TLS + auth
         │              │
  ┌──────┴────┐   ┌─────┴─────┐
  │ Client A  │   │ Client B  │  ...
  │ ERP DB    │   │ ERP DB    │
  └───────────┘   └───────────┘
```

Central Command has its **own** PostgreSQL database for:
- Admin users
- Client registry (connection details)
- Advertisement templates + assignments
- Config update drafts + push logs

It connects to each client's PostgreSQL **on demand** to read/write
the tables defined in the
[schema contract](../docs/central-command-schema-contract.md):
`announcements`, `ad_banner_settings`, `company_modules`, `modules`,
`companies`.

## Quick Start (Development)

### 1. Start the Central Command database

```bash
cd central-command
docker compose up cc-db -d
```

Or create manually:
```bash
createdb -U postgres central_command
psql -U postgres -c "CREATE USER cc_app WITH PASSWORD 'cc_dev_local';"
psql -U postgres -c "GRANT ALL ON DATABASE central_command TO cc_app;"
```

### 2. Backend

```bash
cd central-command/backend
uv sync                          # install dependencies
uv run python seed.py            # create tables + admin user
uv run uvicorn app.main:app --port 8001 --reload
```

Default admin login: `admin` / `Admin123`

### 3. Frontend

```bash
cd central-command/frontend
npm install
npm run dev                      # starts on http://localhost:5174
```

### 4. Docker (full stack)

```bash
cd central-command
docker compose up --build
```

## Client-Side Schema Contract

Central Command writes to these tables in each client ERP database:

- `announcements` — announcement items (upsert by UUID)
- `ad_banner_settings` — singleton row with promo video URL
- `company_modules` — enable/disable module licenses
- `modules` — read-only reference of available modules
- `companies` — read-only, used for identification

See [central-command-schema-contract.md](../docs/central-command-schema-contract.md)
for full column definitions.

### Alembic Version Check

Before writing to any client database, Central Command reads
`alembic_version.version_num` and verifies compatibility. If the
client's schema is too old, the push is refused.

## API Endpoints

| Method | Path | Description |
|--------|------|-------------|
| POST | `/api/auth/login` | Admin login |
| GET | `/api/auth/me` | Current admin |
| GET | `/api/dashboard/` | Dashboard statistics |
| GET/POST | `/api/clients/` | List / create clients |
| GET/PATCH/DELETE | `/api/clients/{id}` | Get / update / delete client |
| POST | `/api/clients/{id}/test-connection` | Test client DB connectivity |
| GET/POST | `/api/advertisements/` | List / create ads |
| PATCH/DELETE | `/api/advertisements/{id}` | Update / delete ad |
| POST | `/api/advertisements/{id}/push` | Push ad to assigned clients |
| POST | `/api/advertisements/push-all` | Push all active ads |
| GET/POST | `/api/advertisements/videos` | List / create video settings |
| POST | `/api/advertisements/videos/{id}/push` | Push video to clients |
| GET | `/api/licenses/{client_id}/modules` | Read client modules |
| POST | `/api/licenses/{client_id}/companies/{company_id}/modules` | Toggle module |
| GET/POST | `/api/config-updates/` | List / create config updates |
| PATCH | `/api/config-updates/{id}` | Update config |
| POST | `/api/config-updates/{id}/push` | Push to all active clients |
| POST | `/api/config-updates/{id}/push/{client_id}` | Push to specific client |

## Settled Decisions

All 6 open questions from planned-work.md §8 were resolved:

1. **Client DB registry** → Central Command's own DB, `clients` table
2. **Network access** → Internet with TLS + auth
3. **Ad targeting** → Manual per-client assignment
4. **Audit trail** → Central Command's own push logs only
5. **Schema versioning** → Check `alembic_version` table
6. **Future scope** → Yes, config updates (tax rates, settings) too
