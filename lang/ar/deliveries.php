<?php

// خطوات إذن التسليم: مؤكَّد (من طلب، المخزون محجوز) ثم تم الشحن ثم تم التسليم.
return [
    'reasons' => [
        'NOT_AVAILABLE' => 'غير موجود في المستودع',
        'REFUSED' => 'رفضه العميل',
        'DAMAGED' => 'تالف',
        'REPLACED' => 'تم تسليم صنف آخر بدلًا منه',
    ],

    'dispatch' => [
        'button' => 'شحن',
        'title' => 'شحن إذن التسليم :reference؟',
        'hint' => 'من سيأخذه؟ كل الحقول اختيارية.',
        'driver_name' => 'اسم السائق',
        'driver_phone' => 'هاتف السائق',
        'truck_no' => 'رقم الشاحنة',
        'confirm' => 'شحن',
        'keep' => 'ليس الآن',
    ],

    'deliver' => [
        'heading' => 'تأكيد ما تم تسليمه',
        'hint' => 'أدخل الكمية المسلَّمة لكل سطر. إذا سُلِّم أقل من المطلوب، اختر السبب.',
        'ordered' => 'المطلوب',
        'delivered' => 'المسلَّم',
        'reason' => 'سبب الفرق',
        'choose_reason' => 'اختر سببًا',
        'difference' => 'نقص :qty',
        'instead' => 'سُلِّم بدلًا منه',
        'instead_item' => 'الصنف المسلَّم بدلًا من :item',
        'instead_qty' => 'الكمية المسلَّمة بدلًا منه',
        'instead_expiry' => 'تاريخ الانتهاء',
        'confirm' => 'تأكيد التسليم',
        'final_hint' => 'تخرج الكميات المسلَّمة من المخزون الآن، وما لم يُسلَّم يصبح متاحًا من جديد.',
        'serials_hint' => 'ألغِ تحديد الأرقام التسلسلية التي لم تُسلَّم.',
        'reserved_notice' => 'مخزون إذن التسليم هذا محجوز: لا يمكن لأي عملية أخرى أخذه حتى يُسلَّم أو يُلغى.',
        'read_only' => 'فقط من يسلِّمون من :warehouse يمكنهم تأكيد التسليم.',
        'dispatch_first' => 'اشحن إذن التسليم هذا أولًا؛ وبعد خروجه أكّد هنا ما تم تسليمه.',
    ],

    'list' => [
        'short' => 'ناقص',
    ],

    'details' => [
        'dispatched_on' => 'تاريخ الشحن',
        'delivered_on' => 'تاريخ التسليم',
        'driver' => 'السائق',
        'driver_phone' => 'هاتف السائق',
        'truck' => 'الشاحنة',
        'short' => 'نقص: :qty، :reason',
        'instead_of' => 'سُلِّم بدلًا من :item',
    ],

    'differences' => [
        'columns' => [
            'delivered_on' => 'تاريخ التسليم',
            'order' => 'الطلب',
            'ordered' => 'المطلوب',
            'delivered' => 'المسلَّم',
            'short' => 'النقص',
            'reason' => 'السبب',
            'instead' => 'سُلِّم بدلًا منه',
            'delivered_by' => 'أكّده',
        ],
    ],

    // Email to the order's branch manager once its Delivery note is delivered (App\Mail\DeliveryConfirmedEmail).
    'mail' => [
        'subject' => 'تم التسليم: الطلبية :order (:reference) إلى :branch',
        'intro' => 'تم تسليم بضاعة الطلبية :order إلى :branch (:customer) في :date.',
        'customer' => 'العميل',
        'branch' => 'الفرع',
        'order' => 'الطلبية',
        'delivery_note' => 'سند التسليم',
        'warehouse' => 'من المستودع',
        'delivered_on' => 'تاريخ التسليم',
        'confirmed_by' => 'أكّده',
        'driver' => 'السائق',
        'truck' => 'الشاحنة',
        'note' => 'ملاحظة مرجعية',
        'item' => 'الصنف',
        'ordered' => 'المطلوب',
        'delivered' => 'المُسلَّم',
        'instead_of' => 'سُلِّم بدلًا من :item',
        'why' => 'تصلك هذه الرسالة بصفتك مدير فرع :branch لدى :customer.',
    ],

    'errors' => [
        'already_dispatched' => 'تم شحن إذن التسليم هذا من قبل :name في :time.',
        'not_dispatched' => 'اشحن إذن التسليم هذا قبل تأكيد التسليم.',
        'already_delivered' => 'تم تسليم إذن التسليم هذا من قبل: أكّده :name في :time.',
        'cancelled' => 'تم إلغاء إذن التسليم هذا.',
        'not_allowed' => 'ليست لديك صلاحية شحن أذونات التسليم أو تأكيد تسليمها.',
        'delivered_required' => 'أدخل الكمية المسلَّمة.',
        'more_than_ordered' => 'أكثر من المطلوب (:qty).',
        'serial_not_ordered' => 'الرقم التسلسلي :serial ليس في هذا السطر.',
        'reason_required' => 'اختر سبب تسليم كمية أقل.',
        'instead_needs_replaced' => 'اختر «تم تسليم صنف آخر بدلًا منه» سببًا.',
        'instead_required' => 'اختر الصنف الذي سُلِّم بدلًا منه.',
    ],
];
