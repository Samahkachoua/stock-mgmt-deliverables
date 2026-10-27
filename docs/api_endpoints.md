# API Endpoint List — Multi-Warehouse Stock Management System

Base path: `/api/v1`. All endpoints require a bearer auth token except `/auth/login`.
All list endpoints support `?page=`, `?pageSize=`, and relevant filters. All write
endpoints are authorized server-side against the caller's role permissions and
warehouse assignments, not just hidden in the UI.

## Auth & Users

| Method | Path | Description |
|---|---|---|
| POST | `/auth/login` | Authenticate, returns access + refresh token |
| POST | `/auth/refresh` | Refresh an access token |
| POST | `/auth/logout` | Invalidate the current session |
| GET | `/users` | List users (Admin) |
| POST | `/users` | Create user |
| GET | `/users/{id}` | Get user detail |
| PUT | `/users/{id}` | Update user (name, roles, warehouses, language) |
| POST | `/users/{id}/deactivate` | Deactivate a user |
| GET | `/roles` | List roles and their permissions |
| PUT | `/roles/{id}/permissions` | Update a role's permission set (Admin) |
| GET | `/permissions` | List all available permission codes |

## Master Data

| Method | Path | Description |
|---|---|---|
| GET | `/warehouses` | List warehouses |
| POST | `/warehouses` | Create warehouse (code, name, city, timezone) |
| GET | `/warehouses/{id}` | Get warehouse detail |
| PUT | `/warehouses/{id}` | Update warehouse |
| POST | `/warehouses/{id}/deactivate` | Deactivate warehouse |
| GET | `/customers` | List customers |
| POST | `/customers` | Create customer |
| GET | `/customers/{id}` | Get customer detail |
| PUT | `/customers/{id}` | Update customer |
| POST | `/customers/{id}/deactivate` | Deactivate customer |
| GET | `/units` | List units of measure |
| POST | `/units` | Create unit |
| PUT | `/units/{id}` | Update unit |
| GET | `/products` | List products (filter: customer, sku, active, trackExpiry, trackSerial) |
| POST | `/products` | Create product (names EN/AR/FR, base unit, flags) |
| GET | `/products/{id}` | Get product detail (incl. alt units, barcodes) |
| PUT | `/products/{id}` | Update product |
| POST | `/products/{id}/deactivate` | Deactivate product |
| POST | `/products/{id}/units` | Add an alternate unit + conversion factor |
| DELETE | `/products/{id}/units/{unitId}` | Remove an alternate unit |
| POST | `/products/{id}/barcodes` | Add a barcode (optionally scoped to a unit) |
| DELETE | `/products/{id}/barcodes/{barcodeId}` | Remove a barcode |
| GET | `/products/lookup?barcode=` | Resolve a product (and unit) by scanned/typed barcode |
| GET | `/products/{id}/serials` | List serial numbers for a product (filter: status, warehouse) |

## Approval Thresholds

| Method | Path | Description |
|---|---|---|
| GET | `/approval-thresholds` | List configured thresholds (global/customer/product) |
| PUT | `/approval-thresholds/global/{transactionType}` | Set the global default threshold for a transaction type |
| PUT | `/approval-thresholds/customer/{customerId}/{transactionType}` | Set a customer-level threshold |
| PUT | `/approval-thresholds/product/{productId}/{transactionType}` | Set a product-level threshold |
| GET | `/approval-thresholds/resolve?productId=&customerId=&transactionType=` | Preview the resolved threshold for a combination |

## Stock Transactions (IN, OUT, Transfer, Damage, Adjustment, Opening Balance)

