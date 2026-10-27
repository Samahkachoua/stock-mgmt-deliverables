# Stock Management System — CLAUDE.md

## What this is
Multi-warehouse stock management for a logistics (3PL) company. Stock is
always owned by a customer, never by the company running the warehouses.

Naming: the OUT type is shown as **Delivery note** (`DN-`) and a draft of it as
**Order** (`ORD-`); older documents keep their `ISS-` / `DRI-` numbers. Codes
stay direction-based (`OUT`, `stock.out.*`, `draft.out.*`).
A draft (Order / Draft receipt) has one warehouse, in its header
(`stock_draft.warehouse_id`); confirming it creates one Delivery note / Receipt.
A Delivery note is only ever created by confirming an Order (no "New" page):
Confirmed → Dispatched → Delivered (rule 14).

Reference material (read before large changes):
- `database/sql/schema.sql` — source of truth for tables, constraints, posting functions
- `docs/SRS.docx` — functional requirements (cite FR numbers in commits/PRs)
- `docs/api_endpoints.md` — endpoint and response conventions
- `docs/erd.png` — entity relationships
- `docs/design/stitch-exports/` — approved UI designs (also reachable live via
  the `stitch` MCP server, read-only; see the `import-stitch-screen` skill)
- Secrets (Stitch API key, Supabase keys, DB passwords) live only in `.env` /
  the user's local MCP config — never in committed files, CLAUDE.md, or output.

Primary product goal: a warehouse operator must be able to use this with
**zero training**. When two implementations are possible, pick the one that
needs less explanation, not the one that is more configurable.

## Stack
- PHP 8.3+, Laravel (latest stable) — fill in exact version: `<fill in>`
- UI: Blade + Livewire 3 + Tailwind CSS. Designs come from Google Stitch as
  HTML/Tailwind exports; convert them with the `import-stitch-screen` skill.
- Back-office CRUD (warehouses, customers, units, users): Filament (optional,
  decide once) — `<keep or remove>`
- Database: Supabase Postgres, used as a plain Postgres database only.
  **Not** used: Supabase Auth, Supabase Data API (PostgREST), Realtime, Edge
  Functions. Laravel owns auth, authorization and all data access.
- File storage (only uploaded Excel import files): Supabase Storage through its
  S3-compatible endpoint, as a Laravel `s3` disk (`use_path_style_endpoint => true`).
  Transactions have **no attachments or photos** — don't add upload fields.
- Excel import/export: `maatwebsite/excel`. PDF: **mPDF** (not dompdf — dompdf
  does not shape Arabic text correctly).
- Tests: Pest, against Postgres. Lint/format: Laravel Pint. Static analysis: Larastan.

## Commands
- Tests: `php artisan test`
- Format: `vendor/bin/pint`
- Static analysis: `vendor/bin/phpstan analyse`
- Local DB: `supabase start` (Supabase CLI) or any local Postgres 15+ — never
  run tests or `migrate:fresh` against the hosted Supabase project.

## Database rules (Supabase + Laravel)
1. The first migration (`database/migrations/0001_01_01_000000_create_stock_schema.php`)
   loads `database/sql/schema.sql` with `DB::unprepared(file_get_contents(...))`.
   The file has no BEGIN/COMMIT on purpose — Laravel wraps migrations in a
   transaction already. Never add BEGIN/COMMIT to it.
2. Later changes are normal Laravel migrations. Use `DB::unprepared()` for
   plpgsql functions/triggers. Never edit a migration that has already run in
   any shared environment; add a new one.
   **`schema.sql` always describes the whole current database** (all tables,
   Laravel's own included, columns, indexes, functions, seed data). With every
   new migration, make the same change in `schema.sql` too, and start the
   migration's `up()` with a guard that returns when the change is already
   there (e.g. `if (Schema::hasColumn('stock_movement', 'seq')) { return; }`),
   so a database built from `schema.sql` skips it and an older one gets it.
3. **Every new table must get RLS enabled** in the same migration:
   `DB::statement('ALTER TABLE <name> ENABLE ROW LEVEL SECURITY');`
   This includes Laravel's own tables (sessions, cache, jobs, failed_jobs,
   password_reset_tokens, migrations). Supabase exposes `public` tables through
   its Data API; RLS with no policies blocks that while Laravel (the table
   owner) is unaffected. A Pest test asserts every public table has RLS on.
   The Data API should also be switched off in the Supabase dashboard.
