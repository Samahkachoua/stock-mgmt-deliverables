<?php

// The top bar search (App\Support\Search\GlobalSearch).
return [
    'label' => 'Rechercher',
    'title' => 'Rechercher',
    'open' => 'Rechercher',
    'placeholder' => 'Numéro, article, client, n° de série…',
    'hint' => 'Saisissez un numéro de document, un nom ou code d’article, un client, un n° de série ou un lot.',
    'no_results' => 'Aucun résultat pour « :text ». Essayez un numéro de document, un nom ou code d’article.',
    'reference_note' => 'Réf. :note',
    'customer_stock' => 'Voir son stock',
    'groups' => [
        'documents' => 'Documents',
        'items' => 'Articles',
        'customers' => 'Clients',
        'serials' => 'Numéros de série',
        'batches' => 'Lots',
    ],
];
