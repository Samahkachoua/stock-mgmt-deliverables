---
name: add-report
description: Use when adding a new operational report (e.g. beyond stock on hand, stock card, expiry, damage, adjustments, transfers-in-transit, audit log). Follow this checklist so new reports match the filtering, export, and permission conventions the rest of the app already uses.
---

# Add a new report

## 1. Define the question the report answers
Write down, in one sentence, exactly what question this report answers and
for whom (Operator? Manager? Auditor?). If it's a variant of an existing
report (e.g. "expiry report but grouped by customer"), prefer adding a
filter/grouping option to the existing report over creating a new one.

## 2. Write the query
- Base it on `stock_movement` (history) or `stock_balance` (current state) —
  never re-derive balances by summing movements at query time if
  `stock_balance` already has the answer; only query `stock_movement`
  directly for history/audit-style reports.
- Filter names must match the convention in `docs/api_endpoints.md`:
  warehouse, customer, product, date range, and (where relevant)
  reason/status. On screen they are properties of the report page, kept in
  the session by `App\Livewire\Concerns\RemembersListState` — never URL
  query parameters.
- Respect warehouse scoping: a user with restricted `user_warehouse` access
  must never see rows from a warehouse they're not assigned to, even via a
  report. Reuse the same middleware/guard used elsewhere, don't re-implement
  the check in the report endpoint.
- Respect customer scoping the same way if this session's user is a future
  customer-portal user.
- Follow "List state" and "Printing" in CLAUDE.md: no filters/page/sort in the URL; export and print from the component's current state.

## 3. Add the endpoint
Register the report in `App\Reports\Reports`; it then opens at
`GET /reports/<name>` (`App\Livewire\Reports\ReportPage`, a clean URL), and
its Excel/PDF buttons call the page's `export('xlsx'|'pdf')` Livewire action,
which uses the shared `App\Reports\Export\Exporter` with the page's current
filters (don't write a new Excel/PDF generator per report, and never an
export link that carries the filters). A link that must open the report
already filtered goes through a route that names the record and stores the
filters in the session (see `ListPresetController`).

## 4. Add the UI
- Filters at the top, results table below, export button — match the layout
  of the existing reports so users don't have to relearn the pattern.
- Column headers and filter labels go through the i18n layer (EN/AR/FR);
  check the Arabic layout, especially column alignment (RTL tables often
  need the export/filter bar mirrored too).
- If the report is likely to be checked often, consider a small dashboard
  tile/summary version, not just the full filterable table.

## 5. Test
- Unit test the query logic with a few warehouse/customer scoping cases,
  including a user who should NOT see certain rows.
- Test the export produces a valid file (row count matches the on-screen
  table).

## 6. Update docs
Add the report to the reports table in the SRS and to
`docs/api_endpoints.md`.



