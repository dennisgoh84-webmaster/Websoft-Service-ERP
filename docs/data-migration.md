# Data Migration (ODOO / ZSOFT)

**Status:** built 2026-09-25. It lives at **Maintenance → Data Migration** and
is gated by the `data_migration` module. It grew out of the Odoo-only
command-line importer recorded as
[planned-work.md #6](planned-work.md#6-odoo-migration-program----contacts-subscriptions-timesheets-sales-quotationsinvoicesreceipts-chart-of-accounts-raised-2026-09-12).
That importer is now the engine underneath these screens; the command itself
was removed.

It brings data in from the two old systems, **ODOO** and **ZSOFT**, one module
at a time, into the **Internal Company** you choose.

## Decisions (Dennis, 2026-09-25)

| Question | Decision |
|---|---|
| Where the data comes from | Each system's own **Excel/CSV exports**. The tool never connects to ODOO or ZSOFT. |
| Document numbers | Imported documents **keep their old number** (`INV/2025/00001`, `ZI-2019-0001`, `SUB/2025/0001`). This system's own counters carry on for new documents. A record the old system never numbered (an ODOO timesheet) takes this system's next number. |
| General Ledger | **No ledger data comes from either system.** The new financial year starts from management-accounts opening balances, keyed in as one **Journal Voucher** here. Migrated invoices and receipts are **history only** and post nothing. |
| ZSOFT past invoices | History with no ledger posting. Each one sits under its Company / Individual, so it shows on that record and on the **Prospect** screens for the same party. |
| Duplicates | Same **UEN or GST registration no.** as an existing Company / Individual → **linked** automatically. **Name only** → you choose **Link** or **Create new** in the preview. An existing record is never merged into or overwritten. |
| Roll back | **Remove if untouched.** A batch's records are removed only if none has been changed or used since the import. Otherwise nothing is removed and the blockers are listed. The batch, a copy of every removed record and the Event Log entry stay forever. |
| Staff who have left | Created as **inactive users**, who can never sign in, so their history keeps a name. |
| Field Gap | Every column in an old file is **mapped**, **left out**, or marked a **Field Gap** (it needs a field added here first). A module's import stays **locked until its list is signed off**. |
| Access | New `data_migration` module in Module Control / Group Authority. **VIEW** sees the Dashboard, the modules and the Batch Log. **FULL** can upload, map, sign off, dry run, import and roll back. Switch it off after cut-over. |

Still open (open-business-decisions #10.1 / #10.2): which history is
"important", and the phasing order. The tool imports whatever subset is
chosen, whenever it is chosen.

## The screens

1. **Dashboard.** Live progress, with one bar per module showing the records
   imported out of the rows in its latest uploaded file, plus rows with
   errors. It refreshes every 5 seconds while an import runs and every 30
   seconds otherwise.
2. **Migration Modules.** Every module in run order. Each row has three
   actions:
   - **Field Gap:** the column-by-column mapping, with Export and Sign off.
   - **Import:** upload that module's consolidated Excel file.
   - **Roll back:** undo its last import.
3. **Import.** Four steps:
   - **Upload.** Choose the Internal Company, the source system and the
     module, then add the `.xlsx`/`.csv` file (up to 20 MB, first row =
     column headings).
   - **Map fields.** Known column names map automatically, each shown with a
     sample value. The mapping is saved for the next file of the same
     module. Changing it clears the sign-off.
   - **Dry-run preview.** The run does everything the import would, then
     undoes it. It shows:
     - how many records are new, linked, already imported or skipped;
     - the **possible duplicates** to decide;
     - the **errors**, which you can export with their row numbers.
   - **Import.** Only after a clean dry run, with the Field Gap list signed
     off. It is all or nothing and runs in the background with a progress
     bar.
4. **Batch Log.** Every batch with who and when (DD/MM/YYYY), with filters and
   Export. **View** reopens a batch. **Roll back** needs a reason.

## Modules, in run order

ODOO runs first, so that ZSOFT's Company / Individual rows can link to the
ones ODOO brought in.

| # | Source | Their module | Here | Notes |
|---|---|---|---|---|
| 1 | ODOO | Contacts | Company / Individual | A person under a company (parent) becomes a Contact person. |
| 2 | ODOO | Subscriptions | Contracts | Add Contracted hours / Consumed hours columns by hand (ODOO has no hours). |
| 3 | ODOO | Sales Quotations | Quotations | Lines included. An accepted quotation creates no contract. |
| 4 | ODOO | Sales Invoices | Sales Invoices (history) | Amount due carried. Credit notes, drafts and cancelled invoices are skipped. |
| 5 | ODOO | Customer Payments | Receipts (history) | Marked applied before cut-over. Can never be banked again. |
| 6 | ODOO | Timesheets | Service Records | Filed under one closed Job Order per Company / Individual + project + task. |
| 7 | ZSOFT | Customers | Company / Individual | Links to ODOO records by UEN / GST no. |
| 8 | ZSOFT | Contracts | Contracts | |
| 9 | ZSOFT | Job Orders | Job Orders | Old number and status kept. The contract is found by its number. |
| 10 | ZSOFT | Service Records | Service Records | Filed under the migrated Job Order named on the row. |
| 11 | ZSOFT | Past Invoices | Sales Invoices (history) | No Amount due column = fully paid history. |

## Rules every module follows

- **Money is never recomputed.** Every amount is the old system's own figure.
  The GST rate recorded is the rate actually charged, taken from the
  document's own tax and net amounts, so a 7%/8%-era invoice keeps its rate.
  A document with no GST needs a Tax code column (ZR / ES / OS). Which one it
  was is not ours to guess.
- **Only SGD.** A row in any other currency fails.
- **Dates.** DD/MM/YYYY, or the ISO dates that ODOO and Excel write.
- **Re-uploads are safe.** A row already imported from the same source (by
  its Source ID, or failing that its document number) is left as it is and
  never overwritten.
- **Service Records don't deduct again.** Old hours are kept exactly, with no
  re-rounding. Migrated records are approved and `not_hour_metered`, because
  a migrated contract's balance already arrives as its Consumed hours.
- **PDPA consent is never set** by migration.
- **Audit.** Every upload, mapping change, sign-off, dry run, import, refused
  import, duplicate decision, roll back and export is written to Event Logs.

## How it works underneath

- **`migration_batches`:** one row per uploaded file, kept for its whole
  life. It holds:
  - the stored file, under `<uploads_dir>/<company>/migration/<batch>/`;
  - the column headings and a sample row;
  - the mapping and duplicate decisions used;
  - the latest run's row-by-row report;
  - live progress;
  - the roll-back record.
- **`migration_record_map`:** which record each source row became, per
  source system. `created` or `linked`. Roll back removes only `created`
  records and then stamps the map rows `rolled_back_at`, so the same rows can
  be imported again.
- **`migration_mappings`:** the saved mapping and Field Gap sign-off per
  Internal Company + source + module.
- **`invoices.pre_migration_paid_sgd` / `payments.pre_migration_allocated_sgd`:**
  what the old system had already settled. A receipt recorded here after
  cut-over settles the rest of a migrated invoice without resetting the old
  payments. **`migrated_at`** marks a migrated invoice or receipt, and the
  Bank step refuses a migrated receipt.
- **Background runs.** A dry run or import runs as a background
  `php artisan data-migration:run` process, so a large file can outlast a web
  request. Its progress goes through a second database connection, because
  the import's own transaction is invisible until it commits.
  - `MIGRATION_BACKGROUND=false` runs it inside the request instead.
  - `PHP_CLI` names the command-line PHP binary.
- **Code:**
  - `app/Services/DataMigration/`:
    - `MigrationEngine` (the run);
    - `MigrationBatches` (the lifecycle);
    - `MigrationMappings` (Field Gap);
    - `MigrationRollback`;
    - `MigrationCatalog` (modules and auto-mapping);
    - one importer per module under `Importers/`.
  - API: `DataMigrationController`, routes `routes/api/data_migration.php`.
  - Screens: `frontend/src/pages/DataMigration*Page.tsx`,
    `components/DataMigration.tsx`.

## Next steps

1. Dennis exports every module from ODOO and ZSOFT as Excel.
2. Upload each file, then look at its **Field Gap** list. Export it and send
   it over.
3. Fields marked Field Gap get added here, and shown on that module's screen.
4. Once every list is signed off, take the latest exports and import them for
   real, in the order above.
