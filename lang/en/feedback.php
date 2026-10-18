<?php

// Toast messages (CLAUDE.md "Feedback messages"): results of actions, and errors that
// have no field to sit under. Business errors live with their feature (stock.errors, transfers.errors).
return [
    'region' => 'Notifications',
    'close' => 'Close',
    'view' => 'View',
    'sign_in' => 'Sign in',
    'saved' => ':type :reference saved',
    'dispatched' => 'Transfer :reference sent to :warehouse',
    'sent_for_approval' => ':type :reference sent for manager approval',
    'approved' => ':type :reference approved stock is updated',
    'rejected' => ':type :reference rejected stock was not changed',
    'received' => 'Transfer :reference received',
    'received_with_shortage' => 'Transfer :reference received with a shortage',
    'cancelled' => ':type :reference cancelled, and its stock movements were reversed',
    'rows_need_attention' => '{1} 1 row needs attention|[2,*] :count rows need attention',
    'fields_need_attention' => '{1} 1 field needs attention|[2,*] :count fields need attention',
    'date_order' => 'Date from must be before Date to',
    'date_order_hint' => 'Change one of the dates, or clear the filters.',

    'errors' => [
        'unexpected' => 'Something went wrong and nothing was saved. Reference: :reference please tell your administrator.',
        'connection' => 'Connection lost your entries are still here, try again.',
        'session_expired' => 'Your session expired please sign in again.',
        'not_allowed' => 'You don’t have permission to do this.',
    ],
];
