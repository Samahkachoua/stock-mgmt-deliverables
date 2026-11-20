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
    'nothing_to_create' => 'يمكنك الاطلاع على المخزون والعمليات دون إنشائها. اطلب ذلك من المسؤول عند الحاجة.',
    'recent' => [
        'title' => 'تابع من حيث توقفت',
        'empty' => 'ستظهر هنا العمليات التي تنشئها أو تعمل عليها.',
        'line' => ':customer · :event',
        // Nouns ("your last action"), so the sentence needs no gender.
        'events' => [
            'created' => 'آخر إجراء لك: إنشاء',
            'edited' => 'آخر إجراء لك: تعديل',
            'sent_for_approval' => 'آخر إجراء لك: إرسال للموافقة',
            'approved' => 'آخر إجراء لك: موافقة',
            'rejected' => 'آخر إجراء لك: رفض',
            'posted' => 'آخر إجراء لك: إتمام',
            'confirmed' => 'آخر إجراء لك: تأكيد',
            'dispatched' => 'آخر إجراء لك: شحن',
            'received' => 'آخر إجراء لك: استلام',
            'delivered' => 'آخر إجراء لك: تسليم',
            'cancelled' => 'آخر إجراء لك: إلغاء',
        ],
    ],
];
