<?php

// Permission names for the Roles screen. `labels` is keyed by permission.code with
// dots written as underscores; codes without an entry show their stored description.
return [
    'modules' => [
        'STOCK' => 'المخزون',
        'PRODUCT' => 'الأصناف',
        'MASTERDATA' => 'البيانات الأساسية',
        'REPORT' => 'التقارير',
        'ADMIN' => 'الإدارة',
    ],

    'labels' => [
        'stock_view' => 'عرض المخزون والمستندات',
        'stock_in_create' => 'إنشاء الاستلامات',
        'stock_out_create' => 'إنشاء الصرف',
        'stock_transfer_dispatch' => 'إرسال التحويلات',
        'stock_transfer_receive' => 'استلام التحويلات',
        'stock_damage_create' => 'تسجيل التالف',
        'stock_damage_approve' => 'الموافقة على التالف',
        'stock_adjustment_create' => 'إنشاء التسويات',
        'stock_adjustment_approve' => 'الموافقة على التسويات',
        'stock_opening_create' => 'إدخال الأرصدة الافتتاحية',
        'transaction_cancel' => 'إلغاء المستندات',
        'product_manage' => 'إضافة الأصناف وتعديلها',
        'product_import' => 'استيراد الأصناف من Excel',
        'masterdata_manage' => 'إدارة المستودعات والعملاء والوحدات',
        'report_view' => 'عرض التقارير',
        'audit_view' => 'عرض سجل النشاط',
        'admin_users_manage' => 'إدارة المستخدمين والأدوار',
        'admin_settings_manage' => 'إدارة حدود الموافقة والإعدادات',
        'draft_in_create' => 'إنشاء وتعديل مسودات الاستلام',
        'draft_in_confirm' => 'تأكيد مسودات الاستلام',
        'draft_out_create' => 'إنشاء وتعديل مسودات الصرف',
        'draft_out_confirm' => 'تأكيد مسودات الصرف',
        'stock_in_view' => 'عرض الاستلامات',
        'stock_out_view' => 'عرض عمليات الصرف',
        'stock_transfer_view' => 'عرض التحويلات',
        'stock_adjustment_view' => 'عرض التسويات',
        'stock_damage_view' => 'عرض التالف',
        'stock_opening_view' => 'عرض الأرصدة الافتتاحية',
        'draft_in_view' => 'عرض مسودات الاستلام',
        'draft_out_view' => 'عرض مسودات الصرف',
        'dashboard_view' => 'عرض لوحة المالك',
    ],
];
