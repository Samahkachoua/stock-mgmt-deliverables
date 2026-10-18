<?php

return [
    'tagline' => 'نظام إدارة المستودعات',
    'skip_to_content' => 'انتقل إلى المحتوى',
    'select_warehouse' => 'اختر المستودع',
    'language' => 'اللغة',
    'theme' => 'المظهر',
    'theme_light' => 'فاتح',
    'theme_dark' => 'داكن',
    'theme_system' => 'حسب النظام',
    'theme_invalid' => 'اختر فاتح أو داكن أو حسب النظام.',
    'user_menu' => 'قائمة الحساب',
    'sign_out' => 'تسجيل الخروج',
    'no_warehouses_title' => 'لا يوجد مستودع مخصص لك',
    'no_warehouses_body' => 'حسابك غير مرتبط بأي مستودع بعد. اطلب من المسؤول إضافتك إلى مستودع.',
    'forbidden_title' => 'لا تملك صلاحية الوصول إلى هذه الصفحة',
    'forbidden_body' => 'حسابك غير مسموح له بفتح هذه الصفحة. اطلب من المسؤول إذا كنت بحاجة إليها.',
    'go_to_start' => 'الذهاب إلى صفحة البداية',

    // Loading states (CLAUDE.md "Loading states"): shared by every form, list and export.
    'loading' => [
        'saving' => 'جارٍ الحفظ…',
        'list' => 'جارٍ التحميل…',
        'preparing_file' => 'جارٍ تجهيز الملف…',
    ],

    // Error pages (resources/views/errors). Shown even when the app is failing.
    'errors' => [
        'home' => 'الذهاب إلى الصفحة الرئيسية',
        'reference' => 'المرجع: :reference',
        'back' => 'رجوع',
        'not_found' => ['title' => 'الصفحة غير موجودة', 'body' => 'هذا العنوان لا يؤدي إلى أي صفحة. ربما كُتب بشكل خاطئ أو نُقلت الصفحة.'],
        'expired' => ['title' => 'انتهت صلاحية الصفحة', 'body' => 'غبت لبعض الوقت، لذا يجب تحميل الصفحة مجددًا. ارجع وحاول مرة أخرى .لم يُحفظ شيء.'],
        'too_many' => ['title' => 'محاولات كثيرة', 'body' => 'يُرجى الانتظار دقيقة ثم المحاولة مجددًا.'],
        'server' => ['title' => 'حدث خطأ ما', 'body' => 'تم تسجيل المشكلة. حاول مجددًا بعد قليل، وإذا تكررت فأخبر المسؤول.'],
        'maintenance' => ['title' => 'سنعود خلال دقائق', 'body' => 'يجري تحديث النظام. يُرجى المحاولة بعد قليل.'],
    ],
];
