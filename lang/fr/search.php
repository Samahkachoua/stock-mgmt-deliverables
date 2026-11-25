<?php

// The top bar search (App\Support\Search\GlobalSearch).
return [
    'label' => 'Rechercher',
    'title' => 'Rechercher',
    'open' => 'Rechercher',
    'placeholder' => 'Page, numéro, article, client…',
    'hint' => 'Saisissez un nom de page, un numéro d’opération, un nom ou code d’article, un client, un n° de série ou un lot.',
    'no_results' => 'Aucun résultat pour « :text ». Essayez un numéro d’opération, un nom ou code d’article.',
    'reference_note' => 'Réf. :note',
    'customer_stock' => 'Voir son stock',
    'groups' => [
        'pages' => 'Pages',
        'documents' => 'Opérations',
        'items' => 'Articles',
        'customers' => 'Clients',
        'serials' => 'Numéros de série',
        'batches' => 'Lots',
    ],
];
