# Self-test program

Decided 2026-09-26 (open-business-decisions.md #49, "Self-test"): "one
command runs it, walking every screen on a desktop and a phone-sized
screen, keying in fields, saving and checking what was stored. It runs
before every push, and nightly on the test server with a pass/fail
email and screenshots of failures." Built 2026-09-26.

## What it checks

Every check runs twice: on a **desktop** (1366×768) and on a **phone**
(an Android profile, 412 px wide, touch, a phone's user agent — so the
app treats it exactly as it treats a real phone).

1. **Sign in** through the real sign-in page, including the one-time
   PDPA declaration a fresh account meets.

2. **Key-in flows** (`selftest/runner/flows/`). Each flow types into a
   real form the way staff would, then reads back through the API what
   the server stored, field by field:
   - Dates are typed as bare digits, as on a phone's number pad, so
     DateInput's own slashes are exercised.
   - Choices are picked from their lists by what they show.
   - The form is saved.

   The flows run in business order; later ones use what earlier ones
   made:

   | Flow | What is checked |
   |---|---|
   | Setup Lists | a Country, then a State in it |
   | Company / Individual | quick add (a lower-case, badly spaced name → FULL CAPITALS, tidied); profile (UEN, GST no., address with Country/State pickers, PO approval limit 500, credit limit); a contact person |
   | Product Catalog | a service sold in Hours |
   | Contract | 20 hours, SGD 3,000, typed start date; Activate issues the annual invoice (3,000 + 9% GST) |
   | Job Order | against that contract: products, type, billing, priority, due date |
   | Service Record | 50 minutes rounds up to 60 (SRV-007) |
   | Incident | an email whose sender (typed in capitals) is matched to the Company / Individual by its contact's email |
   | Quotation | a catalog line and a free-text line, GST, then Submit → Approve → Send → Accept into a Service Support and an Annual contract |
   | Sales Invoice | a catalog line, GST, due date from the 30-day terms, posted to the GL |
   | Receipt | recorded, allocated to the invoice, invoice paid |
   | Prospect | added, then a meeting logged on it |
   | Software Task | programmer, tester, target date, hours |
   | Purchase Order within the limit | 200 (218 with GST) against the 500 limit: a draft anyone with authority approves (PUR-001) |
   | Purchase Order above the limit | 1,000: needs the owner (PUR-001); approved, imported to Accounts Payable |
   | Supplier bill | tax code TX, an expense account other than the default |
   | Payment Voucher | recorded, allocated to the bill, banked |
   | Other receipt / Other payment | bank interest to 4900 and bank charges to 6500 (ACC-005), posted, banked into the Bank Book; the Bank Book has no entry form |
   | Warehouse, Stock Item | added |
   | Goods Receive Note | 10 at 12.50; nothing moves until Confirm; then quantity 10 at an average of 12.50 (INV-002) |
   | Stock Adjustment | −2; nothing moves while pending (INV-001); moves on Approve |
   | Journal Voucher | two balanced lines, Save & post |
   | Staff | a support engineer; username lower-cased; must change password at first sign-in |
   | Mobile App (phone only) | reached from the top bar's "Mobile app" switch; every tab checked; an activity logged on a prospect from the phone; back to the full site |

3. **Every screen** (`selftest/runner/screens.mjs`). The list is read
   from `frontend/src/App.tsx` at run time, so a new screen is swept
   without anyone listing it. A screen whose address carries an id
   (`/contracts/:id`, the print pages) opens a real record made by the
   flows. Every Setup List type is opened.

4. The **Customer Helpdesk Portal's** sign-in page.

Every screen, and the screen each flow ends on, fails on any of:
- a script error on the page;
- a server call that failed (4xx/5xx);
- an error banner shown;
- a value the screen could not fill in, showing as `undefined`, `NaN`
  or `[object Object]`;
- on the phone, a page wider than the screen (it scrolls sideways).
  The report names the widest element.

A failure keeps a full-page screenshot.

## Running it

**On a dev machine** (PHP, PostgreSQL, Node; see DEV_SETUP.md), one command:

```bash
./selftest/run.sh                  # everything, desktop + phone (~10 minutes)
./selftest/run.sh --only phone     # one screen size
./selftest/run.sh --grep Contract  # only checks whose name matches
./selftest/run.sh --serve          # start the throwaway app and leave it up
```

- It builds its own **throwaway database**, `websoft_selftest`.
  `php artisan selftest:prepare-db` refuses any database whose name
  does not end in `_selftest`, so real data can never be wiped by it.
- Every module is switched on in that database, paid add-ons included,
  so every screen is reachable.
- It starts its own backend and a production build of the frontend on
  spare ports (8100 / 4180), beside the usual dev servers, and stops
  them afterwards.
- It has **no mailbox and no WhatsApp**, so nothing keyed in can reach
  a real person.

Report: `selftest/results/latest/report.html`, with the failure
screenshots next to it. Exit code: 0 all passed, 1 something failed,
2 could not run.

`SELFTEST_CHROMIUM=/path/to/chrome` points it at an existing Chromium
when Playwright's own browser download is not on the machine.

## Before every push

`selftest/pre-push.sh` runs the whole gate from CLAUDE.md in order, and
any red step stops:
1. Pint;
2. the backend suite;
3. the frontend build;
4. the self-test.

`git config core.hooksPath .githooks` makes git run it on every push.

## Nightly on the test server

```bash
sudo ./deploy/install-selftest.sh dennis@example.com[,someone@example.com]
```

This installs the `websoft-selftest.timer` systemd timer, which runs
`selftest/run-server.sh` at **02:30 Singapore time**, and keeps the
recipients in `.env` as `SELFTEST_EMAIL_TO`. Each run:

1. **Builds a separate, throwaway copy of the app** from the same
   Dockerfiles as the real stack, so it tests exactly what is deployed.
   `selftest/docker-compose.yml`:
   - its own project, `websoft-selftest`;
   - its own database, in memory;
   - no mailbox;
   - nothing published on the host.
2. **Runs everything above** in Playwright's own image, pinned to the
   frontend's Playwright version.
3. **Removes that copy** again.
4. **Emails the result** through the *real* stack's System Email
   mailbox (Maintenance → System Email):
   - subject `Websoft self-test PASSED: all N checks (27/09/2026 02:30)`
     or `FAILED: n of N checks`;
   - what failed, where and why;
   - a screenshot of each failure attached (up to 15).

   A run that cannot even start still sends an email with the reason
   and the end of its log. Each email is recorded in Event Logs
   (`selftest_report_sent`).

Reports are kept under `selftest/results/<date-time>/` (the last 14),
`selftest/results/latest` → the newest. Run it by hand with
`./selftest/run-server.sh` (`--no-email` to skip the email), or
`sudo systemctl start websoft-selftest.service`.

## Adding to it

- **A new screen** needs nothing: it is swept from `App.tsx`.
- **A screen with an id in its address:** add where to find a record
  to `RECORD_FOR` in `screens.mjs`. Until then it is reported as
  skipped with that instruction.
- **A new or changed form gets a flow** in `selftest/runner/flows/`
  (CLAUDE.md: test every field by keying it in, on a desktop and a
  phone).
  - Find fields by their label with `keyIn(scope, 'Label', value)`. It
    handles `.form-row` labels, labels that wrap their control, and
    the stock screens' label-beside-control layout.
  - Read back with `apiGet` and assert with `expectStored`.

## What it found on its first runs (2026-09-26), all fixed

Found by the checks, or by reading the forms while writing the flows:

- **Document lines came back in random order.** Quotation, GRN, GTN,
  GRTN, GIN and Stock Adjustment lines have UUID keys and were read
  "by id", so a quotation could list its lines in a different order
  from the one keyed. Now `line_no` (`HasLineNumber`); the migration
  numbered existing lines and logged each document to Event Logs.
- **The supplier bill's Expense account was ignored.** The server
  never read it, so every bill posted to 5000. It is now stored,
  checked (an active expense account of the company) and posted to.
- **Payment Voucher allocations read "undefined: $ 1,090.00".** The
  API did not send the bill number.
- **Mobile App → Quotations showed "$NaN" for every amount**, no
  customer name, and a "Pending" filter that matched nothing. It read
  field names the API does not send.

- **Event Logs showed a GL posting's ledger lines as "[object
  Object]".** Nested values are now spelled out.
- **Email Inbox showed "no IMAP settings yet" as a red error.** It
  was the first run after that screen landed. It is an instruction,
  not a failure, so it is now a plain note, like the app's other
  "nothing set up yet" messages; a mailbox that fails to read is still
  red.
- **A Purchase Order within the supplier's approval limit could never
  be approved on screen.** PUR-001 lets anyone with authority approve
  it, and the server did, but the screen only offered "Approve (owner)"
  for POs above the limit. So such a PO stayed a draft and never
  reached Accounts Payable. It now has an "Approve" button.
