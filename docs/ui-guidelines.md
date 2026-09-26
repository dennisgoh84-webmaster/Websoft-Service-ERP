# UI Guidelines: Labels, Export, and Print

This documents the conventions every screen should follow, so the app reads
as one system rather than 27 separately-invented ones. It was written by
generalizing the patterns already established on the Invoices module
(`InvoicesPage.tsx` / `InvoicePrintPage.tsx`), which is the reference
implementation for everything below -- when in doubt, look at how Invoices
does it.

## 1. Screen structure and labels

- **Page title** is the module name as it appears in the sidebar nav
  (`Layout.tsx`), as an `<h1>`. Don't invent a different name for the same
  page in two places.
- **Section headings** (`<h2>`) name what's in the card below them, usually
  with a live count: `Quotations (12)`, `Bills (4)`. Prefer the plural noun,
  not a verb ("Quotations", not "View quotations").
- **Explanatory copy** under the title is a `<p className="muted">`
  summarizing what the screen does and citing the confirmed business rule
  it implements (e.g. "AR-002: write-offs need a reason..."), matching
  `docs/business-requirements.md`'s rule IDs. This is how a screen documents
  itself instead of needing a separate manual.
- **Primary submit buttons** (create/save/record) use the default button
  style, no `className`, and a present-tense verb + noun: "Create
  quotation", "Record receipt", "Raise PO". While the request is in
  flight, disable the button and swap the label to the "-ing" form:
  `{saving ? 'Creating...' : 'Create quotation (Draft)'}`.
- **Secondary actions** (reset, cancel, remove-a-line, status-change
  actions like Send/Reject/Approve, and navigational buttons like Print)
  use `className="secondary"`.
- **Destructive or money-moving actions that need a reason** (write-off,
  dispute) use `window.prompt` to collect the reason inline rather than a
  modal -- consistent with the rest of the app not using a modal library.
- **Filter bars** live in a `<div className="filter-bar">` at the top of a
  card, each filter in a `<div className="form-row" style={{ margin: 0 }}>`,
  ending in a `secondary` "Reset filters" button that clears every filter
  in that bar (not just some of them).
- **Terminology**: "Job Order" (not Ticket), "Service Record" (not
  Timesheet) -- see `CLAUDE.md`. Money is always labelled with the currency
  code (SGD), not a `$` sign, except inside a printed form's line-item
  table where `($)` in the column header is enough.

### Dates (standardised 2026-09-25)