| Method | Path | Description |
|---|---|---|
| GET | `/stock-transactions` | List transactions (filter: type, status, warehouse, customer, date range) |
| POST | `/stock-transactions` | Create a transaction in Draft status (header + lines) |
| GET | `/stock-transactions/{id}` | Get transaction detail (header, lines, approval history, movements) |
| PUT | `/stock-transactions/{id}` | Update a Draft transaction's lines |
| POST | `/stock-transactions/{id}/lines` | Add a line to a Draft transaction |
| DELETE | `/stock-transactions/{id}/lines/{lineId}` | Remove a line from a Draft transaction |
| POST | `/stock-transactions/{id}/submit` | Submit for posting; evaluates approval threshold, moves to Pending Approval or posts directly |
| POST | `/stock-transactions/{id}/approve` | Manager approval (posts the transaction); rejects if approver = requester |
| POST | `/stock-transactions/{id}/reject` | Manager rejection with reason |
| POST | `/stock-transactions/{id}/cancel` | Cancel a Draft/Pending/Dispatched transaction |
| POST | `/stock-transactions/{id}/dispatch` | Transfer only: dispatch from source, creates in-transit balance |
| POST | `/stock-transactions/{id}/receive` | Transfer only: receive at destination (supports partial/short receipt) |
| GET | `/stock-transactions/{id}/audit` | Full audit trail for this transaction |

### Convenience creation endpoints (thin wrappers over the generic transaction API)

| Method | Path | Description |
|---|---|---|
| POST | `/stock/opening-balance` | Create + post an opening balance in one call |
| POST | `/stock/in` | Create a receiving (IN) transaction |
| POST | `/stock/out` | Create an issuing (OUT) transaction; returns the FEFO-suggested expiry date(s) if applicable |
| POST | `/stock/transfer` | Create a transfer transaction (Draft) |
| POST | `/stock/damage` | Create a damage transaction |
| POST | `/stock/adjustment` | Create an adjustment transaction |

## Stock Balances & Movements

| Method | Path | Description |
|---|---|---|
| GET | `/stock/balance` | Current balances (filter: warehouse, customer, product, expiry date) |
| GET | `/stock/balance/summary` | Aggregated on-hand quantities by product/warehouse |
| GET | `/stock/movements` | Movement ledger (filter: warehouse, customer, product, expiry date, date range, direction) |
| GET | `/stock/in-transit` | Open transfers awaiting receipt, with age and any discrepancy |
| GET | `/stock/expiring?withinDays=` | Stock nearing expiry, per expiry date |

## Reports

Every report is a page with its filters as query parameters, and an export of
exactly the rows the page counts (`App\Reports\Report` + `App\Reports\Export\Exporter`).
`warehouse` is one of the user's warehouses or `all` (default: the current one);
`customer` is a customer id; dates are `YYYY-MM-DD` in the warehouse's time zone;
`search` matches an item's name (any language) or code. Each report has its own permission,
`report.<key>.view` with `-` as `_` (e.g. `report.stock_card.view`), unless noted; rows are always limited to the user's warehouses (and, for a
customer login, to its own customer).

