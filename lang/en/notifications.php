<?php

return [
    'title' => 'Notifications',
    'subtitle' => 'What waits for you and what happened to your requests. Kept for :days days.',
    'bell_unread' => '{1} Notifications, 1 unread|[2,*] Notifications, :count unread',
    'mark_all_read' => 'Mark all as read',
    'see_all' => 'See all notifications',
    'empty_short' => 'Nothing new.',
    'empty_title' => 'No notifications yet',
    'empty_body' => 'You will see here approvals waiting for you, orders and draft receipts to confirm, and the result of your own requests.',
    'reason' => 'Reason',
    'no_access' => 'You no longer have access to this operation.',

    'events' => [
        'approval' => [
            'requested' => ':actor sent :type :reference for approval',
            'approved' => ':type :reference was approved by :actor',
            'rejected' => ':type :reference was rejected by :actor',
        ],
        'draft' => [
            'created' => ':actor added :type :reference (:customer)',
            'updated' => ':actor changed :type :reference (:customer)',
            'cancelled' => ':actor cancelled :type :reference (:customer)',
        ],
    ],

    // Shown under a notification someone else has dealt with.
    'outcomes' => [
        'approved' => 'Approved by :name · :time',
        'rejected' => 'Rejected by :name · :time',
        'confirmed' => 'Confirmed by :name · :time',
        'cancelled' => 'Cancelled by :name · :time',
    ],

    'states' => [
        'all' => 'All notifications',
        'unread' => 'New',
        'read' => 'Read',
        'closed' => 'Done',
    ],

    'groups' => [
        'approvals' => 'Approvals',
        'orders' => 'Orders',
        'draft_receipts' => 'Draft receipts',
    ],

    'filters' => [
        'group' => 'Type',
        'all_groups' => 'All types',
    ],

    'list' => [
        'message' => 'Notification',
        'when' => 'Date',
        'state' => 'Status',
        'search_placeholder' => 'Search by reference',
    ],

    'mail' => [
        'open' => 'Open in the app',
        'why' => [
            'approvals' => 'You get this email because you can approve this operation. Turn it off in Settings › Users, "Email about approval requests".',
            'approval_results' => 'You get this email because you made this request. Turn it off in Settings › Users, "Email about my approval results".',
        ],
    ],
];
