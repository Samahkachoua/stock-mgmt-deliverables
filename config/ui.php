<?php

/*
|--------------------------------------------------------------------------
| UI configuration
|--------------------------------------------------------------------------
|
| Styling lookups only. Transaction type and status *codes* live in the
| database (transaction_type / transaction_status); these maps only say which
| colour tokens a code is drawn with. Class names are written out in full so
| Tailwind can find them (app.css scans this file).
|
*/

return [

    // Language switcher: code => label shown in its own language (endonym).
    'locales' => [
        'en' => 'English',
        'ar' => 'العربية',
        'fr' => 'Français',
    ],

    'themes' => ['light', 'dark', 'system'],

    // Lists paint at once with skeleton rows and fetch their rows right after
    // (CLAUDE.md "Loading states"). Off in tests, which read the rows directly.
    'defer_rows' => (bool) env('UI_DEFER_ROWS', true),

    // Colour classes per transaction type token (which type uses which token:
    // config/transactions.php).
    'types' => [
        'receive' => ['icon' => 'text-type-receive', 'soft' => 'bg-type-receive-soft', 'strong' => 'bg-type-receive-strong'],
        'delivery' => ['icon' => 'text-type-delivery', 'soft' => 'bg-type-delivery-soft', 'strong' => 'bg-type-delivery-strong'],
        'transfer' => ['icon' => 'text-type-transfer', 'soft' => 'bg-type-transfer-soft', 'strong' => 'bg-type-transfer-strong'],
        'adjustment' => ['icon' => 'text-type-adjustment', 'soft' => 'bg-type-adjustment-soft', 'strong' => 'bg-type-adjustment-strong'],
        'damage' => ['icon' => 'text-type-damage', 'soft' => 'bg-type-damage-soft', 'strong' => 'bg-type-damage-strong'],
        'opening' => ['icon' => 'text-type-opening', 'soft' => 'bg-type-opening-soft', 'strong' => 'bg-type-opening-strong'],
    ],

    // Colour classes per status token (which status uses which token:
    // config/transactions.php).
    'statuses' => [
        'pending' => 'bg-status-pending-soft text-status-pending border-status-pending-line',
        'transit' => 'bg-status-transit-soft text-status-transit border-status-transit-line',
        'completed' => 'bg-status-completed-soft text-status-completed border-status-completed-line',
        'rejected' => 'bg-status-rejected-soft text-status-rejected border-status-rejected-line',
        'cancelled' => 'bg-status-cancelled-soft text-status-cancelled border-status-cancelled-line',
        'draft' => 'bg-status-draft-soft text-status-draft border-status-draft-line',
    ],

    // Status labels (x-badge, x-status-badge...): a filled pill with white text.
    // The soft `statuses` above stay for notices, toasts and repeated chips
    // (reserved quantities, count differences, item tracking tags).
    'badges' => [
        'pending' => 'bg-status-pending-solid text-on-status',
        'transit' => 'bg-status-transit-solid text-on-status',
        'completed' => 'bg-status-completed-solid text-on-status',
        'rejected' => 'bg-status-rejected-solid text-on-status',
        'cancelled' => 'bg-status-cancelled-solid text-on-status',
        'draft' => 'bg-status-draft-solid text-on-status',
    ],

    // Sidebar. `permission` (a permission code or gate) is required to see the
    // item and open its page; `type` picks the colour from `types`; null = neutral item.
    // Document types and drafts use their page title as label, so the sidebar
    // and the page (and the Dashboard) always say the same name.
    //
    // Sections, in order: `label` is the small heading above them (null = none);
    // a section the user can see nothing of is left out, heading included.
    // A new area (e.g. Shipments, after Outbound) is one more section here + its
    // `nav.sections.*` translation. Built by App\Support\Navigation\Sidebar.
    'nav' => [
        ['label' => null, 'items' => [
            ['route' => 'home', 'permission' => 'stock.view', 'label' => 'nav.home', 'icon' => 'home', 'type' => null],
            // Figures for the owner (only ADMIN holds dashboard.view for now).
            ['route' => 'dashboard', 'permission' => 'dashboard.view', 'label' => 'nav.dashboard', 'icon' => 'bar_chart', 'type' => null],
        ]],
        // Drafts sit above the document they become; open to anyone who may create or
        // confirm them (gates in AppServiceProvider).
        ['label' => 'nav.sections.inbound', 'items' => [
            ['route' => 'draft-receipts', 'permission' => 'drafts.in.open', 'label' => 'drafts.types.receipt.title', 'icon' => 'assignment_returned', 'type' => 'receive'],
            ['route' => 'receive', 'permission' => 'documents.receive', 'label' => 'transactions.types.receive.title', 'icon' => 'move_to_inbox', 'type' => 'receive'],
        ]],
        ['label' => 'nav.sections.outbound', 'items' => [
            ['route' => 'orders', 'permission' => 'drafts.out.open', 'label' => 'drafts.types.order.title', 'icon' => 'content_paste_go', 'type' => 'delivery'],
            ['route' => 'delivery-note', 'permission' => 'documents.delivery-note', 'label' => 'transactions.types.delivery_note.title', 'icon' => 'outbox', 'type' => 'delivery'],
        ]],
        ['label' => 'nav.sections.inventory', 'items' => [
            ['route' => 'stock', 'permission' => 'stock.view', 'label' => 'nav.stock', 'icon' => 'inventory_2', 'type' => null],
            ['route' => 'stock-by-expiry', 'permission' => 'report.stock_by_expiry.view', 'label' => 'nav.stock_by_expiry', 'icon' => 'calendar_today', 'type' => null],
            ['route' => 'transfer', 'permission' => 'documents.transfer', 'label' => 'transactions.types.transfer.title', 'icon' => 'swap_horiz', 'type' => 'transfer'],
            ['route' => 'adjustment', 'permission' => 'documents.adjustment', 'label' => 'transactions.types.adjustment.title', 'icon' => 'tune', 'type' => 'adjustment'],
            ['route' => 'damage', 'permission' => 'documents.damage', 'label' => 'transactions.types.damage.title', 'icon' => 'report', 'type' => 'damage'],
            ['route' => 'opening-balance', 'permission' => 'documents.opening-balance', 'label' => 'transactions.types.opening.title', 'icon' => 'account_balance_wallet', 'type' => 'opening'],
        ]],
        ['label' => null, 'items' => [
            ['route' => 'reports', 'permission' => 'reports.open', 'label' => 'nav.reports', 'icon' => 'description', 'type' => null],
        ]],
        ['label' => 'nav.sections.setup', 'items' => [
            ['route' => 'items', 'permission' => 'product.manage', 'label' => 'nav.items', 'icon' => 'category', 'type' => null],
            ['route' => 'imports', 'permission' => 'product.import', 'label' => 'nav.imports', 'icon' => 'upload', 'type' => null],
            ['route' => 'settings', 'permission' => 'settings.open', 'label' => 'nav.settings', 'icon' => 'settings', 'type' => null],
        ]],
    ],

    // Seconds a success or warning toast stays on screen (errors stay until closed).
    'toast_seconds' => 10,

    // Settings tabs (docs/design/stitch-exports/settings). Each tab is its own page
    // with its own permission; the Settings sidebar item opens for anyone who may
    // open at least one tab (the `settings.open` gate).
    'settings_tabs' => [
        ['route' => 'settings.warehouses', 'permission' => 'masterdata.manage', 'label' => 'settings.tabs.warehouses', 'icon' => 'warehouse'],
        ['route' => 'settings.customers', 'permission' => 'masterdata.manage', 'label' => 'settings.tabs.customers', 'icon' => 'groups'],
        ['route' => 'settings.branches', 'permission' => 'masterdata.manage', 'label' => 'settings.tabs.branches', 'icon' => 'apartment'],
        ['route' => 'settings.units', 'permission' => 'masterdata.manage', 'label' => 'settings.tabs.units', 'icon' => 'straighten'],
        ['route' => 'settings.drivers', 'permission' => 'driver.view', 'label' => 'settings.tabs.drivers', 'icon' => 'local_shipping'],
        ['route' => 'settings.approval-limits', 'permission' => 'admin.settings.manage', 'label' => 'settings.tabs.approval_limits', 'icon' => 'verified_user'],
        ['route' => 'settings.users', 'permission' => 'admin.users.manage', 'label' => 'settings.tabs.users', 'icon' => 'badge'],
        ['route' => 'settings.roles', 'permission' => 'admin.users.manage', 'label' => 'settings.tabs.roles', 'icon' => 'admin_panel_settings'],
        ['route' => 'settings.general', 'permission' => 'admin.settings.manage', 'label' => 'settings.tabs.general', 'icon' => 'schedule'],
    ],

];
