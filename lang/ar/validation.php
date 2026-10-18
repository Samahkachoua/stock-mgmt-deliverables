<?php

// Messages for validation rules without a message of their own. Every rule
// the app can trigger is listed, in all three languages, so no screen falls
// back to another language. Plain sentences, no "The field ...".
return [
    'required' => 'أدخل :attribute.',
    'email' => 'أدخل بريدًا إلكترونيًا صحيحًا.',
    'string' => 'يجب أن يكون :attribute نصًا.',

    'accepted' => 'حدّد :attribute للمتابعة.',
    'array' => ':attribute غير صالح.',
    'boolean' => 'اختر نعم أو لا لـ :attribute.',
    'confirmed' => 'إدخالا :attribute غير متطابقين.',
    'current_password' => 'كلمة المرور هذه غير صحيحة.',
    'date' => 'أدخل تاريخًا صحيحًا لـ :attribute.',
    'date_format' => 'أدخل :attribute بالصيغة :format.',
    'decimal' => 'استخدم :decimal منازل عشرية كحد أقصى لـ :attribute.',
    'exists' => 'اختر :attribute من القائمة.',
    'extensions' => 'يجب أن يكون :attribute ملفًا من نوع :values.',
    'file' => 'اختر ملفًا لـ :attribute.',
    'in' => 'اختر :attribute من القائمة.',
    'integer' => 'أدخل عددًا صحيحًا لـ :attribute.',
    'max' => [
        'array' => 'اختر :max كحد أقصى لـ :attribute.',
        'file' => 'الملف كبير جدًا (:max كيلوبايت كحد أقصى).',
        'numeric' => 'أدخل :max أو أقل لـ :attribute.',
        'string' => ':attribute طويل جدًا (:max حرفًا كحد أقصى).',
    ],
    'mimes' => 'يجب أن يكون :attribute ملفًا من نوع :values.',
    'min' => [
        'array' => 'اختر :min على الأقل لـ :attribute.',
        'file' => 'يجب ألا يقل حجم الملف عن :min كيلوبايت.',
        'numeric' => 'أدخل :min أو أكثر لـ :attribute.',
        'string' => ':attribute قصير جدًا (:min أحرف على الأقل).',
    ],
    'numeric' => 'أدخل رقمًا لـ :attribute.',
    'regex' => ':attribute ليس بالصيغة الصحيحة.',
    'unique' => ':attribute مستخدم مسبقًا.',
    'uploaded' => 'تعذّر رفع الملف. حاول مجددًا أو جرّب ملفًا أصغر.',
    'uuid' => 'اختر :attribute من القائمة.',

    'attributes' => [
        'email' => 'البريد الإلكتروني',
        'password' => 'كلمة المرور',
        'file' => 'الملف',
    ],
];
