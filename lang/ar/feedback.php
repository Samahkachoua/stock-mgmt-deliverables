<?php

// Toast messages (CLAUDE.md "Feedback messages"): results of actions, and errors that
// have no field to sit under. Business errors live with their feature (stock.errors, transfers.errors).
return [
    'region' => 'الإشعارات',
    'close' => 'إغلاق',
    'view' => 'عرض',
    'sign_in' => 'تسجيل الدخول',
    'saved' => 'تم حفظ :type :reference',
    'dispatched' => 'تم إرسال التحويل :reference إلى :warehouse',
    'sent_for_approval' => 'تم إرسال :type :reference لموافقة المدير',
    'approved' => 'تمت الموافقة على :type :reference تم تحديث المخزون',
    'rejected' => 'تم رفض :type :reference لم يتغير المخزون',
    'received' => 'تم استلام التحويل :reference',
    'received_with_shortage' => 'تم استلام التحويل :reference مع نقص',
    'delivery_dispatched' => 'تم شحن إذن التسليم :reference',
    'delivered' => 'تم تسليم إذن التسليم :reference وتحديث المخزون',
    'delivered_short' => 'تم تسليم إذن التسليم :reference مع فروقات، وهي مدرجة في تقرير فروقات التسليم',
    'delivery_cancelled' => 'تم إلغاء إذن التسليم :reference وأصبح مخزونه المحجوز متاحًا من جديد',
    'cancelled' => 'تم إلغاء :type :reference تم عكس حركات المخزون',
    'rows_need_attention' => '{1} سطر واحد يحتاج إلى مراجعة|{2} سطران يحتاجان إلى مراجعة|[3,10] :count أسطر تحتاج إلى مراجعة|[11,*] :count سطرًا يحتاج إلى مراجعة',
    'fields_need_attention' => '{1} حقل واحد يحتاج إلى مراجعة|{2} حقلان يحتاجان إلى مراجعة|[3,10] :count حقول تحتاج إلى مراجعة|[11,*] :count حقلًا يحتاج إلى مراجعة',
    'date_order' => 'يجب أن يكون «من تاريخ» قبل «إلى تاريخ»',
    'date_order_hint' => 'غيّر أحد التاريخين أو امسح عوامل التصفية.',

    'errors' => [
        'unexpected' => 'حدث خطأ ما ولم يُحفظ شيء. المرجع: :reference يُرجى إبلاغ المسؤول.',
        'connection' => 'انقطع الاتصال ما أدخلته ما زال موجودًا، حاول مجددًا.',
        'session_expired' => 'انتهت جلستك يُرجى تسجيل الدخول مجددًا.',
        'not_allowed' => 'لا تملك صلاحية القيام بذلك.',
    ],
];
