<?php

// Le tableau de bord du propriétaire (App\Livewire\Dashboard, App\Reports\Dashboard).
return [
    'title' => 'Tableau de bord',
    'subtitle' => 'L’état des entrepôts : le travail fait, le stock à risque et ce qui attend.',

    'period' => [
        'all' => 'Toutes les dates',
        'range' => ':from – :to',
        'from' => 'À partir du :from',
        'to' => 'Jusqu’au :to',
    ],
    'now' => 'Maintenant',
    'loading' => 'Chargement des chiffres…',

    'branches' => [
        'title' => 'Commandes par agence',
        'hint' => 'Les commandes de chaque agence, client par client, et où elles en sont. Ouvrez une agence pour voir ses commandes.',
        'legend' => 'Étapes',
        'stage' => [
            'open' => 'Ouvertes',
            'ready' => 'Prêtes à expédier',
            'on_way' => 'En route',
            'delivered' => 'Livrées',
        ],
        'orders' => '{0,1} :count commande|[2,*] :count commandes',
        'late' => '{0,1} :count en retard|[2,*] :count en retard',
        'open' => 'Ouvrir les commandes de :branch (:customer)',
        'see_all' => 'Voir toutes les agences (:count)',
        'fewer' => 'Afficher les plus actives seulement',
        'branch' => 'Agence',
        'customer' => 'Client',
        'total' => 'Total',
        'late_column' => 'En retard',
        'empty' => 'Aucune commande sur cette période. Changez les dates, l’entrepôt ou le client pour en voir plus.',
    ],

    'discrepancies' => [
        'title' => 'Écarts de livraison par agence',
        'hint' => 'Lignes livrées où l’agence a reçu moins que commandé, ou un autre article, par motif. Ouvrez une agence pour voir les lignes.',
        'legend' => 'Motifs',
        'short' => '{0,1} :count ligne incomplète|[2,*] :count lignes incomplètes',
        'rate' => ':rate % des lignes livrées',
        'open' => 'Ouvrir les écarts de livraison de :branch (:customer)',
        'short_column' => 'Manquant',
        'delivered_column' => 'Lignes livrées',
        'empty' => 'Aucun écart de livraison sur cette période : chaque agence a reçu ce qu’elle a commandé.',
    ],

    'warehouses' => [
        'title' => 'Charge par entrepôt',
        'hint' => 'Articles traités dans chaque entrepôt. Un transfert compte là d’où il est parti.',
        'items' => '{0,1} :count article|[2,*] :count articles',
        'documents' => '{0,1} :count opération|[2,*] :count opérations',
    ],

    'customers' => [
        'title' => 'Charge par client',
        'hint' => 'Articles traités pour chaque client, et le type de travail. Choisissez un client pour ne voir que le sien sur le tableau de bord.',
        'legend' => 'Type de travail',
        'groups' => [
            'in' => 'Entrées',
            'out' => 'Sorties',
            'transfers' => 'Transferts',
            'counts' => 'Inventaires',
        ],
        'fewer' => 'Afficher moins',
        'show' => 'Afficher uniquement :customer sur le tableau de bord',
        'see_all' => 'Voir tous les clients (:count)',
        'empty' => 'Aucun travail sur cette période. Changez les dates ou l’entrepôt pour en voir plus.',
    ],

    'table' => [
        'show' => 'Afficher en tableau',
        'period' => 'Période',
        'documents' => 'Opérations',
        'lines' => 'Articles',
        'warehouse' => 'Entrepôt',
    ],

    'expiring' => [
        'title' => 'Bientôt périmé',
        'hint' => 'Périmé, ou périmant sous :days jours, le plus proche d’abord.',
        'empty' => 'Rien de périmé ni périmant sous :days jours.',
    ],

    'dormant' => [
        'title' => 'Sans mouvement',
        'hint' => 'En stock sans aucun mouvement depuis :days jours ou plus, les plus anciens d’abord.',
        'empty' => 'Tous les articles en stock ont bougé ces :days derniers jours.',
    ],

    'see_all' => 'Tout voir (:count)',
];
