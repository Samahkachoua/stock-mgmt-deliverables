<?php

// Draft receipts and orders: they change no stock until confirmed; confirming
// creates one receipt / delivery note in the draft's warehouse.
return [
    'types' => [
        'receipt' => [
            'title' => 'Draft receipts',
            'singular' => 'Draft receipt',
            'new' => 'New Draft Receipt',
            'subtitle' => 'Stock expected from a customer branch. Nothing changes in stock until a draft is confirmed.',
            'form_subtitle' => 'List what is coming. Stock changes only when the draft is confirmed.',
            'branch' => 'From branch',
            'branch_hint' => 'The customer branch the goods come from.',
            'warehouse_hint' => 'The warehouse the goods come into.',
            'confirm' => 'Confirm and receive',
            'created_heading' => 'Receipts created',
            'empty_title' => 'No draft receipts yet',
            'empty_body' => 'Use “New Draft Receipt” to list stock that is on its way.',
        ],
        'order' => [
            'title' => 'Orders',
            'singular' => 'Order',
            'new' => 'New Order',
            'subtitle' => 'Stock requested for a customer branch. Nothing changes in stock until the order is confirmed.',
            'form_subtitle' => 'List what should go out. Stock changes only when the order is confirmed.',
            'branch' => 'To branch',
            'branch_hint' => 'The customer branch the goods go to.',
            'delivery_date' => 'Delivery date',
            'warehouse_hint' => 'The warehouse the goods go out from.',
            'confirm' => 'Confirm and deliver',
            'created_heading' => 'Delivery notes created',
            'empty_title' => 'No orders yet',
            'empty_body' => 'Use “New Order” to list stock that should go out.',
        ],
    ],

    'status' => [
        'draft' => 'Draft',
        'confirmed' => 'Confirmed',
        'cancelled' => 'Cancelled',
    ],

    // Orders: how urgent (stock_draft.priority).
    'priority' => [
        'label' => 'Priority',
        'URGENT' => 'Urgent',
        'NORMAL' => 'Normal',
        'LOW' => 'Low',
    ],

    'filters' => [
        'branch' => 'Branch',
        'all_branches' => 'All branches',
        'drafts_first' => 'All statuses, drafts first',
        'search_placeholder' => 'Search reference, customer or branch…',
    ],

    'list' => [
        'warehouse' => 'Warehouse',
    ],

    'details' => [
        'lines_heading' => 'Items',
        'warehouse' => 'Warehouse',
        'confirmed_by' => 'Confirmed by',
        'confirmed_on' => 'Confirmed on',
        'cancel_reason' => 'Why it was cancelled',
        'not_in_stock_in' => 'Not in stock yet. Confirming creates the receipt into :warehouse.',
        'not_in_stock_out' => 'Still in stock. Confirming creates the delivery note from :warehouse.',
        'waiting_for_confirmation' => 'Waiting for someone who may confirm it.',
    ],

    'form' => [
        'edit_title' => 'Edit :reference',
        'choose_branch' => 'Choose a branch',
        'no_branches' => 'This customer has no branches yet. Add them in Settings › Branches.',
        'lines_heading' => 'Items',
        'choose_warehouse' => 'Choose…',
        'notice_in' => 'Saving changes no stock. When confirmed, this becomes a receipt.',
        'notice_out' => 'Saving changes no stock. When confirmed, this becomes a delivery note.',
    ],

    'actions' => [
        'edit' => 'Edit',
        'cancel' => 'Cancel :type',
        'save' => 'Save :type',
        'save_and_confirm' => 'Save and confirm',
        'confirming' => 'Confirming…',
    ],

    'confirm_cancel' => [
        'title' => 'Cancel :type :reference?',
        'body' => 'It can no longer be changed or confirmed. Stock is not affected.',
        'button' => 'Cancel :type',
        'reason' => 'Why is it cancelled?',
        'reason_placeholder' => 'e.g. The branch withdrew the order',
    ],

    'feedback' => [
        'saved' => ':type :reference saved',
        'updated' => ':type :reference saved',
        'confirmed' => ':type :reference confirmed — :documents',
        'documents_in' => '{1} 1 receipt created|[2,*] :count receipts created',
        'documents_out' => '{1} 1 delivery note created|[2,*] :count delivery notes created',
        'cancelled' => ':type :reference cancelled',
    ],

    'leave' => [
        'title' => 'Discard your changes to :reference?',
        'title_new' => 'Discard this new :type?',
    ],

    // Email to the people who can confirm a draft that was added, changed or
    // cancelled (App\Mail\DraftEmail): texts per kind of draft, then shared labels.
    'mail' => [
        'urgent_subject' => 'Urgent: :subject',
        'order' => [
            'subject' => [
                'created' => 'New order :reference from :customer',
                'updated' => 'Order :reference from :customer was changed',
                'cancelled' => 'Order :reference from :customer was cancelled',
            ],
            'intro' => [
                'created' => ':name has submitted an order. Open it to check and confirm it; stock changes only when it is confirmed.',
                'updated' => ':name changed this order. Open it to see the lines as they are now before you confirm it.',
                'cancelled' => ':name cancelled this order. It can no longer be confirmed; stock is not affected.',
            ],
            'branch' => 'To branch',
            'open' => 'Open order',
            'why' => 'You get this email because you can confirm orders in this warehouse. An administrator can turn it off in Settings › Users.',
        ],
        'receipt' => [
            'subject' => [
                'created' => 'New draft receipt :reference from :customer',
                'updated' => 'Draft receipt :reference from :customer was changed',
                'cancelled' => 'Draft receipt :reference from :customer was cancelled',
            ],
            'intro' => [
                'created' => ':name has listed goods on their way. Open the draft receipt to check it and confirm it when they arrive; stock changes only when it is confirmed.',
                'updated' => ':name changed this draft receipt. Open it to see the lines as they are now before you confirm it.',
                'cancelled' => ':name cancelled this draft receipt. It can no longer be confirmed; stock is not affected.',
            ],
            'branch' => 'From branch',
            'open' => 'Open draft receipt',
            'why' => 'You get this email because you can confirm draft receipts in this warehouse. An administrator can turn it off in Settings › Users.',
        ],
        'reason' => 'Reason',
        'customer' => 'Customer',
        'warehouse' => 'Warehouse',
        'date' => 'Date',
        'items' => 'Items',
        'note' => 'Reference',
    ],

    'errors' => [
        'delivery_date_required' => 'Choose a delivery date.',
        'delivery_date_before' => 'The delivery date can\'t be before the order date.',
        'priority_required' => 'Choose a priority.',
        'already_confirmed' => 'This document was already confirmed by :name at :time.',
        'already_cancelled' => 'This document was cancelled.',
        'changed' => ':name changed this document meanwhile. Open it again to see the changes.',
        'cannot_create' => 'You don’t have permission to create this document.',
        'cannot_edit' => 'You don’t have permission to change this document.',
        'cannot_confirm' => 'You don’t have permission to confirm this document.',
        'cannot_cancel' => 'You don’t have permission to cancel this document.',
        'warehouse_not_allowed' => 'You don’t work in :warehouse, so you can’t confirm this document.',
        'branch_required' => 'Choose a branch.',
        'branch_not_allowed' => 'You can’t use this branch.',
        'choose_warehouse' => 'Choose a warehouse.',
    ],
];
