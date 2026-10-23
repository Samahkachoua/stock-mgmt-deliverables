<?php

// The owner dashboard (App\Livewire\Dashboard, App\Reports\Dashboard).
return [
    'title' => 'Dashboard',
    'subtitle' => 'How the warehouses are doing: work done, stock at risk and what is waiting.',

    'period' => [
        'all' => 'All dates',
        'range' => ':from – :to',
        'from' => 'From :from',
        'to' => 'Up to :to',
    ],
    'now' => 'Now',
    'loading' => 'Loading figures…',

    'tiles' => [
        'heading' => 'Key figures',
        'pending' => 'Waiting for approval',
        'pending_oldest' => '{1} Oldest waiting :count day|[0,*] Oldest waiting :count days',
        'pending_none' => 'Nothing waiting',
        'transit' => 'Transfers on the way',
        'transit_late' => '{0} None over :days days|{1} :count over :days days|[2,*] :count over :days days',
        'expiry' => 'Expired or expiring within :days days',
        'expired' => '{0} None expired yet|[1,*] :count already expired',
        'damage' => 'Damage lines',
        'adjustments' => 'Adjustment lines',
        'open' => 'Open: :what',
    ],

    'activity' => [
        'title' => 'Activity by type',
        'hint' => 'Receipts, delivery notes, transfers… done over time.',
        'step' => [
            'day' => 'Per day',
            'month' => 'Per month',
            'year' => 'Per year',
        ],
        'bar' => ':period: :count',
        'empty' => 'No activity in this period. Change the dates or the warehouse to see more.',
    ],

    'warehouses' => [
        'title' => 'Workload by warehouse',
        'hint' => 'Items handled in each warehouse. A transfer counts where it was sent from.',
        'items' => '{1} :count item|[0,*] :count items',
        'documents' => '{1} :count document|[0,*] :count documents',
    ],

    'table' => [
        'show' => 'Show as a table',
        'period' => 'Period',
        'documents' => 'Documents',
        'lines' => 'Items',
        'warehouse' => 'Warehouse',
    ],

    'expiring' => [
        'title' => 'Expiring soon',
        'hint' => 'Expired, or expiring within :days days, nearest first.',
        'empty' => 'Nothing expired or expiring within :days days.',
    ],

    'dormant' => [
        'title' => 'Not moving',
        'hint' => 'In stock with no movement for :days days or more, longest first.',
        'empty' => 'Every item in stock moved in the last :days days.',
    ],

    'see_all' => 'See all (:count)',
];
