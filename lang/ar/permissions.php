<?php

// Permission names for the Roles screen. `labels` is keyed by permission.code with
// dots written as underscores; codes without an entry show their stored description.
// A report's permission (report.<key>.view) reads `report` with the report's title.
return [
    'modules' => [
        'STOCK' => 'المخزون',
        'DRAFT_IN' => 'مسودات الاستلام',
        'IN' => 'الاستلامات',
        'DRAFT_OUT' => 'الطلبيات',
        'OUT' => 'أذونات التسليم',
        'TRANSFER' => 'التحويلات',
        'ADJUSTMENT' => 'التسويات',
        'DAMAGE' => 'التالف',
        'OPENING_BALANCE' => 'الأرصدة الافتتاحية',
        'PRODUCT' => 'الأصناف',
        'MASTERDATA' => 'البيانات الأساسية',
        'REPORT' => 'التقارير',
        'ADMIN' => 'الإدارة',
    ],

    'labels' => [
        'stock_view' => 'عرض المخزون',
        'stock_in_create' => 'إنشاء الاستلامات',
        'stock_out_create' => 'شحن أذونات التسليم وتأكيد تسليمها',
        'stock_transfer_dispatch' => 'إرسال التحويلات',
        'stock_transfer_receive' => 'استلام التحويلات',
        'stock_damage_create' => 'تسجيل التالف',
        'stock_damage_approve' => 'الموافقة على التالف',
        'stock_adjustment_create' => 'إنشاء التسويات',
        'stock_adjustment_approve' => 'الموافقة على التسويات',
        'stock_opening_create' => 'إدخال الأرصدة الافتتاحية',
        'product_manage' => 'إضافة الأصناف وتعديلها',
        'product_import' => 'استيراد الأصناف من Excel',
        'masterdata_manage' => 'إدارة المستودعات والعملاء والوحدات',
        'audit_view' => 'عرض سجل النشاط',
        'admin_users_manage' => 'إدارة المستخدمين والأدوار',
        'admin_settings_manage' => 'إدارة حدود الموافقة والإعدادات',
        'draft_in_create' => 'إنشاء وتعديل مسودات الاستلام',
        'draft_in_confirm' => 'تأكيد مسودات الاستلام',
        'draft_out_create' => 'إنشاء وتعديل الطلبيات',
        'draft_out_confirm' => 'تأكيد الطلبيات',
        'stock_in_view' => 'عرض الاستلامات',
        'stock_out_view' => 'عرض أذونات التسليم',
        'stock_transfer_view' => 'عرض التحويلات',
        'stock_adjustment_view' => 'عرض التسويات',
        'stock_damage_view' => 'عرض التالف',
        'stock_opening_view' => 'عرض الأرصدة الافتتاحية',
        'draft_in_view' => 'عرض مسودات الاستلام',
        'draft_out_view' => 'عرض الطلبيات',
        'dashboard_view' => 'عرض لوحة المالك',
        'stock_in_cancel' => 'إلغاء الاستلامات',
        'stock_out_cancel' => 'إلغاء أذونات التسليم',
        'stock_opening_cancel' => 'إلغاء الأرصدة الافتتاحية',
    ],

    'report' => 'عرض تقرير :report',
];
