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
    'nothing_to_create' => 'Vous pouvez consulter le stock et les opérations, mais pas en créer. Demandez à un administrateur si besoin.',
    'recent' => [
        'title' => 'Reprendre là où vous en étiez',
        'empty' => 'Les opérations que vous créez ou traitez apparaîtront ici.',
        'line' => ':customer · :event',
        // Nouns ("your last action"), so no agreement with the operation's gender.
        'events' => [
            'created' => 'Votre dernière action : création',
            'edited' => 'Votre dernière action : modification',
            'sent_for_approval' => 'Votre dernière action : envoi pour approbation',
            'approved' => 'Votre dernière action : approbation',
            'rejected' => 'Votre dernière action : rejet',
            'posted' => 'Votre dernière action : validation',
            'confirmed' => 'Votre dernière action : confirmation',
            'dispatched' => 'Votre dernière action : expédition',
            'received' => 'Votre dernière action : réception',
            'delivered' => 'Votre dernière action : livraison',
            'cancelled' => 'Votre dernière action : annulation',
        ],
    ],
];
