<?php

// The page after signing in (App\Http\Controllers\HomeController).
return [
    'page_title' => 'الرئيسية',
    'title' => 'مرحبًا، :name',
    'subtitle' => 'ماذا تريد أن تفعل؟',
    'waiting' => 'بانتظارك',
    'arriving' => 'تحويلات في الطريق إلى مستودعاتك',
    // A status card read aloud: "3 Adjustments to approve".
    'waiting_card' => ':label: :count',
    'start' => 'ابدأ عملية جديدة',
    'nothing_to_create' => 'يمكنك الاطلاع على المخزون والمستندات دون إنشائها. اطلب ذلك من المسؤول عند الحاجة.',
    'recent' => [
        'title' => 'تابع من حيث توقفت',
        'empty' => 'ستظهر هنا المستندات التي تنشئها أو تعمل عليها.',
    ],
];
