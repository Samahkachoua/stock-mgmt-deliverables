<?php

// The page after signing in (App\Http\Controllers\HomeController).
return [
    'page_title' => 'Home',
    'title' => 'Hello, :name',
    'subtitle' => 'What would you like to do?',
    'waiting' => 'Waiting for you',
    // Each approvable type names its own card (transactions.types.<type>.to_approve).
    // Dispatched transfers have no arrival date: this counts every one on its way.
    'arriving' => 'Transfers on the way to your warehouses',
    // A status card read aloud: "3 Adjustments to approve".
    'waiting_card' => ':count :label',
    'start' => 'Start something new',
    'nothing_to_create' => 'You can look at stock and operations, but not create them. Ask an administrator if you need to.',
    // The documents and drafts the user last worked on (App\Support\Home\RecentActivity).
    'recent' => [
        'title' => 'Pick up where you left off',
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
