<?php

// Messages from posting and cancelling stock documents (App\Services\Stock).
return [
    'saved' => ':reference was saved. Stock is updated.',
    'cancelled' => ':reference was cancelled. Its stock movements were reversed.',
    'sent_for_approval' => ':reference was sent for approval. Stock changes once a manager approves it.',
    'approved' => ':reference was approved. Stock is updated.',
    'rejected' => ':reference was rejected. Stock was not changed.',

    'approval' => [
        'reject_reason' => 'Why is it rejected?',
        'reject_placeholder' => 'e.g. Count again with a second person',
        'confirm_reject' => 'Reject',
        'keep' => 'Back',
        'own' => 'You sent this for approval, so another manager must approve or reject it.',
        'waiting' => 'Waiting for a manager to approve it. Stock has not changed yet.',
        'rejection_reason' => 'Rejection reason',
        'approved_by' => 'Approved by',
        'rejected_by' => 'Rejected by',
    ],

    'cancel' => [
        'button' => 'Cancel document',
        'question' => 'Cancel :reference? Its stock movements are reversed and it can’t be used again.',
        'confirm' => 'Yes, cancel it',
        'keep' => 'Keep it',
    ],

    // A lot: batch number and expiry date (App\Support\Batches).
    'batch' => [
        'label' => ':batch · :date',
        'none' => 'No batch',
    ],

    'errors' => [
        'not_enough' => 'Only :qty of :item available in :warehouse.',
        'not_enough_on_date' => 'On :date only :qty of :item was available in :warehouse. Stock that arrived later can’t go out on that date.',
        'not_enough_plain' => 'Not enough stock: only :qty (base units) available.',
        'not_enough_with_expired' => 'Only :qty of :item available in :warehouse that has not expired (:expired more has expired).',
        'only_expired' => 'The remaining :qty of :item in :warehouse are expired. Expired stock can’t be delivered.',
        'not_exact' => 'This quantity can’t be counted exactly in the base unit. Use a whole number of base units.',
        'type_not_supported' => 'This kind of document can’t be saved yet.',
        'warehouse_not_allowed' => 'You don’t work in this warehouse.',
        'warehouse_inactive' => 'This warehouse is not in use.',
        'customer_inactive' => 'This customer is not in use.',
        'serial_and_expiry' => 'Items with both serial numbers and expiry dates can’t be handled yet. Change the item’s tracking first.',
        'serial_count' => 'Enter one serial number per piece (:count).',
        'serial_twice' => 'Serial :serial is entered twice.',
        'serial_in_stock' => 'Serial :serial is already in stock.',
        'serial_not_in_stock' => 'Serial :serial is not in stock in this warehouse.',
        'serial_not_on_date' => 'Serial :serial arrived in this warehouse after :date, so it can’t go out on that date.',
        'batch_other_date' => 'Batch :batch of :item expires :date — enter that date or another batch number.',
        'expiry_expired' => 'The stock expiring :date has expired. Expired stock can’t go out this way.',
        'cannot_cancel_status' => 'Only completed documents can be cancelled.',
        'already_used' => 'Some of this stock has already gone out: :item received :received, only :left is left. It can’t be cancelled.',
        'serial_already_used' => 'Serial :serial has already gone out, so this receipt can’t be cancelled.',
        'serial_back_in_stock' => 'Serial :serial is back in stock already, so this delivery note can’t be cancelled.',
        'nothing_to_adjust' => 'Every count matches the system, so there is nothing to adjust.',
        'count_changed' => 'Stock of :item has changed since this count reject it and count again.',
        'not_pending' => 'This document has already been approved or rejected.',
        'own_document' => 'You can’t approve or reject a transaction you created another manager must decide.',
        'already_rejected' => 'This document was already rejected by :name at :time.',
        'already_approved' => 'This document was already approved by :name at :time.',
        'cancel_reason_required' => 'Please provide a reason for the cancellation.',
        'reject_reason_required' => 'Please provide a reason for the rejection.',
        'cannot_approve' => 'You can’t approve this kind of document.',
    ],
];
