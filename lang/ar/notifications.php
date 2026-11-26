<?php

return [
    'title' => 'الإشعارات',
    'subtitle' => 'ما ينتظرك وما حدث لطلباتك. تُحفظ لمدة :days يومًا.',
    'bell_unread' => '{1} الإشعارات، إشعار واحد غير مقروء|[2,*] الإشعارات، :count غير مقروءة',
    'mark_all_read' => 'تعليم الكل كمقروء',
    'see_all' => 'عرض كل الإشعارات',
    'empty_short' => 'لا جديد.',
    'empty_title' => 'لا توجد إشعارات بعد',
    'empty_body' => 'ستظهر هنا الموافقات التي تنتظرك، والطلبيات ومسودات الاستلام التي يجب تأكيدها، ونتيجة طلباتك.',
    'reason' => 'السبب',
    'no_access' => 'لم يعد لديك وصول إلى هذه العملية.',

    'events' => [
        'approval' => [
            'requested' => 'طلب موافقة على :type :reference من :actor',
            'approved' => 'تمت الموافقة على :type :reference من :actor',
            'rejected' => 'تم رفض :type :reference من :actor',
        ],
        'draft' => [
            'created' => 'تمت إضافة :type :reference (:customer) من :actor',
            'updated' => 'تم تعديل :type :reference (:customer) من :actor',
            'cancelled' => 'تم إلغاء :type :reference (:customer) من :actor',
        ],
    ],

    'outcomes' => [
        'approved' => 'تمت الموافقة من :name · :time',
        'rejected' => 'تم الرفض من :name · :time',
        'confirmed' => 'تم التأكيد من :name · :time',
        'cancelled' => 'تم الإلغاء من :name · :time',
    ],

    'states' => [
        'all' => 'كل الإشعارات',
        'unread' => 'جديد',
        'read' => 'مقروء',
        'closed' => 'منتهٍ',
    ],

    'groups' => [
        'approvals' => 'الموافقات',
        'orders' => 'الطلبيات',
        'draft_receipts' => 'مسودات الاستلام',
    ],

    'filters' => [
        'group' => 'النوع',
        'all_groups' => 'كل الأنواع',
    ],

    'list' => [
        'message' => 'الإشعار',
        'when' => 'التاريخ',
        'state' => 'الحالة',
        'search_placeholder' => 'البحث بالمرجع',
    ],

    'mail' => [
        'open' => 'فتح في التطبيق',
        'why' => [
            'approvals' => 'تصلك هذه الرسالة لأنك تستطيع الموافقة على هذه العملية. يمكن إيقافها من الإعدادات › المستخدمون، «إرسال بريد عن طلبات الموافقة».',
            'approval_results' => 'تصلك هذه الرسالة لأنك صاحب هذا الطلب. يمكن إيقافها من الإعدادات › المستخدمون، «إرسال بريد عن نتيجة طلباتي».',
        ],
    ],
];
