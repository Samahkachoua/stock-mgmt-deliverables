<?php

return [
    'from_warehouse' => 'From warehouse',
    'in_transit_notice' => 'The stock leaves this warehouse now and stays In transit until :warehouse confirms what arrived.',
    'send_to' => 'Send to :warehouse',
    'dispatched' => ':reference was sent. It is In transit until the other warehouse confirms receipt.',
    'received' => ':reference was received. Stock is updated.',
    'received_with_shortage' => ':reference was received with a shortage. It is listed in Transfer shortages.',

    'direction' => [
        'label' => 'Direction',
        'all' => 'Incoming and outgoing',
        'incoming' => 'Incoming',
        'outgoing' => 'Outgoing',
    ],

    'reasons' => [
        'DAMAGED_IN_TRANSIT' => 'Damaged in transit',
        'MISSING' => 'Missing',
        'COUNTING_ERROR' => 'Counting error',
    ],

    'receive' => [
        'heading' => 'Check what arrived',
        'hint' => 'Enter the quantity that arrived for each line. If less arrived than was sent, choose why.',
        'received' => 'Received',
        'reason' => 'Reason for difference',
        'choose_reason' => 'Choose a reason',
        'difference' => ':qty short',
        'confirm' => 'Confirm receipt',
        'final_hint' => 'Once confirmed, the stock is added here and this transfer can’t be changed or cancelled.',
        'serials_hint' => 'Untick serial numbers that did not arrive.',
        'read_only' => 'Only :warehouse can confirm what arrived.',
    ],

    'details' => [
        'dispatched_on' => 'Sent on',
        'received_on' => 'Received on',
        'shortage' => 'Short: :qty :reason',
    ],

    'shortages' => [
        'link' => 'Transfer shortages',
        'columns' => [
            'received_on' => 'Received on',
            'sent' => 'Sent',
            'received' => 'Received',
            'short' => 'Short',
            'reason' => 'Reason',
            'received_by' => 'Received by',
        ],
    ],

    'errors' => [
        'already_received' => 'This transfer was already received by :name at :time.',
        'not_in_transit' => 'This transfer is no longer In transit.',
        'not_destination' => 'Only people working in the destination warehouse can confirm what arrived.',
        'received_required' => 'Enter the quantity that arrived.',
        'more_than_sent' => 'More than was sent (:qty).',
        'serial_not_sent' => 'Serial :serial was not sent with this line.',
        'reason_required' => 'Choose why less arrived.',
        'received_cannot_cancel' => 'This transfer was already received by :name at :time, so it can’t be cancelled.',
        'only_sender_cancels' => 'Only the sending warehouse can cancel a transfer.',
    ],
];
