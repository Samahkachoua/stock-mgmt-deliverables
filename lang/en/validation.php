<?php

// Messages for validation rules without a message of their own. Every rule
// the app can trigger is listed, in all three languages, so no screen falls
// back to another language. Plain sentences, no "The field ...".
return [
    'required' => 'Enter your :attribute.',
    'email' => 'Enter a valid email address.',
    'string' => 'The :attribute must be text.',

    'accepted' => 'Tick :attribute to go on.',
    'array' => 'The :attribute is not valid.',
    'boolean' => 'Choose yes or no for :attribute.',
    'confirmed' => 'The two :attribute entries don’t match.',
    'current_password' => 'This password is not right.',
    'date' => 'Enter a valid date for :attribute.',
    'date_format' => 'Enter :attribute as :format.',
    'decimal' => 'Use at most :decimal decimal places for :attribute.',
    'exists' => 'Choose a :attribute from the list.',
    'extensions' => 'The :attribute must be a :values file.',
    'file' => 'Choose a file for :attribute.',
    'in' => 'Choose a :attribute from the list.',
    'integer' => 'Enter a whole number for :attribute.',
    'max' => [
        'array' => 'Choose at most :max for :attribute.',
        'file' => 'The file is too large (at most :max KB).',
        'numeric' => 'Enter :max or less for :attribute.',
        'string' => 'The :attribute is too long (at most :max characters).',
    ],
    'mimes' => 'The :attribute must be a :values file.',
    'min' => [
        'array' => 'Choose at least :min for :attribute.',
        'file' => 'The file must be at least :min KB.',
        'numeric' => 'Enter :min or more for :attribute.',
        'string' => 'The :attribute is too short (at least :min characters).',
    ],
    'numeric' => 'Enter a number for :attribute.',
    'regex' => 'The :attribute is not in the right format.',
    'unique' => 'This :attribute is already used.',
    'uploaded' => 'The file could not be uploaded. Try again, or try a smaller file.',
    'uuid' => 'Choose a :attribute from the list.',

    'attributes' => [
        'email' => 'email',
        'password' => 'password',
        'file' => 'file',
    ],
];
