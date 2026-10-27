<?php

// The top bar search (App\Support\Search\GlobalSearch).
return [
    'label' => 'Search',
    'title' => 'Search',
    'open' => 'Search',
    'placeholder' => 'Search number, item, customer, serial…',
    'hint' => 'Type a document number, item name or code, customer, serial or batch.',
    'no_results' => 'No results for “:text”. Try a document number, item name or code.',
    'reference_note' => 'Ref. :note',
    'customer_stock' => 'Open their stock',
    'groups' => [
        'documents' => 'Documents',
        'items' => 'Items',
        'customers' => 'Customers',
        'serials' => 'Serial numbers',
        'batches' => 'Batches',
    ],
];
