<?php

// Delivery note steps: Confirmed (from an Order, stock reserved) -> Dispatched -> Delivered.
return [
    // Why a line was delivered short (stock_transaction_line.shortage_reason).
    'reasons' => [
        'NOT_AVAILABLE' => 'Not found in the warehouse',
        'REFUSED' => 'Refused by the customer',
        'DAMAGED' => 'Damaged',
        'REPLACED' => 'Another item delivered instead',
    ],

    'dispatch' => [
        'button' => 'Dispatch',
        'title' => 'Dispatch delivery note :reference?',
        'hint' => 'Who is taking it out? Every field is optional.',
        'driver_name' => 'Driver name',
        'driver_phone' => 'Driver phone',
        'truck_no' => 'Truck number',
        'confirm' => 'Dispatch',
        'keep' => 'Not now',
    ],

    'deliver' => [
        'heading' => 'Confirm what was delivered',
        'hint' => 'Enter the quantity delivered for each line. If less was delivered than ordered, choose why.',
        'ordered' => 'Ordered',
        'delivered' => 'Delivered',
        'reason' => 'Reason for difference',
        'choose_reason' => 'Choose a reason',
        'difference' => ':qty short',
        'instead' => 'Delivered instead',
        'instead_item' => 'Item delivered instead of :item',
        'instead_qty' => 'Quantity delivered instead',
        'instead_expiry' => 'Expiry date',
        'confirm' => 'Confirm delivery',
        'final_hint' => 'The delivered quantities leave stock now. Anything not delivered is available again.',
        'serials_hint' => 'Untick serial numbers that were not delivered.',
        'reserved_notice' => 'The stock of this delivery note is reserved: nothing else can take it until it is delivered or cancelled.',
        'read_only' => 'Only people who deliver from :warehouse can confirm the delivery.',
        'dispatch_first' => 'Dispatch this delivery note first; once it has left, confirm here what was delivered.',
    ],

    'list' => [
        'short' => 'Short',
    ],

    'details' => [
        'dispatched_on' => 'Dispatched on',
        'delivered_on' => 'Delivered on',
        'driver' => 'Driver',
        'driver_phone' => 'Driver phone',
        'truck' => 'Truck',
        'short' => 'Short: :qty, :reason',
        'instead_of' => 'Delivered instead of :item',
    ],

    // The Delivery discrepancies report.
    'differences' => [
        'columns' => [
            'delivered_on' => 'Delivered on',
            'order' => 'Order',
            'ordered' => 'Ordered',
            'delivered' => 'Delivered',
            'short' => 'Short',
            'reason' => 'Reason',
            'instead' => 'Delivered instead',
            'delivered_by' => 'Confirmed by',
        ],
    ],

    // Email to the order's branch manager once its Delivery note is delivered (App\Mail\DeliveryConfirmedEmail).
    'mail' => [
        'subject' => 'Delivered: order :order (:reference) for :branch',
        'intro' => 'The goods of order :order for :branch (:customer) were delivered on :date.',
        'customer' => 'Customer',
        'branch' => 'Branch',
        'order' => 'Order',
        'delivery_note' => 'Delivery note',
        'warehouse' => 'From warehouse',
        'delivered_on' => 'Delivered on',
        'confirmed_by' => 'Confirmed by',
        'driver' => 'Driver',
        'truck' => 'Truck',
        'note' => 'Reference note',
        'item' => 'Item',
        'ordered' => 'Ordered',
        'delivered' => 'Delivered',
        'instead_of' => 'Delivered instead of :item',
        'why' => 'You get this email as the manager of branch :branch of :customer.',
    ],

    'errors' => [
        'already_dispatched' => 'This delivery note was already dispatched by :name at :time.',
        'not_dispatched' => 'Dispatch this delivery note before confirming the delivery.',
        'already_delivered' => 'This delivery note was already delivered: confirmed by :name at :time.',
        'cancelled' => 'This delivery note was cancelled.',
        'not_allowed' => 'You don’t have permission to dispatch or deliver delivery notes.',
        'delivered_required' => 'Enter the quantity delivered.',
        'more_than_ordered' => 'More than was ordered (:qty).',
        'serial_not_ordered' => 'Serial :serial is not on this line.',
        'reason_required' => 'Choose why less was delivered.',
        'instead_needs_replaced' => 'Choose “Another item delivered instead” as the reason.',
        'instead_required' => 'Choose the item that was delivered instead.',
    ],
];