4. Connection: use Supabase's **session-mode pooler or direct connection
   (port 5432)** with `sslmode=require`. Do NOT use the transaction-mode pooler
   (port 6543) — Laravel/PDO relies on prepared statements and session state
   (and the ledger relies on `SELECT ... FOR UPDATE` inside transactions).
5. Ids are `BIGINT GENERATED ALWAYS AS IDENTITY` (never insert an id; use
   `insertGetId(..., 'id')`). In PHP they are strings of digits: models use
   `App\Models\Concerns\HasNumericKey`, the pgsql connection fetches with
   `PDO::ATTR_STRINGIFY_FETCHES`, and ids from the browser are checked with
   `App\Support\Ids::valid()` (validation rule `integer`, routes `whereNumber`).
   A database created before the switch is converted once with
   `database/sql/convert_uuid_to_bigint.sql`.
   Users table is `app_user`, not Laravel's default `users`:
   - `App\Models\User`: `protected $table = 'app_user';`, `use HasNumericKey;`,
     `protected $authPasswordName = 'password_hash';`
   - Delete Laravel's default `create_users_table` migration; keep its
     `sessions` and `password_reset_tokens` parts but make `sessions.user_id`
     a `foreignId`.
6. Permissions use the schema's own `role` / `permission` / `role_permission`
   / `user_role` / `user_warehouse` tables (no spatie package). Register them
   as Gates at boot (cached), and check them in Policies — never inline
   `if ($user->role == ...)` checks in components.
   Each list (with its details and print pages) has its own view permission
   (`stock.in.view` … `stock.opening.view`, `draft.in.view`, `draft.out.view`;
   `view` in config/transactions.php). Creating or approving/confirming a type
   opens it too (gates `documents.<slug>`, `drafts.in.open` / `drafts.out.open`).
   `stock.view` is only the Stock page. Each type cancels with its own
   permission (`stock.in.cancel`, `stock.out.cancel`, `stock.opening.cancel`;
   `cancel` in config/transactions.php; a transfer In transit: its dispatcher).
   Each report has its own (`Report::permission()`, `report.<key>.view`).
   Settings › Roles shows one section per type (`permission.module`,
   `Permission::MODULES` order).
7. Store `TIMESTAMPTZ` in UTC (the app and the DB session run in UTC). One
   company-wide time zone (Settings › General, `app_setting` `timezone`,
   `App\Support\CompanyTime`) decides "today" (form defaults, future-date
   checks, expiry, document number years) and every time shown
   (`Format::dateTime()` converts to it). Never use `CarbonImmutable::today()`,
   `now()->toDateString()` or a per-warehouse time zone for these.

## Non-negotiable business rules
These are enforced in the database, but the application must also respect
them so users get a clear, translated message instead of a raw DB error.

1. **Balances are never edited directly.** Every stock change is an immutable
   `stock_movement` row written by `fn_post_movement`, which also updates the
   `stock_balance` cache in the same transaction. All posting goes through one
   class, `App\Services\Stock\PostingService`, inside `DB::transaction()`.
   Never write `UPDATE stock_balance` or insert into `stock_movement` anywhere else.
   Reservations (rule 14) go only through `App\Services\Stock\Reservations`
   (`fn_reserve` / `fn_release_reservations`); never write `stock_reservation`
   or `stock_balance.qty_reserved` by hand.
2. **Negative stock is always blocked.** `fn_apply_balance` raises
   `Negative stock blocked: ...`. `PostingService` must pre-check available
   quantity and throw a domain exception that the UI shows inline next to the
   quantity field (and the API returns as `409` with the available amount).
   Line quantities are whole numbers up to **999,999 in the chosen unit**
   (`PostingService::MAX_QTY`; base quantity ≤ `MAX_BASE`), checked live in
   the forms, in the Excel import and again in PostingService / DraftService.
