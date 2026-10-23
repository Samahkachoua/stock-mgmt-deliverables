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

    'tiles' => [
        'heading' => 'Chiffres clés',
        'pending' => 'En attente d’approbation',
        'pending_oldest' => '{0,1} Le plus ancien attend depuis :count jour|[2,*] Le plus ancien attend depuis :count jours',
        'pending_none' => 'Rien en attente',
        'transit' => 'Transferts en route',
        'transit_late' => '{0} Aucun depuis plus de :days jours|{1} :count depuis plus de :days jours|[2,*] :count depuis plus de :days jours',
        'expiry' => 'Périmé ou périmant sous :days jours',
        'expired' => '{0} Rien de périmé|{1} :count déjà périmé|[2,*] :count déjà périmés',
        'damage' => 'Lignes de casse',
        'adjustments' => 'Lignes d’ajustement',
        'open' => 'Ouvrir : :what',
    ],

    'activity' => [
        'title' => 'Activité par type',
        'hint' => 'Réceptions, bons de livraison, transferts… réalisés au fil du temps.',
        'step' => [
            'day' => 'Par jour',
            'month' => 'Par mois',
            'year' => 'Par an',
        ],
        'bar' => ':period : :count',
        'empty' => 'Aucune activité sur cette période. Changez les dates ou l’entrepôt pour en voir plus.',
    ],

    'warehouses' => [
        'title' => 'Charge par entrepôt',
        'hint' => 'Articles traités dans chaque entrepôt. Un transfert compte là d’où il est parti.',
        'items' => '{0,1} :count article|[2,*] :count articles',
        'documents' => '{0,1} :count document|[2,*] :count documents',
    ],

    'table' => [
        'show' => 'Afficher en tableau',
        'period' => 'Période',
        'documents' => 'Documents',
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
