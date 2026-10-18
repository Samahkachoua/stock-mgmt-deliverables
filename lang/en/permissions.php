<?php

// Permission names for the Roles screen. `labels` is keyed by permission.code with
// dots written as underscores; codes without an entry show their stored description.
return [
    'modules' => [
        'STOCK' => 'Stock',
        'PRODUCT' => 'Items',
        'MASTERDATA' => 'Master data',
        'REPORT' => 'Reports',
        'ADMIN' => 'Administration',
    ],

    'labels' => [
        'stock_view' => 'See stock and documents',
        'stock_in_create' => 'Create receipts',
        'stock_out_create' => 'Create issues',
        'stock_transfer_dispatch' => 'Dispatch transfers',
        'stock_transfer_receive' => 'Receive transfers',
        'stock_damage_create' => 'Record damage',
        'stock_damage_approve' => 'Approve damage',
        'stock_adjustment_create' => 'Create adjustments',
        'stock_adjustment_approve' => 'Approve adjustments',
        'stock_opening_create' => 'Enter opening balances',
        'transaction_cancel' => 'Cancel documents',
        'product_manage' => 'Add and edit items',
        'product_import' => 'Import items from Excel',
        'masterdata_manage' => 'Manage warehouses, customers and units',
        'report_view' => 'See reports',
        'audit_view' => 'See the activity log',
        'admin_users_manage' => 'Manage users and roles',
        'admin_settings_manage' => 'Manage approval limits and settings',
    ],
];
