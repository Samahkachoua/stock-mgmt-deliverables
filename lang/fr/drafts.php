<?php

// Brouillons de réception et commandes : ils ne changent pas le stock avant d’être
// confirmés ; la confirmation crée une réception / un bon de livraison par entrepôt de leurs lignes.
return [
    'types' => [
        'receipt' => [
            'title' => 'Brouillons de réception',
            'singular' => 'Brouillon de réception',
            'new' => 'Nouveau brouillon de réception',
            'subtitle' => 'Stock attendu d’une agence du client. Le stock ne change pas tant que le brouillon n’est pas confirmé.',
            'form_subtitle' => 'Indiquez ce qui arrive et l’entrepôt de chaque article. Le stock change seulement à la confirmation.',
            'branch' => 'Agence d’origine',
            'branch_hint' => 'L’agence du client d’où viennent les marchandises.',
            'confirm' => 'Confirmer et réceptionner',
            'created_heading' => 'Réceptions créées',
            'empty_title' => 'Aucun brouillon de réception',
            'empty_body' => 'Utilisez « Nouveau brouillon de réception » pour noter du stock en route.',
        ],
        'order' => [
            'title' => 'Commandes',
            'singular' => 'Commande',
            'new' => 'Nouvelle commande',
            'subtitle' => 'Stock demandé pour une agence du client. Le stock ne change pas tant que la commande n’est pas confirmée.',
            'form_subtitle' => 'Indiquez ce qui doit sortir et l’entrepôt de chaque article. Le stock change seulement à la confirmation.',
            'branch' => 'Agence de destination',
            'branch_hint' => 'L’agence du client où vont les marchandises.',
            'confirm' => 'Confirmer et livrer',
            'created_heading' => 'Bons de livraison créés',
            'empty_title' => 'Aucune commande pour l’instant',
            'empty_body' => 'Utilisez « Nouvelle commande » pour noter du stock à livrer.',
        ],
    ],

    'status' => [
        'draft' => 'Brouillon',
        'confirmed' => 'Confirmé',
        'cancelled' => 'Annulé',
    ],

    'filters' => [
        'branch' => 'Agence',
        'all_branches' => 'Toutes les agences',
        'drafts_first' => 'Tous les statuts, brouillons d’abord',
        'search_placeholder' => 'Rechercher une référence, un client ou une agence…',
    ],

    'list' => [
        'warehouses' => 'Entrepôts',
    ],

    'details' => [
        'lines_heading' => 'Articles par entrepôt',
        'warehouses' => 'Entrepôts',
        'confirmed_by' => 'Confirmé par',
        'confirmed_on' => 'Confirmé le',
        'cancel_reason' => 'Motif de l’annulation',
        'not_in_stock_in' => '{1} Pas encore en stock. La confirmation crée 1 réception.|[2,*] Pas encore en stock. La confirmation crée :count réceptions, une par entrepôt.',
        'not_in_stock_out' => '{1} Toujours en stock. La confirmation crée 1 bon de livraison.|[2,*] Toujours en stock. La confirmation crée :count bons de livraison, un par entrepôt.',
        'waiting_for_confirmation' => 'En attente d’une personne autorisée à le confirmer.',
    ],

    'form' => [
        'edit_title' => 'Modifier :reference',
        'choose_branch' => 'Choisir une agence',
        'no_branches' => 'Ce client n’a pas encore d’agence. Ajoutez-les dans Paramètres › Agences.',
        'lines_heading' => 'Articles',
        'warehouse' => 'Entrepôt',
        'row_warehouse' => 'Entrepôt, ligne :row',
        'choose_warehouse' => 'Choisir…',
        'notice_in' => '{1} Enregistrer ne change pas le stock. Une fois confirmé, ce sera une réception.|[2,*] Enregistrer ne change pas le stock. Une fois confirmé, ce seront :count réceptions, une par entrepôt.',
        'notice_out' => '{1} Enregistrer ne change pas le stock. Une fois confirmée, ce sera un bon de livraison.|[2,*] Enregistrer ne change pas le stock. Une fois confirmée, ce seront :count bons de livraison, un par entrepôt.',
    ],

    'actions' => [
        'edit' => 'Modifier',
        'cancel' => 'Annuler :type',
        'save' => 'Enregistrer :type',
        'save_and_confirm' => 'Enregistrer et confirmer',
        'confirming' => 'Confirmation…',
    ],

    'confirm_cancel' => [
        'title' => 'Annuler :type :reference ?',
        'body' => 'Il ne pourra plus être modifié ni confirmé. Le stock n’est pas touché.',
        'button' => 'Annuler :type',
        'reason' => 'Pourquoi est-il annulé ?',
        'reason_placeholder' => 'ex. L’agence a retiré la commande',
    ],

    'feedback' => [
        'saved' => ':type :reference enregistré',
        'updated' => ':type :reference enregistré',
        'confirmed' => ':type :reference confirmé — :documents',
        'documents_in' => '{1} 1 réception créée|[2,*] :count réceptions créées',
        'documents_out' => '{1} 1 bon de livraison créé|[2,*] :count bons de livraison créés',
        'cancelled' => ':type :reference annulé',
    ],

    'leave' => [
        'title' => 'Abandonner vos modifications de :reference ?',
        'title_new' => 'Abandonner ce nouveau :type ?',
    ],

    'errors' => [
        'already_confirmed' => 'Ce document a déjà été confirmé par :name à :time.',
        'already_cancelled' => 'Ce document a été annulé.',
        'changed' => ':name a modifié ce document entre-temps. Rouvrez-le pour voir les modifications.',
        'cannot_create' => 'Vous n’avez pas le droit de créer ce document.',
        'cannot_edit' => 'Vous n’avez pas le droit de modifier ce document.',
        'cannot_confirm' => 'Vous n’avez pas le droit de confirmer ce document.',
        'cannot_cancel' => 'Vous n’avez pas le droit d’annuler ce document.',
        'warehouse_not_allowed' => 'Vous ne travaillez pas à :warehouse, vous ne pouvez donc pas confirmer ce document.',
        'branch_required' => 'Choisissez une agence.',
        'branch_not_allowed' => 'Vous ne pouvez pas utiliser cette agence.',
        'choose_warehouse' => 'Choisissez un entrepôt.',
    ],
];
