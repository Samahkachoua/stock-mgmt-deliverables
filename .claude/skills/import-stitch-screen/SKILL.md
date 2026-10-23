---
name: import-stitch-screen
description: Use when turning a Google Stitch HTML/Tailwind export (in docs/design/stitch-exports/) into Blade + Livewire code for this Laravel app. Covers extracting shared components, converting to RTL-safe logical classes, theme tokens for light/dark, translations, and wiring real data.
---

# Import a Stitch screen into Laravel

Stitch exports are **design references, not production code**. Never paste an
export into `resources/views` as-is. The goal is the same look, built from the
app's shared components.

## 0. Get the design (Stitch MCP)
If the `stitch` MCP server is connected (`/mcp`), use its tools to find the
project and the screen by name, and fetch the screen's HTML and screenshot.
- **Read only.** Do not generate, edit or delete Stitch screens unless the
  user explicitly asks — generations use the user's Stitch quota and change
  the approved designs.
- Save what you fetched to `docs/design/stitch-exports/<screen-name>/`
  (`code.html` + `screenshot.png`) and commit it, so the design is versioned
  and available to anyone working without the MCP.
- Never print, log, or write the Stitch API key anywhere.
If the server isn't connected, use the files already in
`docs/design/stitch-exports/`, or ask the user to export the screen.

## 1. Read before writing
- Open the export in `docs/design/stitch-exports/<screen>/` (HTML + screenshot).
- Open `CLAUDE.md` → "Screen patterns". Decide whether this screen is a
  **List**, **New form** or **Details** page, or a non-transaction page.
- List the shared components the screen needs and check which already exist
  in `resources/views/components/` and `app/Livewire/`. Reuse first.

## 2. Shared components (build once, then reuse)
The first time, extract these from the Delivery note (Issue) screens; later screens only
configure them:
- `x-layouts.app` — sidebar, top bar (warehouse selector, theme switch,
  language switch, user menu)
- `x-page-header` — title, type icon/colour, primary action
- `x-filters-bar`, `x-data-table` (clickable rows, pagination, empty state)
- `livewire:transaction-form` — header card + lines grid + sticky footer,
  configured per transaction type (header fields, extra line columns,
  hint text, button label, colour)
- `x-transaction-summary`, `x-lines-table`, `x-activity-list` for details pages
- `x-type-badge`, `x-status-badge` — read colours from the shared colour map
If a Stitch screen differs from an existing component only cosmetically,
keep the component and ignore the difference. Stitch is not always consistent
between screens.

## 3. Convert the markup
- **Physical → logical classes** (required for Arabic):
  `ml-/mr-` → `ms-/me-`, `pl-/pr-` → `ps-/pe-`, `left-/right-` →
  `start-/end-`, `text-left/right` → `text-start/end`, `rounded-l/r` →
  `rounded-s/e`, `border-l/r` → `border-s/e`, `space-x-*` → `gap-*`.
  Directional icons get `rtl:-scale-x-100`.
- **Hard-coded colours → theme tokens**: replace hex values and colour
  utilities (`bg-[#F8FAFC]`, `bg-white`, `text-slate-900`, `bg-orange-600`…)
  with the app's CSS-variable-backed classes (surface, text, border, brand,
  type-*, status-*) so light and dark both work. No per-element `dark:` pairs.
- **Text → translations**: every visible string becomes `__('...')` with keys
  added to `lang/en`, `lang/ar`, `lang/fr`. Keep sample data out of lang files.
- Drop anything Stitch added that is not in the approved design brief
  (extra widgets, charts, images, icons with no action, scan/upload controls).
- Remove Stitch's CDN scripts/fonts; the app's Vite build provides Tailwind and fonts.

## 4. Wire real data
- Replace sample rows with Livewire properties / Eloquent queries using the
  warehouse- and customer-scoped models.
- Item search: only the selected customer's active items, searched by name
  (current locale) or code, debounced, max ~20 results.
- Available-quantity hint: from `stock_balance` for the current warehouse,
  converted into the selected unit.
- Saving goes through `App\Services\Stock\PostingService` — never write
  movements or balances from a component.

## 5. Check before calling it done
- Compare side by side with the Stitch screenshot in Light theme.
- Switch to Dark theme: surfaces, red errors, amber warnings readable.
- Switch to Arabic: layout mirrored, codes/references still left-to-right,
  no clipped labels.
- Keyboard: Tab order follows Customer → Item → Unit → Quantity → next row.
- Pest feature test for the component's main behaviour (e.g. new row
  appended after quantity entry, Save disabled while a row is invalid).
