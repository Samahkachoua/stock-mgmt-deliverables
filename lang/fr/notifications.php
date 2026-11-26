<?php

return [
    'title' => 'Notifications',
    'subtitle' => 'Ce qui vous attend et ce qu’il est advenu de vos demandes. Conservées :days jours.',
    'bell_unread' => '{1} Notifications, 1 non lue|[2,*] Notifications, :count non lues',
    'mark_all_read' => 'Tout marquer comme lu',
    'see_all' => 'Voir toutes les notifications',
    'empty_short' => 'Rien de nouveau.',
    'empty_title' => 'Aucune notification pour l’instant',
    'empty_body' => 'Vous verrez ici les approbations qui vous attendent, les commandes et brouillons de réception à confirmer, et le résultat de vos demandes.',
    'reason' => 'Motif',
    'no_access' => 'Vous n’avez plus accès à cette opération.',

    'events' => [
        'approval' => [
            'requested' => 'Demande d’approbation : :type :reference, envoyée par :actor',
            'approved' => 'Demande approuvée : :type :reference, par :actor',
            'rejected' => 'Demande refusée : :type :reference, par :actor',
        ],
        'draft' => [
            'created' => 'Ajout : :type :reference (:customer), par :actor',
            'updated' => 'Modification : :type :reference (:customer), par :actor',
            'cancelled' => 'Annulation : :type :reference (:customer), par :actor',
        ],
    ],

    'outcomes' => [
        'approved' => 'Approbation par :name · :time',
        'rejected' => 'Refus par :name · :time',
        'confirmed' => 'Confirmation par :name · :time',
        'cancelled' => 'Annulation par :name · :time',
    ],

    'states' => [
        'all' => 'Toutes les notifications',
        'unread' => 'Nouveau',
        'read' => 'Lu',
        'closed' => 'Traité',
    ],

    'groups' => [
        'approvals' => 'Approbations',
        'orders' => 'Commandes',
        'draft_receipts' => 'Brouillons de réception',
    ],

    'filters' => [
        'group' => 'Type',
        'all_groups' => 'Tous les types',
    ],

    'list' => [
        'message' => 'Notification',
        'when' => 'Date',
        'state' => 'Statut',
        'search_placeholder' => 'Rechercher par référence',
    ],

    'mail' => [
        'open' => 'Ouvrir dans l’application',
        'why' => [
            'approvals' => 'Vous recevez cet e-mail car vous pouvez approuver cette opération. Désactivez-le dans Paramètres › Utilisateurs, « Envoyer un e-mail pour les demandes d’approbation ».',
            'approval_results' => 'Vous recevez cet e-mail car vous avez fait cette demande. Désactivez-le dans Paramètres › Utilisateurs, « Envoyer un e-mail pour le résultat de mes demandes ».',
        ],
    ],
];
