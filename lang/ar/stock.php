<?php

// Messages from posting and cancelling stock documents (App\Services\Stock).
return [
    'saved' => 'تم حفظ :reference وتحديث المخزون.',
    'cancelled' => 'تم إلغاء :reference وعكس حركات المخزون الخاصة به.',
    'sent_for_approval' => 'أُرسل :reference للموافقة. يتغيّر المخزون بعد موافقة المدير.',
    'approved' => 'تمت الموافقة على :reference وتحديث المخزون.',
    'rejected' => 'تم رفض :reference ولم يتغيّر المخزون.',

    'approval' => [
        'reject_reason' => 'لماذا تم الرفض؟',
        'reject_placeholder' => 'مثال: أعد العدّ بحضور شخص آخر',
        'confirm_reject' => 'رفض',
        'keep' => 'رجوع',
        'own' => 'أنت من أرسل هذا للموافقة، لذلك يجب أن يوافق عليه أو يرفضه مدير آخر.',
        'waiting' => 'بانتظار موافقة المدير. لم يتغيّر المخزون بعد.',
        'rejection_reason' => 'سبب الرفض',
        'approved_by' => 'وافق عليه',
        'rejected_by' => 'رفضه',
    ],

    'cancel' => [
        'button' => 'إلغاء المستند',
        'question' => 'إلغاء :reference؟ ستُعكس حركات المخزون الخاصة به ولن يمكن استخدامه مرة أخرى.',
        'confirm' => 'نعم، ألغِه',
        'keep' => 'الإبقاء عليه',
    ],

    // A lot: batch number and expiry date (App\Support\Batches).
    'batch' => [
        'label' => ':batch · :date',
        'none' => 'بدون دفعة',
    ],

    'errors' => [
        'not_enough' => 'المتاح من :item في :warehouse هو :qty فقط.',
        'not_enough_on_date' => 'في :date كان المتاح من :item في :warehouse هو :qty فقط. لا يمكن إخراج مخزون وصل لاحقًا بهذا التاريخ.',
        'not_enough_plain' => 'المخزون غير كافٍ: المتاح :qty فقط (بالوحدة الأساسية).',
        'not_enough_with_expired' => 'المتاح غير المنتهي الصلاحية من :item في :warehouse هو :qty فقط (و:expired منتهي الصلاحية).',
        'only_expired' => 'المتبقي من :item في :warehouse (:qty) منتهي الصلاحية. لا يمكن تسليم مخزون منتهي الصلاحية.',
        'not_exact' => 'لا يمكن عدّ هذه الكمية بدقة بالوحدة الأساسية. استخدم عددًا صحيحًا من الوحدات الأساسية.',
        'type_not_supported' => 'لا يمكن حفظ هذا النوع من المستندات بعد.',
        'warehouse_not_allowed' => 'أنت لا تعمل في هذا المستودع.',
        'warehouse_inactive' => 'هذا المستودع غير مستخدم.',
        'customer_inactive' => 'هذا العميل غير نشط.',
        'serial_and_expiry' => 'لا يمكن بعدُ التعامل مع أصناف لها أرقام تسلسلية وتواريخ انتهاء معًا. غيّر طريقة تتبّع الصنف أولًا.',
        'serial_count' => 'أدخل رقمًا تسلسليًا واحدًا لكل قطعة (:count).',
        'serial_twice' => 'الرقم التسلسلي :serial مُدخل مرتين.',
        'serial_in_stock' => 'الرقم التسلسلي :serial موجود في المخزون مسبقًا.',
        'serial_not_in_stock' => 'الرقم التسلسلي :serial غير موجود في مخزون هذا المستودع.',
        'serial_not_on_date' => 'وصل الرقم التسلسلي :serial إلى هذا المستودع بعد :date، لذا لا يمكن إخراجه بهذا التاريخ.',
        'batch_other_date' => 'تنتهي صلاحية الدفعة :batch من :item في :date — أدخل هذا التاريخ أو رقم دفعة آخر.',
        'expiry_expired' => 'انتهت صلاحية المخزون الذي ينتهي في :date. لا يمكن إخراج مخزون منتهي الصلاحية بهذه الطريقة.',
        'cannot_cancel_status' => 'لا يمكن إلغاء إلا المستندات المكتملة.',
        'already_used' => 'خرج جزء من هذا المخزون: استُلم من :item :received، والمتبقي :left فقط. لا يمكن إلغاؤه.',
        'serial_already_used' => 'خرج الرقم التسلسلي :serial، لذلك لا يمكن إلغاء هذا الاستلام.',
        'serial_back_in_stock' => 'عاد الرقم التسلسلي :serial إلى المخزون، لذلك لا يمكن إلغاء إذن التسليم هذا.',
        'nothing_to_adjust' => 'كل الكميات المعدودة تطابق النظام، فلا يوجد ما يُعدَّل.',
        'count_changed' => 'تغيّر مخزون :item منذ هذا العدّ ارفضه وأعد العدّ.',
        'not_pending' => 'تمت الموافقة على هذا المستند أو رفضه مسبقًا.',
        'own_document' => 'لا يمكنك الموافقة على حركة أنشأتها أو رفضها يجب أن يقرر مدير آخر.',
        'already_rejected' => 'رفض :name هذا المستند مسبقًا في :time.',
        'already_approved' => 'وافق :name على هذا المستند مسبقًا في :time.',
        'cancel_reason_required' => 'اذكر سبب الإلغاء.',
        'reject_reason_required' => 'اذكر سبب الرفض.',
        'cannot_approve' => 'لا يمكنك الموافقة على هذا النوع من المستندات.',
    ],
];
