<?php

// Draft receipts and draft issues: they change no stock until confirmed; confirming
// creates one receipt / issue per warehouse of their lines.
return [
    'types' => [
        'receipt' => [
            'title' => 'Draft receipts',
            'singular' => 'Draft receipt',
            'new' => 'New Draft Receipt',
            'subtitle' => 'Stock expected from a customer branch. Nothing changes in stock until a draft is confirmed.',
            'form_subtitle' => 'List what is coming and the warehouse each item goes to. Stock changes only when the draft is confirmed.',
            'branch' => 'From branch',
            'branch_hint' => 'The customer branch the goods come from.',
            'confirm' => 'Confirm and receive',
            'created_heading' => 'Receipts created',
            'empty_title' => 'No draft receipts yet',
            'empty_body' => 'Use “New Draft Receipt” to list stock that is on its way.',
        ],
        'issue' => [
            'title' => 'Draft issues',
            'singular' => 'Draft issue',
            'new' => 'New Draft Issue',
            'subtitle' => 'Stock requested for a customer branch. Nothing changes in stock until a draft is confirmed.',
            'form_subtitle' => 'List what should go out and the warehouse each item comes from. Stock changes only when the draft is confirmed.',
            'branch' => 'To branch',
            'branch_hint' => 'The customer branch the goods go to.',
            'confirm' => 'Confirm and issue',
            'created_heading' => 'Issues created',
            'empty_title' => 'No draft issues yet',
            'empty_body' => 'Use “New Draft Issue” to list stock that should go out.',
        ],
    ],

    'status' => [
        'draft' => 'Draft',
        'confirmed' => 'Confirmed',
        'cancelled' => 'Cancelled',
    ],

    'filters' => [
        'branch' => 'Branch',
        'all_branches' => 'All branches',
        'drafts_first' => 'All statuses, drafts first',
        'search_placeholder' => 'Search reference, customer or branch…',
    ],

    'list' => [
        'warehouses' => 'Warehouses',
    ],

    'details' => [
        'lines_heading' => 'Items by warehouse',
        'warehouses' => 'Warehouses',
        'confirmed_by' => 'Confirmed by',
        'confirmed_on' => 'Confirmed on',
        'cancel_reason' => 'Why it was cancelled',
        'not_in_stock_in' => '{1} Not in stock yet. Confirming creates 1 receipt.|[2,*] Not in stock yet. Confirming creates :count receipts, one per warehouse.',
        'not_in_stock_out' => '{1} Still in stock. Confirming creates 1 issue.|[2,*] Still in stock. Confirming creates :count issues, one per warehouse.',
        'waiting_for_confirmation' => 'Waiting for someone who may confirm it.',
    ],

    'form' => [
        'edit_title' => 'Edit :reference',
        'choose_branch' => 'Choose a branch',
        'no_branches' => 'This customer has no branches yet. Add them in Settings › Branches.',
        'lines_heading' => 'Items',
        'warehouse' => 'Warehouse',
        'row_warehouse' => 'Warehouse, row :row',
        'choose_warehouse' => 'Choose…',
        'notice_in' => '{1} Saving changes no stock. When confirmed, this becomes a receipt.|[2,*] Saving changes no stock. When confirmed, this becomes :count receipts, one per warehouse.',
        'notice_out' => '{1} Saving changes no stock. When confirmed, this becomes an issue.|[2,*] Saving changes no stock. When confirmed, this becomes :count issues, one per warehouse.',
    ],

    'actions' => [
        'edit' => 'Edit',
        'cancel' => 'Cancel draft',
        'save' => 'Save draft',
        'save_and_confirm' => 'Save and confirm',
        'confirming' => 'Confirming…',
    ],

    'confirm_cancel' => [
        'title' => 'Cancel :type :reference?',
        'body' => 'The draft can no longer be changed or confirmed. Stock is not affected.',
        'button' => 'Cancel :type',
        'reason' => 'Why is it cancelled?',
        'reason_placeholder' => 'e.g. The branch withdrew the order',
    ],

    'feedback' => [
        'saved' => ':type :reference saved',
        'updated' => ':type :reference saved',
        'confirmed' => ':type :reference confirmed — :documents',
        'documents_in' => '{1} 1 receipt created|[2,*] :count receipts created',
        'documents_out' => '{1} 1 issue created|[2,*] :count issues created',
        'cancelled' => ':type :reference cancelled',
    ],

    'leave' => [
        'title' => 'Discard your changes to :reference?',
        'title_new' => 'Discard this new :type?',
    ],

    'errors' => [
        'already_confirmed' => 'This draft was already confirmed by :name at :time.',
        'already_cancelled' => 'This draft was cancelled.',
        'changed' => ':name changed this draft meanwhile. Open it again to see the changes.',
        'cannot_create' => 'You don’t have permission to create this draft.',
        'cannot_edit' => 'You don’t have permission to change this draft.',
        'cannot_confirm' => 'You don’t have permission to confirm this draft.',
        'cannot_cancel' => 'You don’t have permission to cancel this draft.',
        'warehouse_not_allowed' => 'You don’t work in :warehouse, so you can’t confirm this draft.',
        'branch_required' => 'Choose a branch.',
        'branch_not_allowed' => 'You can’t use this branch.',
        'choose_warehouse' => 'Choose a warehouse.',
    ],
];
