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
|   prefix         reference prefix shown on documents, e.g. DN-2026-000123
|   view           permission to open the list, details and print pages (also
|                  open to whoever may create or approve the type, see `view_also`)
|   view_also      other permissions that also open them (e.g. receiving transfers)
|   create         permission needed for the "New" page
|   approve        permission to approve/reject (types that support approval)
|   header_fields  extra header fields besides Customer, Date and Reference note:
|                  reason  (choices from `reasons`); the To-warehouse field is added
|                  automatically when the type requires a destination warehouse
|   line_columns   extra line columns: expiry (expiry date typed, on
|                  expiry-tracked items), expiry_pick (expiry date chosen from
|                  those in stock, nearest pre-selected),
|                  counted (In system | Counted | Difference instead of Quantity)
|   list_columns   optional list page columns: total (Total quantity), reason
|                  (the document's reason), draft (the draft it was confirmed
|                  from, e.g. the Order of a Delivery note); default ['total']
|   hint           available = "Available: N" and quantity may not exceed it;
|                  in_stock  = "In stock now: N" and no maximum
|   reasons        reason codes (database enum values) for the `reason` field
|   line_key       what makes two lines the same (CLAUDE.md rule 12): item plus
|                  `unit` and/or `expiry` (expiry only for expiry-tracked items).
|                  Two lines with the same key are refused, in the form and on save.
|   confirm_save   ask before saving (true), even though the type can be cancelled
|                  afterwards; types that can't be cancelled (Damage, Adjustment)
|                  always ask when they post at once
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
            'view' => 'stock.in.view',
            'create' => 'stock.in.create',
            'header_fields' => [],
            // The Draft receipt it was confirmed from, if any.
            'list_columns' => ['total', 'draft'],
            'line_columns' => ['expiry'],
            'hint' => 'in_stock',
            'line_key' => ['unit', 'expiry'],
            'lang' => 'receive',
        ],
        'OUT' => [
            'slug' => 'delivery-note',
            'token' => 'delivery',
            'icon' => 'outbox',
            'prefix' => 'DN',
            'view' => 'stock.out.view',
            'create' => 'stock.out.create',
            'header_fields' => [],
            // The Order it was confirmed from, if any.
            'list_columns' => ['total', 'draft'],
            'line_columns' => ['expiry_pick'],
            'hint' => 'available',
            'line_key' => ['unit', 'expiry'],
            'lang' => 'delivery_note',
        ],
        'TRANSFER' => [
            'slug' => 'transfer',
            'token' => 'transfer',
            'icon' => 'swap_horiz',
            'prefix' => 'TRF',
            'view' => 'stock.transfer.view',
            // The destination sees what is on its way to be received.
            'view_also' => ['stock.transfer.receive'],
            'create' => 'stock.transfer.dispatch',
            'header_fields' => [],
            'line_columns' => ['expiry_pick'],
            'hint' => 'available',
            'line_key' => ['unit', 'expiry'],
            'lang' => 'transfer',
        ],
        'ADJUSTMENT' => [
            'slug' => 'adjustment',
            'token' => 'adjustment',
            'icon' => 'tune',
            'prefix' => 'ADJ',
            'view' => 'stock.adjustment.view',
            'create' => 'stock.adjustment.create',
            'approve' => 'stock.adjustment.approve',
            'header_fields' => ['reason'],
            'line_columns' => ['counted'],
            // A sum of counted quantities means nothing; the reason says why the stock was counted.
            'list_columns' => ['reason'],
            'hint' => 'in_stock',
            // A count is per item (and expiry date) in any unit: two units would adjust it twice.
            'line_key' => ['expiry'],
            'reasons' => ['STOCK_COUNT_VARIANCE', 'LOSS_THEFT', 'DATA_ENTRY_CORRECTION', 'OTHER'],
            'lang' => 'adjustment',
        ],
        'DAMAGE' => [
            'slug' => 'damage',
            'token' => 'damage',
            'icon' => 'report',
            'prefix' => 'DMG',
            'view' => 'stock.damage.view',
            'create' => 'stock.damage.create',
            'approve' => 'stock.damage.approve',
            'header_fields' => ['reason'],
            'line_columns' => ['expiry_pick'],
            'list_columns' => ['total', 'reason'],
            'hint' => 'available',
            'line_key' => ['unit', 'expiry'],
            'reasons' => ['HANDLING', 'TRANSPORT', 'EXPIRED', 'WAREHOUSE_INCIDENT', 'OTHER'],
            'lang' => 'damage',
        ],
        'OPENING_BALANCE' => [
            'slug' => 'opening-balance',
            'token' => 'opening',
            'icon' => 'account_balance_wallet',
            'prefix' => 'OPB',
            'view' => 'stock.opening.view',
            'create' => 'stock.opening.create',
            'header_fields' => [],
            'line_columns' => ['expiry'],
            'hint' => 'in_stock',
            'line_key' => ['unit', 'expiry'],
            // Starting stock is usually entered once, in bulk: check it before it posts.
            'confirm_save' => true,
            'lang' => 'opening',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Drafts: draft receipt / order
    |--------------------------------------------------------------------------
    |
    | Keyed by the transaction_type.code that confirming a draft creates (one
    | posted document in the draft's warehouse). A draft never touches stock.
    |
    |   slug      URL segment and route name prefix: <slug>, <slug>.create,
    |             <slug>.show, <slug>.edit, <slug>.print
    |   token     colour token in config('ui.types') (same as the type it creates)
    |   prefix    reference prefix, e.g. DRR-2026-000004 (sequence doc_no_draft_<code>_seq)
    |   view      permission to open the list, details and print pages (also open
    |             to whoever may create or confirm drafts of this kind)
    |   create    permission to create and edit drafts
    |   confirm   permission to confirm (and to change a draft while confirming it)
    |   lang      key under lang/<locale>/drafts.php `types` and `mail`
    |   notify    app_user column: whether a confirmer gets an email when one is
    |             added, changed or cancelled (Settings › Users)
    |
    */
    'drafts' => [
        'IN' => [
            'slug' => 'draft-receipts',
            'token' => 'receive',
            'icon' => 'assignment_returned',
            'prefix' => 'DRR',
            'view' => 'draft.in.view',
            'create' => 'draft.in.create',
            'confirm' => 'draft.in.confirm',
            'lang' => 'receipt',
            'notify' => 'notify_draft_receipts',
        ],
        'OUT' => [
            'slug' => 'orders',
            'token' => 'delivery',
            'icon' => 'content_paste_go',
            'prefix' => 'ORD',
            'view' => 'draft.out.view',
            'create' => 'draft.out.create',
            'confirm' => 'draft.out.confirm',
            'lang' => 'order',
            'notify' => 'notify_new_orders',
        ],
    ],

    // Status of a draft (stock_draft.status, a transaction_status.code) as the UI shows it.
    'draft_statuses' => [
        'DRAFT' => ['token' => 'draft', 'label' => 'draft'],
        'POSTED' => ['token' => 'completed', 'label' => 'confirmed'],
        'CANCELLED' => ['token' => 'cancelled', 'label' => 'cancelled'],
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