| Method | Path | Description |
|---|---|---|
| GET | `/reports` | The reports the user may open |
| GET | `/reports/stock-on-hand?search=&customer=&warehouse=&expiring=1` | Stock on hand per item, customer and warehouse, with larger-unit breakdown, stock arriving in transit and nearest expiry (`stock.view`; shown as the Stock page, `/stock`) |
| GET | `/reports/stock-card?customer=&item=&warehouse=&from=&to=` | Every movement of one item in one warehouse, with opening balance, balance after each movement and closing balance |
| GET | `/reports/expiry?search=&warehouse=&customer=&status=all\|expired\|expiring&days=30` | Stock expired or expiring within `days`, per expiry date |
| GET | `/reports/dormant-stock?search=&warehouse=&customer=&days=90` | Items in stock whose last movement in that warehouse is more than `days` old, longest idle first |
| GET | `/reports/damage?search=&from=&to=&reason=&warehouse=&customer=` | Completed damage lines by period, reason and warehouse |
| GET | `/reports/adjustments?search=&from=&to=&reason=&warehouse=&customer=&direction=IN\|OUT` | Completed adjustment lines: in system, counted, difference; gains and losses counted apart |
| GET | `/reports/transfers-in-transit?search=&warehouse=&direction=incoming\|outgoing&customer=` | Stock dispatched and not yet received, with days on the way |
| GET | `/reports/transfer-shortages?search=&from=&to=&reason=&warehouse=&customer=` | Transfer lines received short, with the reason (`/transfer/shortages` redirects here) |
| GET | `/reports/delivery-differences?search=&from=&to=&reason=&warehouse=&customer=` | Delivery note lines delivered short or replaced by another item, with the reason |
| GET | `/reports/audit-log?search=&from=&to=&entity=&action=` | Who did what and when, before → after (`audit.view` only; stock documents limited to the user's warehouses) |
| GET | `/dashboard` | Owner dashboard (`dashboard.view`, ADMIN by default): filters warehouse, customer, date from/to (kept in the session); figures for pending approvals, transfers in transit (late after 7 days), expired/expiring within 30 days, damage and adjustment lines; activity by type and workload (items handled) by warehouse over the dates; the first 5 rows of Expiry and Dormant stock. Each figure is the row count of the report it opens, with the same filters |
| GET | `/reports/{name}/export?format=xlsx\|pdf&<same filters>` | Export of any of the above: Excel (numbers as numbers), or PDF (always light, DejaVu Sans, right to left in Arabic). `422` when a required filter is missing (stock card without an item) |
| GET | `/stock/{productId}/{warehouseId}` | One item's stock in one warehouse: per expiry date, serials in stock, last 20 movements with balance after (`stock.view`) |

## Import

Staged Excel import (`App\Imports\ImportEngine`), the same for items and opening
balances: **1 Upload → 2 Check → 3 Import**. Nothing is written before the user
confirms; confirming checks every row again and writes the valid ones in one
transaction. `.xlsx` only, at most 5,000 data rows (`config/imports.php`).
`{type}` is `items` (`product.import`) or `opening-balance` (`product.import`
and `stock.opening.create`; warehouses must be the user's).

| Method | Path | Description |
|---|---|---|
| GET | `/imports` | New import, and past imports (who, when, result) |
| GET | `/imports/template/{type}` | Template in the user's language; second sheet lists the codes to use (customers, units, the user's warehouses, items) |
| GET | `/imports/new/{type}` | Upload page: stores the file on the imports disk, creates an `import_job` (UPLOADED) and queues `ReadImportFile`, which reads rows into `import_row` (progress in `processed_rows` / `total_rows`) and checks them (VALIDATED; FAILED with `error_message` for a wrong file) |
| GET | `/imports/{jobId}` | Check page: counters (ready / warnings / errors), the problem rows with their message, "Check again", and "Import" (re-check + write; job CONFIRMED with `result`) |
| GET | `/imports/{jobId}/errors` | Error report (.xlsx): the rows with errors or warnings as uploaded, plus a Message column |

Row status: `VALID`, `WARNING` (imported, with a note), `ERROR` (left out),
`IMPORTED`. Opening balances create one Opening Balance document per warehouse
and customer, posted through `PostingService`.

## Conventions

- **Errors:** standard problem-details JSON (`code`, `message`, `details[]`); a blocked
  negative-stock attempt returns `409 Conflict` with the available quantity in `details`.
- **Idempotency:** all `POST` endpoints that post stock (`submit`, `dispatch`, `receive`,
  `approve`) accept an `Idempotency-Key` header so a retried request cannot double-post.
- **Pagination:** list endpoints return `{ items: [...], page, pageSize, total }`.
- **Localization:** responses that include product/master-data names return all three
  language fields; the client picks which to display per the user's `preferred_lang`.
- **Authorization:** every endpoint checks both the caller's permission code (e.g.
  `stock.damage.approve`) and, where a warehouse is involved, that the caller is
  assigned to that warehouse.