3. **Base unit = smallest unit.** The base unit's own `product_unit` row has
   `factor_to_base = 1`; every alternate unit must be `> 1` (1 Box = 12 Piece).
   Enforced by `trg_validate_product_unit`; validate the same rule in the Form
   Request / Livewire validation so the user sees it before saving.
4. **`transaction_type` and `transaction_status` are database tables**, not
   PHP enums or constants. Load them via models (cache at boot); never
   hardcode a `match`/`switch` over type codes that can drift from the DB.
   Adding a type is a data change — use the `add-transaction-type` skill.
5. **Transfers are always two steps**: dispatch (source → `in_transit_balance`)
   then receive (in-transit → destination), with short receipt supported.
   Never add a one-step "move" shortcut.
6. **Approval thresholds**: product override → customer default → global
   default (`fn_resolve_threshold`). Approver ≠ requester — check it in the
   Policy too, not only via the DB constraint. A user who holds the type's
   approve permission needs no second approval: their own Adjustment/Damage
   over the limit is posted at once (audit_log `self_approved`), never left pending.
7. **Warehouse scoping is mandatory.** A user can only act on warehouses in
   `user_warehouse`. Enforce it in one shared place (middleware + a global
   query scope on warehouse-bound models), never by hand per component.
   There is **no warehouse selector in the top bar**: each page that needs a
   warehouse has its own field or filter, starting on the user's default
   warehouse (`app_user.default_warehouse_id`, set in Settings › Users;
   `User::defaultWarehouseId()`).
8. **Customer scoping**: every stock query is filterable by `customer_id` and
   must stay correct for a future customer-portal user who sees only their
   own customer's stock.
