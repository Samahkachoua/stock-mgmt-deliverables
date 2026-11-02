<?php

// The page after signing in (App\Http\Controllers\HomeController).
return [
    'page_title' => 'Accueil',
    'title' => 'Bonjour, :name',
    'subtitle' => 'Que souhaitez-vous faire ?',
    'waiting' => 'En attente de vous',
    'arriving' => 'Transferts en route vers vos entrepôts',
    // A status card read aloud: "3 Adjustments to approve".
    'waiting_card' => ':count :label',
    'start' => 'Commencer quelque chose',
    'nothing_to_create' => 'Vous pouvez consulter le stock et les documents, mais pas en créer. Demandez à un administrateur si besoin.',
    'recent' => [
        'title' => 'Reprendre là où vous en étiez',
        'empty' => 'Les documents que vous créez ou traitez apparaîtront ici.',
    ],
];
