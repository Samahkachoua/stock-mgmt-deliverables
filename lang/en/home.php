<?php

// The page after signing in (App\Livewire\Home): one version for staff
// (App\Support\Home\StaffHome), one for customer users (CustomerHome).
return [
    'page_title' => 'Home',
    // "Good afternoon, Nour" (staff) / "Good afternoon, Acme Foods" (customer).
    'title' => ':greeting, :name',
    'greeting' => [
        'morning' => 'Good morning',
        'afternoon' => 'Good afternoon',
        'evening' => 'Good evening',
    ],
    'subtitle_staff' => ':date · Here is what needs you today',
    'subtitle_customer' => ':date · Your orders and stock at a glance',
    'warehouse' => 'Warehouse',
    'all_warehouses' => 'All my warehouses',

    'attention' => 'Needs your attention',
    'attention_count' => '{1} :count item|[0,*] :count items',
    // A card read aloud: "3 Adjustments to approve".
    'waiting_card' => ':count :label',
    // Cards with nothing waiting are named on one line instead.
    'caught_up_on' => 'All caught up on:',
    'all_caught_up' => 'All caught up, nothing is waiting:',
    // Each approvable type names its own card (transactions.types.<type>.to_approve).
    'arriving' => 'Transfers on the way to you',
    'chips' => [
        'overdue' => 'Overdue',
        'on_way' => 'On the way',
        'waiting' => 'Waiting',
        'incoming' => 'Incoming',
        'expiring' => 'Expiring',
        'open' => 'Open',
    ],
    'actions' => [
        'review' => 'Review now',
        'open_list' => 'Open list',
        'approve' => 'Approve',
        'track' => 'Track',
        'view_stock' => 'View stock',
    ],
    'cards' => [
        'dispatched' => 'Dispatched, not yet delivered',
        'dispatched_note' => 'Longest: :number, :days',
        'to_dispatch' => 'Delivery notes to dispatch',
        'orders' => 'Orders to confirm',
        'receipts' => 'Draft receipts to receive',
        'oldest_waiting' => 'Oldest waiting :days',
        'arriving_note' => 'Oldest on the way for :days',
    ],
    // How long something has waited.
    'days' => '{0} less than a day|{1} :count day|[2,*] :count days',
    'lines' => '{1} :count line|[0,*] :count lines',

    'start' => 'Start something new',
    'more' => 'More:',
    'new_item' => 'Add new item',
    'nothing_to_create' => 'You can look at stock and operations, but not create them. Ask an administrator if you need to.',

    'glance' => [
        'title' => ':warehouse at a glance',
        'items' => 'Items in stock',
        'items_note' => '{0} No stock yet|{1} For :count customer|[2,*] For :count customers',
        'orders' => 'Open orders',
        'orders_note' => ':confirm to confirm · :dispatch to dispatch · :transit on the way',
        'expiring' => 'Expiring in :days days',
        'lots' => '{1} batch|[0,*] batches',
        'expiring_none' => 'Nothing expires soon',
        'expiring_first' => 'Earliest: :date, :item',
    ],

    'queue' => [
        'title' => 'Today\'s work queue',
        'all_orders' => 'See all orders',
        'empty' => 'Nothing waiting. You\'re all caught up.',
        'awaiting_delivery' => ':customer · out for delivery for :days',
        'ready_to_dispatch' => ':customer · :lines · ready to dispatch',
        'order' => ':customer · :lines',
        'order_late' => ':customer · :lines · :days late',
        'order_today' => ':customer · :lines · due today',
        'order_due' => ':customer · :lines · due :date',
        'to_approve' => ':customer · waiting :days for approval',
        'arriving' => 'From :warehouse · on the way for :days',
    ],

    'customer' => [
        'check' => [
            'title' => 'Check your stock',
            'label' => 'Item name or code',
            'placeholder' => 'Item name or code',
            'view' => 'Show',
            'total' => 'Total',
            'expiry' => 'By expiry date',
            'submit' => 'Search',
            'recent' => 'Recent:',
        ],
        'cards' => [
            'expiring' => '{1} Batch expiring within :days days|[0,*] Batches expiring within :days days',
            'expiring_note' => 'First on :date (:item)',
            'on_way' => '{1} Order on its way|[0,*] Orders on their way',
            'on_way_note' => ':number · :lines',
            'editable' => '{1} Order you can still edit or cancel|[0,*] Orders you can still edit or cancel',
            'editable_note' => 'Locked once the warehouse confirms it',
        ],
        'snapshot' => [
            'title' => 'Your stock snapshot',
            'open_stock' => 'Open full stock list',
            'items' => 'Items in stock',
            'lots' => 'Batches in stock',
            'expiring' => 'Expiring in :days days',
            'by_expiry' => 'Batches by time to expiry',
            'lot_count' => '{1} :count batch|[0,*] :count batches',
            'buckets' => [
                'expired' => 'Expired',
                'd30' => '0–30 days',
                'd60' => '31–60 days',
                'd90' => '61–90 days',
                'later' => 'Over 90 days',
            ],
        ],
        'soonest' => [
            'title' => 'Expiring soonest',
            'all' => 'View all by expiry',
            'empty' => 'Nothing with an expiry date in stock.',
            'item' => 'Item',
            'batch' => 'Batch',
            'qty' => 'Quantity',
            'expires' => 'Expires',
            'no_batch' => 'No batch',
            'left' => ':date · :days',
            'days' => '{0} today|{1} :count day|[2,*] :count days',
        ],
        'orders' => [
            'title' => 'Your open orders',
            'all' => 'All orders',
            'empty' => 'No open orders. Orders the warehouse hasn\'t confirmed yet appear here.',
            'line' => ':lines · saved :saved',
        ],
    ],

    // The documents and drafts the user last worked on (App\Support\Home\RecentActivity).
    'recent' => [
        'title' => 'Recent activity',
        'empty' => 'Operations you create or work on will appear here.',
        // Under the number: whose operation it is and what the user did to it last.
        'line' => ':customer · :event',
        'events' => [
            'created' => 'You created it',
            'edited' => 'You edited it',
            'sent_for_approval' => 'You sent it for approval',
            'approved' => 'You approved it',
            'rejected' => 'You rejected it',
            'posted' => 'You completed it',
            'confirmed' => 'You confirmed it',
            'dispatched' => 'You dispatched it',
            'received' => 'You received it',
            'delivered' => 'You delivered it',
            'cancelled' => 'You cancelled it',
        ],
    ],
];