9. **Serial-tracked products** use only their base unit — hide the unit
   dropdown for them. **Expiry-tracked products** require an expiry date AND
   a batch number on every Receive / Opening Balance / Draft receipt line
   (and in the Opening Balance import). Stock is kept per **lot** = expiry
   date + batch (`expiry_date` and `batch_no` on lines, draft lines,
   movements, balances and in-transit rows; `App\Support\Batches`).
   - A batch has **one expiry date** per item: `product_batch` (unique item +
     batch), referenced by lines and movements; typing a known batch fills
     in and locks its date, another date is refused (`BatchDateMismatch`).
     Only expiry items have batches (`trg_validate_product_batch`). Batch
     numbers are kept trimmed and upper case, at most 40 characters, no "|".
   - Stock from before batches has no batch ("No batch", `batch_no` NULL);
     it can still go out or be counted.
   - On Delivery note, Transfer, Damage, Order and Adjustment the user picks
     the lot per line from one dropdown ("B-1042 · 12 Mar 2027 · 40 Piece";
     the form's value is `Batches::key()`, "2027-03-12|B-1042"); the nearest
     is pre-selected; Damage may also pick expired stock. `PostingService`
     takes stock only from that lot. A line with a date but no batch takes
     the date's only batch, and is refused ("Choose the batch") when the date
     has several. A line without a date (no UI path today) falls back to
     FEFO across lots: nearest expiry, then "No batch", then batch number.
10. **Adjustments are entered as a counted quantity**, not a +/− amount. The
    line stores what the user counted; the difference against the system
    balance (at save time) becomes an IN or OUT movement. Show the difference
    live in the form.
11. **No backdated stock going out.** A Delivery note, Transfer or Damage dated
    before today may only take what was in the warehouse on that date and
    must leave every later day ≥ 0 (`Availability::onDate()`: a movement
    counts on its document date; transfer stock at the destination on the
    day it was received). Serials must have been there on that date too.
    The form's "Available on <date>" hint, expiry dates and serials follow
    the chosen date; PostingService re-checks it on save and on approval.
12. **No duplicate lines in a transaction.** A line's key (`line_key` in
    config/transactions.php, `TypeUi::lineKey()`):
  - Receive, Opening Balance, Delivery note, Transfer, Damage: item + unit, plus the
    lot (expiry date + batch) for expiry-tracked items (so one item can still
    be taken from two lots with two rows, rule 9).
  - Adjustment: item + lot, in any unit (a count is compared with the
    whole balance: counting it in two units would adjust it twice).
  Two lines with the same key are never allowed; the same item in another
  unit (not on a count) or with another lot is. Availability is checked on
  the combined base quantity of all lines of that item (of that lot).
  - Prevent it while typing: a new row of an item starts on a unit (and
    nearest lot) no other row of it has yet. When a row's key still
    matches an existing row, clear the new row, show the toast "Olive Oil 1L
    (Carton, batch B-1042, expiry 12 Mar 2027) is already in row 1 — change
    the quantity there" and move focus to that row's quantity.
  - Check again on save (server side, `PostingService` for every path, and
    `DraftService` for a draft): `DuplicateLine`, same toast,
    red border on both rows. The Opening Balance import adds up rows of the
    same item + unit + expiry + batch (and a serial item's pieces) into one line.
  - Database safety net: unique index `uq_transaction_line_key` on
    stock_transaction_line over transaction + item + unit + expiry + batch,
    with "no expiry" / "no batch" each treated as one value.
13. **Service items never touch stock.** An item with `product.is_service`
    (packing, delivery fee...) has only its base unit, no expiry and no
    serials (`ck_product_service_untracked`, `trg_validate_product_unit`).
    It may be a line only on types with `services` in config/transactions.php
    (Receipt, Delivery note and their drafts; `TypeUi::allowsServices()`):
    the line is saved, but no movement or balance is written
    (`fn_post_movement` refuses a service) and availability is never checked.
    Stock reports and the other types' item pickers leave services out
    (`Product::stocked()`, `ItemCatalog` `withServices`).
14. **Orders reserve stock; a Delivery note has three steps.** Saving an Order
    reserves its lines (`stock_reservation`, cached in
    `stock_balance.qty_reserved`); editing releases and reserves again,
    cancelling releases. Confirming creates a Delivery note in `CONFIRMED` that
    takes over the reservation (no movement). Dispatch (`DISPATCHED`) records
    the optional driver name, phone and truck number. Deliver (`POSTED`, shown
    "Delivered") releases the reservation and posts OUT only for the quantities
    delivered: less needs a reason (`PostingService::DELIVERY_REASONS`), a line
    may be replaced by another item (`replaces_line_id`); differences are in
    the Delivery discrepancies report. Cancelling before delivery releases the
    stock. **Available = in stock − reserved** everywhere stock is taken
    (`Availability`, `ItemCatalog`: item search, hints, Transfer, Damage,
    other Orders); an Order being edited counts its own reservation
    (`ownDraftId`). A count may not go below the reserved quantity, and
    `fn_apply_balance` refuses any OUT that would ("Reserved stock blocked").
    A Delivery note's stock leaves on the day it is delivered (`delivered_at`).

## i18n and RTL (English, Arabic, French)
- Every user-facing string goes through `__('file.key')` with translations in
  `lang/en/*.php`, `lang/ar/*.php`, `lang/fr/*.php`. No hardcoded strings in
  Blade, Livewire, validation messages, or notifications.
- Layout sets `<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">`.
- **Use Tailwind logical classes only**: `ms-/me-`, `ps-/pe-`, `start-/end-`,
  `text-start/text-end`, `rounded-s/rounded-e`, `border-s/border-e`.
  Never `ml-/mr-/pl-/pr-/left-/right-/text-left/text-right` — Stitch exports
  use physical classes and must be converted on import.
- Mirror directional icons (arrows, chevrons, back) in RTL with `rtl:-scale-x-100`.
- Numbers, quantities and dates are formatted per locale; SKUs, barcodes and
  serial numbers are always shown left-to-right (wrap in `dir="ltr"`).
- Fonts: a family that covers Arabic and Latin (e.g. IBM Plex Sans Arabic or
  Cairo for Arabic, Inter for Latin).
- A screen is not done until it has been checked in Arabic.

## Screen patterns (from the approved Stitch designs)
Designs: `docs/design/stitch-exports/`. Every transaction type (Receive,
Delivery note, Transfer, Adjustment, Damage, Opening Balance) uses the SAME three
pages, built from shared Blade/Livewire components — never a one-off layout
per type. Only the colour, title and the type-specific columns differ.

1. **List page** — title + "+ New <Type>" button; filters: search (reference
   or customer), Warehouse (users with several warehouses: starts on their
   default, or "All my warehouses"), Customer, Date range, Status; table:
   Reference, Date, Customer, Items, Status, Created by
   (Transfer adds From → To; several warehouses add a Warehouse column).
   The whole row links to the details page. Paginated.
2. **New transaction form** — header card (Customer first and required;
   everything else disabled until it is chosen; Warehouse pre-filled with
   the user's default, hidden when they have only one; Date defaults to
   today; optional one-line Reference note; plus type-specific header fields:
   Reason for Adjustment/Damage, From and To warehouse for Transfer).
   Changing the warehouse after lines exist clears the lines (warn inline first).
   Lines grid: Item (searchable by name or code, limited to the chosen
   customer's items) → Unit (dropdown, defaults to base unit) → Quantity,
   with a grey hint under it: "Available: 64 Piece" in the selected unit,
   plus "= 36 Piece" when the unit isn't the base unit. When a row's
   quantity is filled, a new empty row is appended automatically; the empty
   last row is never validated or saved. Delete icon per row. Errors are
   shown in red under the field; Save is disabled while any row is invalid.
   Sticky footer: summary ("3 items · 212 Piece"), Cancel, Save.
   Type-specific columns:
   - Receive / Opening Balance: Batch | Expiry date, shown only on rows whose
     item tracks expiry; hint reads "In stock now", no maximum.
   - Delivery note / Transfer / Damage: Batch · expiry dropdown (batch · date
     · quantity, nearest pre-selected), only on rows whose item tracks expiry;
     the "Available" hint and maximum are for that lot. To take from two
     lots, add two rows.
   - Adjustment: In system (read-only) | Counted (input) | Difference
     (auto, green +/red −). Expiry items also show the Batch · expiry dropdown
     (the lots in stock; there is no way yet to count a batch the system
     doesn't have).
   - Serial items: Quantity is replaced by a serial-number picker/input.
   - Adjustment/Damage above the approval threshold: a one-line notice
     above the footer and the button becomes "Send for approval".
   Changing the customer after lines exist clears the lines (warn inline first).
3. **Details page** (read-only) — back link, reference + status badge,
   summary card, lines table (Transfer: Sent | Received, editable only for
   the destination warehouse while In transit, with a reason dropdown when
   they differ), status-dependent actions (Approve/Reject for managers on
   pending items, Confirm receipt, Print), and an Activity list from `audit_log`.

## Density
All sizes come from tokens in resources/css/app.css (type scale, spacing,
control height, row height) — never hard-coded per page.
- Base text 14px (was 16), tables 13px, secondary text 12px; nothing
  below 12px. Headings scale down proportionally.
- Arabic uses one step larger (base 15px, tables 14px) because Arabic
  glyphs read smaller at the same size.
- Controls: 36px high with a mouse, 44px on touch (`@media (pointer: coarse)`).
- Table rows ~40px (cell padding ~8px × 12px); cards padding 16px (was 24);
  gaps between sections 16px; page padding 16–24px.
- Phone layouts keep the touch sizes; density mainly gains space on
  tablet and desktop.
- Never use CSS zoom/transform or a smaller root font-size hack to shrink
  the UI; contrast rules still apply.
## Responsive layout
Breakpoints: phone < 768px, tablet 768–1279px, desktop ≥ 1280px (Tailwind md / xl).
No horizontal page scrolling at any width. Built once in the shared
components — never per page.

**App frame**
- Desktop: full sidebar. Tablet: sidebar collapsed to icons (labels as
  tooltips). Phone: sidebar hidden, opened as a drawer from a menu button
  in the top bar (closes on navigation / outside tap; slides from the
  correct side in Arabic).
- Phone top bar: menu button, page title, user menu; theme and language
  move into the user menu. No warehouse selector (see rule 7).

**Filters bar**
- The search input keeps its current size and position.
- All other filters (Customer, Status, Date from, Date to, …) have EQUAL
  width in a grid: desktop 4 per row, tablet 2 per row; they wrap, never
  shrink unevenly.
- Phone: search stays visible; the other filters move behind a
  "Filters" button showing the active count ("Filters · 2"), opening a
  panel with them stacked full width plus "Apply" and "Clear filters".

**Lists and reports**
- Desktop: full table. Tablet: table with low-priority columns hidden
  (e.g. Created by, Items); each list defines its column priority.
- Phone: each row becomes a card — reference + status badge on top,
  then customer and date; the whole card is tappable.
- Pagination becomes "Previous / Next" with "Page 2 of 9" on phone.

**Transaction form**
- Header card fields: 3 per row desktop, 2 tablet, 1 phone.
- Lines grid: table on desktop/tablet; on phone each line is a card —
  Item full width, then Unit and Quantity side by side, the
  "Available" hint under them, delete icon in the card corner; new
  empty card appended automatically as on desktop.
- Item picker on phone opens as a full-screen search panel.
- Sticky footer on phone: summary on one line, Save full width below.

**Details page**
- Summary card 2 columns desktop/tablet, 1 on phone; lines table → cards
  on phone; action buttons wrap, primary action first.

**Overlays**
- Toasts: top-end on desktop/tablet, full width at the top on phone.
- Confirmation modal: centred dialog on desktop/tablet, bottom sheet on phone.

**Rules**
- Use the theme tokens and logical classes as always; check every
  breakpoint in Arabic and dark mode.
- Touch targets ≥ 44px on all sizes; no hover-only actions.
- Print layout is unaffected by these breakpoints.

## Confirmations (delete and other destructive actions)
One shared confirmation modal component; never the browser's `confirm()`.

**Always confirm**
- Delete any saved record (unit, role, approval limit, …)
- Deactivate a warehouse, customer, item or user
- Cancel a completed transaction or an in-transit transfer
- Reject a pending adjustment/damage
- Leave a form with unsaved changes (Cancel, back link, any other link):
  "Discard your changes to Canned Beans?" / "Discard changes". Nothing
  changed, or just saved, leaves without asking. Shared: the
  `WarnsBeforeLeaving` trait + `<x-leave-guard>` as the form's root
  (item form today). Closing the tab gets the browser's own question.
- Save an Adjustment/Damage that posts at once (under the approval limit,
  or self-approved): it changes stock immediately and can't be cancelled.
  "Send for approval" does not ask — nothing changes until it is approved.
- Save an Opening Balance (`confirm_save` in config/transactions.php): it
  posts at once; the question asks to check items and quantities (it can
  still be cancelled later, so it doesn't say it is final).

**Don't confirm**
- Saving other forms, approving, confirming receipt (they already show a
  success toast and can be reversed by Cancel)
- Removing a line from a form that hasn't been saved yet

**The modal**
- Title names the action and the record: "Delete unit Box?",
  "Cancel delivery note DN-2026-000123?"
- One sentence on the consequence: "This returns 36 Piece of Canned Beans
  400g to Beirut Main stock and can't be undone."
- Two buttons: "Keep it" (default focus, safe) and a red button with the
  specific action: "Delete unit", "Cancel delivery note" — never a bare "Yes/OK".
- Cancelling a transaction and rejecting require a reason (required field
  inside the modal), saved to audit_log.
- Esc and clicking outside close it without action; focus returns to the
  button that opened it.
- If the record can't be deleted (in use, has stock), don't open the
  confirm — show why instead: "Box is used by 12 items and can't be deleted."
- The submit button in the modal uses the loading state; result shown with
  the usual success/error toast.
- The server always re-checks permission and rules; the modal is only UI.
- Theme tokens, RTL-aware, translated in en/ar/fr with parameters.
## Feedback messages (success / error)
One shared toast component + one notify helper; never ad-hoc alerts per page.

**Success**
- After every successful save/action, show a green toast with the result
  and the reference: "Delivery note DN-2026-000123 saved" + link "View".
- Sent for approval → amber toast: "Damage DMG-2026-000045 sent for manager approval".
- Approve / Reject / Confirm receipt / Cancel each have their own message.
- Survives the redirect (session flash), auto-closes after 10 s (`config('ui.toast_seconds')`), closable.

**Errors — always say what went wrong, in the user's language**
1. Validation errors: inline under each field (as already defined) PLUS a
   red toast summarising: "2 rows need attention". Entered data is kept.
2. Business errors: each has its own exception class with a translation
   key and parameters, shown in a red toast and inline where relevant, e.g.
   - InsufficientStock → "Only 64 Piece of Canned Beans 400g available in Beirut Main"
   - OnlyExpiredStock → "The remaining 20 Piece of Olive Oil 1L are expired"
   - StaleCount → "Stock changed since this count — reject it and count again"
   - SelfApproval → "You can't approve a transaction you created"
   - AlreadyProcessed → "This transfer was already received by Nour at 10:42"
   - NotAllowed → "You don't have permission to do this"
   PostingService translates database errors (e.g. "Negative stock blocked")
   into these exceptions — raw DB/PHP messages never reach the screen.
3. Unexpected errors: red toast "Something went wrong and nothing was saved.
   Reference: ERR-7F3A2C — please tell your administrator." The full error
   (stack trace, user, URL, input) is logged with that reference.
4. Connection lost / timeout: "Connection lost — your entries are still here,
   try again." Session expired (419): "Your session expired — please sign in
   again", instead of Livewire's default dialog.
- Error toasts stay until closed. Never show SQL, stack traces, file paths
  or exception class names; APP_DEBUG=false outside local.

**Presentation**
- Position: top-end (logical, so it moves to the left in Arabic), stacked,
  max 3 visible.
- Theme tokens for light/dark; icon + text (never colour alone);
  role="status" for success, role="alert" for errors.
- All messages in lang/en|ar|fr with parameters (reference, item, quantity,
  unit, warehouse) — no concatenated strings.
## Loading states
Use Livewire's built-in loading tools (`wire:loading`, `wire:target`,
`wire:loading.attr`, `wire:loading.delay`) through shared components —
never ad-hoc spinners per page.

**Form submit (Save / Send for approval / Confirm receipt / Approve / Reject)**
- The moment it's clicked: the button is disabled, shows a small spinner and
  changes label to "Saving…" (translated); the rest of the form is
  read-only (`wire:loading.attr="disabled"` targeted at the submit action).
- No delay on submit feedback — it must appear instantly.
- Double submit is impossible: besides disabling the button, each form
  instance has a submission token (UUID created on mount) saved in
  `stock_transaction.client_request_id` (unique). A repeated request with
  the same token returns the already-created transaction instead of
  creating a second one.
- On success: redirect to the details page with a short success toast.
- On error: button re-enabled, error toast shown, problem fields outlined in
  red, entered lines kept..

**Lists and reports (filter, search, sort, page change)**
- Keep the current rows visible but dimmed (`opacity-50`, `pointer-events-none`)
  with a thin progress bar at the top of the table while reloading — the
  table must not jump or go blank.
- First load of a list: skeleton rows (5–8 grey bars matching the columns),
  not a full-page spinner.
- Use `wire:loading.delay` (~200 ms) for list loaders so fast responses
  don't flicker.
- Search inputs are debounced (300 ms) and show a small spinner inside the
  field while searching (also for the item picker in the lines grid).

**Other**
- Page navigation uses `wire:navigate` with Livewire's top progress bar,
  coloured with the brand token.
- Export buttons show a spinner and "Preparing file…"; exports expected to
  take more than a few seconds run as a queued job and notify when ready.
- Loaders use theme tokens (light/dark), work in RTL, and set
  `aria-busy="true"` on the region being reloaded.

## List state (filters, sorting, pagination) — never in the URL
- List and report URLs stay clean (e.g. `/delivery-note`). Filters, search, sorting,
  page number and page size are NEVER put in the query string.
- Livewire list components: no `#[Url]` attributes; use
  `WithPagination` together with `WithoutUrlPagination`.
- State is kept per user and per list in the session
  (e.g. `session('list_state.delivery-note')`), restored on mount, so going to a
  details page and back keeps the user's filters. Every list has a
  "Clear filters" link that resets it.
- Exports (Excel/PDF) and Print use the component's current state through a
  Livewire action — never a link that rebuilds the filters as URL parameters.
- Route parameters that identify a record (e.g. `/delivery-note/{id}`) are fine;
  this rule is about filter/sort/page state only.
- Date filters are always two fields, "Date from" and "Date to", empty by
  default (no date filter), inclusive, interpreted in the company time zone.

## Printing
- Every screen that can be printed has a dedicated print view
  (e.g. `/delivery-note/{id}/print`) using a separate `layouts.print` layout:
  no sidebar, no top bar, no buttons, no dark theme.
- Print layout: company name/logo, document title and reference, status,
  summary fields, lines table, totals, printed by/at, signature lines
  (Prepared by / Received by). A4, `@page { size: A4; margin: 12mm; }`,
  table header repeated on each page, rows not split across pages.
- Always light colours and black text, regardless of the user's theme;
  Arabic prints right-to-left with the Arabic font.
- The "Print" button opens the print view, which calls `window.print()` on load.
- As a safety net, the app layout's sidebar, top bar and action buttons carry
  `print:hidden`, so browser printing of a normal page doesn't show them either.

## UX rules for "no training needed"
- Nothing that isn't in the approved designs: no barcode scanning UI, no
  photo/file attachments on transactions, no dashboard widgets on Home or
  the operator pages. Figures and charts live only on the owner dashboard
  (`/dashboard`, permission `dashboard.view`, ADMIN by default; built from
  `App\Reports\Dashboard`, each figure = the row count of the report it
  opens). If a new field seems useful, ask before adding it.
- The system suggests instead of asking: base unit pre-selected, nearest expiry
  date pre-selected (changeable) for Delivery note/Transfer/Damage, today's date pre-filled.
- Prevent errors instead of reporting them: live availability hint and
  inline validation while typing; Save disabled until the form is valid.
- Same colour per transaction type everywhere (one shared colour map):
  Receive green, Delivery note orange, Transfer blue, Adjustment purple,
  Damage red, Opening Balance slate. Status badges: Pending approval amber,
  In transit blue, Completed green, Rejected red, Cancelled grey.
- Normal saves never ask for confirmation (except a final Adjustment/Damage
  and an Opening Balance).
  Deleting, deactivating, cancelling and rejecting always do (see "Confirmations"). Empty states say what to do next.
- Compact density (see "Density"): inputs and buttons 36px with a mouse,
  44px on touch screens (`pointer: coarse`). Every page works on phone,
  tablet and desktop — see "Responsive layout".

## Light / dark theme
- Per-user choice `light` / `dark` / `system` (default `system`), stored in
  `app_user.preferred_theme`, switched from the top bar without a reload.
- Tailwind class-based dark mode (`dark` class on `<html>`). The server
  renders the class for light/dark; for `system`, a small inline script in
  `<head>` applies `prefers-color-scheme` before first paint (no flash).
- All colours are CSS variables defined once (surface, text, border, brand,
  one per transaction type and status) with light and dark values; views use
  the tokens, never hard-coded hex values or per-component `dark:` colour pairs.
- Contrast: text ≥ 4.5:1, icons/borders ≥ 3:1 in both themes — check red
  errors and amber warnings in dark mode especially.
- Excel/PDF exports and printouts are always light.
- A screen is done only when checked in light, dark, and Arabic.

## Testing expectations
- Every action that posts stock (submit, dispatch, receive, approve) has a
  Pest feature test proving negative stock is blocked and balances end correct.
- Concurrency test for the ledger: two separate DB connections reserving
  (ordering) the same last stock; only one may succeed. (Must not run inside
  `RefreshDatabase`'s wrapping transaction — use `DatabaseMigrations` or
  truncation for that test.)
- Arch/DB test: every table in `public` has `rowsecurity = true`.
- Tests use a local Postgres database (`phpunit.xml`: `DB_CONNECTION=pgsql`,
  `DB_DATABASE=stock_test`). **Never SQLite** — the schema depends on plpgsql
  functions and triggers that SQLite cannot run.
- After any migration change, run it on a scratch database before committing.

## Skills in this repo
- `add-transaction-type` — adding a stock transaction type or status
- `add-report` — adding an operational report
- `import-stitch-screen` — turning a Stitch HTML export into a Livewire screen

## When in doubt
Check `database/sql/schema.sql` first — it shows what the database already
enforces and therefore what the application must also check and explain.
