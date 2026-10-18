# Performance check

SRS NFR-3: list and report screens return within 2 seconds. Checked on a
dataset built by `database/seeders/PerformanceSeeder.php`, which adds
200 items (one in five tracks expiry, three expiry dates each) and about 100,000
ledger movements spread over a year, on top of the demo data: 1,986 receipt,
issue and damage documents of 50 lines in 4 warehouses and 2 customers. Every
movement is posted through the database's `fn_post_movement`, so balances
equal the ledger (`stock:reconcile` confirms it, in 0.45 s).

How to repeat it: see README §2. The test is `tests/Performance/ScreenSpeedTest.php`;
it fails if any screen's median of three runs (after a warm-up) reaches 2 s.

## Results (8 Oct 2026, local Supabase Postgres, Windows laptop, PHP 8.2)

Signed in as the administrator (all warehouses), current warehouse Beirut Main.

| Screen | Median | Slowest single query |
|---|---:|---:|
| Issue list, all dates | 32 ms | 3 ms |
| Receive list, page 30 | 65 ms | 10 ms |
| Damage list | 32 ms | 3 ms |
| Issue form (open) | 33 ms | 2 ms |
| Issue form: choose customer, item, quantity | 193 ms | 5 ms |
| Stock page, this warehouse | 90 ms | 3 ms |
| Stock page, all warehouses | 209 ms | 6 ms |
| Damage report, a year, all warehouses | 373 ms | 23 ms |
| Audit log | 72 ms | 3 ms |
| Stock card, busiest item, a year | 313 ms | 7 ms |
| Item stock details, busiest item | 115 ms | 5 ms |
| Stock card Excel export, a year | 457 ms | 5 ms |

Every screen is well under the limit; the largest share of each time is
rendering, not the database.

## Indexes

Already in place for these screens: the list index
`stock_transaction (warehouse_id, transaction_type, document_date)`, the ledger
order index `stock_movement (product_id, warehouse_id, seq)` used by the stock
card and item details, and the balance primary key.

Added by `2026_10_12_000000_add_growth_indexes` for queries that grow with the
ledger rather than with what a screen shows:

| Index | Why | Before → after (100k movements) |
|---|---|---|
| `stock_movement (line_id)` | receiving and cancelling read each line's movements inside the posting transaction, while balances are locked | full scan 4.5 ms → 0.03 ms per line; the scan grows with the ledger, so a 50-line receipt at a few million movements would have held its locks for seconds |
| `audit_log (created_at DESC, id DESC)` | the audit log report: newest first, by period | grows with every document |
| `approval (transaction_id)` | pending approvals and the approver column of reports | grows with every approval |

## Not measured here

- Hosted Supabase adds network latency per query (typically 1–5 ms within a
  region, far more across regions): keep the app server in the same region as
  the database. Screens run 10–40 queries.
- Concurrent users: the posting path locks the balances it changes
  (`SELECT … FOR UPDATE`), so two people posting the same item in the same
  warehouse wait for each other; that is by design (SRS NFR-2).
