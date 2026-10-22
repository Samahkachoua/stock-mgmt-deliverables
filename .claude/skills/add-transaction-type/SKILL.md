---
name: add-transaction-type
description: Use when adding a new kind of stock transaction (beyond the initial IN, OUT, TRANSFER, DAMAGE, ADJUSTMENT, OPENING_BALANCE) or a new workflow status. Follow this checklist end to end rather than improvising, since transaction_type/transaction_status are database tables the whole app depends on.
---

# Add a new transaction type or status

`transaction_type` and `transaction_status` are database tables
(`database/sql/schema.sql`), not enums. Adding a new one is primarily a data change
plus wiring, not a schema change — follow these steps in order.

## 1. Confirm it's actually needed
Check `database/sql/schema.sql` and `docs/SRS.docx` first. Most new requirements fit
an existing type with a different `reason_code` (see the `DAMAGE` and
`ADJUSTMENT` reason enums) rather than needing a whole new transaction type.
Only proceed if the new thing genuinely needs its own workflow (its own
status progression, its own approval rule, or a different set of
required fields).

## 2. Insert the reference row
Add a migration that inserts into `transaction_type` (skip it when the row
is already there), and add the same row to the seed data in
`database/sql/schema.sql`, which always describes the whole current database:
```sql
INSERT INTO transaction_type
  (code, name, requires_destination_warehouse, requires_dispatch_receive,
   supports_approval_threshold, display_order, active, posting_direction, uses_expired)
VALUES ('YOUR_CODE', 'Human Name', FALSE, FALSE, TRUE, 7, TRUE, 'OUT', FALSE)
ON CONFLICT (code) DO NOTHING;
```
Decide each flag deliberately:
- `requires_destination_warehouse` / `requires_dispatch_receive`: true only
  if this type moves stock between two warehouses like TRANSFER.
- `supports_approval_threshold`: true if this type should route through
  `fn_resolve_threshold` / the approval workflow like DAMAGE/ADJUSTMENT.
- `posting_direction`: IN adds stock, OUT takes it; NULL when decided per
  line (ADJUSTMENT) or in two steps (TRANSFER).
- `uses_expired`: true only if the type may take expired stock (DAMAGE).

If you need a new **status** (not just a type), insert into
`transaction_status` the same way, setting `is_terminal` and
`affects_stock` correctly — `affects_stock = true` means posting/reaching
this status must call `fn_post_movement`.

## 3. Wire the backend
- Add the create/submit/(approve|dispatch|receive) endpoints following the
  existing pattern in `docs/api_endpoints.md` for a comparable type (DAMAGE
  is the closest template for a simple type; TRANSFER for one with
  dispatch/receive).
- Do not write a new posting function — reuse `fn_post_movement` /
  `fn_apply_balance`. A new transaction type should never need new ledger
  logic, only new header/line validation around the existing posting call.
- If `supports_approval_threshold = true`, call `fn_resolve_threshold` with
  the new type's code exactly as you would for DAMAGE/ADJUSTMENT.
- Define the new type's duplicate-line key (CLAUDE.md rule 12) in its UI config; the shared form and PostingService enforce it.

## 4. Wire the frontend
- Add the screen following the "one task per screen" rule in `CLAUDE.md`.
- Add a distinct color for the new type, consistent with the existing
  IN/OUT/Transfer/Damage color coding.
- Add the icon/label to any transaction-type picker/filter UI.

## 5. Translate
Add the type's display name and any new field labels to all three language
files (EN/AR/FR). Test the new screen specifically in Arabic (RTL).

## 6. Test
- Unit test: the new type's validation rules (required fields, thresholds).
- Integration test: full flow from create through to posted, asserting
  `stock_movement` and `stock_balance` end up correct.
- If `supports_approval_threshold`, a test that the approver-cannot-equal-
  requester rule still applies.

## 7. Update docs
Add the new type to `docs/api_endpoints.md` and to the SRS's transaction
type table so the two stay in sync with the database.


