<?php

/*
|--------------------------------------------------------------------------
| Transaction screens: per-type UI configuration
|--------------------------------------------------------------------------
|
| Keyed by transaction_type.code. This file holds only how a type LOOKS and
| where it lives (slug, colour, icon, labels, columns). How a type BEHAVES —
| destination warehouse, dispatch/receive, approval threshold — is read from
| the transaction_type table at run time (App\Support\Transactions\TransactionTypes),
| never decided here. A type that exists in the database but not here has no screens yet.
|
| Keys:
|   slug           URL segment and route name prefix: <slug>, <slug>.create, <slug>.show
|   token          colour token in config('ui.types') and lang/<locale>/types.php
|   icon           resources/icons/<icon>.svg
|   prefix         reference prefix shown on documents, e.g. ISS-2026-000123
|   create         permission needed for the "New" page (the list needs stock.view)
|   approve        permission to approve/reject (types that support approval)
|   header_fields  extra header fields besides Customer, Date and Reference note:
|                  reason  (choices from `reasons`); the To-warehouse field is added
|                  automatically when the type requires a destination warehouse
|   line_columns   extra line columns: expiry (expiry date typed, on
|                  expiry-tracked items), expiry_pick (expiry date chosen from
|                  those in stock, nearest pre-selected),
|                  counted (In system | Counted | Difference instead of Quantity)
|   list_columns   optional list page columns: total (Total quantity), reason
|                  (the document's reason); default ['total']
|   hint           available = "Available: N" and quantity may not exceed it;
|                  in_stock  = "In stock now: N" and no maximum
|   reasons        reason codes (database enum values) for the `reason` field
|   lang           key under lang/<locale>/transactions.php `types`
|
*/

return [

    'types' => [
        'IN' => [
            'slug' => 'receive',
            'token' => 'receive',
            'icon' => 'move_to_inbox',
            'prefix' => 'REC',
            'create' => 'stock.in.create',
            'header_fields' => [],
            'line_columns' => ['expiry'],
            'hint' => 'in_stock',
            'lang' => 'receive',
        ],
        'OUT' => [
            'slug' => 'issue',
            'token' => 'issue',
            'icon' => 'outbox',
            'prefix' => 'ISS',
            'create' => 'stock.out.create',
            'header_fields' => [],
            'line_columns' => ['expiry_pick'],
            'hint' => 'available',
            'lang' => 'issue',
        ],
        'TRANSFER' => [
            'slug' => 'transfer',
            'token' => 'transfer',
            'icon' => 'swap_horiz',
            'prefix' => 'TRF',
            'create' => 'stock.transfer.dispatch',
            'header_fields' => [],
            'line_columns' => ['expiry_pick'],
            'hint' => 'available',
            'lang' => 'transfer',
        ],
        'ADJUSTMENT' => [
            'slug' => 'adjustment',
            'token' => 'adjustment',
            'icon' => 'tune',
            'prefix' => 'ADJ',
            'create' => 'stock.adjustment.create',
            'approve' => 'stock.adjustment.approve',
            'header_fields' => ['reason'],
            'line_columns' => ['counted'],
            // A sum of counted quantities means nothing; the reason says why the stock was counted.
            'list_columns' => ['reason'],
            'hint' => 'in_stock',
            'reasons' => ['STOCK_COUNT_VARIANCE', 'LOSS_THEFT', 'DATA_ENTRY_CORRECTION', 'OTHER'],
            'lang' => 'adjustment',
        ],
        'DAMAGE' => [
            'slug' => 'damage',
            'token' => 'damage',
            'icon' => 'report',
            'prefix' => 'DMG',
            'create' => 'stock.damage.create',
            'approve' => 'stock.damage.approve',
            'header_fields' => ['reason'],
            'line_columns' => ['expiry_pick'],
            'list_columns' => ['total', 'reason'],
            'hint' => 'available',
            'reasons' => ['HANDLING', 'TRANSPORT', 'EXPIRED', 'WAREHOUSE_INCIDENT', 'OTHER'],
            'lang' => 'damage',
        ],
        'OPENING_BALANCE' => [
            'slug' => 'opening-balance',
            'token' => 'opening',
            'icon' => 'account_balance_wallet',
            'prefix' => 'OPB',
            'create' => 'stock.opening.create',
            'header_fields' => [],
            'line_columns' => ['expiry'],
            'hint' => 'in_stock',
            'lang' => 'opening',
        ],
    ],

    /*
    | Status shown in the UI per transaction_status.code: token in config('ui.statuses')
    | and label key in lang/<locale>/transactions.php `status`. The UI has no Draft:
    | codes not listed here are never offered as a filter, and a document in such a
    | status shows its database name in a neutral badge.
    */
    'statuses' => [
        'PENDING_APPROVAL' => ['token' => 'pending', 'label' => 'pending_approval'],
        'DISPATCHED' => ['token' => 'transit', 'label' => 'in_transit'],
        'POSTED' => ['token' => 'completed', 'label' => 'completed'],
        'REJECTED' => ['token' => 'rejected', 'label' => 'rejected'],
        'CANCELLED' => ['token' => 'cancelled', 'label' => 'cancelled'],
    ],

];
