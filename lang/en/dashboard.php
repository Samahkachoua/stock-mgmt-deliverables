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

    'branches' => [
        'title' => 'Orders by branch',
        'hint' => 'Orders of each branch, customer by customer, and how far they have got. Open a branch to see its orders.',
        'legend' => 'Stages',
        'stage' => [
            'open' => 'Open',
            'ready' => 'Ready to dispatch',
            'on_way' => 'On the way',
            'delivered' => 'Delivered',
        ],
        'orders' => '{1} :count order|[0,*] :count orders',
        'late' => '{1} :count late|[0,*] :count late',
        'open' => 'Open the orders of :branch (:customer)',
        'see_all' => 'See all branches (:count)',
        'fewer' => 'Show the busiest only',
        'branch' => 'Branch',
        'customer' => 'Customer',
        'total' => 'Total',
        'late_column' => 'Late',
        'empty' => 'No orders in this period. Change the dates, the warehouse or the customer to see more.',
    ],

    'discrepancies' => [
        'title' => 'Delivery discrepancies by branch',
        'hint' => 'Delivered lines where the branch got less than it ordered, or another item, by reason. Open a branch to see the lines.',
        'legend' => 'Reasons',
        'short' => '{1} :count short line|[0,*] :count short lines',
        'rate' => ':rate% of delivered lines',
        'open' => 'Open the delivery discrepancies of :branch (:customer)',
        'short_column' => 'Short',
        'delivered_column' => 'Delivered lines',
        'empty' => 'No delivery discrepancies in this period: every branch got what it ordered.',
    ],

    'warehouses' => [
        'title' => 'Workload by warehouse',
        'hint' => 'Items handled in each warehouse. A transfer counts where it was sent from.',
        'items' => '{1} :count item|[0,*] :count items',
        'documents' => '{1} :count operation|[0,*] :count operations',
    ],

    'customers' => [
        'title' => 'Workload by customer',
        'hint' => 'Items handled for each customer, and what kind of work they were. Choose a customer to see only theirs on the dashboard.',
        'legend' => 'Kind of work',
        'groups' => [
            'in' => 'In',
            'out' => 'Out',
            'transfers' => 'Transfers',
            'counts' => 'Counts',
        ],
        'fewer' => 'Show fewer',
        'show' => 'Show only :customer on the dashboard',
        'see_all' => 'See all customers (:count)',
        'empty' => 'No work done in this period. Change the dates or the warehouse to see more.',
    ],

    'table' => [
        'show' => 'Show as a table',
        'period' => 'Period',
        'documents' => 'Operations',
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
