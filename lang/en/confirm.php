<?php

// The confirmation modal (CLAUDE.md "Confirmations"): title, consequence and action button per
// destructive action, and why a record can't be deleted or deactivated.
return [
    'keep' => 'Keep it',
    'reason_required' => 'Explain why.',
    // Leaving a form with unsaved changes (App\Livewire\Concerns\WarnsBeforeLeaving).
    'discard' => [
        'item' => [
            'title' => 'Discard your changes to :name?',
            'title_new' => 'Discard this new item?',
            'body' => 'What you changed on this page hasn’t been saved and will be lost.',
            'button' => 'Discard changes',
        ],
    ],
    'delete' => [
        'item' => [
            'title' => 'Delete item :name?',
            'body' => ':name and its units are removed for good. This can’t be undone.',
            'button' => 'Delete item',
        ],
        'unit' => [
            'title' => 'Delete unit :name?',
            'body' => ':name is removed for good. This can’t be undone.',
            'button' => 'Delete unit',
        ],
        'role' => [
            'title' => 'Delete role :name?',
            'body' => 'The role and its permissions are removed for good. This can’t be undone.',
            'button' => 'Delete role',
        ],
        'limits' => [
            'title' => 'Delete the approval limits of :name?',
            'body' => 'The general limits apply to :name again. This can’t be undone.',
            'button' => 'Delete limits',
            'done' => 'Approval limits of :name deleted',
        ],
    ],
    'deactivate' => [
        'warehouse' => [
            'title' => 'Deactivate warehouse :name?',
            'body' => ':name can no longer be chosen for new transactions. You can turn it back on later.',
            'button' => 'Deactivate warehouse',
        ],
        'customer' => [
            'title' => 'Deactivate customer :name?',
            'body' => ':name can no longer be chosen for new transactions. You can turn it back on later.',
            'button' => 'Deactivate customer',
        ],
        'item' => [
            'title' => 'Deactivate item :name?',
            'body' => ':name can no longer be chosen on new transactions. You can turn it back on later.',
            'button' => 'Deactivate item',
        ],
        'user' => [
            'title' => 'Deactivate user :name?',
            'body' => ':name will not be able to sign in. You can turn the account back on later.',
            'button' => 'Deactivate user',
        ],
    ],
    'cancel' => [
        'title' => 'Cancel :type :reference?',
        'returns' => 'This returns :stock to :warehouse stock and can’t be undone.',
        'removes' => 'This takes :stock out of :warehouse stock again and can’t be undone.',
        'more' => '{1} :first and 1 other item|[2,*] :first and :count other items',
        'stock' => ':qty of :item',
        'button' => 'Cancel :type',
        'reason' => 'Why is it cancelled?',
        'reason_placeholder' => 'e.g. Entered for the wrong customer',
    ],
    'reject' => [
        'title' => 'Reject :type :reference?',
        'body' => 'Stock will not change, and :name will see your reason. This can’t be undone.',
        'button' => 'Reject :type',
        'reason' => 'Why is it rejected?',
    ],
    'blocked' => [
        'item_on_documents' => '{1} :name is used on 1 document (:types) and can’t be deleted. Turn off “In use” to hide it instead.|[2,*] :name is used on :count documents (:types) and can’t be deleted. Turn off “In use” to hide it instead.',
        'item_has_records' => ':name has stock records and can’t be deleted. Turn off “In use” to hide it instead.',
        'unit_in_use' => '{1} :name is used by 1 item and can’t be deleted.|[2,*] :name is used by :count items and can’t be deleted.',
        'unit_on_documents' => ':name is used on saved documents and can’t be deleted.',
        'role_system' => ':name is a built-in role and can’t be deleted.',
        'role_in_use' => '{1} :name is given to 1 user and can’t be deleted.|[2,*] :name is given to :count users and can’t be deleted.',
    ],
];
