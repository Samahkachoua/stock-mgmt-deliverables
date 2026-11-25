<?php

// The top bar search (App\Support\Search\GlobalSearch).
return [
    'label' => 'Search',
    'title' => 'Search',
    'open' => 'Search',
    'placeholder' => 'Search pages, numbers, items, customers…',
    'hint' => 'Type a page name, operation number, item name or code, customer, serial or batch.',
    'no_results' => 'No results for “:text”. Try an operation number, item name or code.',
    'reference_note' => 'Ref. :note',
    'customer_stock' => 'Open their stock',
    'groups' => [
        'pages' => 'Pages',
        'documents' => 'Operations',
        'items' => 'Items',
        'customers' => 'Customers',
        'serials' => 'Serial numbers',
        'batches' => 'Batches',
    ],
];
