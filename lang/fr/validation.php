<?php

// Messages for validation rules without a message of their own. Every rule
// the app can trigger is listed, in all three languages, so no screen falls
// back to another language. Plain sentences, no "The field ...".
return [
    'required' => 'Saisissez votre :attribute.',
    'email' => 'Saisissez une adresse e-mail valide.',
    'string' => 'Le champ :attribute doit être du texte.',

    'accepted' => 'Cochez :attribute pour continuer.',
    'array' => 'Le champ :attribute n’est pas valide.',
    'boolean' => 'Choisissez oui ou non pour :attribute.',
    'confirmed' => 'Les deux saisies de :attribute ne correspondent pas.',
    'current_password' => 'Ce mot de passe n’est pas correct.',
    'date' => 'Saisissez une date valide pour :attribute.',
    'date_format' => 'Saisissez :attribute au format :format.',
    'decimal' => 'Utilisez au plus :decimal décimales pour :attribute.',
    'exists' => 'Choisissez :attribute dans la liste.',
    'extensions' => 'Le champ :attribute doit être un fichier :values.',
    'file' => 'Choisissez un fichier pour :attribute.',
    'in' => 'Choisissez :attribute dans la liste.',
    'integer' => 'Saisissez un nombre entier pour :attribute.',
    'max' => [
        'array' => 'Choisissez au plus :max pour :attribute.',
        'file' => 'Le fichier est trop volumineux (:max Ko au plus).',
        'numeric' => 'Saisissez :max ou moins pour :attribute.',
        'string' => 'Le champ :attribute est trop long (:max caractères au plus).',
    ],
    'mimes' => 'Le champ :attribute doit être un fichier :values.',
    'min' => [
        'array' => 'Choisissez au moins :min pour :attribute.',
        'file' => 'Le fichier doit faire au moins :min Ko.',
        'numeric' => 'Saisissez :min ou plus pour :attribute.',
        'string' => 'Le champ :attribute est trop court (:min caractères au moins).',
    ],
    'numeric' => 'Saisissez un nombre pour :attribute.',
    'regex' => 'Le champ :attribute n’est pas au bon format.',
    'unique' => 'Ce :attribute est déjà utilisé.',
    'uploaded' => 'Le fichier n’a pas pu être envoyé. Réessayez, ou essayez un fichier plus petit.',
    'uuid' => 'Choisissez :attribute dans la liste.',

    'attributes' => [
        'email' => 'e-mail',
        'password' => 'mot de passe',
        'file' => 'fichier',
    ],
];
