<?php

return [
    'from_warehouse' => 'من المستودع',
    'in_transit_notice' => 'يغادر المخزون هذا المستودع الآن ويبقى قيد النقل حتى يؤكد :warehouse ما وصل.',
    'send_to' => 'إرسال إلى :warehouse',
    'dispatched' => 'تم إرسال :reference. يبقى قيد النقل حتى يؤكد المستودع الآخر الاستلام.',
    'received' => 'تم استلام :reference وتحديث المخزون.',
    'received_with_shortage' => 'تم استلام :reference مع نقص، وهو مدرج في نواقص التحويلات.',

    'direction' => [
        'label' => 'الاتجاه',
        'all' => 'الوارد والصادر',
        'incoming' => 'وارد',
        'outgoing' => 'صادر',
    ],

    'reasons' => [
        'DAMAGED_IN_TRANSIT' => 'تلف أثناء النقل',
        'MISSING' => 'مفقود',
        'COUNTING_ERROR' => 'خطأ في العدّ',
    ],

    'receive' => [
        'heading' => 'تحقّق مما وصل',
        'hint' => 'أدخل الكمية التي وصلت لكل سطر. إذا وصل أقل مما أُرسل، اختر السبب.',
        'received' => 'المستلم',
        'reason' => 'سبب الفرق',
        'choose_reason' => 'اختر سببًا',
        'difference' => 'نقص :qty',
        'confirm' => 'تأكيد الاستلام',
        'final_hint' => 'بعد التأكيد يُضاف المخزون هنا ولا يمكن تعديل هذا التحويل أو إلغاؤه.',
        'serials_hint' => 'أزل العلامة عن الأرقام التسلسلية التي لم تصل.',
        'read_only' => ':warehouse فقط يمكنه تأكيد ما وصل.',
    ],

    'details' => [
        'dispatched_on' => 'تاريخ الإرسال',
        'received_on' => 'تاريخ الاستلام',
        'shortage' => 'نقص: :qty :reason',
    ],

    'shortages' => [
        'link' => 'نواقص التحويلات',
        'columns' => [
            'received_on' => 'تاريخ الاستلام',
            'sent' => 'المرسل',
            'received' => 'المستلم',
            'short' => 'النقص',
            'reason' => 'السبب',
            'received_by' => 'استلمه',
        ],
    ],

    'errors' => [
        'already_received' => 'استلم :name هذا التحويل مسبقًا في :time.',
        'not_in_transit' => 'هذا التحويل لم يعد قيد النقل.',
        'not_destination' => 'وحدهم العاملون في المستودع الوجهة يمكنهم تأكيد ما وصل.',
        'received_required' => 'أدخل الكمية التي وصلت.',
        'more_than_sent' => 'أكثر مما أُرسل (:qty).',
        'serial_not_sent' => 'الرقم التسلسلي :serial لم يُرسل مع هذا السطر.',
        'reason_required' => 'اختر سبب وصول كمية أقل.',
        'received_cannot_cancel' => 'استلم :name هذا التحويل مسبقًا في :time، لذلك لا يمكن إلغاؤه.',
        'only_sender_cancels' => 'وحده المستودع المرسل يمكنه إلغاء التحويل.',
    ],
];
