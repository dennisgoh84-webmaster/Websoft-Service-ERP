# Test server: install and upgrade

One command to stand up a Websoft Service ERP test server, and one to
upgrade it. Both run the **PHP/Laravel backend** (`backend-php/`),
which is where all current work lives.

> **Not yet run anywhere.** These scripts and the Docker image were
> written without a Docker daemon available, so nothing here has been
> executed end to end. The application itself is well tested (802
> passing), but *this packaging* is unproven — expect to fix something
> on the first build, and read [What can go wrong](#what-can-go-wrong)
> before you start.

---

## What you need

- A Linux server (2 GB RAM is enough; LibreOffice makes the image
  chunky, so allow ~5 GB disk)
- Docker Engine with Compose v2 — `docker compose version` must work
- Port 80 free, or set `HTTP_PORT` to something else

---

## Install

```bash
git clone https://github.com/dennisgoh84-webmaster/Websoft-Service-ERP.git
cd websoft-service-erp
./deploy/install.sh
```

That generates the secrets into `.env`, builds the images, starts
Postgres, applies migrations, seeds demo data, and starts the app. It
prints the URLs and the demo login when it finishes.

**Or, without git** — download and unpack a package (see
[Making a package](#making-a-package)), then run `./deploy/install.sh`
inside it.

`install.sh` refuses to run if `.env` already exists, so it can never
quietly re-key or re-seed a server someone is using.

---

## Upgrade

```bash
cd websoft-service-erp
./deploy/upgrade.sh
```

In order: **backs up the database first**, pulls the latest `main`,
builds the new images while the old ones keep serving, applies
migrations, restarts.

The backup comes first because migrations are the one step a restart
cannot undo. If the dump comes out empty the script stops before
anything touches the schema. Backups land in `backups/`, last 10 kept,
and the rollback commands are printed at the end.

The login screen shows the version the build came from, e.g.
**Version 1.0.291 · 27/09/2026**: the major.minor in the repo's
`VERSION` file (changed by hand only for a big release), then the number
of changes in the code's history -- so it goes up by itself with every
release -- and that change's date (Singapore time). `deploy/version.sh`
stamps it in on every install and upgrade, and the upgrade agent reports
it to Central Command's Client Upgrades, which shows the same wording
for this server, so the two can be tallied.

### Nightly self-test

`sudo ./deploy/install-selftest.sh you@example.com` installs
`websoft-selftest.timer`. At 02:30 Singapore time it runs
`selftest/run-server.sh`: a throwaway copy of the app, every screen on
a desktop and a phone, the main forms keyed in, then a pass/fail email
through the System Email mailbox. See [../docs/self-test.md](../docs/self-test.md).

### Upgrades from Central Command (no SSH needed after the first deploy)

`install.sh` and `upgrade.sh` both install a systemd timer,
`websoft-upgrade-agent.timer`, that runs `deploy/upgrade-agent.sh`
once a minute. The agent reports the checked-out commit to the app
and performs any upgrade or rollback that Central Command has queued
in the `upgrade_requests` table, by running `./deploy/upgrade.sh
<commit>` exactly as you would by hand. So the deploy that brings in
this version is the last one you need to do over SSH.

- The shared secret lives in `.env` as `UPGRADE_AGENT_TOKEN`
  (generated automatically).
- `systemctl status websoft-upgrade-agent.timer` shows the timer;
  `backups/upgrade-logs/agent.log` is the agent's own log and each
  run's full output is in `backups/upgrade-logs/upgrade-<id>.log`.
- If the installer could not write systemd units (no sudo), run
  `sudo ./deploy/install-upgrade-agent.sh` once.
- An agent-driven upgrade leaves the checkout detached at that commit;
  a manual `./deploy/upgrade.sh` goes back onto `main` by itself.

Uploaded files and the database are in named Docker volumes. Nothing
in either script deletes them — neither ever runs `down -v`.

```bash
./deploy/upgrade.sh --no-pull    # rebuild the checkout you already have
```

---

## Day to day

```bash
C="docker compose"          # from the repo root, where .env is

$C ps                         # what is running
$C logs -f backend-php        # application log
$C restart backend-php        # restart just the app
$C exec db psql -U websoft_app -d websoft_service_erp   # database shell
$C down                       # stop everything (keeps all data)
```

---

## What can go wrong

**The build fails on `libreoffice-writer`.** It is a large package and
a slow mirror can time out. Re-run `./deploy/install.sh` — Docker
resumes from its cached layers. It is not optional: without it the
Word downloads keep working while every "Email X" button fails with
"PDF conversion failed", which is a silent, one-sided failure that is
easy to miss.

**Port 80 is taken.** `HTTP_PORT=8080 ./deploy/install.sh`, or edit
`HTTP_PORT` in `.env` and re-run `$C up -d`.

**`docker: permission denied`.** Add yourself to the docker group:
`sudo usermod -aG docker $USER`, then log out and back in.

**Migrations fail.** Nothing is half-applied — Laravel wraps each
migration in a transaction. Read `$C logs migrate-php`, fix, and
re-run `./deploy/upgrade.sh --no-pull`.

---

## Making a package

For a server without git access, or to pin exactly what you shipped:

```bash
./deploy/make-package.sh
```

Writes `dist/websoft-service-erp-<date>-<commit>.tar.gz` plus a
`.sha256` next to it. Move it across and unpack:

```bash
scp dist/websoft-service-erp-*.tar.gz you@test-server:~/
ssh you@test-server
sha256sum -c websoft-service-erp-*.tar.gz.sha256
tar xzf websoft-service-erp-*.tar.gz
cd websoft-service-erp-*/
./deploy/install.sh
```

The package excludes `.git`, `node_modules`, `vendor`, and any local
`.env` — the images build their own dependencies, and secrets are
generated on the target server, never carried in a tarball.

Upgrading from a package means unpacking the new one beside the old
and running `./deploy/upgrade.sh --no-pull` in it, with `.env` and
`backups/` copied across first:

```bash
cp ../websoft-service-erp-<old>/.env .
cp -r ../websoft-service-erp-<old>/backups . 2>/dev/null || true
./deploy/upgrade.sh --no-pull
```

---

## Security note

This recipe is for a **test server**: plain HTTP and seeded demo data.
Put HTTPS in front before it holds anything real —
[DEPLOY.md section 6](../DEPLOY.md).

The owner account is `dennis@websoft.example`. The seeder's password is
published in this repository, so `install.sh` replaces it with a
randomly generated one and prints it once, at the end of the install —
write it down, it is not stored anywhere you can read it back. To set a
different one at any time:

```bash
$C exec backend-php php artisan user:set-password \
    dennis@websoft.example '<new password>'
```

`.env` is written mode 600 and holds `APP_KEY`, which encrypts stored
mailbox passwords. Keep it: without it those cannot be read back.