Every date reads and is typed **DD/MM/YYYY**, whatever the viewer's
browser locale or time zone (Dennis's standing rule).

- **Showing a date:** always `formatDate()` / `formatDateTime()` from
  `lib/format.ts`, never a raw `YYYY-MM-DD` and never
  `toLocaleDateString()`. Timestamps are shown in **Singapore time**
  (the company's time zone), so a date saved as midnight Singapore time
  never reads as the day before in a browser set to another zone.
- **Entering a date:** always `<DateInput>` (`components/DateInput.tsx`),
  never `<input type="date">`, whose format follows the browser's locale
  (often MM/DD/YYYY). It is a drop-in: `value` is the ISO date and
  `onChange` gets `{ target: { value } }`. It accepts a typed
  DD/MM/YYYY, rejects an impossible date such as 31/02, honours
  `min`/`max`/`required` through the browser's own form validation, and
  has a calendar button for picking.
  **Since 2026-09-26** (Dennis, after keying dates on a phone): the
  slashes fill in as digits are typed -- 12092026 shows 12/09/2026, a
  phone's number pad having no "/" -- a two-digit year is read as 20yy,
  and the calendar button has the browser's own date input laid
  invisibly over it, so a tap on a phone opens the phone's picker.
- **A document the user dates** (a supplier bill, a voucher, a stock
  note) offers its date field, defaulting to today -- never a hidden
  "always today".
- **Test every date field by keying it in**, on a desktop and on a
  phone-sized screen (Playwright with a phone device profile): digits
  only, a picked date, then submit and check what was saved.
- **"Today" as a default:** `todayIso()` from `lib/format.ts` (today in
  Singapore), not `new Date().toISOString()` (today in UTC, which is
  still yesterday before 8am Singapore time).
- Month-only pickers (report periods) stay `<input type="month">`: they
  show a month and year, with no day to mix up.

## 2. Export (CSV / Excel) -- for every list/report screen

Report screens (Accounting, Operations, Stock) also offer **PDF** in the format list
(2026-09-24): `window.print()`, with the print stylesheet hiding the
filters and `ReportHeader` printing the company, report title, the
filters in force and the print time -- the same browser print route the
print forms use, so no server-side PDF step.

Every screen whose main content is a table of records gets an Export
control, placed in the filter bar (or, if there's no filter bar, at the top
of the card) so it exports whatever the current filters show.

**Frontend**: use `<ExportControl>` (`frontend/src/components/ExportControl.tsx`)
rather than hand-rolling a select + button:

```tsx
<ExportControl
  formats={[{ value: 'csv', label: 'CSV' }, { value: 'excel', label: 'Excel' }]}
  onExport={async (format) => {
    const blob = format === 'csv'
      ? await api.exportXCsv(currentFilters())
      : await api.exportXExcel(currentFilters())
    downloadBlob(blob, format === 'csv' ? 'x.csv' : 'x.xlsx')
  }}
/>
```

It renders a single **"Export"** button with the format `<select>` to its
right (order changed 2026-09-24 at Dennis's request) (never
"Export CSV" as the permanent label -- the format select already says
that), shows "Exporting..." and disables itself while the request is in
flight, and surfaces a thrown error the same way the rest of the page
does (via the page's own `error` state -- `ExportControl` takes an
`onError` callback for this).

**Backend**: one `GET .../export.csv` and one `GET .../export.xlsx`
endpoint per module, next to its `list_*` endpoint, built from:

```python
FIELDS = ["col_a", "col_b", ...]

def _row(obj) -> dict: ...
def _list_for_export(db, company_id, ...filters) -> list[dict]: ...

@router.get("/export.csv")
def export_x_csv(..., db=Depends(get_db), current_user=Depends(require_module_access(MODULE, AccessLevel.VIEW))):
    rows = _list_for_export(db, current_user.company_id, ...)
    return StreamingResponse(iter([exports.rows_to_csv(FIELDS, rows)]), media_type="text/csv",
        headers={"Content-Disposition": "attachment; filename=x.csv"})

@router.get("/export.xlsx")
def export_x_excel(...):
    ...  # same, via exports.rows_to_excel(...)
```

Reuse `app/services/exports.py` (`rows_to_csv` / `rows_to_excel`) --
never write a CSV/XLSX writer by hand in a router. Apply the same filters
and `require_module_access` gate as the module's `list_*` endpoint, so
export never leaks rows or fields the viewer couldn't otherwise see.

## 3. Print / Word forms -- for documents handed to a customer or supplier

A "document" is anything that leaves the company as a physical or PDF
paper: Invoice, Quotation, Receipt (customer-facing), Payment Voucher
(supplier-facing). These get a dedicated `.../:id/print` page, not just a
row in a table.

**Row action**: the list page gets a `secondary`-styled `<Link>` to the
print route, labelled exactly **"Print"**:

```tsx
<Link to={`/quotations/${q.id}/print`} className="secondary" style={{ padding: '6px 10px' }}>
  Print
</Link>
```

**The print page** (`QuotationPrintPage.tsx`, etc.) follows
`InvoicePrintPage.tsx` structurally and visually -- same CSS classes
(`invoice-sheet`, `form-header`, `form-meta`, `invoice-lines`,
`totals-strip`, `form-signature-row`, ...), since those classes are
already generic enough for any document type and already match
Webmaster's own letterhead (reference: Quote_0160). A new print page
should not invent new layout CSS; it reuses what's in
`frontend/src/index.css` under `## invoice/print forms`.

Format choice is a `<select>` (`PDF (Print)` / `Word`) plus a single
**"Export"** button in a `no-print` bar above the form, exactly like
Invoices:

```tsx
async function onExport() {
  if (exportFormat === 'pdf') { window.print(); return }
  downloadBlob(await api.exportXDocx(id), `${doc.number}.docx`)
}
```

**Backend**: the Word version is generated by a small, document-specific
function in `app/services/docx_forms.py` (one function per document type
-- there is deliberately no generic templating layer, since a one-record
form's layout is specific to what it's showing), exposed as
`GET /api/x/{id}/export.docx`. The PDF version is never generated
server-side -- it's always the browser's own Print dialog against the
print page, so the PDF and the on-screen form can never drift apart.

## 4. What NOT to add this to

Detail/edit pages for a single record that isn't a document handed to
someone outside the company (Customer/Contract/Job Order/Staff detail,
Company Setup, Module Control) don't get an Export control. Config
screens aren't reports.

## 5. Checklist for a new screen

- [ ] Title matches the sidebar label exactly.
- [ ] If it's a list: Export control in the filter bar, wired to
      `export.csv`/`export.xlsx` endpoints that reuse `app/services/exports.py`
      and the module's existing `require_module_access` gate.
- [ ] If it's a document: a `.../:id/print` page reusing the shared
      print-form CSS, a "Print" row-action link, and a
      `docx_forms.py` function + `export.docx` endpoint.
- [ ] Buttons follow section 1's labelling (verb+noun primary action,
      `secondary` for everything else, "-ing" label while saving).
