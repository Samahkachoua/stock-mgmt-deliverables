<?php

// Permission names for the Roles screen. `labels` is keyed by permission.code with
// dots written as underscores; codes without an entry show their stored description.
return [
    'modules' => [
        'STOCK' => 'Stock',
        'PRODUCT' => 'Articles',
        'MASTERDATA' => 'Données de base',
        'REPORT' => 'Rapports',
        'ADMIN' => 'Administration',
    ],

    'labels' => [
        'stock_view' => 'Voir le stock et les documents',
        'stock_in_create' => 'Créer des réceptions',
        'stock_out_create' => 'Créer des sorties',
        'stock_transfer_dispatch' => 'Expédier des transferts',
        'stock_transfer_receive' => 'Recevoir des transferts',
        'stock_damage_create' => 'Enregistrer la casse',
        'stock_damage_approve' => 'Approuver la casse',
        'stock_adjustment_create' => 'Créer des ajustements',
        'stock_adjustment_approve' => 'Approuver les ajustements',
        'stock_opening_create' => 'Saisir le stock initial',
        'transaction_cancel' => 'Annuler des documents',
        'product_manage' => 'Ajouter et modifier des articles',
        'product_import' => 'Importer des articles depuis Excel',
        'masterdata_manage' => 'Gérer entrepôts, clients et unités',
        'report_view' => 'Voir les rapports',
        'audit_view' => 'Voir le journal d’activité',
        'admin_users_manage' => 'Gérer les utilisateurs et les rôles',
        'admin_settings_manage' => 'Gérer les limites d’approbation et les paramètres',
    ],
];
